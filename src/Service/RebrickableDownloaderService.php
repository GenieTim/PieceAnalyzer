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

    public const CACHE_TTL_SECONDS = 86400; // 24 hours (Rebrickable policy: updated ~00:00 UTC, max 1 download per 24 hours)
    public const METADATA_FILE = '.rebrickable_last_download';

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

    public function recordDownloadSuccess(): void
    {
        $metaPath = $this->dataPath . '/' . self::METADATA_FILE;
        @file_put_contents($metaPath, (string) time());
    }

    public function getLastDownloadTimestamp(): ?int
    {
        $metaPath = $this->dataPath . '/' . self::METADATA_FILE;
        if (!file_exists($metaPath)) {
            return null;
        }
        $val = (int) trim((string) @file_get_contents($metaPath));
        return $val > 0 ? $val : null;
    }

    /**
     * Check if a download from Rebrickable is needed based on file existence and 24h cache policy.
     */
    public function isDownloadNeeded(bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        foreach (self::DUMP_FILES as $file) {
            $base = str_ends_with($file, '.gz') ? substr($file, 0, -3) : $file;
            $target = $this->dataPath . '/' . $base;
            if (!file_exists($target) || filesize($target) === 0) {
                return true;
            }
        }

        $lastTimestamp = $this->getLastDownloadTimestamp();
        // If metadata file doesn't exist, fallback to mtime of sets.csv
        if ($lastTimestamp === null) {
            $setsTarget = $this->dataPath . '/sets.csv';
            if (file_exists($setsTarget) && filesize($setsTarget) > 0) {
                $lastTimestamp = (int) filemtime($setsTarget);
            }
        }

        if ($lastTimestamp === null) {
            return true;
        }

        return (time() - $lastTimestamp) >= self::CACHE_TTL_SECONDS;
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

        // Check if any file needs downloading
        $needsDownload = $this->isDownloadNeeded($force);

        if (!$needsDownload) {
            $current = 0;
            foreach (self::DUMP_FILES as $file) {
                ++$current;
                $base = str_ends_with($file, '.gz') ? substr($file, 0, -3) : $file;
                $target = $this->dataPath . '/' . $base;
                $success = file_exists($target) && filesize($target) > 0;
                $results[$file] = $success;
                if ($onProgress !== null) {
                    $onProgress($file, 'start', $current, $total);
                    $onProgress($file, 'skipped', $current, $total);
                }
            }
            return $results;
        }

        // Try fast atomic all_downloads.zip first when a download is needed
        if ($this->downloadZip($force)) {
            $current = 0;
            foreach (self::DUMP_FILES as $file) {
                ++$current;
                $base = str_ends_with($file, '.gz') ? substr($file, 0, -3) : $file;
                $target = $this->dataPath . '/' . $base;
                $success = file_exists($target) && filesize($target) > 0;
                $results[$file] = $success;
                if ($onProgress !== null) {
                    $onProgress($file, 'start', $current, $total);
                    $onProgress($file, $success ? 'done' : 'failed', $current, $total);
                }
            }
            return $results;
        }

        // Fallback: download individual .gz files
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
     * Download and extract all_downloads.zip containing all Rebrickable CSVs.
     */
    public function downloadZip(bool $force = false): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }

        if (!is_dir($this->dataPath) && (!mkdir($this->dataPath, 0755, true) && !is_dir($this->dataPath))) {
            return false;
        }

        $targetZipPath = $this->dataPath . '/all_downloads.zip.tmp';
        $url = self::BASE_URL . 'all_downloads.zip';

        $this->logger->info('Attempting bulk download from all_downloads.zip', ['url' => $url]);

        try {
            $downloaded = $this->saveUrlToPath($url, $targetZipPath);
            if (!$downloaded || !file_exists($targetZipPath)) {
                $this->cleanUpFiles([$targetZipPath]);
                return false;
            }

            $zip = new \ZipArchive();
            if ($zip->open($targetZipPath) !== true) {
                $this->cleanUpFiles([$targetZipPath]);
                return false;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $filename = basename($stat['name']);
                if (str_ends_with($filename, '.csv')) {
                    $zip->extractTo($this->dataPath, $stat['name']);
                }
            }
            $zip->close();
            $this->cleanUpFiles([$targetZipPath]);

            $this->recordDownloadSuccess();
            $this->logger->info('Successfully extracted all CSV dumps from all_downloads.zip');
            return true;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to download or extract all_downloads.zip, will fallback to individual files', [
                'error' => $e->getMessage(),
            ]);
            $this->cleanUpFiles([$targetZipPath]);
            return false;
        }
    }

    /**
     * Download and decompress a single Rebrickable dump file.
     *
     * @param string $filename Name of the file, e.g. 'sets.csv.gz' or 'sets.csv'
     * @param bool $force Force download even if fresh file exists
     */
    public function downloadFile(string $filename, bool $force = false): bool
    {
        $baseName = basename($filename);
        $gzFilename = str_ends_with($baseName, '.gz') ? $baseName : $baseName . '.gz';
        $csvFilename = str_ends_with($baseName, '.gz') ? substr($baseName, 0, -3) : $baseName;

        if (!in_array($gzFilename, self::DUMP_FILES, true)) {
            $this->logger->warning('Rejected download for unrecognized dump file', ['file' => $filename]);
            return false;
        }

        if (!is_dir($this->dataPath) && (!mkdir($this->dataPath, 0755, true) && !is_dir($this->dataPath))) {
            $this->logger->error('Failed to create data directory', ['path' => $this->dataPath]);
            return false;
        }

        $targetCsvPath = $this->dataPath . '/' . $csvFilename;

        // Skip if not forced and file exists and is less than CACHE_TTL_SECONDS old with non-zero size
        if (!$force && file_exists($targetCsvPath) && filesize($targetCsvPath) > 0) {
            $lastDownload = $this->getLastDownloadTimestamp() ?? (int) filemtime($targetCsvPath);
            if ((time() - $lastDownload) < self::CACHE_TTL_SECONDS) {
                $this->logger->info('File is already up to date, skipping download', [
                    'file' => $csvFilename,
                    'path' => $targetCsvPath,
                    'age_seconds' => time() - $lastDownload,
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

            $this->recordDownloadSuccess();
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
        $options = [
            'timeout' => 300,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'Accept' => '*/*',
            ],
        ];

        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $options['cafile'] = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }

        if ($this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            $response = $this->httpClient->request('GET', $url, $options);

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

        $sslOptions = [];
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $sslOptions['cafile'] = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 300,
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'header' => "Accept: */*\r\n",
            ],
            'ssl' => $sslOptions,
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
