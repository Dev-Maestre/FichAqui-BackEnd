<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Services\FrontendPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
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
}
