<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use App\Form\SelectLoadFormType;
use App\Service\CsvLegoLoaderService;
use App\Service\BricklinkLegoLoaderService;
use App\Service\BrickPickerPriceLoaderService;

class LoadController extends Controller
{

    /**
     * Load a range of set no's
     *
     * @Route("/range", name="load_range")
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
                    'form' => $form->createView()
        ));
    }

    /**
     * Load sets from csv files, NUMBER at a time, than redirect to next if still available
     *
     * @Route("/files/{index}", name="load_files")
     * @param Request $request
     * @param CsvLegoLoaderService $loader
     * @return Response
     */
    public function refreshAction(Request $request, CsvLegoLoaderService $loader, $index)
    {
        $NUMBER = 500;
        $start = intval($index);
        // switch the following two lines if you want to load sets seperatly
        $end = $start + $NUMBER;
//        $end = 0;
        try {
            $sets = $loader->loadSets($start, $end);
            if (count($sets) < $NUMBER - 1 || !$end) {
                $this->addFlash('success', 'Refreshed and loaded ' . ($start + count($sets)) . 'sets successfully.');
            } else {
                return $this->redirectToRoute('load_files', array('index' => $end));
            }
            $loader->loadPrices();
        } catch (\Exception $e) {
            $this->addFlash('alert', 'Failed to load Sets. Error message: ' . $e->getMessage());
        }

        return $this->redirectToRoute('list_all');
    }

    /**
     * Load prices of sets with BrickPickerPriceLoaderService
     *
     * @Route("/price/brickpicker", name="load_prices_brickpicker")
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
