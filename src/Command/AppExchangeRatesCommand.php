<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ExchangeRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:prices:rates', description: 'Display and refresh multi-currency exchange rates.')]
class AppExchangeRatesCommand extends Command
{
    public function __construct(private readonly ExchangeRateService $exchangeRateService)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Fetch latest rates from live API')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('refresh')) {
            $io->note('Fetching latest live exchange rates from European Central Bank / Frankfurter API...');
            $rates = $this->exchangeRateService->fetchLatestRates();
            $io->success('Rates updated successfully.');
        } else {
            $rates = $this->exchangeRateService->getRates();
        }

        $io->title('Multi-Currency Exchange Rates (Base: ' . ExchangeRateService::BASE_CURRENCY . ')');

        $rows = [];
        foreach ($rates as $curr => $rate) {
            $rows[] = [
                $curr,
                number_format($rate, 4),
                sprintf('1 EUR = %s %s', number_format($rate, 4), $curr),
                sprintf('1 %s = %s EUR', $curr, number_format(1 / $rate, 4)),
            ];
        }

        $io->table(['Currency', 'Rate to EUR', 'EUR Conversion', 'Inverse Conversion'], $rows);

        return Command::SUCCESS;
    }
}
