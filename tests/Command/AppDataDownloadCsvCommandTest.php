<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AppDataDownloadCsvCommand;
use App\Service\RebrickableDownloaderService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AppDataDownloadCsvCommandTest extends TestCase
{
    public function testExecuteSuccessful(): void
    {
        $downloader = $this->createMock(RebrickableDownloaderService::class);
        $downloader->method('getDumpFiles')->willReturn([
            'sets.csv.gz',
            'parts.csv.gz',
        ]);
        $downloader->method('getDataPath')->willReturn('/app/data');
        $downloader->method('downloadAll')->willReturnCallback(function (bool $force, ?callable $onProgress = null): array {
            if ($onProgress !== null) {
                $onProgress('sets.csv.gz', 'done', 1, 2);
                $onProgress('parts.csv.gz', 'done', 2, 2);
            }
            return [
                'sets.csv.gz' => true,
                'parts.csv.gz' => true,
            ];
        });

        $command = new AppDataDownloadCsvCommand($downloader);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Rebrickable CSV Dumps Downloader', $display);
        $this->assertStringContainsString('sets.csv.gz', $display);
        $this->assertStringContainsString('SUCCESS', $display);
        $this->assertStringContainsString('All Rebrickable CSV dumps downloaded and decompressed successfully', $display);
    }

    public function testExecuteWithForceOption(): void
    {
        $downloader = $this->createMock(RebrickableDownloaderService::class);
        $downloader->method('getDumpFiles')->willReturn(['sets.csv.gz']);
        $downloader->method('getDataPath')->willReturn('/app/data');
        $downloader->expects($this->once())
            ->method('downloadAll')
            ->with($this->equalTo(true), $this->anything())
            ->willReturn(['sets.csv.gz' => true]);

        $command = new AppDataDownloadCsvCommand($downloader);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('force: yes', $tester->getDisplay());
    }

    public function testExecuteWithFailures(): void
    {
        $downloader = $this->createMock(RebrickableDownloaderService::class);
        $downloader->method('getDumpFiles')->willReturn(['sets.csv.gz', 'parts.csv.gz']);
        $downloader->method('getDataPath')->willReturn('/app/data');
        $downloader->method('downloadAll')->willReturn([
            'sets.csv.gz' => true,
            'parts.csv.gz' => false,
        ]);

        $command = new AppDataDownloadCsvCommand($downloader);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('FAILED', $display);
        $this->assertStringContainsString('Some Rebrickable CSV dumps could not be downloaded', $display);
    }
}
