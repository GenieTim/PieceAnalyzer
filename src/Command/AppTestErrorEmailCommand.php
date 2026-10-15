<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test:error-email',
    description: 'Test critical failure email notifications and verify Monolog / Mailer configuration'
)]
class AppTestErrorEmailCommand extends Command
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly MailerInterface $mailer,
        protected readonly KernelInterface $kernel,
        #[Autowire('%env(ADMIN_EMAIL)%')]
        protected readonly string $adminEmail,
        #[Autowire('%env(ERROR_EMAIL_FROM)%')]
        protected readonly string $fromEmail,
        #[Autowire('%env(MAILER_DSN)%')]
        protected readonly string $mailerDsn,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'direct-only',
                null,
                InputOption::VALUE_NONE,
                'Only send a direct test email via MailerInterface without emitting a Monolog log'
            )
            ->addOption(
                'log-only',
                null,
                InputOption::VALUE_NONE,
                'Only emit a Monolog critical error log without sending a direct test email'
            )
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Piece Analyzer - Error Email Notification Diagnostic');

        $env = $this->kernel->getEnvironment();
        $isProd = ($env === 'prod');
        $isNullTransport = str_starts_with(trim($this->mailerDsn), 'null://');

        $dsnDisplay = $isNullTransport
            ? $this->mailerDsn . ' (⚠️ Discards emails)'
            : (string) preg_replace('/:[^:@]+@/', ':***@', $this->mailerDsn);

        $io->section('Current Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Environment', $env],
                ['ADMIN_EMAIL (Recipient)', $this->adminEmail],
                ['ERROR_EMAIL_FROM (Sender)', $this->fromEmail],
                ['MAILER_DSN', $dsnDisplay],
            ]
        );

        if ($isNullTransport) {
            $io->warning(
                "MAILER_DSN is configured with 'null://null' (NullTransport).\n" .
                "Mailer and Monolog will process emails, but no actual emails will be dispatched to the network.\n" .
                "To deliver real emails, set MAILER_DSN to an SMTP or cloud provider in .env."
            );
        }

        $directOnly = (bool) $input->getOption('direct-only');
        $logOnly = (bool) $input->getOption('log-only');

        // Step 1: Direct Mailer Test
        if (!$logOnly) {
            $io->section('1. Testing Direct Email Delivery via Symfony Mailer');
            try {
                $email = (new Email())
                    ->from($this->fromEmail)
                    ->to($this->adminEmail)
                    ->subject('[Piece Analyzer Test] Error notification pipeline test')
                    ->html(
                        '<h1>Piece Analyzer - Test Error Alert</h1>' .
                        '<p>Diagnostic email to confirm email delivery configuration.</p>' .
                        '<ul>' .
                        '<li><strong>Environment:</strong> ' . htmlspecialchars($env, ENT_QUOTES) . '</li>' .
                        '<li><strong>Timestamp:</strong> ' . date('Y-m-d H:i:s T') . '</li>' .
                        '</ul>'
                    );

                $this->mailer->send($email);
                $io->success("Direct test email dispatched to <{$this->adminEmail}> (via {$this->mailerDsn}).");
            } catch (\Throwable $e) {
                $io->error("Failed to send direct test email: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Step 2: Monolog Critical Log Test
        if (!$directOnly) {
            $io->section('2. Testing Monolog Critical Error Handler');

            if (!$isProd) {
                $io->note(
                    "Current environment is '{$env}'. Monolog's email handler is configured in prod environment.\n" .
                    "To test Monolog's automated email dispatch, run this command with: --env=prod"
                );
            }

            $context = [
                'environment' => $env,
                'test_id' => bin2hex(random_bytes(6)),
                'timestamp' => date('c'),
            ];

            $this->logger->critical('Test critical failure triggered via app:test:error-email', $context);
            $io->success("Emitted critical error log record to Monolog logger with context.");

            if ($isProd) {
                $io->info(
                    "In 'prod' environment, Monolog flushes an HTML error email to <{$this->adminEmail}>."
                );
            }
        }

        $io->success('All error email notification diagnostics completed successfully.');

        return Command::SUCCESS;
    }
}
