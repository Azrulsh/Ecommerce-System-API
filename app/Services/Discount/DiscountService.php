<?php

namespace App\Services\Discount;

//third question
class DiscountService {

private DiscountStrategyinterface $strategy;

public function __construct(DiscountStrategyinterface $strategy){
    $this->strategy=$strategy;
}

public function applyDiscount(float $price):float{
    return $this->strategy->calculate($price);
}
}