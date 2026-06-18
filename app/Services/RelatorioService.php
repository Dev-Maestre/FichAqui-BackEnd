<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Support\AssetUrl;

class RelatorioService
{
    /**
     * @return array<string, mixed>
     */
    public function forEvento(Evento $evento): array
    {
        $pedidos = Pedido::query()
            ->with('itens')
            ->where('evento_id', $evento->id)
            ->whereIn('payment_status', ['paid', 'approved'])
            ->whereNotIn('status', ['payment_failed'])
            ->get();

        $orderCount = $pedidos->count();
        $totalRevenue = $pedidos->sum(fn (Pedido $p) => (float) $p->total);
        $averageTicket = $orderCount > 0 ? round($totalRevenue / $orderCount, 2) : 0.0;

        return [
            'totalRevenue' => round($totalRevenue, 2),
            'orderCount' => $orderCount,
            'averageTicket' => $averageTicket,
            'salesByHour' => $this->salesByHour($pedidos),
            'salesByCategory' => $this->salesByCategory($pedidos),
            'topProducts' => $this->topProducts($pedidos),
        ];
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return list<array{hour: string, value: float}>
     */
    private function salesByHour(Collection $pedidos): array
    {
        $buckets = [];

        foreach ($pedidos as $pedido) {
            $hour = $pedido->created_at?->format('H').'h' ?? '00h';
            $buckets[$hour] = ($buckets[$hour] ?? 0) + (float) $pedido->total;
        }

        ksort($buckets);

        return collect($buckets)
            ->map(fn (float $value, string $hour) => ['hour' => $hour, 'value' => round($value, 2)])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return list<array{name: string, percentage: float}>
     */
    private function salesByCategory(Collection $pedidos): array
    {
        $totals = [];
        $grand = 0.0;

        foreach ($pedidos as $pedido) {
            foreach ($pedido->itens as $item) {
                $snapshot = $item->item_snapshot ?? [];
                $category = $snapshot['category'] ?? 'outros';
                $lineTotal = $this->lineTotal($item);
                $totals[$category] = ($totals[$category] ?? 0) + $lineTotal;
                $grand += $lineTotal;
            }
        }

        if ($grand <= 0) {
            return [];
        }

        return collect($totals)
            ->map(fn (float $value, string $name) => [
                'name' => ucfirst($name),
                'percentage' => round(($value / $grand) * 100, 1),
            ])
            ->sortByDesc('percentage')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return list<array{name: string, sales: int, revenue: float, image: string|null}>
     */
    private function topProducts(Collection $pedidos): array
    {
        $products = [];

        foreach ($pedidos as $pedido) {
            foreach ($pedido->itens as $item) {
                $snapshot = $item->item_snapshot ?? [];
                $name = $snapshot['name'] ?? 'Item';
                $image = $snapshot['image'] ?? null;
                $lineTotal = $this->lineTotal($item);

                if (! isset($products[$name])) {
                    $products[$name] = ['name' => $name, 'sales' => 0, 'revenue' => 0.0, 'image' => $image];
                }

                $products[$name]['sales'] += $item->quantity;
                $products[$name]['revenue'] += $lineTotal;
            }
        }

        return collect($products)
            ->sortByDesc('revenue')
            ->take(10)
            ->map(function (array $row) {
                $row['revenue'] = round($row['revenue'], 2);
                $row['image'] = AssetUrl::resolve($row['image']);

                return $row;
            })
            ->values()
            ->all();
    }

    private function lineTotal(PedidoItem $item): float
    {
        $snapshot = $item->item_snapshot ?? [];
        $unit = (float) ($snapshot['unitPrice'] ?? $snapshot['price'] ?? 0);

        if ($unit <= 0 && isset($snapshot['name'])) {
            return 0.0;
        }

        return $unit * $item->quantity;
    }
}
