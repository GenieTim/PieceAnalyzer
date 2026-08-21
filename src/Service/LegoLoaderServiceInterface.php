<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\Set;
use Doctrine\Common\Collections\Collection;

/**
 * LegoLoaderServiceInterface
 *
 * @author timbernhard
 */
interface LegoLoaderServiceInterface
{
    /**
     * @return array<int, mixed>|int
     */
    public function loadSets(int|string $from, int|string $to): array|int;

    public function loadSet(mixed $set_no, bool $flush = true): ?Set;

    /**
     * @return Collection<int, \App\Entity\Piece>
     */
    public function getPiecesOfSet(Set &$set, bool $flush = false): Collection;

    public function loadPrices(bool $all = false): static;

    public function getColors(): mixed;

    public function getCategories(): mixed;
}
