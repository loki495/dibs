<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ChangeLog;
use App\Models\Issue;
use App\Models\McpCallLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a write Action (or an event source) records into the change log. Deliberately
 * not a singleton: it is cheap, and it takes the scoped ActivityContext at construction.
 */
class ActivityRecorder
{
    /** Bookkeeping columns that change on every save and say nothing about what the user did. */
    private const array IGNORED_ATTRIBUTES = ['created_at', 'updated_at', 'revision', 'last_seen_at'];

    public function __construct(
        private readonly ActivityContext $context,
        private readonly ActivityRedactor $redactor,
    ) {}

    /**
     * Record that an Action changed (or created, or deleted) $subject.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  see diff() and diffModel()
     */
    public function change(string $action, ?Model $subject, string $summary, array $changes = [], ?string $subjectLabel = null): ?ChangeLog
    {
        return $this->write(ChangeLog::CATEGORY_CHANGE, $action, $summary, $changes, $subject, $subjectLabel);
    }

    /**
     * Record something that happened but did not change one subject: a sign-in, a GitHub pull, a queue drain.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function event(string $category, string $action, string $summary, array $changes = []): ?ChangeLog
    {
        return $this->write($category, $action, $summary, $changes, null, null);
    }

    /**
     * Record one MCP tool call. The request id comes from the current context, so it lines up with any
     * change entries the same call produced. Arguments are redacted and truncated like a diff would be.
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  McpCallLog::STATUS_*  $status
     */
    public function mcpCall(string $tool, array $arguments, string $status, ?string $errorMessage, int $durationMs, ?string $agentLabel): ?McpCallLog
    {
        return $this->guarded(fn (): McpCallLog => McpCallLog::query()->create([
            'request_id' => $this->context->requestId(),
            'tool' => Str::limit($tool, 255),
            'arguments' => $this->redactor->redact($arguments),
            'status' => $status,
            'error_message' => $errorMessage === null ? null : $this->redactor->redact($errorMessage),
            'duration_ms' => $durationMs,
            'agent_label' => $agentLabel === null ? null : Str::limit($agentLabel, 255),
        ]));
    }

    /**
     * The fields whose value differs between two snapshots.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $diff = [];
        foreach (array_keys($before + $after) as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;

            if ($from !== $to) {
                $diff[$field] = ['from' => $from, 'to' => $to];
            }
        }

        return $diff;
    }

    /**
     * What the last save did to $model: the changed attributes of an update, every attribute of a
     * fresh insert (from null), every attribute of a deleted row (to null).
     *
     * @param  list<string>  $only  restrict the result to these fields when given
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diffModel(Model $model, array $only = []): array
    {
        $diff = $this->modelDiff($model);

        return $only === [] ? $diff : array_intersect_key($diff, array_flip($only));
    }

    /**
     * Record what the last save did to $model, or nothing when it changed no field worth recording.
     * The summary names the changed fields, e.g. "Updated issue: title, body".
     */
    public function changeModel(string $action, Model $model, string $verb = 'Updated'): ?ChangeLog
    {
        $diff = $this->diffModel($model);

        if ($diff === []) {
            return null;
        }

        return $this->change($action, $model, $verb.' '.Str::lower(class_basename($model)).': '.implode(', ', array_keys($diff)), $diff);
    }

    /** The title or name of a record, for showing a chosen option or project as text rather than an id. */
    public function nameOf(string $modelClass, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $model = $modelClass::query()->find($id);
        $name = $model instanceof Model ? ($model->getAttribute('title') ?? $model->getAttribute('name')) : null;

        return is_scalar($name) ? (string) $name : null;
    }

    /** An issue as text for a diff, e.g. "Fix the sink (#12)"; null when there is none. */
    public function issueRef(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $title = $this->nameOf(Issue::class, $id);

        return $title === null ? "#{$id}" : "{$title} (#{$id})";
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function modelDiff(Model $model): array
    {
        $attributes = array_diff_key(
            $model->getAttributes(),
            array_flip([...self::IGNORED_ATTRIBUTES, $model->getKeyName()]),
        );

        if (! $model->exists) {
            return $this->diff($attributes, []);
        }

        if ($model->wasRecentlyCreated && $model->getChanges() === []) {
            return $this->diff([], $attributes);
        }

        $previous = $model->getPrevious();
        $after = array_diff_key($model->getChanges(), array_flip(self::IGNORED_ATTRIBUTES));

        return $this->diff(array_intersect_key($previous, $after), $after);
    }

    /** @param  array<string, array{from: mixed, to: mixed}>  $changes */
    private function write(string $category, string $action, string $summary, array $changes, ?Model $subject, ?string $subjectLabel): ?ChangeLog
    {
        return $this->guarded(fn (): ChangeLog => ChangeLog::query()->create([
            'request_id' => $this->context->requestId(),
            'source' => $this->context->source(),
            'category' => $category,
            'action' => $action,
            'subject_type' => $subject instanceof Model ? Str::lower(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject instanceof Model ? Str::limit($subjectLabel ?? $this->labelOf($subject), 255) : null,
            'summary' => $this->redactor->redact($summary),
            'changes' => $changes === [] ? null : $this->redactor->redact($changes),
            'actor_type' => $this->context->actorType(),
            'actor_label' => $this->context->actorLabel(),
        ]));
    }

    /**
     * A log entry that cannot be written must not undo the write it describes in production,
     * but a local/debug run has to see it.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T|null
     */
    private function guarded(callable $write): mixed
    {
        try {
            return $write();
        } catch (Throwable $e) {
            if (config('app.debug')) {
                throw $e;
            }

            report($e);

            return null;
        }
    }

    private function labelOf(Model $subject): string
    {
        $label = $subject->getAttribute('title') ?? $subject->getAttribute('name');

        return is_scalar($label) ? (string) $label : '';
    }
}
