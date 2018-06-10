<?php

namespace App\Form;

use App\Form\PiecePropertyChoiceLoader;

class ColorChoiceLoader extends PiecePropertyChoiceLoader
{
    protected function getChoices($value = null)
    {
        return $this->pieceRepository->findDistinctColors();
    }
}
