<?php

namespace App\Http\Controllers;

use App\Models\MensagemRecebida;
use App\Models\MensagemWhatsapp;
use App\Services\WhatsApp\WhatsApp;

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
}
