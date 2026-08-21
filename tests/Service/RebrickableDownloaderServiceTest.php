<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RebrickableDownloaderService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RebrickableDownloaderServiceTest extends TestCase
{
    private string $tempDataDir;

    protected function setUp(): void
    {
        $this->tempDataDir = sys_get_temp_dir() . '/rebrickable_test_' . uniqid('', true);
        if (!is_dir($this->tempDataDir)) {
            mkdir($this->tempDataDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDataDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testGetDataPathAndDumpFiles(): void
    {
        $service = new RebrickableDownloaderService($this->tempDataDir, null, new NullLogger());

        $this->assertSame(rtrim($this->tempDataDir, '/'), $service->getDataPath());
        $dumpFiles = $service->getDumpFiles();
        $this->assertContains('sets.csv.gz', $dumpFiles);
        $this->assertContains('parts.csv.gz', $dumpFiles);
        $this->assertContains('inventories.csv.gz', $dumpFiles);
        $this->assertContains('inventory_parts.csv.gz', $dumpFiles);
        $this->assertContains('colors.csv.gz', $dumpFiles);
        $this->assertContains('themes.csv.gz', $dumpFiles);
        $this->assertContains('part_categories.csv.gz', $dumpFiles);
    }

    public function testDownloadFileWithHttpClientSuccess(): void
    {
        $rawCsv = "set_num,name,year\n75192-1,Millennium Falcon,2017\n";
        $gzipped = gzencode($rawCsv);
        $this->assertIsString($gzipped);

        $mockResponse = new MockResponse($gzipped, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $result = $service->downloadFile('sets.csv.gz', true);

        $this->assertTrue($result);
        $targetFile = $this->tempDataDir . '/sets.csv';
        $this->assertFileExists($targetFile);
        $this->assertSame($rawCsv, file_get_contents($targetFile));
    }

    public function testDownloadFileSkipsWhenFreshAndNotForced(): void
    {
        $targetFile = $this->tempDataDir . '/sets.csv';
        file_put_contents($targetFile, 'existing content');
        touch($targetFile, time());

        // HttpClient should not be called
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $result = $service->downloadFile('sets.csv', false);

        $this->assertTrue($result);
        $this->assertSame('existing content', file_get_contents($targetFile));
    }

    public function testDownloadFileForcesWhenForceIsTrue(): void
    {
        $targetFile = $this->tempDataDir . '/sets.csv';
        file_put_contents($targetFile, 'old content');
        touch($targetFile, time());

        $newCsv = "set_num,name,year\n001-1,Gears,1965\n";
        $gzipped = gzencode($newCsv);
        $this->assertIsString($gzipped);

        $mockResponse = new MockResponse($gzipped, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $result = $service->downloadFile('sets.csv.gz', true);

        $this->assertTrue($result);
        $this->assertSame($newCsv, file_get_contents($targetFile));
    }

    public function testDownloadFileHandlesHttpNon200Error(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 404]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $result = $service->downloadFile('sets.csv.gz', true);

        $this->assertFalse($result);
    }

    public function testDownloadAllExecutesCallbackAndReturnsResults(): void
    {
        $rawCsv = "id,name\n1,Test\n";
        $gzipped = gzencode($rawCsv);
        $this->assertIsString($gzipped);

        $httpClient = new MockHttpClient(fn(string $method, string $url): MockResponse => new MockResponse($gzipped, ['http_code' => 200]));

        $progressEvents = [];
        $callback = function (string $file, string $status, int $current, int $total) use (&$progressEvents): void {
            $progressEvents[] = ['file' => $file, 'status' => $status, 'current' => $current, 'total' => $total];
        };

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $results = $service->downloadAll(true, $callback);

        $this->assertCount(count(RebrickableDownloaderService::DUMP_FILES), $results);
        foreach ($results as $file => $success) {
            $this->assertTrue($success, "File {$file} should have downloaded successfully");
        }
        $this->assertNotEmpty($progressEvents);
    }

    public function testDownloadFileHandlesCorruptedGzip(): void
    {
        $invalidGzip = "not a valid gzip stream header";

        $mockResponse = new MockResponse($invalidGzip, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $service = new RebrickableDownloaderService($this->tempDataDir, $httpClient, new NullLogger());
        $result = $service->downloadFile('corrupt.csv.gz', true);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($this->tempDataDir . '/corrupt.csv');
    }
}
