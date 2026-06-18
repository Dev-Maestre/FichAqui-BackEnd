<?php

namespace Database\Seeders;

use App\Models\CartaoSalvo;
use App\Models\Carteira;
use App\Models\User;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        $maria = User::query()->where('email', 'maria@email.com')->first();
        if (! $maria) {
            return;
        }

        Carteira::query()->updateOrCreate(
            ['user_id' => $maria->id],
            ['balance' => 46.00],
        );

        CartaoSalvo::query()->updateOrCreate(
            ['id' => 'card-1'],
            [
                'user_id' => $maria->id,
                'brand' => 'visa',
                'last_four' => '4242',
                'holder_name' => 'Maria Silva',
                'is_default' => true,
            ]
        );

        CartaoSalvo::query()->updateOrCreate(
            ['id' => 'card-2'],
            [
                'user_id' => $maria->id,
                'brand' => 'mastercard',
                'last_four' => '5555',
                'holder_name' => 'Maria Silva',
                'is_default' => false,
            ]
        );
    }
}
