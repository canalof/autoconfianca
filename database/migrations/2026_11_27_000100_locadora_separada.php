<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locadora como área separada da oficina:
 * - caixa e contas a receber/pagar ganham a coluna "area" (oficina ou locadora);
 * - frota com documentos, preços e vencimentos;
 * - contrato com plano (diária, semanal, mensal), franquia de km e parcelas;
 * - multas de trânsito da frota.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['caixas', 'contas_receber', 'contas_pagar'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->string('area', 10)->default('oficina');
                $t->index(['empresa_id', 'area']);
            });
        }
        DB::table('contas_receber')->where('categoria', 'locacao')->update(['area' => 'locadora']);

        Schema::table('contas_receber', function (Blueprint $t) {
            $t->foreignId('reserva_id')->nullable()->constrained('mobilidade_reservas')->nullOnDelete();
        });

        Schema::table('mobilidade_frota', function (Blueprint $t) {
            $t->string('marca', 40)->nullable();
            $t->unsignedSmallInteger('ano')->nullable();
            $t->string('cor', 30)->nullable();
            $t->string('combustivel', 12)->nullable();
            $t->string('renavam', 11)->nullable();
            $t->string('chassi', 17)->nullable();
            $t->unsignedInteger('diaria_centavos')->default(0);
            $t->unsignedInteger('semanal_centavos')->default(0);
            $t->unsignedInteger('mensal_centavos')->default(0);
            $t->unsignedInteger('caucao_centavos')->default(0);
            $t->unsignedInteger('km_livre_dia')->nullable();
            $t->unsignedInteger('km_excedente_centavos')->default(0);
            $t->date('ipva_vencimento')->nullable();
            $t->date('licenciamento_vencimento')->nullable();
            $t->date('seguro_vencimento')->nullable();
            $t->unsignedInteger('proxima_revisao_km')->nullable();
            $t->string('foto')->nullable();
            $t->text('observacoes')->nullable();
        });

        Schema::table('mobilidade_reservas', function (Blueprint $t) {
            $t->string('plano', 10)->default('diaria');
            $t->unsignedInteger('valor_periodo_centavos')->default(0);
            $t->unsignedInteger('km_livre')->nullable();
            $t->unsignedInteger('km_excedente_centavos')->default(0);
            $t->dateTime('parcelas_ate')->nullable();
        });
        DB::table('mobilidade_reservas')->update(['valor_periodo_centavos' => DB::raw('diaria_centavos')]);

        Schema::create('locadora_multas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $t->foreignId('frota_id')->constrained('mobilidade_frota')->restrictOnDelete();
            $t->foreignId('reserva_id')->nullable()->constrained('mobilidade_reservas')->nullOnDelete();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->foreignId('conta_receber_id')->nullable()->constrained('contas_receber')->nullOnDelete();
            $t->dateTime('data_infracao');
            $t->string('auto', 30)->nullable();
            $t->string('descricao', 200);
            $t->string('local', 200)->nullable();
            $t->unsignedInteger('valor_centavos');
            $t->date('vencimento')->nullable();
            $t->date('prazo_indicacao')->nullable();
            $t->string('situacao', 12)->default('aberta');
            $t->text('observacao')->nullable();
            $t->timestamps();
            $t->index(['empresa_id', 'situacao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locadora_multas');
        Schema::table('mobilidade_reservas', function (Blueprint $t) {
            $t->dropColumn(['plano', 'valor_periodo_centavos', 'km_livre', 'km_excedente_centavos', 'parcelas_ate']);
        });
        Schema::table('mobilidade_frota', function (Blueprint $t) {
            $t->dropColumn(['marca', 'ano', 'cor', 'combustivel', 'renavam', 'chassi', 'diaria_centavos', 'semanal_centavos',
                'mensal_centavos', 'caucao_centavos', 'km_livre_dia', 'km_excedente_centavos', 'ipva_vencimento',
                'licenciamento_vencimento', 'seguro_vencimento', 'proxima_revisao_km', 'foto', 'observacoes']);
        });
        Schema::table('contas_receber', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reserva_id');
        });
        foreach (['caixas', 'contas_receber', 'contas_pagar'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) use ($tabela) {
                $t->dropIndex($tabela.'_empresa_id_area_index');
                $t->dropColumn('area');
            });
        }
    }
};
