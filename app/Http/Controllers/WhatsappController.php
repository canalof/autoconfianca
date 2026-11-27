<?php

namespace App\Http\Controllers;

use App\Models\MensagemRecebida;
use App\Models\MensagemWhatsapp;
use App\Services\WhatsApp\WhatsApp;
use Illuminate\Http\Request;

/** Conexão do WhatsApp da oficina, respostas dos clientes e mensagens automáticas enviadas. */
class WhatsappController extends Controller
{
    public function index(WhatsApp $whatsapp)
    {
        $this->authorize('agenda.ver');
        $empresa = auth()->user()->empresa;
        $recebidas = MensagemRecebida::with('cliente')->latest('id')->limit(100)->get();
        MensagemRecebida::whereNull('vista_em')->whereKey($recebidas->pluck('id'))->update(['vista_em' => now()]);

        return view('whatsapp.index', [
            'ligado' => $whatsapp->disponivel($empresa),
            'estado' => $empresa->whatsapp_estado,
            'estadoEm' => $empresa->whatsapp_estado_em,
            'recebidas' => $recebidas,
            'enviadas' => MensagemWhatsapp::where('tipo', '!=', 'aviso_equipe')->latest('id')->limit(50)->get(),
        ]);
    }

    /** Tela para conectar o celular da oficina lendo o QR Code (ou com código de 8 dígitos). */
    public function conectar(WhatsApp $whatsapp)
    {
        $this->authorize('empresa.configurar');
        $empresa = auth()->user()->empresa;
        abort_unless($whatsapp->disponivel($empresa), 404);

        return view('whatsapp.conectar', ['instancia' => $empresa->whatsapp_instancia]);
    }

    /** Chamado pela tela a cada poucos segundos: QR novo, código ou "conectado". */
    public function qr(Request $request, WhatsApp $whatsapp)
    {
        $this->authorize('empresa.configurar');
        $empresa = auth()->user()->empresa;
        abort_unless($whatsapp->disponivel($empresa), 404);
        $numero = $request->validate(['numero' => ['nullable', 'string', 'max:20']])['numero'] ?? null;
        $r = $request->boolean('so_estado') ? ['estado' => $whatsapp->estado($empresa) ?? 'connecting'] : $whatsapp->conectar($empresa, $numero);
        if (($r['estado'] ?? null) === 'open' && $empresa->whatsapp_estado !== 'open') {
            $empresa->forceFill(['whatsapp_estado' => 'open', 'whatsapp_estado_em' => now()])->save();
            $whatsapp->reenviarFalhas($empresa, 24, 5);
        }

        return response()->json($r)->header('Cache-Control', 'no-store');
    }

    /** Mensagem de teste para conferir se o WhatsApp automático está saindo. */
    public function teste(Request $request, WhatsApp $whatsapp)
    {
        $this->authorize('empresa.configurar');
        $empresa = auth()->user()->empresa;
        abort_unless($whatsapp->disponivel($empresa), 404);
        $dados = $request->validate(['numero' => ['required', 'string', 'max:20']]);
        abort_unless(WhatsApp::numero($dados['numero']), 422, 'Celular inválido.');
        $ok = $whatsapp->enviar($empresa, $dados['numero'],
            'Teste do sistema '.$empresa->nome_fantasia.': o WhatsApp automático está funcionando.', 'teste');

        return back()->with($ok ? 'ok' : 'erro', $ok ? 'Mensagem de teste enviada.' : 'Não foi possível enviar. Veja o motivo na lista abaixo.');
    }
}
