<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/** Carro da frota da locadora: documentos, preços de tabela e vencimentos. */
class MobilidadeFrota extends Model
{
    use Auditavel, PertenceAEmpresa;

    protected $table = 'mobilidade_frota';

    public const SITUACOES = ['disponivel' => 'Disponível', 'manutencao' => 'Em manutenção', 'inativo' => 'Fora da frota'];

    public const COMBUSTIVEIS = ['flex' => 'Flex', 'gasolina' => 'Gasolina', 'etanol' => 'Etanol', 'diesel' => 'Diesel', 'gnv' => 'GNV', 'eletrico' => 'Elétrico', 'hibrido' => 'Híbrido'];

    /** Vencimentos acompanhados no painel: campo => rótulo. */
    public const DOCUMENTOS = ['ipva_vencimento' => 'IPVA', 'licenciamento_vencimento' => 'Licenciamento', 'seguro_vencimento' => 'Seguro'];

    protected $fillable = ['placa', 'descricao', 'km', 'situacao', 'manutencao', 'marca', 'ano', 'cor', 'combustivel', 'renavam', 'chassi',
        'diaria_centavos', 'semanal_centavos', 'mensal_centavos', 'caucao_centavos', 'km_livre_dia', 'km_excedente_centavos',
        'ipva_vencimento', 'licenciamento_vencimento', 'seguro_vencimento', 'proxima_revisao_km', 'foto', 'observacoes'];

    protected function casts(): array
    {
        return ['km' => 'integer', 'ano' => 'integer', 'diaria_centavos' => 'integer', 'semanal_centavos' => 'integer', 'mensal_centavos' => 'integer',
            'caucao_centavos' => 'integer', 'km_livre_dia' => 'integer', 'km_excedente_centavos' => 'integer', 'proxima_revisao_km' => 'integer',
            'ipva_vencimento' => 'date', 'licenciamento_vencimento' => 'date', 'seguro_vencimento' => 'date'];
    }

    public function reservas()
    {
        return $this->hasMany(MobilidadeReserva::class, 'frota_id');
    }

    public function nomeCompleto(): string
    {
        return trim(implode(' ', array_filter([$this->marca, $this->descricao, $this->ano, $this->cor ? '· '.$this->cor : null])));
    }

    /** Preço de tabela do plano, em centavos. */
    public function preco(string $plano): int
    {
        return match ($plano) {
            'semanal' => $this->semanal_centavos,
            'mensal' => $this->mensal_centavos,
            default => $this->diaria_centavos,
        };
    }

    /** Documentos vencidos ou vencendo nos próximos $dias: [rótulo => data]. */
    public function documentosVencendo(int $dias = 30): array
    {
        $limite = today()->addDays($dias);
        $lista = [];
        foreach (self::DOCUMENTOS as $campo => $rotulo) {
            if ($this->$campo && $this->$campo->lte($limite)) {
                $lista[$rotulo] = $this->$campo;
            }
        }

        return $lista;
    }

    public function revisaoProxima(): bool
    {
        return $this->proxima_revisao_km !== null && $this->km >= $this->proxima_revisao_km - 500;
    }
}
