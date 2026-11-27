<?php

namespace App\Models;

use App\Models\Concerns\PertenceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lembrete de revisão gerado na entrega do carro (ex.: troca de óleo daqui a 6 meses). */
class Lembrete extends Model
{
    use PertenceAEmpresa;

    protected $fillable = ['veiculo_id', 'cliente_id', 'ordem_servico_id', 'inspecao_id', 'descricao', 'data_prevista', 'km_previsto', 'status', 'enviado_em', 'origem', 'segundo_aviso_em'];

    protected function casts(): array
    {
        return ['data_prevista' => 'date', 'enviado_em' => 'datetime', 'segundo_aviso_em' => 'datetime', 'km_previsto' => 'integer'];
    }

    public function veiculo(): BelongsTo
    {
        return $this->belongsTo(Veiculo::class)->withTrashed();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function mensagem(string $oficina): string
    {
        $nome = $this->cliente ? ', '.explode(' ', trim($this->cliente->nome))[0] : '';
        $km = $this->km_previsto ? ' ou aos '.number_format($this->km_previsto, 0, ',', '.').' km' : '';
        $quando = $this->data_prevista->lt(today()) ? 'venceu em '.$this->data_prevista->format('d/m/Y')
            : 'previsto para '.$this->data_prevista->format('d/m/Y');

        $chamada = $this->data_prevista->lt(today()) ? 'Já está na hora de' : 'Está chegando a hora de';

        return "Olá{$nome}! Aqui é da {$oficina}. {$chamada}: {$this->descricao} do seu "
            .trim($this->veiculo->descricao()).' ('.$this->veiculo->placaFormatada().'), '.$quando.$km
            .'. Quer agendar? É só responder esta mensagem.'.self::RODAPE;
    }

    /** Segundo e último aviso, quando venceu e o carro não voltou. */
    public function mensagemSegundoAviso(string $oficina): string
    {
        $nome = $this->cliente ? ', '.explode(' ', trim($this->cliente->nome))[0] : '';

        return "Olá{$nome}! Passando para lembrar: {$this->descricao} do seu ".trim($this->veiculo->descricao())
            .' ('.$this->veiculo->placaFormatada().') venceu em '.$this->data_prevista->format('d/m/Y')
            .". Quer que a gente reserve um horário na {$oficina}? É só responder.".self::RODAPE;
    }

    public const RODAPE = "\n\nPara não receber mais lembretes, responda PARAR.";
}
