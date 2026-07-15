<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Models\CarteiraMovimento;
use App\Services\FrontendPresenter;
use App\Services\SavedCardService;
use App\Services\WalletTopUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletTopUpService $walletTopUpService,
        private readonly SavedCardService $savedCardService,
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

    public function transactions(Request $request): JsonResponse
    {
        $movimentos = CarteiraMovimento::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'transactions' => $movimentos
                ->map(fn (CarteiraMovimento $movimento) => FrontendPresenter::walletTransaction($movimento))
                ->values()
                ->all(),
        ]);
    }

    public function storeCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cardToken' => ['required', 'string'],
            'paymentMethodId' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (empty($user->cpf)) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF obrigatorio antes de salvar cartao via gateway.'],
            ]);
        }

        $cartao = $this->savedCardService->addFromToken(
            $user,
            $validated['cardToken'],
            $validated['paymentMethodId'],
        );

        return response()->json(FrontendPresenter::savedCard($cartao), 201);
    }

    public function destroyCard(Request $request, string $cardId): JsonResponse
    {
        $this->savedCardService->delete($request->user(), $cardId);

        return response()->json(null, 204);
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
