<?php

namespace App\Support;

class Costing
{
    /**
     * Moving weighted-average unit cost, rounded to 2 dp.
     * When there is no prior cost basis (no stock or cost = 0) the incoming
     * unit cost simply becomes the new average.
     */
    public static function movingAverage(float $beforeQty, float $beforeCost, float $inQty, float $inUnitCost): float
    {
        if ($beforeQty <= 0 || $beforeCost <= 0) {
            return round($inUnitCost, 2);
        }

        $newQty = $beforeQty + $inQty;
        if ($newQty <= 0) {
            return round($inUnitCost, 2);
        }

        return round((($beforeQty * $beforeCost) + ($inQty * $inUnitCost)) / $newQty, 2);
    }
}
