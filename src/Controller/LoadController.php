<?php

namespace App\Controller;

use App\Form\SelectLoadFormType;
use App\Service\CsvLegoLoaderService;
use App\Service\BricklinkLegoLoaderService;
use Symfony\Component\HttpFoundation\Request;
use App\Service\BrickPickerPriceLoaderService;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LoadController extends AbstractController
{

    /**
     * Load a range of set no's
     *
     * @Route("/range", name="load_range")
     * @deprecated v3
     * @param Request $request
     * @param CsvLegoLoaderService $loader
     * @return Response
     */
    public function loadRangeAction(Request $request, BricklinkLegoLoaderService $loader)
    {
        $form = $this->createForm(SelectLoadFormType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $loader->loadSets($data['from'], $data['to']);
            return $this->redirectToRoute('index');
        }

        return $this->render('form/load_form.html.twig', array(
            'form' => $form->createView(),
        ));
    }

    /**
     * Load sets from csv files
     *
     * @Route("/files", name="load_files")
     * @param Request $request
     * @param CsvLegoLoaderService $loader
     * @return Response
     */
    public function refreshAction(Request $request, KernelInterface $kernel)
    {
        $application = new Application($kernel);

        try {
            $application->setAutoExit(false);

            $input = new ArrayInput(array(
                'command' => 'app:data:import-csv',
            ));

            // You can use NullOutput() if you don't need the output
            $output = new NullOutput();
            $application->run($input, $output);
            $this->addFlash('success', 'Successfully imported Sets.');
            return $this->redirectToRoute('load_prices');
        } catch (\Exception $e) {
            $this->addFlash('alert', 'Failed to load Sets. Error message: ' . $e->getMessage());
        }

        return $this->redirectToRoute('list_all');
    }

    /**
     * Load prices of sets with BrickPickerPriceLoaderService
     *
     * @Route("/price/brickpicker", name="load_prices")
     * @param Request $request
     * @param \App\Service\BrickPickerPriceLoaderService $loader
     * @return Response
     */
    public function loadPrices(Request $request, BrickPickerPriceLoaderService $loader)
    {
        $loader->loadPrices(false);
        return $this->redirectToRoute('list_all');
    }
}
