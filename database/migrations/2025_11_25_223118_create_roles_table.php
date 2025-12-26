<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table already exists from Entrust package, just seed the default roles
        if (Schema::hasTable('roles')) {
            // Check if roles already seeded
            $count = DB::table('roles')->count();
            if ($count === 0) {
                DB::table('roles')->insert([
                    [
                        'role_id' => 1,
                        'name' => 'admin',
                        'display_name' => 'Organizador',
                        'description' => 'Administrador/Organizador da maratona - acesso total ao sistema',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'role_id' => 2,
                        'name' => 'participant',
                        'display_name' => 'Participante',
                        'description' => 'Participante da maratona - pode submeter solucoes e ver clarifications',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                    [
                        'role_id' => 3,
                        'name' => 'spectator',
                        'display_name' => 'Espectador',
                        'description' => 'Visualizador do placar - apenas visualizacao do scoreboard',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop the table, just remove the seeded data
        DB::table('roles')->whereIn('name', ['admin', 'participant', 'spectator'])->delete();
    }
};
