<?php

namespace Database\Seeders;

use App\Models\Barraca;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Database\Seeder;

class FichaquiSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->usuarios() as $usuario) {
            User::query()->updateOrCreate(
                ['email' => $usuario['email']],
                [
                    'external_id' => $usuario['external_id'],
                    'name' => $usuario['name'],
                    'password' => $usuario['password'],
                    'roles' => $usuario['roles'],
                    'organizer_id' => $usuario['organizer_id'] ?? null,
                    'phone' => $usuario['phone'] ?? null,
                    'cpf' => $usuario['cpf'] ?? null,
                    'birth_date' => $usuario['birth_date'] ?? null,
                    'stall_id' => $usuario['stall_id'] ?? null,
                ]
            );
        }

        foreach ($this->eventos() as $evento) {
            Evento::query()->updateOrCreate(['id' => $evento['id']], $evento);
        }

        foreach ($this->barracas() as $barraca) {
            Barraca::query()->updateOrCreate(['id' => $barraca['id']], $barraca);
        }
    }

    private function usuarios(): array
    {
        return [
            [
                'external_id' => 'user-maria',
                'email' => 'maria@testuser.com',
                'password' => '123456',
                'name' => 'Maria Silva',
                'roles' => ['client'],
                'phone' => '(41) 99999-1234',
                'cpf' => '529.982.247-25',
                'birth_date' => '1992-03-15',
            ],
            [
                'external_id' => 'user-raul',
                'email' => 'raul@paroquia.com',
                'password' => '123456',
                'name' => 'Raul Souza',
                'roles' => ['client', 'organizer'],
                'organizer_id' => 'org-paroquia',
            ],
            [
                'external_id' => 'user-atendente',
                'email' => 'atendente@email.com',
                'password' => '123456',
                'name' => 'Joao Atendente',
                'roles' => ['stall_manager'],
                'stall_id' => 'stall-1',
            ],
        ];
    }

    private function eventos(): array
    {
        // Picsum Photos é uma API de imagens aleatórias gratuita, ideal para o MVP.
        // Usamos seeds determinísticos para que os banners e ícones de cada evento sejam consistentes.
        return [
            [
                'id' => '1',
                'name' => 'Festa de Sao Joao',
                'description' => 'A maior festa junina da comunidade.',
                'date' => '2026-06-24',
                'start_time' => '18:00',
                'end_time' => '23:00',
                'location' => 'Paroquia Sao Joao Batista',
                'city_id' => 'curitiba-pr',
                'cidade' => 'Curitiba',
                'estado' => 'PR',
                'latitude' => -25.4284,
                'longitude' => -49.2733,
                'organizer_id' => 'org-paroquia',
                'banner' => 'https://picsum.photos/seed/event-1-banner/800/400',
                'status' => 'published',
                'capacity' => 500,
                'primary_color' => '#d97706',
                'icon' => 'https://picsum.photos/seed/event-1-icon/100/100',
            ],
            [
                'id' => '2',
                'name' => 'Festa de Natal',
                'description' => 'Celebracao natalina com comidas tipicas.',
                'date' => '2026-12-25',
                'start_time' => '17:00',
                'end_time' => '22:00',
                'location' => 'Salao Paroquial',
                'city_id' => 'curitiba-pr',
                'cidade' => 'Curitiba',
                'estado' => 'PR',
                'latitude' => -25.4300,
                'longitude' => -49.2700,
                'organizer_id' => 'org-paroquia',
                'banner' => 'https://picsum.photos/seed/event-2-banner/800/400',
                'status' => 'published',
                'capacity' => 300,
                'primary_color' => '#dc2626',
                'icon' => 'https://picsum.photos/seed/event-2-icon/100/100',
            ],
            [
                'id' => 'estabelecimento-paroquia',
                'name' => 'Cantina Paroquial',
                'description' => 'Cantina fixa da paroquia, aberta o ano todo.',
                'date' => null,
                'start_time' => null,
                'end_time' => null,
                'location' => 'Salao Paroquial',
                'city_id' => 'curitiba-pr',
                'cidade' => 'Curitiba',
                'estado' => 'PR',
                'latitude' => null,
                'longitude' => null,
                'organizer_id' => 'org-paroquia',
                'banner' => 'https://picsum.photos/seed/event-cantina-banner/800/400',
                'status' => 'active',
                'capacity' => 80,
                'primary_color' => '#16a34a',
                'icon' => 'https://picsum.photos/seed/event-cantina-icon/100/100',
            ],
        ];
    }

    private function barracas(): array
    {
        return [
            ['id' => 'stall-1', 'evento_id' => '1', 'name' => 'Barraca do Pastel', 'category' => 'comidas', 'responsible' => 'Maria Silva', 'color' => '#ef4444', 'status' => 'open', 'stock' => 150],
            ['id' => 'stall-2', 'evento_id' => '1', 'name' => 'Barraca do Milho', 'category' => 'comidas', 'responsible' => 'Joao Santos', 'color' => '#f59e0b', 'status' => 'open', 'stock' => 200],
            ['id' => 'stall-5', 'evento_id' => '1', 'name' => 'Pescaria', 'category' => 'jogos', 'responsible' => 'Carlos Oliveira', 'color' => '#22c55e', 'status' => 'open', 'stock' => 50],
            ['id' => 'stall-n1', 'evento_id' => '2', 'name' => 'Doces Natalinos', 'category' => 'doces', 'responsible' => 'Helena Dias', 'color' => '#16a34a', 'status' => 'open', 'stock' => 120],
            ['id' => 'stall-est-1', 'evento_id' => 'estabelecimento-paroquia', 'name' => 'Cantina Principal', 'category' => 'comidas', 'responsible' => 'Raul Souza', 'color' => '#16a34a', 'status' => 'open', 'stock' => 100],
        ];
    }
}
