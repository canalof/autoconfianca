<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp nos dois sentidos (webhook da Evolution): estado da conexão, entregue/lida,
 * mensagens que os clientes mandam e segundo aviso dos lembretes de revisão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            $t->string('whatsapp_estado', 20)->nullable();
            $t->dateTime('whatsapp_estado_em')->nullable();
        });

        Schema::table('mensagens_whatsapp', function (Blueprint $t) {
            $t->string('externo_id', 80)->nullable();
            $t->dateTime('entregue_em')->nullable();
            $t->dateTime('lida_em')->nullable();
            $t->index(['empresa_id', 'externo_id']);
            $t->index(['empresa_id', 'destino', 'created_at']);
        });

        Schema::create('mensagens_recebidas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $t->string('de', 20);
            $t->string('nome', 120)->nullable();
            $t->text('texto');
            $t->string('externo_id', 80);
            $t->string('acao', 30)->nullable(); // o que o sistema fez: confirmou, pediu_remarcar, pediu_agendamento, parou
            $t->unsignedBigInteger('referencia_id')->nullable();
            $t->dateTime('vista_em')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'externo_id']);
            $t->index(['empresa_id', 'created_at']);
        });

        Schema::table('lembretes', function (Blueprint $t) {
            $t->string('origem', 12)->default('os'); // os | historico
            $t->dateTime('segundo_aviso_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lembretes', fn (Blueprint $t) => $t->dropColumn(['origem', 'segundo_aviso_em']));
        Schema::dropIfExists('mensagens_recebidas');
        Schema::table('mensagens_whatsapp', function (Blueprint $t) {
            $t->dropIndex(['empresa_id', 'externo_id']);
            $t->dropIndex(['empresa_id', 'destino', 'created_at']);
            $t->dropColumn(['externo_id', 'entregue_em', 'lida_em']);
        });
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn(['whatsapp_estado', 'whatsapp_estado_em']));
    }
};
