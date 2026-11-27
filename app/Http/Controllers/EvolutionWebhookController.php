<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\WebhookRecebido;
use App\Services\WhatsApp\Recebidas;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook da Evolution API (sem login e sem CSRF). Protegido pelo token EVOLUTION_WEBHOOK_TOKEN,
 * que vem em ?token= na URL ou no cabeçalho X-Webhook-Token. A empresa é achada pelo nome da
 * instância e fixada com Tenant::com antes de qualquer leitura ou gravação.
 * Responde 200 rápido sempre que o token confere, para a Evolution não ficar reenviando.
 */
class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, Recebidas $recebidas)
    {
        $esperado = (string) config('whatsapp.webhook_token');
        $veio = (string) ($request->query('token') ?: $request->header('X-Webhook-Token'));
        if ($esperado === '' || ! hash_equals($esperado, $veio)) {
            abort(403);
        }

        $evento = str_replace('_', '.', mb_strtolower((string) $request->input('event')));
        $instancia = (string) $request->input('instance');
        $data = (array) $request->input('data', []);
        $empresa = $instancia !== '' ? Empresa::where('whatsapp_instancia', $instancia)->first() : null;
        if (! $empresa) {
            return response()->json(['ok' => true, 'ignorado' => 'instância desconhecida']);
        }

        try {
            Tenant::com($empresa->id, function () use ($evento, $data, $empresa, $recebidas) {
                match ($evento) {
                    'connection.update' => $recebidas->conexao($empresa, $data),
                    'messages.update' => $recebidas->status($data),
                    'messages.upsert' => $this->unica('upsert', $data) && $recebidas->mensagem($empresa, $data),
                    default => null,
                };
            });
        } catch (\Throwable $e) {
            Log::error('Webhook da Evolution falhou', ['empresa' => $empresa->id, 'evento' => $evento, 'erro' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /** Registra o evento (sem o conteúdo da mensagem) e diz se é a primeira vez que chega. */
    private function unica(string $tipo, array $data): bool
    {
        $id = $data['key']['id'] ?? null;
        if (! $id) {
            return true;
        }
        $reg = WebhookRecebido::firstOrCreate(
            ['provedor' => 'evolution', 'evento_id' => mb_substr($tipo.'|'.$id, 0, 80)],
            ['evento' => $tipo, 'payload' => ['fromMe' => (bool) ($data['key']['fromMe'] ?? false)], 'processado_em' => now()],
        );

        return $reg->wasRecentlyCreated;
    }
}
