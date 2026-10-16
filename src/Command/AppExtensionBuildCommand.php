<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Scraper\ExtensionPackagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:extension:build',
    description: 'Sync scraper extractor and package browser extension zip archives for Chrome and Firefox'
)]
class AppExtensionBuildCommand extends Command
{
    public function __construct(
        private readonly ExtensionPackagerService $packager
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Building Browser Extension Packages');

        $packages = $this->packager->buildPackages();

        $rows = [];
        foreach ($packages as $name => $path) {
            $size = file_exists($path) ? round(filesize($path) / 1024, 1) . ' KB' : 'N/A';
            $rows[] = [$name, $path, $size];
        }

        $io->table(['Target', 'Path', 'Size'], $rows);
        $io->success('Browser extension packages built successfully.');

        return Command::SUCCESS;
    }
}
