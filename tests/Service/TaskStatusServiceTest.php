<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TaskStatusService;
use PHPUnit\Framework\TestCase;

class TaskStatusServiceTest extends TestCase
{
    private string $tempDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/task_status_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $statusFile = $this->tempDir . '/var/scheduled_task_status.json';
        if (file_exists($statusFile)) {
            @unlink($statusFile);
        }
        if (is_dir($this->tempDir . '/var')) {
            @rmdir($this->tempDir . '/var');
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testStartAndGetStatus(): void
    {
        $service = new TaskStatusService($this->tempDir);
        $service->start('test:task', ['meta' => 'data']);

        $status = $service->getStatus();
        $this->assertIsArray($status);
        $this->assertSame('test:task', $status['task']);
        $this->assertSame('running', $status['status']);
        $this->assertSame('data', $status['metadata']['meta']);
    }

    public function testUpdateStepCalculatesMetrics(): void
    {
        $service = new TaskStatusService($this->tempDir);
        $service->start('test:task');

        $metrics = $service->updateStep('processing', 50, 100);

        $this->assertSame(50, $metrics['current']);
        $this->assertSame(100, $metrics['total']);
        $this->assertSame(50.0, $metrics['percentage']);
        $this->assertGreaterThanOrEqual(0, $metrics['elapsed_seconds']);

        $status = $service->getStatus();
        $this->assertIsArray($status);
        $this->assertSame('processing', $status['step']);
        $this->assertSame(50, $status['progress']['current']);
    }

    public function testFinishMarksTaskCompleted(): void
    {
        $service = new TaskStatusService($this->tempDir);
        $service->start('test:task');
        $service->finish(true, ['items' => 100]);

        $status = $service->getStatus();
        $this->assertIsArray($status);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(100, $status['summary']['items']);
        $this->assertArrayHasKey('finished_at', $status);
        $this->assertArrayHasKey('duration_seconds', $status);
    }

    public function testFailMarksTaskFailedWithDiagnostics(): void
    {
        $service = new TaskStatusService($this->tempDir);
        $service->start('test:task');
        $service->fail('Timeout exceeded', ['rate' => 0.5]);

        $status = $service->getStatus();
        $this->assertIsArray($status);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('Timeout exceeded', $status['error']);
        $this->assertSame(0.5, $status['diagnostics']['rate']);
        $this->assertArrayHasKey('failed_at', $status);
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('45s', TaskStatusService::formatDuration(45));
        $this->assertSame('2m 30s', TaskStatusService::formatDuration(150));
        $this->assertSame('1h 05m 20s', TaskStatusService::formatDuration(3920));
    }
}
