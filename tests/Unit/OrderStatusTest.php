<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_workflow_defines_only_valid_transitions_and_terminal_states(): void
    {
        $this->assertTrue(OrderStatus::Placed->canTransitionTo(OrderStatus::Processing));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Shipped));
        $this->assertTrue(OrderStatus::Shipped->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue(OrderStatus::Placed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::Processing->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Shipped->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Placed->canTransitionTo(OrderStatus::Completed));
        foreach (OrderStatus::cases() as $status) {
            $this->assertFalse(OrderStatus::Completed->canTransitionTo($status));
            $this->assertFalse(OrderStatus::Cancelled->canTransitionTo($status));
        }
    }
}
