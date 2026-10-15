<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BrickPickerPriceLoaderService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LoadController extends AbstractController
{
    /**
     * Load sets from csv files
     */
    #[Route(path: '/files', name: 'load_files', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refresh(Request $request, KernelInterface $kernel): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('load_files', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('list_all');
        }

        $application = new Application($kernel);

        try {
            $application->setAutoExit(false);

            $input = new ArrayInput(['command' => 'app:data:import-csv']);
            $output = new NullOutput();
            $application->run($input, $output);
            $this->addFlash('success', 'Successfully imported Sets.');
            return $this->redirectToRoute('list_all');
        } catch (\Throwable) {
            $this->addFlash('alert', 'Failed to load Sets.');
        }

        return $this->redirectToRoute('list_all');
    }

    /**
     * Load prices of sets with BrickPickerPriceLoaderService
     */
    #[Route(path: '/price/brickpicker', name: 'load_prices', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function loadPrices(Request $request, BrickPickerPriceLoaderService $loader): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('load_prices', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('list_all');
        }

        $loader->loadPrices(false);
        $this->addFlash('success', 'Successfully reloaded set prices.');
        return $this->redirectToRoute('list_all');
    }
}
