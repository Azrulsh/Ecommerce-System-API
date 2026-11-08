<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesSummaryService
{
    public function dateRange(Builder $query, array $filters): Builder
    {
        if (isset($filters['from_date'])) {
            $query->where('orders.created_at', '>=', $filters['from_date'].' 00:00:00');
        }
        if (isset($filters['to_date'])) {
            $query->where('orders.created_at', '<', CarbonImmutable::parse($filters['to_date'], 'UTC')->addDay()->format('Y-m-d').' 00:00:00');
        }

        return $query;
    }

    public function summarize(array $filters): array
    {
        $all = $this->dateRange(Order::query(), $filters);
        $counts = (clone $all)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $byStatus = [];
        foreach (OrderStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }
        $recognized = (clone $all)->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereHas('payment', fn ($payment) => $payment->where('status', 'paid')->whereDoesntHave('refund'));
        $money = (clone $recognized)->selectRaw('COALESCE(SUM(subtotal),0) AS gross, COALESCE(SUM(discount_total),0) AS discounts, COALESCE(SUM(grand_total),0) AS net')->first();
        $top = DB::table('order_items')->whereIn('order_id', (clone $recognized)->select('orders.id'))
            ->selectRaw('sku_snapshot, MAX(product_name_snapshot) AS product_name, SUM(quantity) AS quantity_sold, SUM(line_total) AS revenue')
            ->groupBy('sku_snapshot')->orderByDesc('quantity_sold')->orderBy('sku_snapshot')->limit(10)->get();

        return ['total_orders' => array_sum($byStatus), 'completed_orders' => $byStatus['completed'],
            'cancelled_orders' => $byStatus['cancelled'], 'orders_by_status' => $byStatus,
            'gross_sales' => $this->decimal((string) $money->gross), 'discounts_given' => $this->decimal((string) $money->discounts),
            'net_sales' => $this->decimal((string) $money->net),
            'top_selling_products' => $top->map(fn ($row) => ['sku' => $row->sku_snapshot, 'product_name' => $row->product_name,
                'quantity_sold' => (int) $row->quantity_sold, 'revenue' => $this->decimal((string) $row->revenue)])->all()];
    }

    private function decimal(string $value): string
    {
        // SQL SUM over DECIMAL is exact; keep large aggregates as strings, never floats.
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
