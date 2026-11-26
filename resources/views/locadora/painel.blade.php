@php use App\Support\Dinheiro; @endphp
<x-app titulo="Locadora">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1>Locadora</h1>
            <p class="text-suave">{{ now()->translatedFormat('l, d \d\e F') }}</p>
        </div>
        @can('locacao.editar')
            <a href="{{ route('locacao.novo') }}" class="btn-sinal px-5 py-3 text-base">Novo contrato</a>
        @endcan
    </div>

    @php $avisos = collect([
        auth()->user()->can('caixa.operar') && ! $caixa ? ['O caixa da locadora está fechado', route('caixa.index'), 'Abrir caixa'] : null,
        auth()->user()->can('financeiro.ver') && $receberVencido ? [Dinheiro::formatar($receberVencido).' de aluguel vencido', route('receber.index', ['filtro' => 'vencidas']), 'Cobrar'] : null,
    ])->filter(); @endphp
    @if ($avisos->isNotEmpty())
        <div class="mb-4 grid gap-2 sm:grid-cols-2">
            @foreach ($avisos as [$texto, $link, $acao])
                <a href="{{ $link }}" class="flex items-center justify-between gap-3 rounded-md border border-[#f0d58a] bg-[#fff7df] px-4 py-2.5 text-[15px] hover:border-sinal"><span>{{ $texto }}</span><span class="font-semibold">{{ $acao }}</span></a>
            @endforeach
        </div>
    @endif

    <a href="{{ route('locadora.frota.index') }}" class="card mb-4 block hover:border-asfalto-3">
        <div class="flex items-center justify-between border-b border-linha px-5 py-3"><h2>Frota agora</h2><span class="text-sm font-semibold text-asfalto-3">Ver frota</span></div>
        <div class="grid grid-cols-4 divide-x divide-linha">
            @foreach ([['Na frota', $contagem['total']], ['Disponíveis', $contagem['disponiveis']], ['Com cliente', $contagem['alugados']], ['Em manutenção', $contagem['manutencao']]] as [$rotulo, $n])
                <div class="px-3 py-3 sm:px-5"><div class="text-3xl font-semibold">{{ $n }}</div><div class="truncate text-xs text-suave sm:text-sm">{{ $rotulo }}</div></div>
            @endforeach
        </div>
    </a>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        <section class="card">
            <div class="flex items-center justify-between border-b border-linha px-5 py-3"><h2>Saídas para entregar</h2><a href="{{ route('locacao.index') }}" class="text-sm font-semibold text-asfalto-3">Contratos</a></div>
            <ul class="divide-y divide-linha">
                @forelse ($saidasHoje as $r)
                    <li><a href="{{ route('locacao.contrato', $r) }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-fundo">
                        @if ($r->frota)<x-placa :placa="$r->frota->placa" />@endif
                        <span class="min-w-0 flex-1 truncate">{{ $r->cliente?->nome }}</span>
                        <span class="text-sm text-suave">{{ $r->inicio->isToday() ? 'hoje '.$r->inicio->format('H:i') : $r->inicio->format('d/m H:i') }}</span>
                    </a></li>
                @empty
                    <li class="px-5 py-6 text-center text-suave">Nenhuma saída pendente.</li>
                @endforelse
            </ul>
        </section>
        <section class="card">
            <div class="flex items-center justify-between border-b border-linha px-5 py-3"><h2>Devoluções até amanhã</h2></div>
            <ul class="divide-y divide-linha">
                @forelse ($devolucoes as $r)
                    <li><a href="{{ route('locacao.contrato', $r) }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-fundo">
                        @if ($r->frota)<x-placa :placa="$r->frota->placa" />@endif
                        <span class="min-w-0 flex-1 truncate">{{ $r->cliente?->nome }}</span>
                        <span @class(['text-sm', 'font-semibold text-erro' => $r->atrasado(), 'text-suave' => ! $r->atrasado()])>{{ $r->atrasado() ? 'Atrasado desde '.$r->fim->format('d/m H:i') : $r->fim->format('d/m H:i') }}</span>
                    </a></li>
                @empty
                    <li class="px-5 py-6 text-center text-suave">Nenhuma devolução prevista.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        @can('financeiro.ver')
            <section class="card card-corpo">
                <h2 class="mb-3">Dinheiro da locadora</h2>
                <dl class="space-y-2 text-[15px]">
                    <div class="flex justify-between"><dt class="text-suave">Recebido no mês</dt><dd class="font-semibold">{{ Dinheiro::formatar($recebidoMes) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-suave">A receber em 7 dias</dt><dd class="font-semibold">{{ Dinheiro::formatar($receberSemana) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-suave">Vencido</dt><dd @class(['font-semibold', 'text-erro' => $receberVencido > 0])>{{ Dinheiro::formatar($receberVencido) }}</dd></div>
                </dl>
            </section>
        @endcan
        <section class="card">
            <div class="flex items-center justify-between border-b border-linha px-5 py-3"><h2>Multas pendentes</h2><a href="{{ route('locadora.multas.index') }}" class="text-sm font-semibold text-asfalto-3">Multas</a></div>
            <ul class="divide-y divide-linha">
                @forelse ($multasAbertas->take(6) as $m)
                    <li class="flex items-center gap-3 px-5 py-2.5 text-[15px]">
                        <x-placa :placa="$m->frota->placa" />
                        <span class="min-w-0 flex-1 truncate">{{ $m->descricao }}</span>
                        @if ($m->prazo_indicacao)<span @class(['text-sm', 'font-semibold text-erro' => $m->prazo_indicacao->lte(today()->addDays(5)), 'text-suave' => $m->prazo_indicacao->gt(today()->addDays(5))])>indicar até {{ $m->prazo_indicacao->format('d/m') }}</span>@endif
                    </li>
                @empty
                    <li class="px-5 py-6 text-center text-suave">Nenhuma multa pendente.</li>
                @endforelse
            </ul>
        </section>
        <section class="card">
            <div class="flex items-center justify-between border-b border-linha px-5 py-3"><h2>Documentos e revisão</h2></div>
            <ul class="divide-y divide-linha">
                @forelse ($avisosFrota as $c)
                    <li><a href="{{ route('locadora.frota.edit', $c) }}" class="flex items-center gap-3 px-5 py-2.5 text-[15px] hover:bg-fundo">
                        <x-placa :placa="$c->placa" />
                        <span class="min-w-0 flex-1">
                            @foreach ($c->documentosVencendo() as $doc => $data)
                                <span @class(['block', 'text-erro font-semibold' => $data->isPast()])>{{ $doc }} {{ $data->isPast() ? 'venceu' : 'vence' }} {{ $data->format('d/m/Y') }}</span>
                            @endforeach
                            @if ($c->revisaoProxima())<span class="block">Revisão aos {{ number_format($c->proxima_revisao_km, 0, ',', '.') }} km</span>@endif
                        </span>
                    </a></li>
                @empty
                    <li class="px-5 py-6 text-center text-suave">Nada vencendo nos próximos 30 dias.</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-app>
