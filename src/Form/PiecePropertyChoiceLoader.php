<?php

namespace App\Form;

use App\Repository\PieceRepository;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Abstract ChoiceLoader for Piece properties
 */
abstract class PiecePropertyChoiceLoader implements ChoiceLoaderInterface
{
    public function __construct(protected PieceRepository $pieceRepository)
    {
    }

    /**
     * @return array<string|int, mixed>
     */
    abstract protected function getChoices(?callable $value = null): array;

    /**
     * {@inheritdoc}
     */
    public function loadChoiceList(?callable $value = null): \Symfony\Component\Form\ChoiceList\ChoiceListInterface
    {
        return new ArrayChoiceList(
            $this->getChoices($value)
        );
    }

    /**
     * {@inheritdoc}
     * @return array<string|int, mixed>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        if ($values === []) {
            return [];
        }

        return $this->loadChoiceList($value)->getChoicesForValues($values);
    }

    /**
     * {@inheritdoc}
     * @param array<string|int, mixed> $choices
     * @return array<string|int, string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        if ($choices === []) {
            return [];
        }

        return $this->loadChoiceList($value)->getValuesForChoices($choices);
    }
}
