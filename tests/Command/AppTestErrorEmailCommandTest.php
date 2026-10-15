<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AppTestErrorEmailCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

class AppTestErrorEmailCommandTest extends TestCase
{
    public function testExecuteSuccess(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with($this->stringContains('Test critical failure triggered'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(RawMessage::class));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');

        $command = new AppTestErrorEmailCommand(
            $logger,
            $mailer,
            $kernel,
            'admin@piece-analyzer.be-we.ch',
            'errors@piece-analyzer.be-we.ch',
            'null://null'
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Piece Analyzer - Error Email Notification Diagnostic', $display);
        $this->assertStringContainsString('admin@piece-analyzer.be-we.ch', $display);
        $this->assertStringContainsString('errors@piece-analyzer.be-we.ch', $display);
        $this->assertStringContainsString('Direct test email dispatched', $display);
        $this->assertStringContainsString('Emitted critical error log record', $display);
    }

    public function testExecuteDirectOnly(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('critical');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('dev');

        $command = new AppTestErrorEmailCommand(
            $logger,
            $mailer,
            $kernel,
            'admin@piece-analyzer.be-we.ch',
            'errors@piece-analyzer.be-we.ch',
            'smtp://localhost:25'
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--direct-only' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Direct test email dispatched', $display);
        $this->assertStringNotContainsString('2. Testing Monolog Critical Error Handler', $display);
    }

    public function testExecuteMailerFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('Connection refused'));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');

        $command = new AppTestErrorEmailCommand(
            $logger,
            $mailer,
            $kernel,
            'admin@piece-analyzer.be-we.ch',
            'errors@piece-analyzer.be-we.ch',
            'smtp://localhost:25'
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }
}
