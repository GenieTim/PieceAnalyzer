<?php

declare(strict_types=1);

namespace App\Form;

use App\Repository\PieceRepository;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Abstract ChoiceLoader for Piece properties
 */
abstract class PiecePropertyChoiceLoader implements ChoiceLoaderInterface
{
    private ?ChoiceListInterface $choiceList = null;

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
    #[\Override]
    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        if (!$this->choiceList instanceof \Symfony\Component\Form\ChoiceList\ChoiceListInterface) {
            $this->choiceList = new ArrayChoiceList(
                $this->getChoices($value)
            );
        }

        return $this->choiceList;
    }

    /**
     * {@inheritdoc}
     * @return array<string|int, mixed>
     */
    #[\Override]
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
    #[\Override]
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        if ($choices === []) {
            return [];
        }

        return $this->loadChoiceList($value)->getValuesForChoices($choices);
    }
}
