<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Services\FrontendPresenter;
use App\Services\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletTopUpService $walletTopUpService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $carteira = Carteira::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0],
        );

        $cartoes = CartaoSalvo::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json(FrontendPresenter::wallet($carteira, $cartoes));
    }

    public function topUp(Request $request): JsonResponse
    {
        $result = $this->walletTopUpService->topUp($request->user(), $request->all());

        return response()->json(
            FrontendPresenter::walletTopUpResult($result['carteira'], $result['recarga']),
            201,
        );
    }
}
