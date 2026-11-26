@php
    use App\Models\LocadoraMulta;
    use App\Models\MobilidadeReserva;
    use App\Support\Dinheiro;
    use App\Support\Placa;
    $r = $reserva;
    $combustivel = ['Vazio', '¼', '½', '¾', 'Cheio'];
@endphp
<x-app :titulo="'Contrato #'.$r->id">
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-suave"><a class="underline" href="{{ route('locacao.index') }}">Contratos</a> · {{ MobilidadeReserva::MODALIDADES[$r->modalidade] }}</p>
            <h1>Contrato #{{ $r->id }} · {{ $r->cliente?->nome ?? 'Cliente não informado' }}</h1>
        </div>
        <span @class(['rounded-full px-3 py-1 text-sm font-semibold',
            'bg-erro text-white' => $r->atrasado(),
            'bg-[#e6eef8] text-[#1f4f82]' => ! $r->atrasado() && $r->status === 'entregue',
            'bg-[#fff1d6] text-[#7a4b00]' => $r->status === 'reservada',
            'bg-fundo text-suave' => in_array($r->status, ['devolvida', 'cancelada'])])>{{ $r->atrasado() ? 'Atrasado' : MobilidadeReserva::SITUACOES[$r->status] }}</span>
    </div>
    @if (session('erro'))<p class="erro-msg mb-3" role="alert">{{ session('erro') }}</p>@endif
    @if ($errors->any())<p class="erro-msg mb-3" role="alert">{{ $errors->first() }}</p>@endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <section class="card card-corpo">
                <dl class="grid gap-x-6 gap-y-3 text-[15px] sm:grid-cols-2">
                    <div><dt class="text-sm text-suave">Carro</dt><dd class="flex items-center gap-2">
                        @if ($r->frota)<x-placa :placa="$r->frota->placa" /> <span>{{ $r->frota->nomeCompleto() }}</span>@else Parceira: {{ $r->parceira }}{{ $r->contrato_externo ? ' · contrato '.$r->contrato_externo : '' }}@endif
                    </dd></div>
                    <div><dt class="text-sm text-suave">Condutor</dt><dd>{{ $r->condutor?->nome }}{{ $r->condutor?->categoria ? ' · CNH '.$r->condutor->categoria : '' }}</dd></div>
                    <div><dt class="text-sm text-suave">Período</dt><dd>{{ $r->inicio->format('d/m/Y H:i') }} a {{ $r->fim->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-sm text-suave">Plano</dt><dd>{{ MobilidadeReserva::PLANOS[$r->plano] ?? $r->plano }} · {{ $r->valorPeriodo() ? Dinheiro::formatar($r->valorPeriodo()) : 'cortesia' }}</dd></div>
                    <div><dt class="text-sm text-suave">Quilometragem</dt><dd>{{ $r->km_livre ? number_format($r->km_livre, 0, ',', '.').' km livres por dia · excedente '.Dinheiro::formatar($r->km_excedente_centavos).'/km' : 'Livre' }}</dd></div>
                    <div><dt class="text-sm text-suave">Caução</dt><dd>{{ $r->caucao_centavos ? Dinheiro::formatar($r->caucao_centavos) : '—' }}</dd></div>
                    @if ($r->ordemServico)<div><dt class="text-sm text-suave">OS da oficina</dt><dd><a class="underline" href="{{ route('os.show', $r->ordemServico) }}">{{ $r->ordemServico->numeroFormatado() }}</a></dd></div>@endif
                    @if ($r->cliente)<div><dt class="text-sm text-suave">Cliente</dt><dd><a class="underline" href="{{ route('clientes.show', $r->cliente) }}">{{ $r->cliente->nome }}</a>{{ ($fone = $r->cliente->whatsapp ?: $r->cliente->telefone) ? ' · '.$fone : '' }}</dd></div>@endif
                </dl>
            </section>

            @foreach (['entrega' => 'Saída', 'devolucao' => 'Devolução'] as $etapa => $rotulo)
                @if ($r->$etapa)
                    <section class="card card-corpo text-[15px]">
                        <h2 class="mb-2">{{ $rotulo }} · {{ \Carbon\Carbon::parse($r->$etapa['quando'])->format('d/m/Y H:i') }}</h2>
                        <p>{{ number_format($r->$etapa['km'], 0, ',', '.') }} km · combustível {{ $combustivel[$r->$etapa['combustivel']] ?? $r->$etapa['combustivel'] }} · aceite de {{ $r->$etapa['aceite_nome'] }}</p>
                        <p class="mt-1 whitespace-pre-line text-suave">{{ $r->$etapa['vistoria'] }}</p>
                        @if ($etapa === 'devolucao' && $r->entrega)<p class="mt-1">Rodou {{ number_format($r->devolucao['km'] - $r->entrega['km'], 0, ',', '.') }} km.</p>@endif
                    </section>
                @endif
            @endforeach
            @if ($r->ocorrencias)<section class="card card-corpo text-[15px]"><h2 class="mb-2">Ocorrências</h2><p class="whitespace-pre-line">{{ $r->ocorrencias }}</p></section>@endif

            @can('locacao.editar')
                @if ($r->status === 'reservada')
                    @include('locacao._acao-reserva', ['reserva' => $r, 'acao' => 'entregar'])
                @elseif ($r->status === 'entregue')
                    @include('locacao._acao-reserva', ['reserva' => $r, 'acao' => 'devolver'])
                @endif
            @endcan

            <section class="card card-corpo space-y-3">
                @include('mobilidade._anexos', ['registro' => $r, 'vinculo' => 'reserva_id', 'permissao' => 'locacao.editar', 'etapas' => array_filter(['entrega' => $r->entrega ? 'entrega' : null, 'devolucao' => $r->devolucao ? 'devolucao' : null]), 'fotos' => $fotos])
                @include('mobilidade._gerar', ['registro' => $r, 'vinculo' => 'reserva_id', 'permissao' => 'locacao.editar', 'documentosDisponiveis' => array_values(array_filter([$r->ehAluguel() || $r->tipo === 'parceira' ? 'locacao' : 'carro_reserva', $r->entrega ? 'vistoria' : null, $r->devolucao ? 'devolucao' : null]))])
            </section>
        </div>

        <div class="space-y-4">
            @can('locacao.editar')
                @if (in_array($r->status, ['reservada', 'entregue']))
                    <section class="card card-corpo space-y-3">
                        <h2>Prorrogar</h2>
                        <form method="post" action="{{ route('locacao.reserva.acao', [$r, 'prorrogar']) }}" class="space-y-2">
                            @csrf
                            <x-campo nome="fim" rotulo="Nova devolução" tipo="datetime-local" :valor="$r->fim->copy()->addDays($r->plano === 'mensal' ? 30 : ($r->plano === 'semanal' ? 7 : 1))->format('Y-m-d\TH:i')" required />
                            <button class="btn btn-sm">Prorrogar</button>
                        </form>
                        @if ($r->status === 'reservada')
                            <form method="post" action="{{ route('locacao.reserva.acao', [$r, 'cancelar']) }}">@csrf<button class="btn btn-sm btn-perigo">Cancelar reserva</button></form>
                        @endif
                    </section>
                @endif
            @endcan
            @can('financeiro.ver')
                <section class="card">
                    <div class="border-b border-linha px-5 py-3"><h2>Cobranças</h2></div>
                    <ul class="divide-y divide-linha text-[15px]">
                        @forelse ($r->titulos as $t)
                            <li class="flex items-center gap-3 px-5 py-2.5">
                                <span class="min-w-0 flex-1"><span class="block truncate">{{ $t->descricao }}</span><span class="text-sm text-suave">vence {{ $t->vencimento->format('d/m/Y') }}</span></span>
                                <span class="text-right"><span class="block font-semibold">{{ Dinheiro::formatar($t->valor_centavos) }}</span>
                                <span @class(['text-sm', 'text-ok' => $t->status === 'recebida', 'text-erro' => $t->status === 'aberta' && $t->vencimento->isPast(), 'text-suave' => $t->status === 'aberta' && ! $t->vencimento->isPast()])>{{ $t->status === 'recebida' ? 'Recebida' : ($t->vencimento->isPast() ? 'Vencida' : 'Em aberto') }}</span></span>
                            </li>
                        @empty
                            <li class="px-5 py-4 text-suave">{{ $r->parcelado() ? 'As parcelas são geradas na saída do carro.' : 'A cobrança é gerada na devolução.' }}</li>
                        @endforelse
                    </ul>
                    @if ($r->titulos->where('status', 'aberta')->isNotEmpty() && $r->titulos->first()->area === \App\Support\Area::atual())
                        <div class="border-t border-linha px-5 py-3"><a class="text-sm font-semibold underline" href="{{ route('receber.index') }}">Receber em contas a receber</a></div>
                    @endif
                </section>
            @endcan
            @if ($r->multas->isNotEmpty())
                <section class="card">
                    <div class="border-b border-linha px-5 py-3"><h2>Multas</h2></div>
                    <ul class="divide-y divide-linha text-[15px]">
                        @foreach ($r->multas as $m)
                            <li class="px-5 py-2.5"><span class="block">{{ $m->descricao }} · {{ Dinheiro::formatar($m->valor_centavos) }}</span><span class="text-sm text-suave">{{ $m->data_infracao->format('d/m/Y H:i') }} · {{ LocadoraMulta::SITUACOES[$m->situacao] }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-app>
