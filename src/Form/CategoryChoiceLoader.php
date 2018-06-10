<?php

namespace App\Form;

use App\Form\PiecePropertyChoiceLoader;

class CategoryChoiceLoader extends PiecePropertyChoiceLoader
{
    protected function getChoices($value = null)
    {
        return $this->pieceRepository->findDistinctCategories();
    }
}
