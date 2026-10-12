<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\PiecePropertyChoiceLoader;

class CategoryChoiceLoader extends PiecePropertyChoiceLoader
{
    #[\Override]
    protected function getChoices(?callable $value = null): array
    {
        return $this->pieceRepository->findDistinctCategories();
    }
}
