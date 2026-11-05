<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\SliderItemRepository;

class SliderOrder
{
    public function __construct(private readonly SliderItemRepository $sliderItemRepository)
    {
    }

    /**
     * Slides named in the list come first, in that order; all others keep
     * their current order behind them. Ids of other consumers are ignored.
     *
     * @param int[] $itemIdsInNewOrder
     */
    public function arrange(User $consumer, array $itemIdsInNewOrder): void
    {
        $remaining = [];
        foreach ($this->sliderItemRepository->findAllForConsumer($consumer) as $item) {
            $remaining[$item->getId()] = $item;
        }

        $arranged = [];
        foreach ($itemIdsInNewOrder as $itemId) {
            if (isset($remaining[$itemId])) {
                $arranged[] = $remaining[$itemId];
                unset($remaining[$itemId]);
            }
        }

        foreach ([...$arranged, ...array_values($remaining)] as $position => $item) {
            $item->setDisplayOrder($position + 1);
        }
    }
}
