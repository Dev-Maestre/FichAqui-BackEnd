<?php

namespace App\Services;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\Ficha;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Support\AssetUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class RelatorioService
{
    private const TIMEZONE = 'America/Sao_Paulo';

    /**
     * @return array<string, mixed>
     */
    public function forEvento(Evento $evento): array
    {
        $pedidos = $this->confirmedPedidosQuery($evento)->get();

        return $this->buildReport($evento, $pedidos, includeFichaProgress: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function resumoForEvento(Evento $evento): array
    {
        [$start, $end] = $this->todayRange();

        $pedidos = $this->confirmedPedidosQuery($evento)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $report = $this->buildReport($evento, $pedidos, includeFichaProgress: true);

        return [
            'orderCount' => $report['orderCount'],
            'totalRevenue' => $report['totalRevenue'],
            'consumerCount' => $this->distinctConsumers($pedidos),
            'pendingOrderCount' => $this->pendingPedidosQuery($evento)
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'salesByStall' => $report['salesByStall'],
        ];
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return array<string, mixed>
     */
    private function buildReport(Evento $evento, Collection $pedidos, bool $includeFichaProgress): array
    {
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
            'salesByStall' => $this->salesByStall($evento, $pedidos, $includeFichaProgress),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function todayRange(): array
    {
        $start = Carbon::now(self::TIMEZONE)->startOfDay()->utc();
        $end = Carbon::now(self::TIMEZONE)->endOfDay()->utc();

        return [$start, $end];
    }

    /**
     * @return Builder<Pedido>
     */
    private function confirmedPedidosQuery(Evento $evento): Builder
    {
        return Pedido::query()
            ->with(['itens.ofertaVariante.oferta'])
            ->where('evento_id', $evento->id)
            ->whereIn('payment_status', ['paid', 'approved'])
            ->whereNotIn('status', ['payment_failed']);
    }

    /**
     * @return Builder<Pedido>
     */
    private function pendingPedidosQuery(Evento $evento): Builder
    {
        return Pedido::query()
            ->where('evento_id', $evento->id)
            ->where('payment_status', 'pending')
            ->whereNotIn('status', ['payment_failed']);
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     */
    private function distinctConsumers(Collection $pedidos): int
    {
        return $pedidos->pluck('user_id')->filter()->unique()->count();
    }

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return list<array{hour: string, value: float}>
     */
    private function salesByHour(Collection $pedidos): array
    {
        $buckets = [];

        foreach ($pedidos as $pedido) {
            $hour = $pedido->created_at?->timezone(self::TIMEZONE)->format('H').'h' ?? '00h';
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

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return list<array<string, mixed>>
     */
    private function salesByStall(
        Evento $evento,
        Collection $pedidos,
        bool $includeFichaProgress,
    ): array {
        /** @var SupportCollection<string, Barraca> $stalls */
        $stalls = Barraca::query()
            ->where('evento_id', $evento->id)
            ->get()
            ->keyBy('id');

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];

        foreach ($stalls as $stall) {
            $buckets[$stall->id] = [
                'stallId' => $stall->id,
                'name' => $stall->name,
                'color' => $stall->color,
                'status' => $stall->status,
                'revenue' => 0.0,
                'orderCount' => 0,
                '_orderIds' => [],
            ];
        }

        foreach ($pedidos as $pedido) {
            $stallsInPedido = [];

            foreach ($pedido->itens as $item) {
                $stallId = $this->resolveStallId($item, $stalls);
                if ($stallId === null || ! isset($buckets[$stallId])) {
                    continue;
                }

                $buckets[$stallId]['revenue'] += $this->lineTotal($item);
                $stallsInPedido[$stallId] = true;
            }

            foreach (array_keys($stallsInPedido) as $stallId) {
                $buckets[$stallId]['_orderIds'][$pedido->id] = true;
            }
        }

        if ($includeFichaProgress && $pedidos->isNotEmpty()) {
            $fichas = Ficha::query()
                ->whereIn('pedido_id', $pedidos->pluck('id'))
                ->get(['barraca_id', 'status']);

            foreach ($fichas as $ficha) {
                if (! isset($buckets[$ficha->barraca_id])) {
                    continue;
                }

                $buckets[$ficha->barraca_id]['fichasIssued'] = ($buckets[$ficha->barraca_id]['fichasIssued'] ?? 0) + 1;
                if ($ficha->status === 'delivered') {
                    $buckets[$ficha->barraca_id]['fichasDelivered'] = ($buckets[$ficha->barraca_id]['fichasDelivered'] ?? 0) + 1;
                }
            }
        }

        return collect($buckets)
            ->map(function (array $row) use ($includeFichaProgress) {
                $row['revenue'] = round($row['revenue'], 2);
                $row['orderCount'] = count($row['_orderIds']);
                unset($row['_orderIds']);

                return $row;
            })
            ->sortByDesc('revenue')
            ->values()
            ->pipe(function (SupportCollection $rows) use ($includeFichaProgress) {
                $stallRevenueTotal = $rows->sum('revenue');

                return $rows->map(function (array $row) use ($includeFichaProgress, $stallRevenueTotal) {
                    if ($stallRevenueTotal > 0) {
                        $row['percentage'] = round(($row['revenue'] / $stallRevenueTotal) * 100, 1);
                    } else {
                        $row['percentage'] = 0.0;
                    }

                    if ($includeFichaProgress) {
                        $row['fichasIssued'] = $row['fichasIssued'] ?? 0;
                        $row['fichasDelivered'] = $row['fichasDelivered'] ?? 0;
                    }

                    return $row;
                });
            })
            ->all();
    }

    /**
     * @param  SupportCollection<string, Barraca>  $stalls
     */
    private function resolveStallId(PedidoItem $item, SupportCollection $stalls): ?string
    {
        $variante = $item->ofertaVariante;
        if ($variante?->oferta?->barraca_id) {
            return $variante->oferta->barraca_id;
        }

        $snapshot = $item->item_snapshot ?? [];
        $stallName = $snapshot['stallName'] ?? null;
        if (! is_string($stallName) || $stallName === '') {
            return null;
        }

        $match = $stalls->first(fn (Barraca $stall) => $stall->name === $stallName);

        return $match?->id;
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
