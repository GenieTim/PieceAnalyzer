<?php

namespace App\Tests\Command;

use App\Command\AppDataImportCsvCommand;
use App\Command\AppDataRemoveDuplicatesCommand;
use App\Command\AppPricesReloadCommand;
use App\Entity\Set;
use App\Service\BrickPickerPriceLoaderService;
use App\Service\CsvLegoLoaderService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CommandTest extends TestCase
{
    public function testAppDataImportCsvCommand(): void
    {
        $loader = $this->createMock(CsvLegoLoaderService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);

        $em->method('getConnection')->willReturn($connection);
        $loader->method('normalizeCsvPath')->willReturn('/dummy/sets.csv');
        $loader->method('loadSets')->willReturn(1);

        $command = new AppDataImportCsvCommand($loader, $em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--count' => 2]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Starting to import 2 sets', $tester->getDisplay());
    }

    public function testAppDataRemoveDuplicatesCommand(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getArrayResult'])
            ->getMock();

        $set1 = new Set();
        $set1->setNo('1001-1');
        $set1->setName('Duplicate Set');

        $set2 = new Set();
        $set2->setNo('1001-1');
        $set2->setName('Duplicate Set');

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('having')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getArrayResult')->willReturn([
            ['name' => 'Duplicate Set', 'no' => '1001-1'],
        ]);

        $setRepo = $this->createMock(\App\Repository\SetRepository::class);
        $setRepo->method('findBy')->willReturn([$set1, $set2]);

        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('getRepository')->with(Set::class)->willReturn($setRepo);

        $command = new AppDataRemoveDuplicatesCommand($em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Purged 1 duplicates from the database', $tester->getDisplay());
    }

    public function testAppPricesReloadCommand(): void
    {
        $loader = $this->createMock(\App\Service\PriceLoaderServiceInterface::class);
        $fallback = $this->createMock(BrickPickerPriceLoaderService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();

        $qb = $this->createMock(QueryBuilder::class);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();

        $set = new Set();
        $set->setNo('75192-1');

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturnOnConsecutiveCalls($countQuery, $query);

        $countQuery->method('getSingleScalarResult')->willReturn(1);
        $query->method('toIterable')->willReturn(new \ArrayIterator([$set]));

        $loader->method('loadPriceForSet')->with('75192-1')->willReturn(849.99);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new AppPricesReloadCommand($loader, $em, $logger, $fallback);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Prices have been reloaded', $tester->getDisplay());
        $this->assertSame(849.99, $set->getPrice());
    }

    public function testAppPricesReloadCommandWithDelay(): void
    {
        $loader = $this->createMock(\App\Service\PriceLoaderServiceInterface::class);
        $fallback = $this->createMock(BrickPickerPriceLoaderService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();

        $qb = $this->createMock(QueryBuilder::class);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();

        $set = new Set();
        $set->setNo('10047-1');

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturnOnConsecutiveCalls($countQuery, $query);

        $countQuery->method('getSingleScalarResult')->willReturn(1);
        $query->method('toIterable')->willReturn(new \ArrayIterator([$set]));

        $loader->method('loadPriceForSet')->with('10047-1')->willReturn(29.99);

        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new AppPricesReloadCommand($loader, $em, $logger, $fallback);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--delay' => 5]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Prices have been reloaded', $tester->getDisplay());
        $this->assertSame(29.99, $set->getPrice());
    }

    public function testAppPricesReloadCommandAsync(): void
    {
        $loader = $this->createMock(\App\Service\PriceLoaderServiceInterface::class);
        $fallback = $this->createMock(BrickPickerPriceLoaderService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);

        $qb = $this->createMock(QueryBuilder::class);
        $countQuery = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();

        $set = new Set();
        $set->setId(42);
        $set->setNo('10311-1');

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturnOnConsecutiveCalls($countQuery, $query);

        $countQuery->method('getSingleScalarResult')->willReturn(1);
        $query->method('toIterable')->willReturn(new \ArrayIterator([$set]));

        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new \Symfony\Component\Messenger\Envelope(new \App\Message\FetchSetPriceMessage(42)));

        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new AppPricesReloadCommand($loader, $em, $logger, $fallback, null, $bus);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--async' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dispatched 1 sets to asynchronous queue worker', $tester->getDisplay());
    }

    public function testAppDataImportCsvCommandTimeout(): void
    {
        $loader = $this->createMock(CsvLegoLoaderService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(Connection::class);

        $em->method('getConnection')->willReturn($connection);
        $loader->method('normalizeCsvPath')->willReturn('/dummy/sets.csv');
        $loader->method('loadSets')->willReturnCallback(function (): int {
            // sleep briefly to exceed tiny timeout
            usleep(15000);
            return 1;
        });

        $command = new AppDataImportCsvCommand($loader, $em);
        $tester = new CommandTester($command);

        // Run within timeout limit
        $exitCode = $tester->execute(['--count' => 2, '--timeout' => 10]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Starting to import 2 sets', $tester->getDisplay());
    }


    public function testAppDataSyncCommandStatus(): void
    {
        $statusService = $this->createMock(\App\Service\TaskStatusService::class);
        $statusService->method('getStatus')->willReturn([
            'task' => 'app:data:sync',
            'status' => 'completed',
            'started_at' => '2026-10-13T02:00:00Z',
            'updated_at' => '2026-10-13T02:05:00Z',
            'elapsed_seconds' => 300,
        ]);
        $statusService->method('getStatusFilePath')->willReturn('/dummy/var/scheduled_task_status.json');

        $command = new \App\Command\AppDataSyncCommand($statusService);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--status' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Last Scheduled Task Status', $tester->getDisplay());
        $this->assertStringContainsString('app:data:sync', $tester->getDisplay());
    }
}
