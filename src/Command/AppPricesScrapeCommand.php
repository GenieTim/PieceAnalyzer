<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Set;
use App\Enum\Retailer;
use App\Message\FetchSetPriceMessage;
use App\Repository\SetRepository;
use App\Service\Scraper\HeadlessScraperService;
use App\Service\TaskStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:prices:scrape',
    description: 'Scrape live prices using Camoufox / Playwright stealth browser engine.'
)]
class AppPricesScrapeCommand extends Command
{
    public function __construct(
        private readonly HeadlessScraperService $scraperService,
        private readonly SetRepository $setRepo,
        private readonly LoggerInterface $logger,
        private readonly ?MessageBusInterface $bus = null
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Direct URL to scrape')
            ->addOption('set', null, InputOption::VALUE_REQUIRED, 'Set number (e.g. 42232 or 40809,10295)')
            ->addOption('retailer', 'r', InputOption::VALUE_REQUIRED, 'Target retailer (galaxus, brack, amazon_de, toppreise, lego_store, all)', 'all')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Browser engine driver (auto, camoufox, playwright, service)', 'auto')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of missing sets to scrape', '10')
            ->addOption('no-scroll', null, InputOption::VALUE_NONE, 'Disable stepped scrolling')
            ->addOption('max-steps', null, InputOption::VALUE_REQUIRED, 'Max scroll steps per page', '25')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between sets in seconds', '2')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch scrape tasks to asynchronous messenger queue worker')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directUrl = $input->getOption('url');
        $setArg = $input->getOption('set');
        $retailerArg = (string) $input->getOption('retailer');
        $driver = (string) $input->getOption('driver');
        $noScroll = (bool) $input->getOption('no-scroll');
        $maxSteps = (int) $input->getOption('max-steps');
        $delaySec = max(0, (int) $input->getOption('delay'));
        $isAsync = (bool) $input->getOption('async');
        $limit = max(1, (int) $input->getOption('limit'));

        $scraperOptions = [
            'driver' => $driver,
            'scroll' => !$noScroll,
            'max_steps' => $maxSteps,
        ];

        // 1. Direct URL mode
        if (is_string($directUrl) && $directUrl !== '') {
            $io->title('Direct URL Price Scrape');
            $io->writeln(sprintf('Target URL: %s | Driver: %s', $directUrl, $driver));

            $items = $this->scraperService->scrapeUrl($directUrl, $scraperOptions);
            $io->writeln(sprintf('Extracted %d items from page.', count($items)));

            $res = $this->scraperService->ingestExtractedItems($items);
            $io->success(sprintf('Ingestion complete: %d imported, %d skipped out of %d total.', $res['imported'], $res['skipped'], $res['total']));
            return Command::SUCCESS;
        }

        // 2. Resolve retailers
        /** @var list<Retailer> $retailers */
        $retailers = [];
        if ($retailerArg === 'all') {
            $retailers = [
                Retailer::GALAXUS,
                Retailer::BRACK,
                Retailer::AMAZON_DE,
                Retailer::LEGO_STORE,
                Retailer::OTHER, // Toppreise
            ];
        } else {
            foreach (explode(',', $retailerArg) as $r) {
                $trimmed = trim($r);
                if ($trimmed === 'toppreise') {
                    $retailers[] = Retailer::OTHER;
                } elseif (($case = Retailer::tryFrom($trimmed)) instanceof \App\Enum\Retailer) {
                    $retailers[] = $case;
                }
            }
        }

        // 3. Resolve sets
        /** @var list<Set> $sets */
        $sets = [];
        if (is_string($setArg) && $setArg !== '') {
            foreach (explode(',', $setArg) as $sNo) {
                $trimmed = trim($sNo);
                $found = $this->setRepo->findOneBy(['no' => $trimmed])
                    ?? $this->setRepo->findOneBy(['no' => $trimmed . '-1']);
                if ($found instanceof Set) {
                    $sets[] = $found;
                } else {
                    $io->warning(sprintf('Set "%s" not found in database.', $trimmed));
                }
            }
        } else {
            // Find sets with missing prices
            $sets = $this->setRepo->createQueryBuilder('s')
                ->where('s.price IS NULL OR s.price = 0')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        $io->title('Automated Stealth Browser Price Scraper (Camoufox / Playwright)');
        $io->writeln(sprintf(
            'Target sets: %d | Retailers: %s | Driver: %s | Mode: %s',
            count($sets),
            implode(', ', array_map(static fn(Retailer $r): string => $r->label(), $retailers)),
            $driver,
            $isAsync ? 'Asynchronous Queue Dispatch' : 'Synchronous Batch'
        ));

        if (empty($sets)) {
            $io->success('No sets requiring price scrape.');
            return Command::SUCCESS;
        }

        if ($isAsync && $this->bus instanceof MessageBusInterface) {
            $dispatched = 0;
            foreach ($sets as $set) {
                if ($set->getId() !== null) {
                    foreach ($retailers as $ret) {
                        $this->bus->dispatch(new FetchSetPriceMessage(
                            setId: $set->getId(),
                            retailer: $ret
                        ));
                        $dispatched++;
                    }
                }
            }
            $io->success(sprintf('Dispatched %d scrape tasks to asynchronous messenger queue worker.', $dispatched));
            return Command::SUCCESS;
        }

        $totalExtracted = 0;
        $totalImported = 0;

        foreach ($sets as $i => $set) {
            $io->section(sprintf('[%d/%d] Scraping Set #%s (%s)', $i + 1, count($sets), $set->getNo(), $set->getName()));

            try {
                $items = $this->scraperService->scrapeSet($set, $retailers, $scraperOptions);
                $count = count($items);
                $totalExtracted += $count;
                $io->writeln(sprintf('  Found %d price records across retailers.', $count));

                if ($count > 0) {
                    $res = $this->scraperService->ingestExtractedItems($items);
                    $totalImported += $res['imported'];
                    $io->writeln(sprintf('  Persisted %d new verified prices.', $res['imported']));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error scraping set', ['set' => $set->getNo(), 'error' => $e->getMessage()]);
                $io->error(sprintf('Error scraping set #%s: %s', $set->getNo(), $e->getMessage()));
            }

            if ($delaySec > 0 && $i < count($sets) - 1) {
                sleep($delaySec);
            }
        }

        $io->success(sprintf(
            'Scraping completed! Extracted %d total prices, successfully saved %d prices to database.',
            $totalExtracted,
            $totalImported
        ));

        return Command::SUCCESS;
    }
}
