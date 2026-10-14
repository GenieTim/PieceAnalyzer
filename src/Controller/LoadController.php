<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\SelectLoadFormType;
use App\Service\BricklinkLegoLoaderService;
use App\Service\BrickPickerPriceLoaderService;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class LoadController extends AbstractController
{
    /**
     * Load a range of set no's
     *
     * @deprecated v3
     */
    #[Route(path: '/range', name: 'load_range')]
    #[IsGranted('ROLE_ADMIN')]
    public function loadRange(Request $request, BricklinkLegoLoaderService $loader): Response
    {
        $form = $this->createForm(SelectLoadFormType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{from: int, to: int} $data */
            $data = $form->getData();
            $loader->loadSets($data['from'], $data['to']);
            return $this->redirectToRoute('index');
        }

        return $this->render('form/load_form.html.twig', ['form' => $form->createView()]);
    }

    /**
     * Load sets from csv files
     */
    #[Route(path: '/files', name: 'load_files', methods: ['POST'])]
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

