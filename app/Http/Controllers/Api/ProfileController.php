<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FrontendPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'cpf' => ['sometimes', 'nullable', 'string', 'max:20'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }

        if (array_key_exists('cpf', $validated)) {
            $user->cpf = $validated['cpf'];
        }

        if (array_key_exists('birthDate', $validated)) {
            $user->birth_date = $validated['birthDate'];
        }

        $user->save();

        return response()->json(FrontendPresenter::user($user));
    }
}
