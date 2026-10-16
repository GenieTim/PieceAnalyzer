<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Scraper\ExtensionPackagerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

class ExtensionPackagerServiceTest extends TestCase
{
    private string $tempProjectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . '/ext_packager_test_' . uniqid('', true);
        mkdir($this->tempProjectDir . '/assets/js/scraper', 0777, true);
        mkdir($this->tempProjectDir . '/extension/icons', 0777, true);
        mkdir($this->tempProjectDir . '/public/downloads', 0777, true);

        file_put_contents($this->tempProjectDir . '/assets/js/scraper/extractor.js', 'console.log("extractor");');
        file_put_contents($this->tempProjectDir . '/extension/background.js', 'console.log("bg");');
        file_put_contents($this->tempProjectDir . '/extension/popup.html', '<!DOCTYPE html><html></html>');
        file_put_contents($this->tempProjectDir . '/extension/popup.js', 'console.log("popup");');
        file_put_contents($this->tempProjectDir . '/extension/README.md', '# Extension');
        file_put_contents($this->tempProjectDir . '/extension/manifest.json', json_encode([
            'manifest_version' => 3,
            'name' => 'Test Extension',
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT));
        file_put_contents($this->tempProjectDir . '/extension/icons/icon-16.png', 'fake_png');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempProjectDir);
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

    public function testBuildPackagesCreatesChromeAndFirefoxZips(): void
    {
        $packager = new ExtensionPackagerService($this->tempProjectDir, new NullLogger());
        $packages = $packager->buildPackages();

        $this->assertArrayHasKey('chrome', $packages);
        $this->assertArrayHasKey('firefox', $packages);
        $this->assertArrayHasKey('generic', $packages);

        $this->assertFileExists($packages['chrome']);
        $this->assertFileExists($packages['firefox']);
        $this->assertFileExists($packages['generic']);

        // Check Chrome manifest
        $chromeZip = new ZipArchive();
        $this->assertTrue($chromeZip->open($packages['chrome']));
        $chromeManifestJson = $chromeZip->getFromName('manifest.json');
        $this->assertIsString($chromeManifestJson);
        $chromeManifest = json_decode($chromeManifestJson, true);
        $this->assertIsArray($chromeManifest);
        $this->assertArrayHasKey('service_worker', $chromeManifest['background']);
        $this->assertArrayNotHasKey('browser_specific_settings', $chromeManifest);
        $chromeZip->close();

        // Check Firefox manifest
        $firefoxZip = new ZipArchive();
        $this->assertTrue($firefoxZip->open($packages['firefox']));
        $firefoxManifestJson = $firefoxZip->getFromName('manifest.json');
        $this->assertIsString($firefoxManifestJson);
        $firefoxManifest = json_decode($firefoxManifestJson, true);
        $this->assertIsArray($firefoxManifest);
        $this->assertArrayHasKey('scripts', $firefoxManifest['background']);
        $this->assertArrayHasKey('browser_specific_settings', $firefoxManifest);
        $this->assertSame('piece-analyzer@be-we.ch', $firefoxManifest['browser_specific_settings']['gecko']['id']);
        $firefoxZip->close();
    }

    public function testGetPackagePathBuildsOnDemand(): void
    {
        $packager = new ExtensionPackagerService($this->tempProjectDir, new NullLogger());
        $path = $packager->getPackagePath('firefox');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringEndsWith('piece-analyzer-firefox.zip', $path);
    }
}
