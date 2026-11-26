@php use App\Models\MobilidadeFrota; use App\Support\Dinheiro; @endphp
<x-app titulo="Frota">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1>Frota</h1>
        @can('locacao.editar')<a class="btn-primario" href="{{ route('locadora.frota.create') }}">Cadastrar carro</a>@endcan
    </div>
    <nav class="mb-4 flex flex-wrap gap-1" aria-label="Filtro">
        @foreach ([null => 'Todos', 'disponiveis' => 'Disponíveis', 'alugados' => 'Com cliente', 'manutencao' => 'Em manutenção'] as $chave => $rotulo)
            <a href="{{ route('locadora.frota.index', array_filter(['situacao' => $chave])) }}" @class(['btn btn-sm', 'border-sinal' => $situacao === $chave]) @if($situacao === $chave) aria-current="page" @endif>{{ $rotulo }}</a>
        @endforeach
    </nav>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($frota as $carro)
            @php
                [$estado, $cor] = $carro->contratoAtual ? ['Com cliente', 'bg-[#e6eef8] text-[#1f4f82]']
                    : match ($carro->situacao) { 'manutencao' => ['Em manutenção', 'bg-[#fff1d6] text-[#7a4b00]'], 'inativo' => ['Fora da frota', 'bg-fundo text-suave'], default => ['Disponível', 'bg-[#e7f4ea] text-ok'] };
                $avisos = $carro->documentosVencendo();
            @endphp
            <article @class(['card overflow-hidden', 'opacity-60' => $carro->situacao === 'inativo'])>
                <div class="relative aspect-[16/9] bg-fundo">
                    @if ($carro->foto)
                        <img src="{{ route('locadora.frota.foto', $carro) }}?v={{ $carro->updated_at?->timestamp }}" alt="{{ $carro->nomeCompleto() }}" class="h-full w-full object-cover" loading="lazy">
                    @else
                        <svg class="absolute inset-0 m-auto h-16 w-16 text-[#c9cfd6]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M5 16h14l-1.5-5.5A2 2 0 0 0 15.6 9H8.4a2 2 0 0 0-1.9 1.5zM7 16v2M17 16v2M7.5 13h.01M16.5 13h.01"/></svg>
                    @endif
                    <span class="absolute left-3 top-3 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $cor }}">{{ $estado }}</span>
                </div>
                <div class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0"><div class="truncate font-semibold">{{ $carro->nomeCompleto() }}</div><div class="text-sm text-suave">{{ number_format($carro->km, 0, ',', '.') }} km</div></div>
                        <x-placa :placa="$carro->placa" />
                    </div>
                    <p class="text-sm text-suave">
                        @if ($carro->diaria_centavos) Diária {{ Dinheiro::formatar($carro->diaria_centavos) }}@endif
                        @if ($carro->semanal_centavos) · Semana {{ Dinheiro::formatar($carro->semanal_centavos) }}@endif
                        @if ($carro->mensal_centavos) · Mês {{ Dinheiro::formatar($carro->mensal_centavos) }}@endif
                    </p>
                    @if ($carro->contratoAtual)
                        <p class="text-sm"><a class="underline" href="{{ route('locacao.contrato', $carro->contratoAtual) }}">{{ $carro->contratoAtual->cliente?->nome }}</a> · volta {{ $carro->contratoAtual->fim->format('d/m H:i') }}</p>
                    @elseif ($carro->proximaReserva)
                        <p class="text-sm">Reservado: <a class="underline" href="{{ route('locacao.contrato', $carro->proximaReserva) }}">{{ $carro->proximaReserva->inicio->format('d/m H:i') }}</a></p>
                    @endif
                    @foreach ($avisos as $doc => $data)
                        <p @class(['text-sm', 'font-semibold text-erro' => $data->isPast(), 'text-[#7a4b00]' => ! $data->isPast()])>{{ $doc }} {{ $data->isPast() ? 'vencido em' : 'vence em' }} {{ $data->format('d/m/Y') }}</p>
                    @endforeach
                    @if ($carro->revisaoProxima())<p class="text-sm text-[#7a4b00]">Revisão aos {{ number_format($carro->proxima_revisao_km, 0, ',', '.') }} km</p>@endif
                    @can('locacao.editar')
                        <div class="flex flex-wrap gap-2 pt-1">
                            <a class="btn btn-sm" href="{{ route('locadora.frota.edit', $carro) }}">Editar</a>
                            @if (! $carro->contratoAtual && $carro->situacao === 'disponivel')
                                <a class="btn btn-sm" href="{{ route('locacao.novo', ['frota' => $carro->id]) }}">Alugar</a>
                            @endif
                            @unless ($carro->contratoAtual || $carro->situacao === 'inativo')
                                <form method="post" action="{{ route('locadora.frota.oficina', $carro) }}">@csrf<button class="btn btn-sm">Levar para a oficina</button></form>
                            @endunless
                        </div>
                    @endcan
                </div>
            </article>
        @empty
            <p class="card card-corpo text-suave sm:col-span-2 xl:col-span-3">Nenhum carro neste filtro. @can('locacao.editar')<a class="underline" href="{{ route('locadora.frota.create') }}">Cadastre o primeiro carro</a>.@endcan</p>
        @endforelse
    </div>
</x-app>
