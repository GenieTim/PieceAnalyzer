<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use App\Message\FetchSetPriceMessage;
use App\Service\BrickPickerPriceLoaderService;
use App\Service\BricksetPriceLoaderService;
use App\Service\PriceLoaderServiceInterface;
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

#[AsCommand(name: 'app:prices:reload', description: 'Reload the prices of the Items.')]
class AppPricesReloadCommand extends Command
{
    public function __construct(
        protected readonly PriceLoaderServiceInterface $loader,
        protected readonly EntityManagerInterface $em,
        protected readonly LoggerInterface $logger,
        protected readonly ?BrickPickerPriceLoaderService $fallbackLoader = null,
        protected readonly ?TaskStatusService $statusService = null,
        protected readonly ?MessageBusInterface $bus = null
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Reload prices for all items.')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Maximum execution time in seconds (0 = unlimited)', '3600')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of sets to update prices for', '0')
            ->addOption('delay', 'd', InputOption::VALUE_REQUIRED, 'Delay between sets in milliseconds (0 = no delay)', '0')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch reload jobs to asynchronous messenger queue worker.')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $timeout = max(0, (int) $input->getOption('timeout'));
        $limit = max(0, (int) $input->getOption('limit'));
        $delayMs = max(0, (int) $input->getOption('delay'));
        $reloadAll = (bool) $input->getOption('all');

        $statusService = $this->statusService ?? new TaskStatusService(
            \dirname(__DIR__, 2)
        );

        $statusService->start('app:prices:reload', [
            'reload_all' => $reloadAll,
            'timeout' => $timeout,
            'limit' => $limit,
        ]);

        $qb = $this->em->createQueryBuilder()->select('s')->from(Set::class, 's');
        if (!$reloadAll) {
            $qb->andWhere('s.price IS NULL OR s.price = 0');
        }
        $countQuery = clone $qb;
        $totalAvailable = (int) $countQuery->select('count(s.id)')->getQuery()->getSingleScalarResult();
        $targetCount = $limit > 0 ? min($limit, $totalAvailable) : $totalAvailable;

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $io->title('Lego Set Prices Reload');
        $io->writeln(sprintf(
            'Target sets: %d (available: %d) | Timeout: %s | Mode: %s',
            $targetCount,
            $totalAvailable,
            $timeout > 0 ? TaskStatusService::formatDuration($timeout) : 'unlimited',
            $reloadAll ? 'All sets' : 'Missing/zero prices only'
        ));

        if ($targetCount === 0) {
            $io->success('No sets require price reload.');
            $statusService->finish(true, ['total' => 0, 'updated' => 0]);
            return Command::SUCCESS;
        }

        if ($input->getOption('async') && $this->bus instanceof \Symfony\Component\Messenger\MessageBusInterface) {
            $q = $qb->getQuery();
            $unsolved_sets = $q->toIterable();
            $dispatched = 0;
            foreach ($unsolved_sets as $row) {
                $set = is_array($row) ? $row[0] : $row;
                if ($set instanceof Set && $set->getId() !== null) {
                    $this->bus->dispatch(new FetchSetPriceMessage($set->getId()));
                    ++$dispatched;
                }
            }
            $io->success(sprintf('Dispatched %d sets to asynchronous queue worker.', $dispatched));
            $statusService->finish(true, ['total_dispatched' => $dispatched]);
            return Command::SUCCESS;
        }

        $startTime = microtime(true);
        $lastHeartbeat = $startTime;
        $heartbeatInterval = 15; // Log every 15 seconds

        $batchSize = 50;
        $processed = 0;
        $updated = 0;
        $failed = 0;

        $q = $qb->getQuery();
        $unsolved_sets = $q->toIterable();

        foreach ($unsolved_sets as $row) {
            $now = microtime(true);
            $elapsed = $now - $startTime;

            if ($timeout > 0 && $elapsed >= $timeout) {
                $rate = $processed > 0 ? round($processed / $elapsed, 2) : 0.0;
                $diagnostics = [
                    'elapsed_seconds' => round($elapsed, 1),
                    'processed' => $processed,
                    'total' => $targetCount,
                    'updated' => $updated,
                    'failed' => $failed,
                    'rate' => $rate,
                ];
                $statusService->fail('Price reload execution timeout limit reached', $diagnostics);
                $this->logger->critical('Price reload aborted due to timeout', $diagnostics);
                $io->error(sprintf(
                    'Execution time limit of %s reached. Processed %d/%d sets (%d updated, %d missing). Aborted cleanly.',
                    TaskStatusService::formatDuration($timeout),
                    $processed,
                    $targetCount,
                    $updated,
                    $failed
                ));
                return Command::FAILURE;
            }

            $set = is_array($row) ? $row[0] : $row;
            if ($set instanceof Set) {
                $setNo = (string) $set->getNo();
                $foundPrices = false;

                if ($this->loader instanceof \App\Service\BricksetPriceLoaderService) {
                    try {
                        $multiPrices = $this->loader->loadPricesForSet($setNo);
                        if ($multiPrices !== []) {
                            foreach ($multiPrices as $currCode => $val) {
                                $setPrice = new SetPrice(
                                    set: $set,
                                    amount: (int) round($val * 100),
                                    currency: $currCode,
                                    retailer: Retailer::LEGO_STORE,
                                    provider: PriceSourceProvider::BRICKSET
                                );
                                $setPrice->setUrl('https://brickset.com/sets/' . urlencode($setNo));
                                $set->addPrice($setPrice);
                                $this->em->persist($setPrice);
                            }
                            $this->em->persist($set);
                            $foundPrices = true;
                            ++$updated;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Error loading multi-currency prices from Brickset for set ' . $setNo, ['error' => $e->getMessage()]);
                    }
                } else {
                    try {
                        $price = $this->loader->loadPriceForSet($setNo);
                        if ($price !== null && $price > 0) {
                            $setPrice = new SetPrice(
                                set: $set,
                                amount: (int) round($price * 100),
                                currency: $set->getPriceCurrency() ?? 'USD',
                                retailer: Retailer::OTHER,
                                provider: PriceSourceProvider::BRICKSET
                            );
                            $set->addPrice($setPrice);
                            $this->em->persist($setPrice);
                            $this->em->persist($set);
                            $foundPrices = true;
                            ++$updated;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Error loading price with primary loader for set ' . $setNo, ['error' => $e->getMessage()]);
                    }
                }

                if (!$foundPrices && $this->fallbackLoader instanceof \App\Service\BrickPickerPriceLoaderService) {
                    try {
                        $price = $this->fallbackLoader->loadPriceForSet($setNo);
                        if ($price !== null && $price > 0) {
                            $setPrice = new SetPrice(
                                set: $set,
                                amount: (int) round($price * 100),
                                currency: 'USD',
                                retailer: Retailer::OTHER,
                                provider: PriceSourceProvider::BRICKPICKER
                            );
                            $setPrice->setUrl('https://www.brickpicker.com/bpms/set.cfm?set=' . urlencode($setNo));
                            $set->addPrice($setPrice);
                            $this->em->persist($setPrice);
                            $this->em->persist($set);
                            $foundPrices = true;
                            ++$updated;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Error loading price with fallback loader for set ' . $setNo, ['error' => $e->getMessage()]);
                    }
                }

                if (!$foundPrices) {
                    ++$failed;
                }
            }

            ++$processed;

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            if (($processed % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }

            if (($now - $lastHeartbeat) >= $heartbeatInterval || $processed >= $targetCount) {
                $lastHeartbeat = $now;
                $metrics = $statusService->updateStep('reload_prices', $processed, $targetCount);
                $io->writeln(sprintf(
                    '[%s] %d/%d sets (%.1f%%) | Updated: %d | Missing: %d | Rate: %.1f sets/s | Elapsed: %s | ETA: %s | Mem: %.1f MB',
                    date('H:i:s'),
                    $processed,
                    $targetCount,
                    $metrics['percentage'],
                    $updated,
                    $failed,
                    $metrics['rate_per_second'],
                    TaskStatusService::formatDuration($metrics['elapsed_seconds']),
                    $metrics['eta_seconds'] !== null ? TaskStatusService::formatDuration($metrics['eta_seconds']) : 'N/A',
                    $metrics['memory_mb']
                ));
            }
        }

        $this->em->flush();

        $totalDuration = microtime(true) - $startTime;
        $statusService->finish(true, [
            'total_processed' => $processed,
            'updated_prices' => $updated,
            'missing_prices' => $failed,
            'duration_seconds' => round($totalDuration, 2),
        ]);

        $io->success(sprintf(
            'Prices have been reloaded. Processed: %d, Updated: %d, Missing: %d in %s.',
            $processed,
            $updated,
            $failed,
            TaskStatusService::formatDuration($totalDuration)
        ));

        return Command::SUCCESS;
    }
}
