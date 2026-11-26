@php
    use App\Models\MobilidadeReserva;
    use App\Support\Dinheiro;
@endphp
<x-app titulo="Contratos">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1>Contratos</h1>
        <div class="flex flex-wrap gap-2">
            @can('locacao.editar')
                @if ($os)
                    <a class="btn-primario" href="{{ route('locacao.novo', ['os' => $os->id]) }}">Novo carro reserva</a>
                @else
                    <a class="btn-primario" href="{{ route('locacao.novo') }}">Novo aluguel</a>
                    <a class="btn" href="{{ route('locacao.novo', ['modalidade' => 'carro_reserva']) }}">Novo carro reserva</a>
                @endif
            @endcan
        </div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([['Carros disponíveis', $resumo['disponiveis']], ['Com cliente agora', $resumo['emUso']], ['Saem hoje', $resumo['saemHoje']], ['Devolução hoje ou atrasada', $resumo['voltamHoje']]] as [$rotulo, $valor])
            <div class="card card-corpo"><div class="text-sm text-suave">{{ $rotulo }}</div><div class="text-2xl font-bold">{{ $valor }}</div></div>
        @endforeach
    </div>

    <section class="card" id="reservas">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-linha px-5 py-3">
            <h2>Reservas e aluguéis</h2>
            <nav class="flex flex-wrap gap-1" aria-label="Filtro">
                @foreach ([null => 'Todos', 'aluguel' => 'Aluguel', 'carro_reserva' => 'Carro reserva'] as $chave => $rotulo)
                    <a href="{{ route('locacao.index', array_filter(['modalidade' => $chave, 'situacao' => $situacao, 'os' => $os?->id, 'cliente' => $cliente?->id])) }}"
                       @class(['btn btn-sm', 'border-sinal' => $modalidade === ($chave ?: null)]) @if($modalidade === ($chave ?: null)) aria-current="page" @endif>{{ $rotulo }}</a>
                @endforeach
                <span class="mx-1 border-l border-linha"></span>
                @foreach (['abertas' => 'Em aberto', 'encerradas' => 'Encerrados'] as $chave => $rotulo)
                    <a href="{{ route('locacao.index', array_filter(['modalidade' => $modalidade, 'situacao' => $chave, 'os' => $os?->id, 'cliente' => $cliente?->id])) }}"
                       @class(['btn btn-sm', 'border-sinal' => $situacao === $chave]) @if($situacao === $chave) aria-current="page" @endif>{{ $rotulo }}</a>
                @endforeach
            </nav>
        </div>
        @if ($os || $cliente)
            <p class="px-5 pt-3 text-sm">Mostrando só {{ $os ? 'a OS '.$os->numeroFormatado() : 'o cliente '.$cliente->nome }} · <a class="underline" href="{{ route('locacao.index') }}">ver todos</a></p>
        @endif
        <div class="divide-y divide-linha">
            @forelse ($reservas as $reserva)
                <a href="{{ route('locacao.contrato', $reserva) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-fundo" id="reserva-{{ $reserva->id }}">
                    <span class="w-14 text-sm text-suave">#{{ $reserva->id }}</span>
                    @if ($reserva->frota)<x-placa :placa="$reserva->frota->placa" />@endif
                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $reserva->ehAluguel() ? 'bg-[#e6eef8] text-[#1f4f82]' : 'bg-[#eef3e6] text-[#3f5f1f]' }}">{{ MobilidadeReserva::MODALIDADES[$reserva->modalidade] }}</span>
                    <strong class="min-w-0 truncate">{{ $reserva->cliente?->nome ?? 'Cliente não informado' }}</strong>
                    <span class="text-suave">{{ $reserva->frota ? $reserva->frota->descricao : 'Parceira: '.$reserva->parceira }}</span>
                    <span class="text-suave">{{ $reserva->inicio->format('d/m H:i') }} a {{ $reserva->fim->format('d/m H:i') }}</span>
                    <span class="text-sm text-suave">{{ MobilidadeReserva::PLANOS[$reserva->plano] ?? '' }} {{ Dinheiro::formatar($reserva->valorPeriodo()) }}</span>
                    <span @class(['ml-auto text-sm font-semibold', 'text-erro' => $reserva->atrasado()])>{{ $reserva->atrasado() ? 'Atrasado' : (MobilidadeReserva::SITUACOES[$reserva->status] ?? $reserva->status) }}</span>
                </a>
            @empty
                <p class="px-5 py-8 text-center text-suave">Nenhum contrato neste filtro.</p>
            @endforelse
        </div>
        <div class="px-5 py-3">{{ $reservas->links() }}</div>
    </section>
</x-app>
