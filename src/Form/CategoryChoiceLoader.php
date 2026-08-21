<?php

namespace App\Form;

use App\Form\PiecePropertyChoiceLoader;

class CategoryChoiceLoader extends PiecePropertyChoiceLoader
{
    protected function getChoices(?callable $value = null): array
    {
        return $this->pieceRepository->findDistinctCategories();
    }
}
