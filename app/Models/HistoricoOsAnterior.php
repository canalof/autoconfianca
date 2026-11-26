<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/** OS do sistema anterior (Oficina Inteligente), importada só para consulta. */
class HistoricoOsAnterior extends Model
{
    use PertenceAEmpresa;

    protected $table = 'historico_os_anteriores';

    protected $fillable = ['cliente_id', 'veiculo_id', 'numero', 'data', 'cliente_nome', 'placa', 'veiculo', 'responsavel', 'status', 'total_centavos', 'itens'];

    protected function casts(): array
    {
        return ['data' => 'date', 'itens' => 'array'];
    }
}
