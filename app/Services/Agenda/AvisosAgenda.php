<?php

namespace App\Services\Agenda;

use App\Models\AgendaSolicitacao;
use App\Models\Agendamento;
use App\Models\Empresa;
use App\Services\WhatsApp\WhatsApp;
use App\Support\Placa;

/**
 * Mensagens de WhatsApp do agendamento (Evolution API). Sem WhatsApp ligado,
 * nada é enviado e o pedido segue normal na agenda.
 */
class AvisosAgenda
{
    public function __construct(private WhatsApp $whatsapp) {}

    /** Ao cliente: pedido recebido. */
    public function pedidoRecebido(Empresa $empresa, AgendaSolicitacao $s): bool
    {
        $texto = 'Olá, '.$this->primeiroNome($s->nomeContato()).'! Recebemos seu pedido de agendamento na '.$empresa->nome_fantasia
            .' para '.$s->quandoPedido().($s->servico ? ' ('.$s->servico.')' : '').'. Vamos confirmar o horário por aqui.';

        return $this->whatsapp->enviar($empresa, $s->telefoneContato(), $texto, 'agenda', $s->id);
    }

    /** À equipe (números em WHATSAPP_AVISOS): chegou pedido novo. */
    public function avisarOficina(Empresa $empresa, AgendaSolicitacao $s): int
    {
        $linhas = array_filter([
            'Novo pedido de agendamento '.match ($s->origem) { 'site' => 'pelo site', 'whatsapp' => 'pelo WhatsApp (respondeu ao lembrete)', default => 'pela área do cliente' },
            $s->nomeContato().' · '.$s->telefoneContato(),
            trim(($s->carro ?: $s->veiculo?->modelo).' '.Placa::formatar($s->placa ?: $s->veiculo?->placa)) ?: null,
            'Serviço: '.$s->servico,
            'Quando: '.$s->quandoPedido(),
            $s->observacao ? 'Obs.: '.$s->observacao : null,
            'Responder na agenda: '.route('agenda.index'),
        ]);
        $enviados = 0;
        foreach (array_filter(array_map('trim', explode(',', (string) config('whatsapp.avisos')))) as $numero) {
            $enviados += (int) $this->whatsapp->enviar($empresa, $numero, implode("\n", $linhas), 'aviso_equipe', $s->id);
        }

        return $enviados;
    }

    /** Ao cliente: resposta da recepção (confirmado, outro horário ou recusa). */
    public function resposta(Empresa $empresa, AgendaSolicitacao $s, ?Agendamento $a): bool
    {
        $nome = $this->primeiroNome($s->nomeContato());
        $endereco = trim(implode(', ', array_filter([config('oficina.endereco.rua'), config('oficina.endereco.cidade')])), ', ');
        $texto = match (true) {
            $a && $a->status === 'confirmado' => 'Olá, '.$nome.'! Seu horário na '.$empresa->nome_fantasia.' está confirmado: '
                .$a->inicio->translatedFormat('l, d/m').' às '.$a->inicio->format('H:i').'.'.($endereco ? ' Endereço: '.$endereco.'.' : '')
                .' Se precisar remarcar, é só responder.',
            $a && $a->status === 'proposto' && $s->origem === 'whatsapp' => 'Olá, '.$nome.'! Podemos atender '
                .$a->inicio->translatedFormat('l, d/m').' às '.$a->inicio->format('H:i').' para '.$s->servico.'. Responda aqui para confirmar.',
            $a && $a->status === 'proposto' => 'Olá, '.$nome.'! Para '.$s->quandoPedido().' não temos horário livre. Podemos atender '
                .$a->inicio->translatedFormat('l, d/m').' às '.$a->inicio->format('H:i').'?'
                .($s->doSite() ? ' Responda aqui para confirmar.' : ' Responda aqui ou confirme na área do cliente.'),
            default => 'Olá, '.$nome.'! Infelizmente não conseguimos atender o pedido para '.$s->quandoPedido()
                .'. Responda esta mensagem que combinamos outro dia.',
        };

        return $this->whatsapp->enviar($empresa, $s->telefoneContato(), $texto, 'agenda', $s->id);
    }

    private function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0] ?: 'tudo bem';
    }
}
