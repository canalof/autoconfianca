<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Migração do Oficina Inteligente: código do cadastro antigo (para a importação poder
 * ser repetida sem duplicar) e histórico das OS antigas, só para consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->string('codigo_anterior', 20)->nullable()->after('empresa_id');
            $t->index(['empresa_id', 'codigo_anterior']);
        });
        Schema::table('fornecedores', function (Blueprint $t) {
            $t->string('codigo_anterior', 20)->nullable()->after('empresa_id');
            $t->index(['empresa_id', 'codigo_anterior']);
        });
        Schema::create('historico_os_anteriores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->foreignId('veiculo_id')->nullable()->constrained('veiculos')->nullOnDelete();
            $t->unsignedInteger('numero');
            $t->date('data');
            $t->string('cliente_nome', 150)->nullable();
            $t->string('placa', 8)->nullable();
            $t->string('veiculo', 80)->nullable();
            $t->string('responsavel', 80)->nullable();
            $t->string('status', 20)->nullable();
            $t->unsignedBigInteger('total_centavos')->default(0);
            $t->json('itens');
            $t->timestamps();
            $t->unique(['empresa_id', 'numero']);
            $t->index(['empresa_id', 'veiculo_id']);
            $t->index(['empresa_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_os_anteriores');
        Schema::table('fornecedores', function (Blueprint $t) {
            $t->dropIndex(['empresa_id', 'codigo_anterior']);
            $t->dropColumn('codigo_anterior');
        });
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropIndex(['empresa_id', 'codigo_anterior']);
            $t->dropColumn('codigo_anterior');
        });
    }
};
