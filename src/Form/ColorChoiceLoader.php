<?php

namespace App\Form;

use App\Form\PiecePropertyChoiceLoader;

class ColorChoiceLoader extends PiecePropertyChoiceLoader
{
    protected function getChoices(?callable $value = null): array
    {
        return $this->pieceRepository->findDistinctColors();
    }
}
