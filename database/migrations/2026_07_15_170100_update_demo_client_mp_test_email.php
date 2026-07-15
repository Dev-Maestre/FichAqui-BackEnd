<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEMO_CLIENT_EXTERNAL_ID = 'user-maria';

    private const OLD_EMAIL = 'maria@testuser.com';

    private const NEW_EMAIL = 'test_user_5207637493757128652@testuser.com';

    public function up(): void
    {
        DB::table('users')
            ->where('external_id', self::DEMO_CLIENT_EXTERNAL_ID)
            ->update([
                'email' => self::NEW_EMAIL,
                'mercadopago_customer_id' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('external_id', self::DEMO_CLIENT_EXTERNAL_ID)
            ->update([
                'email' => self::OLD_EMAIL,
                'mercadopago_customer_id' => null,
            ]);
    }
};
