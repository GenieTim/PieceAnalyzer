<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AppPricesScrapeCommand;
use App\Entity\Set;
use App\Repository\SetRepository;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AppPricesScrapeCommandTest extends TestCase
{
    public function testExecuteWithDirectUrl(): void
    {
        $scraper = $this->createMock(HeadlessScraperService::class);
        $this->createMock(EntityManagerInterface::class);
        $setRepo = $this->createMock(SetRepository::class);

        $scraper->expects($this->once())
            ->method('scrapeUrl')
            ->with('https://www.toppreise.ch/s?q=lego')
            ->willReturn([
                [
                    'setNo' => '40809',
                    'amountCents' => 2890,
                    'price' => 28.90,
                    'currency' => 'CHF',
                    'retailer' => 'galaxus',
                    'provider' => 'bookmarklet',
                    'url' => 'https://www.galaxus.ch/de/product/40809',
                ]
            ]);

        $scraper->expects($this->once())
            ->method('ingestExtractedItems')
            ->willReturn(['total' => 1, 'imported' => 1, 'skipped' => 0, 'errors' => []]);

        $command = new AppPricesScrapeCommand(
            scraperService: $scraper,
            setRepo: $setRepo,
            logger: new NullLogger()
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--url' => 'https://www.toppreise.ch/s?q=lego',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Extracted 1 items from page', $tester->getDisplay());
        $this->assertStringContainsString('1 imported', $tester->getDisplay());
    }

    public function testExecuteAsyncDispatchesMessages(): void
    {
        $scraper = $this->createMock(HeadlessScraperService::class);
        $setRepo = $this->createMock(SetRepository::class);
        $bus = $this->createMock(MessageBusInterface::class);

        $set = new Set();
        $set->setId(42);
        $set->setNo('42232');
        $set->setName('Koenigsegg');

        $setRepo->expects($this->once())
            ->method('findOneBy')
            ->willReturn($set);

        $bus->expects($this->atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(fn($msg): \Symfony\Component\Messenger\Envelope => new Envelope($msg));

        $command = new AppPricesScrapeCommand(
            scraperService: $scraper,
            setRepo: $setRepo,
            logger: new NullLogger(),
            bus: $bus
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            '--set' => '42232',
            '--retailer' => 'galaxus,brack',
            '--async' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dispatched 2 scrape tasks to asynchronous messenger queue worker', $tester->getDisplay());
    }
}
