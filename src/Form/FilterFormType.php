<?php

declare(strict_types=1);

namespace App\Form;

use App\Repository\PieceRepository;
use App\Service\CsvLegoLoaderService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class FilterFormType extends AbstractType
{
    /** @var array<string, int>|null */
    private ?array $colorChoices = null;

    /** @var array<string, int>|null */
    private ?array $categoryChoices = null;

    public function __construct(protected \App\Repository\PieceRepository $pieceRepository, protected \App\Service\CsvLegoLoaderService $csvLoader)
    {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('part', TextType::class, [
                'label' => 'filter.part_label',
                'required' => false,
                'attr' => [
                    'placeholder' => '3001, 3020, Brick 2x4',
                ],
            ])
            ->add('minYear', IntegerType::class, [
                'label' => 'filter.year_label',
                'required' => false,
                'attr' => [
                    'placeholder' => '2020',
                    'min' => 1949,
                    'max' => (int) date('Y') + 1,
                ],
            ])
            ->add('color', ChoiceType::class, [
                'label' => 'filter.color_label',
                'choices' => $this->getCsvColorChoices(),
                'multiple' => false,
                'required' => false,
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'filter.category_label',
                'choices' => $this->getCsvCategoryChoices(),
                'multiple' => false,
                'required' => false,
            ])
            ->add('search', SubmitType::class, [
                'label' => 'filter.submit',
            ])
        ;
    }

    /**
     * @return array<string, int>
     */
    protected function getCsvColorChoices(): array
    {
        if ($this->colorChoices !== null) {
            return $this->colorChoices;
        }

        $choices = [];
        $colors = $this->csvLoader->getColors();
        foreach ($colors as $choice) {
            if (isset($choice['name'], $choice['id'])) {
                $choices[$choice['name']] = (int) $choice['id'];
            }
        }
        asort($choices);
        $this->colorChoices = ['filter.all_colors' => 0] + $choices;

        return $this->colorChoices;
    }

    /**
     * @return array<string, int>
     */
    protected function getCsvCategoryChoices(): array
    {
        if ($this->categoryChoices !== null) {
            return $this->categoryChoices;
        }

        $choices = [];
        $categories = $this->csvLoader->getCategories();
        foreach ($categories as $choice) {
            if (isset($choice['name'], $choice['id'])) {
                $choices[$choice['name']] = (int) $choice['id'];
            }
        }
        asort($choices);
        $this->categoryChoices = ['filter.all_categories' => 0] + $choices;

        return $this->categoryChoices;
    }
}
