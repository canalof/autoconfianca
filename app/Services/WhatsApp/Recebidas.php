<?php

namespace App\Services\WhatsApp;

use App\Models\AgendaSolicitacao;
use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Lembrete;
use App\Models\MensagemRecebida;
use App\Models\MensagemWhatsapp;
use App\Services\Agenda\AvisosAgenda;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O que chega da Evolution pelo webhook, já dentro da empresa certa (Tenant definido).
 *
 * - connection.update: guarda se o celular está conectado; ao reconectar, reenvia o que falhou.
 * - messages.update: marca entregue / lida nas mensagens enviadas.
 * - messages.upsert: guarda a mensagem do cliente e entende as respostas mais comuns:
 *   PARAR (não recebe mais lembretes), confirmação ou remarcação do horário de amanhã,
 *   e "quero agendar" em resposta a um lembrete de revisão (vira pedido na agenda).
 */
class Recebidas
{
    public function __construct(private WhatsApp $whatsapp, private AvisosAgenda $avisos) {}

    public function conexao(Empresa $empresa, array $data): void
    {
        $estado = WhatsApp::normalizarEstado($data['state'] ?? $data['status'] ?? null);
        if (! $estado) {
            return;
        }
        $antes = $empresa->whatsapp_estado;
        $empresa->forceFill(['whatsapp_estado' => $estado, 'whatsapp_estado_em' => now()])->save();
        if ($estado === 'open' && $antes && $antes !== 'open') {
            $this->whatsapp->reenviarFalhas($empresa, 24, 5);
        }
    }

    public function status(array $data): void
    {
        foreach (array_is_list($data) ? $data : [$data] as $d) {
            $id = $d['keyId'] ?? $d['key']['id'] ?? $d['messageId'] ?? $d['id'] ?? null;
            $st = $d['status'] ?? $d['update']['status'] ?? null;
            if (! $id || $st === null) {
                continue;
            }
            $st = is_numeric($st) ? (int) $st : mb_strtoupper((string) $st);
            $lida = in_array($st, ['READ', 'PLAYED', 4, 5], true);
            $entregue = $lida || in_array($st, ['DELIVERY_ACK', 3], true);
            if (! $entregue) {
                continue;
            }
            $m = MensagemWhatsapp::where('externo_id', mb_substr((string) $id, 0, 80))->first();
            if ($m) {
                $m->forceFill(array_filter([
                    'entregue_em' => $m->entregue_em ?? now(),
                    'lida_em' => $lida ? ($m->lida_em ?? now()) : null,
                ]))->save();
            }
        }
    }

    public function mensagem(Empresa $empresa, array $data): ?MensagemRecebida
    {
        $key = $data['key'] ?? [];
        if (! empty($key['fromMe']) || empty($key['id'])) {
            return null;
        }
        $de = self::numeroDoJid($key);
        if (! $de || self::daEquipe($de)) {
            return null;
        }
        if (MensagemRecebida::where('externo_id', mb_substr((string) $key['id'], 0, 80))->exists()) {
            return null; // a Evolution pode mandar o mesmo evento mais de uma vez
        }
        $texto = self::texto($data);
        $variantes = WhatsApp::variantes($de);
        $cliente = $this->cliente($variantes);

        $recebida = MensagemRecebida::create([
            'cliente_id' => $cliente?->id, 'de' => $de, 'nome' => self::curto($data['pushName'] ?? null, 120),
            'texto' => mb_substr($texto, 0, 4000), 'externo_id' => mb_substr((string) $key['id'], 0, 80),
        ]);
        $this->entender($empresa, $recebida, $cliente, $variantes);

        return $recebida;
    }

    private function entender(Empresa $empresa, MensagemRecebida $r, ?Cliente $cliente, array $variantes): void
    {
        $t = self::normalizar($r->texto);
        if ($t === '') {
            return;
        }

        if (self::querParar($t)) {
            $this->clientesDoNumero($variantes)->each(fn (Cliente $c) => $c->update(['aceita_whatsapp' => false]));
            if ($cliente) {
                $cliente->aceita_whatsapp = false;
            }
            $r->update(['acao' => 'parou']);
            $this->responder($empresa, $r, 'Pronto, você não vai mais receber lembretes da '.$empresa->nome_fantasia
                .' por aqui. Se quiser voltar a receber, é só avisar.');

            return;
        }

        // Respondendo a quê? A última mensagem automática para este número nos últimos 7 dias.
        $ultima = MensagemWhatsapp::whereIn('destino', $variantes)->where('status', 'enviada')
            ->whereIn('tipo', ['agenda', 'agenda_vespera', 'lembrete', 'lembrete_2'])
            ->where('created_at', '>', now()->subDays(7))->latest('id')->first();
        if (! $ultima) {
            return;
        }
        $negativo = self::negativo($t);
        $positivo = ! $negativo && self::positivo($t);

        if ($ultima->tipo === 'agenda') {
            $this->respostaProposta($empresa, $r, (int) $ultima->referencia_id, $positivo, $negativo);

            return;
        }

        if ($ultima->tipo === 'agenda_vespera') {
            $a = Agendamento::find($ultima->referencia_id);
            if (! $a || ! in_array($a->status, ['agendado', 'confirmado'], true)) {
                return;
            }
            if ($negativo) {
                $r->update(['acao' => 'pediu_remarcar', 'referencia_id' => $a->id]);
                $this->avisarEquipe($empresa, $r->id, implode("\n", [
                    'Cliente quer remarcar o horário de '.$a->inicio->format('d/m H:i'),
                    $a->nome.' · '.$r->de, 'Mensagem: '.mb_substr($r->texto, 0, 300), 'Agenda: '.route('agenda.index'),
                ]));
                $this->responder($empresa, $r, 'Tudo bem! A recepção vai falar com você para combinar outro horário.');
            } elseif ($positivo) {
                if ($a->status === 'agendado') {
                    $a->update(['status' => 'confirmado']);
                }
                $r->update(['acao' => 'confirmou', 'referencia_id' => $a->id]);
                $this->responder($empresa, $r, 'Obrigado! Seu horário de amanhã às '.$a->inicio->format('H:i').' está confirmado.');
            }

            return;
        }

        // Resposta a lembrete de revisão / troca de óleo
        $lembrete = Lembrete::with(['veiculo', 'cliente'])->find($ultima->referencia_id);
        if (! $lembrete || $negativo || ! ($positivo || str_contains($t, 'agend'))) {
            return;
        }
        $aberto = AgendaSolicitacao::where('origem', 'whatsapp')->where('status', 'pendente')
            ->where('veiculo_id', $lembrete->veiculo_id)->where('created_at', '>', now()->subDays(7))->first();
        if ($aberto) {
            $r->update(['acao' => 'pediu_agendamento', 'referencia_id' => $aberto->id]);

            return;
        }
        $s = AgendaSolicitacao::create([
            'cliente_id' => $lembrete->cliente_id, 'veiculo_id' => $lembrete->veiculo_id,
            'preferencia' => self::proximoDiaUtil()->setTime(8, 0), 'servico' => mb_substr($lembrete->descricao, 0, 200),
            'status' => 'pendente', 'origem' => 'whatsapp', 'telefone' => $r->de,
            'placa' => $lembrete->veiculo?->placa, 'observacao' => mb_substr('Respondeu ao lembrete pelo WhatsApp: "'.$r->texto.'"', 0, 1000),
        ]);
        $r->update(['acao' => 'pediu_agendamento', 'referencia_id' => $s->id]);
        $this->avisos->avisarOficina($empresa, $s);
        $this->responder($empresa, $r, 'Ótimo! Já avisamos a recepção. Qual o melhor dia e horário para você?');
    }

    /** Horário que a recepção propôs (pedido do site, da área do cliente ou do WhatsApp): o cliente aceita ou recusa respondendo. */
    private function respostaProposta(Empresa $empresa, MensagemRecebida $r, int $solicitacaoId, bool $positivo, bool $negativo): void
    {
        if (! $positivo && ! $negativo) {
            return;
        }
        $feito = DB::transaction(function () use ($solicitacaoId, $positivo) {
            $s = AgendaSolicitacao::lockForUpdate()->find($solicitacaoId);
            $a = $s?->agendamento_id ? Agendamento::lockForUpdate()->find($s->agendamento_id) : null;
            if (! $s || ! $a || $s->status !== 'proposta' || $a->status !== 'proposto' || ! $a->inicio->isFuture()) {
                return null;
            }
            $a->update(['status' => $positivo ? 'confirmado' : 'cancelado']);
            $s->update(['status' => $positivo ? 'confirmada' : 'recusada']);

            return $a;
        }, 3);
        if (! $feito) {
            return;
        }
        if ($positivo) {
            $r->update(['acao' => 'confirmou', 'referencia_id' => $feito->id]);
            $this->responder($empresa, $r, 'Combinado! Seu horário está confirmado: '.$feito->inicio->translatedFormat('l, d/m').' às '.$feito->inicio->format('H:i').'.');
        } else {
            $r->update(['acao' => 'pediu_remarcar', 'referencia_id' => $feito->id]);
            $this->avisarEquipe($empresa, $r->id, implode("\n", [
                'Cliente recusou o horário proposto de '.$feito->inicio->format('d/m H:i'),
                $feito->nome.' · '.$r->de, 'Mensagem: '.mb_substr($r->texto, 0, 300), 'Agenda: '.route('agenda.index'),
            ]));
            $this->responder($empresa, $r, 'Tudo bem! A recepção vai falar com você para combinar outro horário.');
        }
    }

    private function responder(Empresa $empresa, MensagemRecebida $r, string $texto): void
    {
        $this->whatsapp->enviar($empresa, $r->de, $texto, 'resposta', $r->id);
    }

    private function avisarEquipe(Empresa $empresa, int $ref, string $texto): void
    {
        foreach (array_filter(array_map('trim', explode(',', (string) config('whatsapp.avisos')))) as $numero) {
            $this->whatsapp->enviar($empresa, $numero, $texto, 'aviso_equipe', $ref);
        }
    }

    /** Um único cliente com este celular (whatsapp ou telefone). Dois cadastros com o mesmo número = nenhum. */
    private function cliente(array $variantes): ?Cliente
    {
        $achados = $this->clientesDoNumero($variantes, 2);

        return $achados->count() === 1 ? $achados->first() : null;
    }

    /** Clientes cujo WhatsApp ou telefone é este número (o cadastro guarda com máscara). */
    private function clientesDoNumero(array $variantes, ?int $limite = null): \Illuminate\Support\Collection
    {
        if (! $variantes) {
            return collect();
        }

        return Cliente::where(function ($q) use ($variantes) {
            foreach (['whatsapp', 'telefone'] as $col) {
                $q->orWhereIn(DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$col}, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')"), $variantes);
            }
        })->when($limite, fn ($q) => $q->limit($limite))->get();
    }

    private static function daEquipe(string $numero): bool
    {
        foreach (array_filter(array_map('trim', explode(',', (string) config('whatsapp.avisos')))) as $n) {
            if (array_intersect(WhatsApp::variantes($n), WhatsApp::variantes($numero))) {
                return true;
            }
        }

        return false;
    }

    /** Número de quem mandou. Grupos, status e canais são ignorados. */
    public static function numeroDoJid(array $key): ?string
    {
        foreach ([$key['remoteJid'] ?? null, $key['remoteJidAlt'] ?? null, $key['senderPn'] ?? null] as $jid) {
            $jid = (string) $jid;
            if (str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@c.us')) {
                $n = preg_replace('/\D/', '', strstr($jid, '@', true));

                return strlen($n) >= 10 && strlen($n) <= 15 ? $n : null;
            }
        }

        return null;
    }

    public static function texto(array $data): string
    {
        $m = $data['message'] ?? [];
        $t = $m['conversation'] ?? $m['extendedTextMessage']['text'] ?? $m['buttonsResponseMessage']['selectedDisplayText']
            ?? $m['listResponseMessage']['title'] ?? $m['imageMessage']['caption'] ?? $m['videoMessage']['caption'] ?? null;
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }

        return match ($data['messageType'] ?? null) {
            'audioMessage' => '[áudio]',
            'imageMessage' => '[foto]',
            'videoMessage' => '[vídeo]',
            'documentMessage', 'documentWithCaptionMessage' => '[documento]',
            'stickerMessage' => '[figurinha]',
            'locationMessage' => '[localização]',
            'reactionMessage' => '[reação] '.($m['reactionMessage']['text'] ?? ''),
            default => '[mensagem]',
        };
    }

    public static function normalizar(string $texto): string
    {
        $t = str_replace('👍', ' sim ', $texto);
        $t = mb_strtolower(Str::ascii($t));
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public static function querParar(string $t): bool
    {
        return (bool) preg_match('/^(parar|pare|para|sair|stop|descadastrar|remover)$/', $t)
            || (bool) preg_match('/\b(nao quero (mais )?receber|parar de receber|nao me mande|nao mande mais|para de mandar|parem de mandar)\b/', $t);
    }

    public static function negativo(string $t): bool
    {
        return (bool) preg_match('/\b(remarc\w*|desmarc\w*|cancel\w*|adiar|outro dia|outro horario|nao (vou|posso|consigo|da|vai dar|poderei|conseguirei)|nao)\b/', $t)
            && ! preg_match('/^(sim|pode|ok)\b.*\bnao\b/', $t);
    }

    public static function positivo(string $t): bool
    {
        return (bool) preg_match('/^(sim|s|ok|okay|confirm\w*|pode|certo|combinado|beleza|blz|positivo|com certeza|claro|vou|estarei|estaremos|quero|agend\w*|bora|fechado|perfeito|isso|show|top|tudo certo|ta bom|ta certo)\b/', $t);
    }

    private static function proximoDiaUtil(): \Carbon\Carbon
    {
        $d = today()->addDay();
        while ($d->isSunday()) {
            $d->addDay();
        }

        return $d;
    }

    private static function curto(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
