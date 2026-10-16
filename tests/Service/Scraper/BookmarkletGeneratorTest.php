<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\BookmarkletGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class BookmarkletGeneratorTest extends KernelTestCase
{
    private BookmarkletGenerator $generator;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->generator = self::getContainer()->get(BookmarkletGenerator::class);
    }

    public function testStripCommentsPreservesStringsAndRegex(): void
    {
        $input = <<<JS
// Initial comment
const url = 'https://example.com//test'; // inline comment
/* Block comment */
const regex = /[0-9]+\\/test/g;
const strWithQuotes = "Don't touch /* this */ or // this";
const template = `multi-line \${url} // not a comment`;
JS;

        $stripped = $this->generator->stripComments($input);

        $this->assertStringNotContainsString('// Initial comment', $stripped);
        $this->assertStringNotContainsString('// inline comment', $stripped);
        $this->assertStringNotContainsString('/* Block comment */', $stripped);
        $this->assertStringContainsString("'https://example.com//test'", $stripped);
        $this->assertStringContainsString('/[0-9]+\\/test/g', $stripped);
        $this->assertStringContainsString('"Don\'t touch /* this */ or // this"', $stripped);
        $this->assertStringContainsString('`multi-line ${url} // not a comment`', $stripped);
    }

    public function testGenerateBookmarkletReturnsValidJavascriptUrl(): void
    {
        $apiUrl = 'https://example.com/api/price-report';
        $apiToken = 'test-token-12345';

        $href = $this->generator->generateBookmarklet($apiUrl, $apiToken);

        $this->assertStringStartsWith('javascript:', $href);

        $decodedJs = rawurldecode(substr($href, 11));

        $this->assertStringStartsWith('(function(){', $decodedJs);
        $this->assertStringEndsWith('})();', $decodedJs);
        $this->assertStringContainsString($apiUrl, $decodedJs);
        $this->assertStringContainsString($apiToken, $decodedJs);
    }

    public function testGeneratedBookmarkletHasValidSyntaxInNode(): void
    {
        $href = $this->generator->generateBookmarklet('https://example.com/api/price-report', 'test-token');
        $decodedJs = rawurldecode(substr($href, 11));

        // Write to temporary file and execute node -c (syntax check)
        $tempFile = tempnam(sys_get_temp_dir(), 'bm_test_');
        $this->assertNotFalse($tempFile);

        try {
            file_put_contents($tempFile, $decodedJs);

            $process = new Process(['node', '-c', $tempFile]);
            $process->run();

            if ($process->getExitCode() !== 127) {
                // Node is installed and available
                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    'Bookmarklet JavaScript must have valid syntax: ' . $process->getErrorOutput()
                );
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
