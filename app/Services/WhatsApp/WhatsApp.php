<?php

namespace App\Services\WhatsApp;

use App\Models\Empresa;
use App\Models\MensagemWhatsapp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envio automático pelo WhatsApp usando a Evolution API (opcional).
 * Configure EVOLUTION_URL e EVOLUTION_KEY no .env e o nome da instância em Empresa.
 * Sem isso, o sistema usa os botões que abrem o WhatsApp com a mensagem pronta.
 * O que chega da Evolution (respostas, entregue/lida, conexão) entra pelo EvolutionWebhookController.
 */
class WhatsApp
{
    public function disponivel(Empresa $empresa): bool
    {
        return config('whatsapp.url') && config('whatsapp.chave') && $empresa->whatsapp_instancia
            && ($empresa->plano === null || $empresa->plano->whatsapp_automatico);
    }

    public static function numero(?string $telefone): ?string
    {
        $n = preg_replace('/\D/', '', (string) $telefone);
        if (strlen($n) < 10) {
            return null;
        }

        return strlen($n) <= 11 ? '55'.$n : $n;
    }

    /**
     * Formas do mesmo celular brasileiro para comparar com o cadastro:
     * com e sem 55, com e sem o nono dígito (o WhatsApp às vezes devolve sem ele).
     *
     * @return string[] só dígitos
     */
    public static function variantes(?string $telefone): array
    {
        $n = self::numero($telefone);
        if (! $n || ! str_starts_with($n, '55')) {
            return $n ? [$n] : [];
        }
        $local = substr($n, 2); // DDD + número
        $ddd = substr($local, 0, 2);
        $resto = substr($local, 2);
        $formas = [$local];
        if (strlen($resto) === 9 && $resto[0] === '9') {
            $formas[] = $ddd.substr($resto, 1);
        } elseif (strlen($resto) === 8 && in_array($resto[0], ['6', '7', '8', '9'], true)) {
            $formas[] = $ddd.'9'.$resto;
        }

        return array_values(array_unique(array_merge($formas, array_map(fn ($f) => '55'.$f, $formas))));
    }

    /** Envia e registra a mensagem (mensagens_whatsapp), para conferência e novas tentativas. */
    public function enviar(Empresa $empresa, ?string $telefone, string $texto, string $tipo = 'outro', ?int $referencia = null): bool
    {
        $numero = self::numero($telefone);
        if (! $numero || ! $this->disponivel($empresa)) {
            return false;
        }
        $msg = MensagemWhatsapp::create([
            'empresa_id' => $empresa->id, 'destino' => $numero, 'tipo' => $tipo, 'referencia_id' => $referencia, 'texto' => $texto,
        ]);

        return $this->tentar($empresa, $msg);
    }

    /**
     * Envia sem gravar o texto em mensagens_whatsapp. Só para conteúdo sigiloso,
     * como o código de acesso da área do cliente.
     */
    public function enviarSigiloso(Empresa $empresa, ?string $telefone, string $texto): bool
    {
        $numero = self::numero($telefone);

        return $numero && $this->disponivel($empresa) && $this->postar($empresa, $numero, $texto);
    }

    public function tentar(Empresa $empresa, MensagemWhatsapp $msg): bool
    {
        $ok = $this->postar($empresa, $msg->destino, $msg->texto, $erro, $id);
        $msg->update([
            'status' => $ok ? 'enviada' : 'falhou', 'tentativas' => $msg->tentativas + 1,
            'erro' => $ok ? null : mb_substr((string) $erro, 0, 255), 'enviada_em' => $ok ? now() : null,
            'externo_id' => $ok ? $id : $msg->externo_id,
        ]);

        return $ok;
    }

    /**
     * Nova tentativa das que falharam recentemente (por exemplo, enquanto o celular estava desconectado).
     */
    public function reenviarFalhas(Empresa $empresa, int $horas = 24, int $maxTentativas = 3): int
    {
        $ok = 0;
        foreach (MensagemWhatsapp::where('status', 'falhou')->where('tentativas', '<', $maxTentativas)
            ->where('created_at', '>', now()->subHours($horas))->orderBy('id')->get() as $m) {
            $ok += (int) $this->tentar($empresa, $m);
        }

        return $ok;
    }

    /**
     * Estado da conexão do celular na Evolution: open (conectado), close, connecting.
     * null = não deu para perguntar (Evolution fora do ar ou sem configuração).
     */
    public function estado(Empresa $empresa): ?string
    {
        if (! $this->disponivel($empresa)) {
            return null;
        }
        try {
            $r = Http::timeout(10)->withHeaders(['apikey' => config('whatsapp.chave')])
                ->get(rtrim(config('whatsapp.url'), '/').'/instance/connectionState/'.rawurlencode($empresa->whatsapp_instancia));
            if (! $r->successful()) {
                return $r->status() === 404 ? 'close' : null;
            }

            return self::normalizarEstado($r->json('instance.state') ?? $r->json('state'));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalizarEstado(mixed $estado): ?string
    {
        $e = mb_strtolower(trim((string) $estado));

        return match ($e) {
            'open', 'connected' => 'open',
            'connecting' => 'connecting',
            'close', 'closed', 'disconnected', 'refused' => 'close',
            default => $e !== '' ? mb_substr($e, 0, 20) : null,
        };
    }

    private function postar(Empresa $empresa, string $numero, string $texto, ?string &$erro = null, ?string &$id = null): bool
    {
        try {
            $r = Http::timeout(20)
                ->withHeaders(['apikey' => config('whatsapp.chave')])
                ->post(rtrim(config('whatsapp.url'), '/').'/message/sendText/'.rawurlencode($empresa->whatsapp_instancia), array_filter([
                    'number' => $numero,
                    'text' => $texto,
                    // "digitando…" antes de enviar: parece conversa normal e ajuda a evitar bloqueio do número
                    'delay' => (int) config('whatsapp.digitando_ms', 0) ?: null,
                ]));

            if (! $r->successful()) {
                $erro = 'HTTP '.$r->status();
                Log::warning('WhatsApp não enviado', ['empresa' => $empresa->id, 'status' => $r->status()]);
            } else {
                $id = ($v = $r->json('key.id')) ? mb_substr((string) $v, 0, 80) : null;
            }

            return $r->successful();
        } catch (\Throwable $e) {
            $erro = 'Sem conexão com a Evolution API';
            Log::warning('WhatsApp indisponível', ['empresa' => $empresa->id]);

            return false;
        }
    }
}
