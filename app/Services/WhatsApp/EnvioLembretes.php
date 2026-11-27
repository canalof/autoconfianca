<?php

namespace App\Services\WhatsApp;

use App\Models\Agendamento;
use App\Models\Empresa;
use App\Models\Lembrete;
use App\Models\OrdemServico;
use Illuminate\Support\Collection;

/**
 * Envio diário (torque:lembretes, 9h) dos lembretes de revisão e troca de óleo pelo WhatsApp.
 * Roda com o Tenant já definido.
 *
 * - Vencem nos próximos 7 dias: saem todos.
 * - Atrasados (até 120 dias) e segundos avisos: no máximo WHATSAPP_LEMBRETES_ATRASADOS_POR_DIA por dia,
 *   para não disparar centenas de mensagens de uma vez (o número pode ser bloqueado).
 * - Segundo aviso: uma única vez, 14 dias depois do primeiro, se o carro não voltou nem agendou.
 * - Vários lembretes do mesmo carro saem numa mensagem só.
 * - Não envia para quem pediu para parar (aceita_whatsapp) nem se o carro mudou de dono.
 */
class EnvioLembretes
{
    public const DIAS_ATRASO_MAX = 120;

    public const DIAS_SEGUNDO_AVISO = 14;

    public function __construct(private WhatsApp $whatsapp) {}

    public function executar(Empresa $empresa): int
    {
        $enviados = 0;
        $cota = max(0, (int) config('whatsapp.lembretes_atrasados_por_dia', 20));

        $proximos = Lembrete::with(['cliente', 'veiculo'])->where('status', 'pendente')
            ->whereBetween('data_prevista', [today(), today()->addDays(7)])->orderBy('data_prevista')->get();
        $enviados += $this->enviarGrupos($empresa, $proximos, PHP_INT_MAX);

        $atrasados = Lembrete::with(['cliente', 'veiculo'])->where('status', 'pendente')
            ->whereBetween('data_prevista', [today()->subDays(self::DIAS_ATRASO_MAX), today()->subDay()])
            ->orderByDesc('data_prevista')->get();
        $n = $this->enviarGrupos($empresa, $atrasados, $cota);
        $enviados += $n;
        $cota -= $n;

        if ($cota > 0) {
            $enviados += $this->segundosAvisos($empresa, $cota);
        }

        return $enviados;
    }

    /** @param Collection<int, Lembrete> $lembretes */
    private function enviarGrupos(Empresa $empresa, Collection $lembretes, int $limite): int
    {
        $enviados = 0;
        foreach ($lembretes->filter(fn (Lembrete $l) => $this->podeEnviar($l))->groupBy('veiculo_id') as $grupo) {
            if ($enviados >= $limite) {
                break;
            }
            $primeiro = $grupo->first();
            $texto = (clone $primeiro)->forceFill(['descricao' => self::juntar($grupo->pluck('descricao'))])
                ->mensagem($empresa->nome_fantasia);
            if ($this->whatsapp->enviar($empresa, $this->telefone($primeiro), $texto, 'lembrete', $primeiro->id)) {
                Lembrete::whereKey($grupo->pluck('id'))->update(['status' => 'enviado', 'enviado_em' => now()]);
                $enviados++;
                $this->pausa();
            }
        }

        return $enviados;
    }

    private function segundosAvisos(Empresa $empresa, int $limite): int
    {
        $candidatos = Lembrete::with(['cliente', 'veiculo'])->where('status', 'enviado')->whereNull('segundo_aviso_em')
            ->where('enviado_em', '<=', now()->subDays(self::DIAS_SEGUNDO_AVISO))
            ->whereBetween('data_prevista', [today()->subDays(self::DIAS_ATRASO_MAX + 60), today()->subDays(self::DIAS_SEGUNDO_AVISO)])
            ->orderByDesc('data_prevista')->get();
        $enviados = 0;
        foreach ($candidatos->filter(fn (Lembrete $l) => $this->podeEnviar($l))->groupBy('veiculo_id') as $grupo) {
            if ($enviados >= $limite) {
                break;
            }
            $primeiro = $grupo->first();
            $desde = $grupo->min('enviado_em');
            $voltou = OrdemServico::where('veiculo_id', $primeiro->veiculo_id)->where('created_at', '>=', $desde)->exists()
                || Agendamento::where('veiculo_id', $primeiro->veiculo_id)->where('created_at', '>=', $desde)->exists();
            if ($voltou) {
                Lembrete::whereKey($grupo->pluck('id'))->update(['segundo_aviso_em' => now()]); // não manda: o carro voltou
                continue;
            }
            $texto = (clone $primeiro)->forceFill(['descricao' => self::juntar($grupo->pluck('descricao'))])
                ->mensagemSegundoAviso($empresa->nome_fantasia);
            if ($this->whatsapp->enviar($empresa, $this->telefone($primeiro), $texto, 'lembrete_2', $primeiro->id)) {
                Lembrete::whereKey($grupo->pluck('id'))->update(['segundo_aviso_em' => now()]);
                $enviados++;
                $this->pausa();
            }
        }

        return $enviados;
    }

    private function podeEnviar(Lembrete $l): bool
    {
        $c = $l->cliente;
        $v = $l->veiculo;

        return $c && $c->aceita_whatsapp && ! $c->trashed() && $v && ! $v->trashed()
            && (! $v->cliente_id || $v->cliente_id === $c->id) // carro vendido: o lembrete era do dono anterior
            && $this->telefone($l);
    }

    private function telefone(Lembrete $l): ?string
    {
        return WhatsApp::numero($l->cliente?->whatsapp ?: $l->cliente?->telefone);
    }

    /** "Troca de óleo", "Troca de óleo e Filtro de ar", "A, B e C". */
    private static function juntar(Collection $nomes): string
    {
        $n = $nomes->unique()->values();

        return $n->count() <= 1 ? (string) $n->first() : $n->slice(0, -1)->implode(', ').' e '.$n->last();
    }

    private function pausa(): void
    {
        $s = (int) config('whatsapp.intervalo_segundos', 0);
        if ($s > 0 && ! app()->runningUnitTests()) {
            sleep($s);
        }
    }
}
