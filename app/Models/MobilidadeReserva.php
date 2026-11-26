<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;

class MobilidadeReserva extends Model
{
    use Auditavel, PertenceAEmpresa;

    protected $table = 'mobilidade_reservas';

    public const MODALIDADES = ['carro_reserva' => 'Carro reserva', 'aluguel' => 'Aluguel'];

    public const ORIGENS = ['frota' => 'Frota própria', 'parceira' => 'Locadora parceira'];

    /** Plano do contrato: diária cobra tudo na devolução; semanal e mensal geram parcelas antecipadas. */
    public const PLANOS = ['diaria' => 'Diária', 'semanal' => 'Semanal', 'mensal' => 'Mensal'];

    public const SITUACOES = ['reservada' => 'Reservado', 'entregue' => 'Com o cliente', 'devolvida' => 'Encerrado', 'cancelada' => 'Cancelado'];

    protected $fillable = ['modalidade', 'cliente_id', 'ordem_servico_id', 'frota_id', 'condutor_id', 'conta_receber_id', 'tipo', 'parceira', 'contrato_externo', 'inicio', 'fim', 'status', 'diaria_centavos', 'taxas_centavos', 'caucao_centavos', 'entrega', 'devolucao', 'ocorrencias', 'plano', 'valor_periodo_centavos', 'km_livre', 'km_excedente_centavos', 'parcelas_ate'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fim' => 'datetime', 'diaria_centavos' => 'integer', 'taxas_centavos' => 'integer', 'caucao_centavos' => 'integer', 'entrega' => 'array', 'devolucao' => 'array',
            'valor_periodo_centavos' => 'integer', 'km_livre' => 'integer', 'km_excedente_centavos' => 'integer', 'parcelas_ate' => 'datetime'];
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function ordemServico()
    {
        return $this->belongsTo(OrdemServico::class);
    }

    public function frota()
    {
        return $this->belongsTo(MobilidadeFrota::class, 'frota_id');
    }

    public function condutor()
    {
        return $this->belongsTo(MobilidadeCondutor::class, 'condutor_id')->withTrashed();
    }

    public function titulos()
    {
        return $this->hasMany(ContaReceber::class, 'reserva_id')->withoutGlobalScope('area')->orderBy('vencimento');
    }

    public function multas()
    {
        return $this->hasMany(LocadoraMulta::class, 'reserva_id');
    }

    public function parcelado(): bool
    {
        return in_array($this->plano, ['semanal', 'mensal'], true);
    }

    /** Valor por período (diária, semana ou mês). Contratos antigos só têm a diária. */
    public function valorPeriodo(): int
    {
        return $this->valor_periodo_centavos ?: $this->diaria_centavos;
    }

    public function atrasado(): bool
    {
        return $this->status === 'entregue' && $this->fim->isPast();
    }

    public function ehAluguel(): bool
    {
        return $this->modalidade === 'aluguel';
    }
}
