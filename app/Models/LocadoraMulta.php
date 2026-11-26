<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/** Multa de trânsito de um carro da frota, ligada ao contrato em vigor na data da infração. */
class LocadoraMulta extends Model
{
    use Auditavel, PertenceAEmpresa;

    protected $table = 'locadora_multas';

    public const SITUACOES = ['aberta' => 'Aberta', 'indicada' => 'Condutor indicado', 'cobrada' => 'Cobrada do cliente', 'paga' => 'Paga', 'cancelada' => 'Cancelada'];

    protected $fillable = ['frota_id', 'reserva_id', 'cliente_id', 'conta_receber_id', 'data_infracao', 'auto', 'descricao', 'local',
        'valor_centavos', 'vencimento', 'prazo_indicacao', 'situacao', 'observacao'];

    protected function casts(): array
    {
        return ['data_infracao' => 'datetime', 'vencimento' => 'date', 'prazo_indicacao' => 'date', 'valor_centavos' => 'integer'];
    }

    public function frota()
    {
        return $this->belongsTo(MobilidadeFrota::class, 'frota_id');
    }

    public function reserva()
    {
        return $this->belongsTo(MobilidadeReserva::class, 'reserva_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }
}
