<?php

declare(strict_types=1);

namespace App\Services\Process;

use Illuminate\Support\Carbon;

/**
 * Verifies a claimed OS process is genuinely alive and hasn't been recycled by PID reuse, by
 * reading /proc directly rather than trusting anything the claiming caller reports — a claim binds
 * to (pid, process start time), and the server independently confirms that exact pair still exists
 * before honoring a heartbeat, release, or takeover decision.
 *
 * Container/PID-namespace note: this only works when the running process's /proc reflects the same
 * PID namespace as the process it's checking (the `app` service, which runs the MCP server and the
 * CLI, has `pid: "host"` for exactly this reason). The web service doesn't, so it runs with
 * DIBS_PROCESS_LIVENESS=false: its /proc only shows its own container, where a host PID is either
 * missing or a different process. When liveness can't be checked, callers must treat that as
 * "could not be verified", never as "process is dead" — see currentlyAlive().
 */
class LinuxProcessLiveness
{
    /** USER_HZ is 100 on effectively all modern Linux kernels; avoids a getconf subprocess per check. */
    private const int CLOCK_TICKS_PER_SECOND = 100;

    public function __construct(private readonly string $procPath = '/proc', private readonly bool $enabled = true) {}

    /** Whether this process can check the agents' processes at all: enabled, and /proc readable. */
    public function isVerifiable(): bool
    {
        return $this->enabled && is_readable($this->procPath.'/stat') && is_readable($this->procPath.'/uptime');
    }

    /** The real start time of $pid right now, or null if the process doesn't exist or /proc can't be read. */
    public function startedAt(int $pid): ?Carbon
    {
        if (! $this->enabled) {
            return null;
        }
        $bootTime = $this->bootTime();
        if ($bootTime === null) {
            return null;
        }
        $stat = @file_get_contents($this->procPath.'/'.$pid.'/stat');
        if ($stat === false) {
            return null;
        }
        // Field 2 (comm) is parenthesized and may itself contain spaces/parens, so split after its final ')'.
        $afterComm = strrchr($stat, ')');
        if ($afterComm === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($afterComm));
        // Fields here start at 3 (state); field 22 (starttime) is therefore index 22 - 3 = 19.
        $ticks = isset($fields[19]) && is_numeric($fields[19]) ? (int) $fields[19] : null;
        if ($ticks === null) {
            return null;
        }

        return Carbon::createFromTimestamp($bootTime + intdiv($ticks, self::CLOCK_TICKS_PER_SECOND));
    }

    /**
     * True only if $pid currently exists AND its real start time matches $expectedStartedAt — a mismatch
     * means the PID was recycled for a different, unrelated process since the claim was made.
     */
    public function isAlive(int $pid, Carbon $expectedStartedAt): bool
    {
        $actual = $this->startedAt($pid);
        if (! $actual instanceof Carbon) {
            return false;
        }

        // Tolerate rounding: ticks give ~10ms resolution, boot-time arithmetic can be off by a second.
        return abs($actual->getTimestamp() - $expectedStartedAt->getTimestamp()) <= 2;
    }

    /** isAlive(), or null when this environment can't check processes at all (see isVerifiable()). */
    public function currentlyAlive(int $pid, Carbon $expectedStartedAt): ?bool
    {
        return $this->isVerifiable() ? $this->isAlive($pid, $expectedStartedAt) : null;
    }

    private function bootTime(): ?int
    {
        $stat = @file_get_contents($this->procPath.'/stat');
        if ($stat === false) {
            return null;
        }
        if (preg_match('/^btime (\d+)/m', $stat, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
