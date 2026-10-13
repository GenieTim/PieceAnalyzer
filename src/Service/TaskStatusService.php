<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Service to track, persist, and expose task progress, throughput, and status.
 * Writes atomic JSON status files to var/scheduled_task_status.json so task
 * state survives unexpected terminations (e.g. process timeouts or kills).
 */
class TaskStatusService
{
    private readonly string $statusFilePath;
    private ?float $startedAtMicrotime = null;

    public function __construct(string $projectDir)
    {
        $this->statusFilePath = rtrim($projectDir, '/\\') . '/var/scheduled_task_status.json';
    }

    public function getStatusFilePath(): string
    {
        return $this->statusFilePath;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function start(string $task, array $metadata = []): void
    {
        $this->startedAtMicrotime = microtime(true);
        $now = new DateTimeImmutable();

        $data = [
            'task' => $task,
            'status' => 'running',
            'started_at' => $now->format(DateTimeInterface::ATOM),
            'updated_at' => $now->format(DateTimeInterface::ATOM),
            'elapsed_seconds' => 0,
            'pid' => getmypid() ?: 0,
            'memory_start_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'step' => null,
            'progress' => null,
            'throughput' => null,
            'metadata' => $metadata,
            'error' => null,
            'diagnostics' => null,
        ];

        $this->writeStatusFile($data);
    }

    /**
     * Update progress of a long-running step and calculate telemetry.
     *
     * @param array<string, mixed> $extra
     * @return array{
     *     elapsed_seconds: int,
     *     rate_per_second: float,
     *     eta_seconds: ?int,
     *     percentage: float,
     *     current: int,
     *     total: int,
     *     memory_mb: float
     * }
     */
    public function updateStep(string $step, int $current, int $total, array $extra = []): array
    {
        $nowMicro = microtime(true);
        $startTime = $this->startedAtMicrotime ?? $nowMicro;
        $elapsed = max(1, (int) round($nowMicro - $startTime));

        $rate = $elapsed > 0 && $current > 0 ? round($current / ($nowMicro - $startTime), 2) : 0.0;
        $remaining = max(0, $total - $current);
        $eta = $rate > 0 ? (int) round($remaining / $rate) : null;
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0.0;
        $memoryMb = round(memory_get_usage(true) / 1024 / 1024, 1);

        $currentData = $this->getStatus() ?? [];
        $currentData['status'] = 'running';
        $currentData['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $currentData['elapsed_seconds'] = $elapsed;
        $currentData['memory_peak_bytes'] = memory_get_peak_usage(true);
        $currentData['step'] = $step;
        $currentData['progress'] = [
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'remaining' => $remaining,
        ];
        $currentData['throughput'] = [
            'rate_per_second' => $rate,
            'eta_seconds' => $eta,
            'memory_mb' => $memoryMb,
        ];
        $currentData['extra'] = $extra;

        $this->writeStatusFile($currentData);

        return [
            'elapsed_seconds' => $elapsed,
            'rate_per_second' => $rate,
            'eta_seconds' => $eta,
            'percentage' => $percentage,
            'current' => $current,
            'total' => $total,
            'memory_mb' => $memoryMb,
        ];
    }

    /**
     * Mark task as finished successfully.
     *
     * @param array<string, mixed> $summary
     */
    public function finish(bool $success = true, array $summary = []): void
    {
        $nowMicro = microtime(true);
        $elapsed = $this->startedAtMicrotime !== null ? round($nowMicro - $this->startedAtMicrotime, 2) : 0.0;

        $data = $this->getStatus() ?? [];
        $data['status'] = $success ? 'completed' : 'failed';
        $data['finished_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $data['duration_seconds'] = $elapsed;
        $data['memory_peak_bytes'] = memory_get_peak_usage(true);
        $data['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $data['summary'] = $summary;

        $this->writeStatusFile($data);
    }

    /**
     * Mark task as failed or timed out with diagnostics.
     *
     * @param array<string, mixed> $diagnostics
     */
    public function fail(string $error, array $diagnostics = []): void
    {
        $nowMicro = microtime(true);
        $elapsed = $this->startedAtMicrotime !== null ? round($nowMicro - $this->startedAtMicrotime, 2) : 0.0;

        $data = $this->getStatus() ?? [];
        $data['status'] = 'failed';
        $data['failed_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $data['duration_seconds'] = $elapsed;
        $data['memory_peak_bytes'] = memory_get_peak_usage(true);
        $data['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $data['error'] = $error;
        $data['diagnostics'] = $diagnostics;

        $this->writeStatusFile($data);
    }

    /**
     * Read currently saved status file if available.
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(): ?array
    {
        if (!file_exists($this->statusFilePath)) {
            return null;
        }

        $content = @file_get_contents($this->statusFilePath);
        if ($content === false || $content === '') {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Format a duration in seconds into human-readable string (e.g. "1h 12m 45s" or "35s").
     */
    public static function formatDuration(int|float $seconds): string
    {
        $sec = (int) round($seconds);
        if ($sec < 60) {
            return $sec . 's';
        }

        $minutes = (int) floor($sec / 60);
        $remSec = $sec % 60;
        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $remSec);
        }

        $hours = (int) floor($minutes / 60);
        $remMin = $minutes % 60;
        return sprintf('%dh %02dm %02ds', $hours, $remMin, $remSec);
    }

    /**
     * Write data atomically to JSON status file.
     *
     * @param array<string, mixed> $data
     */
    private function writeStatusFile(array $data): void
    {
        $dir = dirname($this->statusFilePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmpFile = $this->statusFilePath . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmpFile, $json) !== false) {
            @rename($tmpFile, $this->statusFilePath);
        }
    }
}
