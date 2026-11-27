<x-app titulo="WhatsApp">
    <h1 class="mb-1">WhatsApp</h1>
    <p class="mb-5 text-suave">Respostas dos clientes às mensagens automáticas e o que foi enviado. A conversa completa continua no celular da oficina.</p>

    @php
        $rotulo = match (true) {
            ! $ligado => ['WhatsApp automático desligado', 'Os botões abrem o WhatsApp com a mensagem pronta. Para ligar, veja docs/whatsapp.md.', 'border-linha bg-fundo'],
            $estado === 'open' => ['Conectado', 'As mensagens automáticas estão saindo pelo número da oficina.', 'border-[#bfe3c8] bg-[#eefaf1]'],
            $estado === null => ['Conexão ainda não conferida', 'O sistema confere a cada 10 minutos. Se o celular ainda não foi conectado, use o botão abaixo.', 'border-linha bg-fundo'],
            default => ['Desconectado', 'Leia o QR Code de novo com o celular da oficina. O que falhar é reenviado quando voltar.', 'border-[#f0d58a] bg-[#fff7df]'],
        };
    @endphp
    <div class="mb-5 rounded-md border px-4 py-3 {{ $rotulo[2] }}">
        <div class="font-semibold">{{ $rotulo[0] }}@if($ligado && $estadoEm)<span class="ml-2 text-sm font-normal text-suave">conferido {{ $estadoEm->diffForHumans() }}</span>@endif</div>
        <div class="text-sm text-suave">{{ $rotulo[1] }}</div>
        @if ($ligado && $estado === 'open')
            @can('empresa.configurar')
                <form method="post" action="{{ route('whatsapp.teste') }}" class="mt-3 flex flex-wrap gap-2">@csrf
                    <input name="numero" class="input w-auto" placeholder="Celular para o teste" inputmode="tel" maxlength="20" required>
                    <button class="btn">Enviar mensagem de teste</button>
                </form>
            @endcan
        @endif
        @if ($ligado && $estado !== 'open')
            @can('empresa.configurar')
                <a href="{{ route('whatsapp.conectar') }}" class="btn-primario mt-3 inline-flex">Conectar WhatsApp (QR Code)</a>
            @endcan
        @endif
    </div>

    <div class="card mb-6 overflow-x-auto">
        <div class="border-b border-linha px-5 py-3"><h2>Respostas dos clientes</h2></div>
        <table class="tabela">
            <thead><tr><th>Quando</th><th>Cliente</th><th>Mensagem</th><th>O sistema fez</th></tr></thead>
            <tbody>
            @forelse ($recebidas as $m)
                <tr @class(['bg-[#fff7df]' => ! $m->vista_em && $m->acao === 'pediu_remarcar'])>
                    <td class="whitespace-nowrap">{{ $m->created_at->format('d/m H:i') }}</td>
                    <td>
                        @if ($m->cliente)<a href="{{ route('clientes.show', $m->cliente_id) }}">{{ $m->cliente->nome }}</a>@else{{ $m->nome ?: 'Sem cadastro' }}@endif
                        <div class="text-xs text-suave">{{ $m->de }}</div>
                    </td>
                    <td class="max-w-md whitespace-pre-line">{{ $m->texto }}</td>
                    <td>{{ \App\Models\MensagemRecebida::ACOES[$m->acao] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-suave">Nenhuma resposta ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card overflow-x-auto">
        <div class="border-b border-linha px-5 py-3"><h2>Mensagens automáticas enviadas</h2></div>
        <table class="tabela">
            <thead><tr><th>Quando</th><th>Para</th><th>Mensagem</th><th>Situação</th></tr></thead>
            <tbody>
            @forelse ($enviadas as $m)
                <tr>
                    <td class="whitespace-nowrap">{{ $m->created_at->format('d/m H:i') }}</td>
                    <td class="whitespace-nowrap">{{ $m->destino }}</td>
                    <td class="max-w-md truncate" title="{{ $m->texto }}">{{ \Illuminate\Support\Str::limit($m->texto, 90) }}</td>
                    <td class="whitespace-nowrap">
                        @if ($m->status === 'falhou')<span class="text-erro">Falhou</span><div class="text-xs text-suave">{{ $m->erro }}</div>
                        @elseif ($m->lida_em)<span class="text-ok">Lida</span>
                        @elseif ($m->entregue_em)Entregue
                        @elseif ($m->status === 'enviada')Enviada
                        @else{{ ucfirst($m->status) }}@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-suave">Nenhuma mensagem enviada ainda.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-app>
