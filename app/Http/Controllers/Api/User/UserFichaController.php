<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Ficha;
use App\Services\FrontendPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserFichaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fichas = Ficha::query()
            ->availableForUser($request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(
            $fichas->map(fn (Ficha $ficha) => FrontendPresenter::ficha($ficha))->values()->all()
        );
    }
}
