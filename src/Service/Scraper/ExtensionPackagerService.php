<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use ZipArchive;

class ExtensionPackagerService
{
    private readonly string $projectDir;
    private readonly string $extensionDir;
    private readonly string $outputDir;

    public function __construct(
        string $projectDir,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->projectDir = rtrim($projectDir, '/\\');
        $this->extensionDir = $this->projectDir . '/extension';
        $this->outputDir = $this->projectDir . '/public/downloads';
    }

    public function getExtensionDir(): string
    {
        return $this->extensionDir;
    }

    public function getOutputDir(): string
    {
        return $this->outputDir;
    }

    /**
     * Synchronize extension/extractor.js from assets/js/scraper/extractor.js
     */
    public function syncExtractor(): bool
    {
        $source = $this->projectDir . '/assets/js/scraper/extractor.js';
        $destination = $this->extensionDir . '/extractor.js';

        if (!file_exists($source)) {
            $this->logger->warning('Source extractor.js not found for extension sync', ['source' => $source]);
            return false;
        }

        if (!is_dir($this->extensionDir) && !mkdir($this->extensionDir, 0755, true) && !is_dir($this->extensionDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $this->extensionDir));
        }

        return copy($source, $destination);
    }

    /**
     * Build zip packages for Chrome and Firefox.
     *
     * @return array<string, string> Map of browser key to absolute zip file path
     */
    public function buildPackages(): array
    {
        $this->syncExtractor();

        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0755, true) && !is_dir($this->outputDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $this->outputDir));
        }

        $chromeZip = $this->outputDir . '/piece-analyzer-chrome.zip';
        $firefoxZip = $this->outputDir . '/piece-analyzer-firefox.zip';
        $genericZip = $this->outputDir . '/piece-analyzer-extension.zip';

        $this->createZipPackage($chromeZip, 'chrome');
        $this->createZipPackage($firefoxZip, 'firefox');

        copy($chromeZip, $genericZip);

        $this->logger->info('Extension packages built successfully', [
            'chrome' => $chromeZip,
            'firefox' => $firefoxZip,
        ]);

        return [
            'chrome' => $chromeZip,
            'firefox' => $firefoxZip,
            'generic' => $genericZip,
        ];
    }

    /**
     * Get the absolute path to a packaged extension zip file, building if missing.
     */
    public function getPackagePath(string $browser): ?string
    {
        $normalized = strtolower(trim($browser));
        if ($normalized === 'firefox' || $normalized === 'gecko' || $normalized === 'ff') {
            $target = $this->outputDir . '/piece-analyzer-firefox.zip';
        } else {
            $target = $this->outputDir . '/piece-analyzer-chrome.zip';
        }

        if (!file_exists($target)) {
            $this->buildPackages();
        }

        return file_exists($target) ? $target : null;
    }

    /**
     * Pack extension files into a zip archive with browser-specific manifest adjustments.
     */
    private function createZipPackage(string $zipPath, string $browser): void
    {
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create zip archive: ' . $zipPath);
        }

        $baseManifestPath = $this->extensionDir . '/manifest.json';
        $manifestContent = file_exists($baseManifestPath) ? (string) file_get_contents($baseManifestPath) : '{}';
        $manifest = json_decode($manifestContent, true);
        if (!is_array($manifest)) {
            $manifest = [];
        }

        if ($browser === 'firefox') {
            // Firefox MV3 background script declaration & Gecko add-on ID
            $manifest['background'] = [
                'scripts' => ['background.js'],
            ];
            $manifest['browser_specific_settings'] = [
                'gecko' => [
                    'id' => 'piece-analyzer@be-we.ch',
                    'strict_min_version' => '109.0',
                ],
            ];
        } else {
            // Standard Chromium MV3 service worker declaration
            $manifest['background'] = [
                'service_worker' => 'background.js',
            ];
            unset($manifest['browser_specific_settings']);
        }

        // Add custom manifest.json
        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Add extension files
        $files = [
            'background.js',
            'extractor.js',
            'popup.html',
            'popup.js',
            'README.md',
        ];

        foreach ($files as $file) {
            $filePath = $this->extensionDir . '/' . $file;
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $file);
            }
        }

        // Add icons
        $iconDir = $this->extensionDir . '/icons';
        if (is_dir($iconDir)) {
            $iconFiles = scandir($iconDir);
            if ($iconFiles !== false) {
                foreach ($iconFiles as $iconFile) {
                    if ($iconFile !== '.' && $iconFile !== '..') {
                        $iconPath = $iconDir . '/' . $iconFile;
                        if (is_file($iconPath)) {
                            $zip->addFile($iconPath, 'icons/' . $iconFile);
                        }
                    }
                }
            }
        }

        $zip->close();
    }
}
