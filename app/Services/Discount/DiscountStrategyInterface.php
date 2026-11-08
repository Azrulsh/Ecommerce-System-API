<?php 

namespace App\Services\Discount;

//first question
interface DiscountStrategyInterface{
    public function calculate(float $originalPrice): float;
}