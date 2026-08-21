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

    public function __construct(protected \App\Repository\PieceRepository $pieceRepository, protected \App\Service\CsvLegoLoaderService $csvLoader)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('color', ChoiceType::class, ['choices' => $this->getCsvColorChoices(), 'multiple' => false])
            ->add('category', ChoiceType::class, ['choices' => $this->getCsvCategoryChoices(), 'multiple' => false])
            ->add('search', SubmitType::class, [])
        ;
    }

    /**
     * @return array<string, int>
     */
    protected function getCsvColorChoices(): array
    {
        $choices = [];
        $colors = $this->csvLoader->getColors();
        foreach ($colors as $choice) {
            if (isset($choice['name'], $choice['id'])) {
                $choices[$choice['name']] = (int) $choice['id'];
            }
        }
        asort($choices);
        return ['any' => 0] + $choices;
    }

    /**
     * @return array<string, int>
     */
    protected function getCsvCategoryChoices(): array
    {
        $choices = [];
        $categories = $this->csvLoader->getCategories();
        foreach ($categories as $choice) {
            if (isset($choice['name'], $choice['id'])) {
                $choices[$choice['name']] = (int) $choice['id'];
            }
        }
        asort($choices);
        return ['any' => 0] + $choices;
    }
}
