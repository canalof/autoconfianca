<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;

class AgendaSolicitacao extends Model
{
    use Auditavel, PertenceAEmpresa;

    protected $table = 'agenda_solicitacoes';

    public const PERIODOS = ['manha' => 'manhã', 'tarde' => 'tarde'];

    protected $fillable = ['cliente_id', 'veiculo_id', 'agendamento_id', 'preferencia', 'servico', 'status',
        'origem', 'nome', 'telefone', 'email', 'carro', 'placa', 'km', 'periodo', 'observacao'];

    protected function casts(): array
    {
        return ['preferencia' => 'datetime'];
    }

    public function cliente(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function doSite(): bool
    {
        return $this->origem === 'site';
    }

    /** Pedido que não veio pela área do cliente (site ou resposta no WhatsApp): a recepção avisa o cliente. */
    public function externo(): bool
    {
        return in_array($this->origem, ['site', 'whatsapp'], true);
    }

    public function origemRotulo(): string
    {
        return match ($this->origem) {
            'site' => 'Site',
            'whatsapp' => 'WhatsApp',
            default => 'Área do cliente',
        };
    }

    /** Nome e WhatsApp de quem pediu: o digitado no site ou o do cadastro. */
    public function nomeContato(): string
    {
        return $this->nome ?: ($this->cliente?->nome ?? 'Cliente');
    }

    public function telefoneContato(): ?string
    {
        return $this->telefone ?: ($this->cliente?->whatsapp ?: $this->cliente?->telefone);
    }

    /** "terça, 02/12 de manhã" ou "02/12 às 08:00". */
    public function quandoPedido(): string
    {
        $dia = $this->preferencia->translatedFormat('l, d/m');

        return $this->periodo ? $dia.' de '.(self::PERIODOS[$this->periodo] ?? $this->periodo) : $dia.' às '.$this->preferencia->format('H:i');
    }

    public function veiculo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Veiculo::class);
    }

    public function agendamento(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Agendamento::class);
    }
}
