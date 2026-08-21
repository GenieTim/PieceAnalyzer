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
            ->onlyMethods(['getResult'])
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
        $query->method('getResult')->willReturn([$set1, $set2]);

        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new AppDataRemoveDuplicatesCommand($em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Purged 1 duplicates from the database', $tester->getDisplay());
    }

    public function testAppPricesReloadCommand(): void
    {
        $loader = $this->createMock(BrickPickerPriceLoaderService::class);
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

        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturnOnConsecutiveCalls($countQuery, $query);

        $countQuery->method('getSingleScalarResult')->willReturn(0);
        $query->method('toIterable')->willReturn(new \ArrayIterator([]));

        $em->method('createQueryBuilder')->willReturn($qb);

        $command = new AppPricesReloadCommand($loader, $em, $logger);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Prices have been reloaded', $tester->getDisplay());
    }
}
