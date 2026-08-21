<?php

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

class LoadController extends AbstractController
{
    /**
     * Load a range of set no's
     *
     * @deprecated v3
     */
    #[Route(path: '/range', name: 'load_range')]
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
    #[Route(path: '/files', name: 'load_files')]
    public function refresh(KernelInterface $kernel): RedirectResponse
    {
        $application = new Application($kernel);

        try {
            $application->setAutoExit(false);

            $input = new ArrayInput(['command' => 'app:data:import-csv']);
            $output = new NullOutput();
            $application->run($input, $output);
            $this->addFlash('success', 'Successfully imported Sets.');
            return $this->redirectToRoute('load_prices');
        } catch (\Throwable $e) {
            $this->addFlash('alert', 'Failed to load Sets. Error message: ' . $e->getMessage());
        }

        return $this->redirectToRoute('list_all');
    }

    /**
     * Load prices of sets with BrickPickerPriceLoaderService
     */
    #[Route(path: '/price/brickpicker', name: 'load_prices')]
    public function loadPrices(BrickPickerPriceLoaderService $loader): RedirectResponse
    {
        $loader->loadPrices(false);
        return $this->redirectToRoute('list_all');
    }
}
