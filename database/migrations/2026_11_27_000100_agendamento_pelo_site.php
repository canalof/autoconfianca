<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de agendamento vindos do site (sem login): a pessoa pode não ter cadastro,
 * então cliente e veículo ficam opcionais e guardamos nome, WhatsApp e carro digitados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agenda_solicitacoes', function (Blueprint $t) {
            $t->foreignId('cliente_id')->nullable()->change();
            $t->foreignId('veiculo_id')->nullable()->change();
            $t->string('origem', 10)->default('portal');
            $t->string('nome', 120)->nullable();
            $t->string('telefone', 20)->nullable();
            $t->string('email', 160)->nullable();
            $t->string('carro', 120)->nullable();
            $t->string('placa', 8)->nullable();
            $t->unsignedInteger('km')->nullable();
            $t->string('periodo', 10)->nullable();
            $t->string('observacao', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agenda_solicitacoes', function (Blueprint $t) {
            $t->dropColumn(['origem', 'nome', 'telefone', 'email', 'carro', 'placa', 'km', 'periodo', 'observacao']);
        });
    }
};
