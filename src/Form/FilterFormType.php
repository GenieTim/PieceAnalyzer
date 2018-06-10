<?php

namespace App\Form;

use App\Repository\PieceRepository;
use App\Service\CsvLegoLoaderService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

class FilterFormType extends AbstractType
{

    protected $pieceRepository;

    protected $csvLoader;

    public function __construct(PieceRepository $pieceRepo, CsvLegoLoaderService $csvLoader)
    {
        $this->pieceRepository = $pieceRepo;
        $this->csvLoader = $csvLoader;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('color', ChoiceType::class, array(
                'choices' => $this->getCsvColorChoices(),
                'multiple' => false,
            ))
            ->add('category', ChoiceType::class, array(
                'choices' => $this->getCsvCategoryChoices(),
                'multiple' => false,
            ))
            ->add('search', SubmitType::class, array(
            ))
        ;
    }

    protected function getCsvColorChoices()
    {
        $choices = array();
        $colors = $this->csvLoader->getColors();
        foreach ($colors as $choice) {
            $choices[$choice['name']] = $choice['id'];
        }
        asort($choices);
        $choices = ['any' => 0] + $choices;
        return $choices;
    }

    protected function getCsvCategoryChoices()
    {
        $choices = array();
        $categories = $this->csvLoader->getCategories();
        foreach ($categories as $choice) {
            $choices[$choice['name']] = $choice['id'];
        }
        asort($choices);
        $choices = ['any' => 0] + $choices;
        return $choices;
    }
}
