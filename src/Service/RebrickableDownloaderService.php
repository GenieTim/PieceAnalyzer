<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Service to download and decompress public daily CSV dumps from Rebrickable.
 */
class RebrickableDownloaderService
{
    public const BASE_URL = 'https://cdn.rebrickable.com/media/downloads/';

    /**
     * @var array<int, string>
     */
    public const DUMP_FILES = [
        'sets.csv.gz',
        'parts.csv.gz',
        'inventories.csv.gz',
        'inventory_parts.csv.gz',
        'colors.csv.gz',
        'themes.csv.gz',
        'part_categories.csv.gz',
    ];

    private readonly string $dataPath;

    public function __construct(
        string $dataPath,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->dataPath = rtrim($dataPath, '/\\');
    }

    public function getDataPath(): string
    {
        return $this->dataPath;
    }

    /**
     * @return array<int, string>
     */
    public function getDumpFiles(): array
    {
        return self::DUMP_FILES;
    }

    /**
     * Download and extract all Rebrickable CSV dumps.
     *
     * @param bool $force Force download even if files were recently downloaded
     * @param (callable(string $file, string $status, int $current, int $total): void)|null $onProgress Optional progress callback
     * @return array<string, bool> Map of dump filename to success boolean
     */
    public function downloadAll(bool $force = false, ?callable $onProgress = null): array
    {
        $results = [];
        $total = count(self::DUMP_FILES);
        $current = 0;

        foreach (self::DUMP_FILES as $file) {
            ++$current;
            if ($onProgress !== null) {
                $onProgress($file, 'start', $current, $total);
            }

            try {
                $success = $this->downloadFile($file, $force);
            } catch (Throwable $e) {
                $this->logger->error('Failed to download Rebrickable file', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                $success = false;
            }

            $results[$file] = $success;

            if ($onProgress !== null) {
                $onProgress($file, $success ? 'done' : 'failed', $current, $total);
            }
        }

        return $results;
    }

    /**
     * Download and decompress a single Rebrickable dump file.
     *
     * @param string $filename Name of the file, e.g. 'sets.csv.gz' or 'sets.csv'
     * @param bool $force Force download even if fresh file exists
     */
    public function downloadFile(string $filename, bool $force = false): bool
    {
        $gzFilename = str_ends_with($filename, '.gz') ? $filename : $filename . '.gz';
        $csvFilename = str_ends_with($filename, '.gz') ? substr($filename, 0, -3) : $filename;

        if (!is_dir($this->dataPath) && (!mkdir($this->dataPath, 0777, true) && !is_dir($this->dataPath))) {
            $this->logger->error('Failed to create data directory', ['path' => $this->dataPath]);
            return false;
        }

        $targetCsvPath = $this->dataPath . '/' . $csvFilename;

        // Skip if not forced and file exists and is less than 24 hours old with non-zero size
        if (!$force && file_exists($targetCsvPath) && filesize($targetCsvPath) > 0) {
            $mtime = (int) filemtime($targetCsvPath);
            if ((time() - $mtime) < 86400) {
                $this->logger->info('File is already up to date, skipping download', [
                    'file' => $csvFilename,
                    'path' => $targetCsvPath,
                ]);
                return true;
            }
        }

        $url = self::BASE_URL . $gzFilename;
        $tempGzPath = $targetCsvPath . '.gz.tmp';
        $tempCsvPath = $targetCsvPath . '.tmp';

        $this->logger->info('Downloading Rebrickable dump', [
            'url' => $url,
            'target' => $targetCsvPath,
        ]);

        try {
            $downloaded = $this->saveUrlToPath($url, $tempGzPath);
            if (!$downloaded) {
                $this->logger->warning('Could not save download to temp file', ['url' => $url]);
                $this->cleanUpFiles([$tempGzPath, $tempCsvPath]);
                return false;
            }

            $extracted = $this->decompressGzFile($tempGzPath, $tempCsvPath);
            if (!$extracted) {
                $this->logger->warning('Could not decompress gzip file', ['gz' => $tempGzPath]);
                $this->cleanUpFiles([$tempGzPath, $tempCsvPath]);
                return false;
            }

            if (!rename($tempCsvPath, $targetCsvPath)) {
                $this->logger->warning('Could not rename decompressed file to target', [
                    'from' => $tempCsvPath,
                    'to' => $targetCsvPath,
                ]);
                $this->cleanUpFiles([$tempGzPath, $tempCsvPath]);
                return false;
            }

            $this->cleanUpFiles([$tempGzPath]);

            $this->logger->info('Successfully downloaded and extracted Rebrickable dump', [
                'file' => $csvFilename,
                'bytes' => filesize($targetCsvPath),
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Exception occurred during download or extraction', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);
            $this->cleanUpFiles([$tempGzPath, $tempCsvPath]);
            return false;
        }
    }

    /**
     * Download content from URL to a local destination file.
     */
    private function saveUrlToPath(string $url, string $destinationPath): bool
    {
        if ($this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->warning('Download returned non-200 status code', [
                    'url' => $url,
                    'status' => $statusCode,
                ]);
                return false;
            }

            $fp = fopen($destinationPath, 'wb');
            if ($fp === false) {
                return false;
            }

            try {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    fwrite($fp, $chunk->getContent());
                }
            } finally {
                fclose($fp);
            }

            return true;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'PieceAnalyzer/1.0 (Symfony)',
            ],
        ]);

        $src = @fopen($url, 'rb', false, $context);
        if ($src === false) {
            return false;
        }

        $dest = fopen($destinationPath, 'wb');
        if ($dest === false) {
            fclose($src);
            return false;
        }

        try {
            while (!feof($src)) {
                $buffer = fread($src, 65536);
                if ($buffer === false) {
                    break;
                }
                fwrite($dest, $buffer);
            }
        } finally {
            fclose($src);
            fclose($dest);
        }

        return true;
    }

    /**
     * Decompress a .gz file to a target CSV path.
     */
    private function decompressGzFile(string $gzPath, string $csvPath): bool
    {
        if (!file_exists($gzPath)) {
            return false;
        }

        $headerCheck = @fopen($gzPath, 'rb');
        if ($headerCheck === false) {
            return false;
        }
        $magic = fread($headerCheck, 2);
        fclose($headerCheck);

        if ($magic !== "\x1f\x8b") {
            $this->logger->warning('File is not a valid gzip archive (invalid magic bytes)', ['path' => $gzPath]);
            return false;
        }

        $gz = @gzopen($gzPath, 'rb');
        if ($gz === false) {
            return false;
        }

        $out = fopen($csvPath, 'wb');
        if ($out === false) {
            gzclose($gz);
            return false;
        }

        try {
            while (!gzeof($gz)) {
                $buffer = gzread($gz, 65536);
                if ($buffer === false) {
                    return false;
                }
                fwrite($out, $buffer);
            }
        } finally {
            gzclose($gz);
            fclose($out);
        }

        return true;
    }

    /**
     * @param array<int, string> $paths
     */
    private function cleanUpFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
