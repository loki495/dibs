#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Host-side endpoint for the Dibs container's SSH capture bridge. Reachable only via a
 * forced-command authorized_keys entry (no shell, no port/agent forwarding) -- the client's
 * actual SSH command is ignored by SSH and this script always runs instead, receiving whatever
 * the client asked for in SSH_ORIGINAL_COMMAND. Never passes that value to a shell.
 *
 * Invocation: SSH_ORIGINAL_COMMAND=<agent name> php run-capture-agent.php < request.json
 * Request (stdin, JSON): {"prompt": "...", "schema": {...}|null}
 * Response (stdout, JSON, single line): {"ok": true, "draft": {...}} or {"ok": false, "error": "..."}
 *
 * Each supported CLI is invoked one-shot/non-interactive, session-less, no state kept between
 * calls. All diagnostics go to stderr; stdout carries exactly one JSON line so the caller can
 * parse it without guessing where the payload starts.
 */
const ALLOWED_AGENTS = ['claude', 'codex', 'agy', 'opencode'];

/**
 * Absolute paths, not bare names: a forced-command SSH session doesn't source the interactive
 * shell's profile, so PATH here is minimal and won't find these under ~/.local/bin or similar.
 */
const AGENT_BINARIES = [
    'claude' => '/home/andres/.local/bin/claude',
    'codex' => '/home/andres/.local/bin/codex',
    'agy' => '/home/andres/.local/bin/agy',
    'opencode' => '/home/andres/.opencode/bin/opencode',
];

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    echo json_encode(['ok' => false, 'error' => $message]).PHP_EOL;
    exit(1);
}

function succeed(array $draft): never
{
    echo json_encode(['ok' => true, 'draft' => $draft]).PHP_EOL;
    exit(0);
}

/** Runs a one-shot CLI process and returns its stdout, throwing on a nonzero exit or timeout. */
function runOneShot(array $command, int $timeoutSeconds = 120): string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start '.$command[0]);
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (! $status['running']) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = $status['exitcode'];
            proc_close($process);

            if ($exitCode !== 0) {
                throw new RuntimeException($command[0].' exited '.$exitCode.': '.trim($stderr));
            }

            return $stdout;
        }

        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            throw new RuntimeException($command[0].' timed out after '.$timeoutSeconds.'s');
        }

        usleep(100_000);
    }
}

/** @return array<string, mixed> the model's raw text reply, decoded as JSON */
function decodeModelJson(string $text): array
{
    $text = trim($text);

    if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m) === 1) {
        $text = $m[1];
    }

    $decoded = json_decode($text, true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Model reply was not valid JSON: '.substr($text, 0, 200));
    }

    return $decoded;
}

function writeSchemaFile(?array $schema): ?string
{
    if ($schema === null) {
        return null;
    }

    $path = tempnam(sys_get_temp_dir(), 'dibs-capture-schema-');
    file_put_contents($path, json_encode($schema));

    return $path;
}

function runClaude(string $prompt, ?array $schema): array
{
    $schemaFile = writeSchemaFile($schema);
    $command = [AGENT_BINARIES['claude'], '-p', $prompt, '--output-format', 'json'];
    if ($schemaFile !== null) {
        $command[] = '--json-schema';
        $command[] = $schemaFile;
    }

    $events = json_decode(runOneShot($command), true);
    if (! is_array($events)) {
        throw new RuntimeException('claude: unexpected output shape');
    }

    $result = end($events);
    if (! is_array($result) || ($result['type'] ?? null) !== 'result' || ! is_string($result['result'] ?? null)) {
        throw new RuntimeException('claude: no result event in output');
    }

    return decodeModelJson($result['result']);
}

function runAgy(string $prompt, ?array $schema): array
{
    $schemaFile = writeSchemaFile($schema);
    $command = [AGENT_BINARIES['agy'], '--output-format', 'json'];
    if ($schemaFile !== null) {
        $command[] = '--json-schema';
        $command[] = $schemaFile;
    }
    $command[] = '--print';
    $command[] = $prompt;

    $result = json_decode(runOneShot($command), true);
    if (! is_array($result)) {
        throw new RuntimeException('agy: unexpected output shape');
    }
    if (($result['status'] ?? null) !== 'SUCCESS' && ($result['error'] ?? null) !== null) {
        throw new RuntimeException('agy: '.$result['error']);
    }
    if (! is_string($result['response'] ?? null)) {
        throw new RuntimeException('agy: no response field in output');
    }

    return decodeModelJson($result['response']);
}

function runCodex(string $prompt, ?array $schema): array
{
    $schemaFile = writeSchemaFile($schema);
    $command = [AGENT_BINARIES['codex'], 'exec', $prompt, '--json', '--skip-git-repo-check', '--sandbox', 'read-only'];
    if ($schemaFile !== null) {
        $command[] = '--output-schema';
        $command[] = $schemaFile;
    }

    $lastMessage = null;
    foreach (explode("\n", trim(runOneShot($command))) as $line) {
        if ($line === '') {
            continue;
        }
        $event = json_decode($line, true);
        if (is_array($event) && ($event['item']['type'] ?? null) === 'agent_message' && is_string($event['item']['text'] ?? null)) {
            $lastMessage = $event['item']['text'];
        }
    }

    if ($lastMessage === null) {
        throw new RuntimeException('codex: no agent_message event in output');
    }

    return decodeModelJson($lastMessage);
}

function runOpencode(string $prompt, ?array $schema): array
{
    $fullPrompt = $prompt;
    if ($schema !== null) {
        $fullPrompt .= "\n\nReply with ONLY a single JSON object matching this JSON Schema, no other text:\n".json_encode($schema);
    }

    $lastText = null;
    foreach (explode("\n", trim(runOneShot([AGENT_BINARIES['opencode'], 'run', $fullPrompt, '--format', 'json']))) as $line) {
        if ($line === '') {
            continue;
        }
        $event = json_decode($line, true);
        if (is_array($event) && ($event['part']['type'] ?? null) === 'text' && is_string($event['part']['text'] ?? null)) {
            $lastText = $event['part']['text'];
        }
    }

    if ($lastText === null) {
        throw new RuntimeException('opencode: no text event in output');
    }

    return decodeModelJson($lastText);
}

$agent = trim((string) getenv('SSH_ORIGINAL_COMMAND'));
$agent = explode(' ', $agent, 2)[0];

if (! in_array($agent, ALLOWED_AGENTS, true)) {
    fail("Unknown or disallowed agent: '{$agent}'");
}

$request = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($request) || ! is_string($request['prompt'] ?? null)) {
    fail('Request body must be JSON with a string "prompt" field');
}

$prompt = $request['prompt'];
$schema = is_array($request['schema'] ?? null) ? $request['schema'] : null;

try {
    $draft = match ($agent) {
        'claude' => runClaude($prompt, $schema),
        'agy' => runAgy($prompt, $schema),
        'codex' => runCodex($prompt, $schema),
        'opencode' => runOpencode($prompt, $schema),
    };
} catch (Throwable $exception) {
    fail($agent.': '.$exception->getMessage());
}

succeed($draft);
