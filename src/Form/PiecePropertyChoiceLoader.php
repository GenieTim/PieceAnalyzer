<?php

namespace App\Form;

use App\Repository\ItemRepository;
use App\Repository\PieceRepository;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Abstract ChoiceLoader for Piece properties
 */
abstract class PiecePropertyChoiceLoader implements ChoiceLoaderInterface
{

    protected $pieceRepository;

    public function __construct(PieceRepository $pieceRepo)
    {
        $this->pieceRepository = $pieceRepo;
    }

    abstract protected function getChoices($value = null);

    /**
     * {@inheritdoc}
     */
    public function loadChoiceList($value = null)
    {
        return new ArrayChoiceList(
            $this->getChoices($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function loadChoicesForValues(array $values, $value = null)
    {
        // Optimize
        if (empty($values)) {
            return array();
        }

        return $this->loadChoiceList($value)->getChoicesForValues($values);
    }

    /**
     * {@inheritdoc}
     */
    public function loadValuesForChoices(array $choices, $value = null)
    {
        // Optimize
        if (empty($values)) {
            return array();
        }

        return $this->loadChoiceList($value)->getValuesForChoices($choices);
    }
}
