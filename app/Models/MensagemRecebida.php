<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem que um cliente mandou para o WhatsApp da oficina (webhook da Evolution). */
class MensagemRecebida extends Model
{
    use PertenceAEmpresa;

    protected $table = 'mensagens_recebidas';

    public const ACOES = [
        'confirmou' => 'Confirmou o horário',
        'pediu_remarcar' => 'Quer remarcar',
        'pediu_agendamento' => 'Quer agendar (virou pedido na agenda)',
        'parou' => 'Pediu para não receber mais',
    ];

    protected $fillable = ['cliente_id', 'de', 'nome', 'texto', 'externo_id', 'acao', 'referencia_id', 'vista_em'];

    protected function casts(): array
    {
        return ['vista_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }
}
