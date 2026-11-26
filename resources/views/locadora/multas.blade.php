@php use App\Models\LocadoraMulta; use App\Support\Dinheiro; @endphp
<x-app titulo="Multas">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3"><h1>Multas</h1></div>
    @if ($errors->any())<p class="erro-msg mb-3" role="alert">{{ $errors->first() }}</p>@endif

    @can('locacao.editar')
        <details class="card card-corpo mb-5" @if ($errors->any()) open @endif>
            <summary class="cursor-pointer font-semibold">Registrar multa</summary>
            <p class="mt-2 text-sm text-suave">O sistema encontra o contrato e o cliente que estavam com o carro na data da infração.</p>
            <form method="post" action="{{ route('locadora.multas.store') }}" class="mt-3 space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="campo"><span>Carro</span><select class="input" name="frota_id" required>
                        <option value="">Selecione</option>
                        @foreach ($frota as $c)<option value="{{ $c->id }}" @selected((int) old('frota_id') === $c->id)>{{ \App\Support\Placa::formatar($c->placa) }} · {{ $c->descricao }}</option>@endforeach
                    </select></label>
                    <x-campo nome="data_infracao" rotulo="Data e hora da infração" tipo="datetime-local" required />
                    <x-campo nome="auto" rotulo="Nº do auto de infração" maxlength="30" />
                    <x-campo nome="descricao" rotulo="Infração" required maxlength="200" class="sm:col-span-2" placeholder="Excesso de velocidade até 20%" />
                    <x-campo nome="valor" rotulo="Valor (R$)" mascara="dinheiro" inputmode="decimal" required />
                    <x-campo nome="local" rotulo="Local" maxlength="200" />
                    <x-campo nome="prazo_indicacao" rotulo="Prazo para indicar condutor" tipo="date" />
                    <x-campo nome="vencimento" rotulo="Vencimento do boleto" tipo="date" />
                </div>
                <label class="campo"><span>Observação</span><textarea class="input" name="observacao" rows="2" maxlength="2000">{{ old('observacao') }}</textarea></label>
                <label class="flex items-center gap-2 text-[15px]"><input type="checkbox" name="lancar_pagar" value="1" @checked(old('lancar_pagar'))> Lançar em contas a pagar da locadora</label>
                <button class="btn-primario">Registrar multa</button>
            </form>
        </details>
    @endcan

    <nav class="mb-3 flex flex-wrap gap-1" aria-label="Filtro">
        @foreach (['pendentes' => 'Pendentes', 'cobrada' => 'Cobradas', 'paga' => 'Pagas', 'todas' => 'Todas'] as $chave => $rotulo)
            <a href="{{ route('locadora.multas.index', ['situacao' => $chave]) }}" @class(['btn btn-sm', 'border-sinal' => $situacao === $chave])>{{ $rotulo }}</a>
        @endforeach
    </nav>
    <div class="card divide-y divide-linha">
        @forelse ($multas as $m)
            <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                <x-placa :placa="$m->frota->placa" />
                <div class="min-w-0 flex-1">
                    <div class="font-semibold">{{ $m->descricao }} · {{ Dinheiro::formatar($m->valor_centavos) }}</div>
                    <div class="text-sm text-suave">
                        {{ $m->data_infracao->format('d/m/Y H:i') }}@if ($m->auto) · Auto {{ $m->auto }}@endif @if ($m->local) · {{ $m->local }}@endif
                        · @if ($m->reserva)<a class="underline" href="{{ route('locacao.contrato', $m->reserva) }}">Contrato #{{ $m->reserva_id }}</a> · {{ $m->cliente?->nome }}@else Sem contrato (carro na locadora)@endif
                        @if ($m->prazo_indicacao) · indicar até {{ $m->prazo_indicacao->format('d/m/Y') }}@endif
                    </div>
                </div>
                <span class="text-sm font-semibold">{{ LocadoraMulta::SITUACOES[$m->situacao] }}</span>
                @can('locacao.editar')
                    @if ($m->cliente_id && ! $m->conta_receber_id && in_array($m->situacao, ['aberta', 'indicada']))
                        <form method="post" action="{{ route('locadora.multas.cobrar', $m) }}">@csrf<button class="btn btn-sm">Cobrar do cliente</button></form>
                    @endif
                    <form method="post" action="{{ route('locadora.multas.situacao', $m) }}" class="flex gap-1">
                        @csrf @method('put')
                        <select class="input py-1.5 text-sm" name="situacao" aria-label="Situação">
                            @foreach (['aberta', 'indicada', 'paga', 'cancelada'] as $s)<option value="{{ $s }}" @selected($m->situacao === $s)>{{ LocadoraMulta::SITUACOES[$s] }}</option>@endforeach
                        </select>
                        <button class="btn btn-sm">Salvar</button>
                    </form>
                @endcan
            </div>
        @empty
            <p class="px-5 py-8 text-center text-suave">Nenhuma multa neste filtro.</p>
        @endforelse
    </div>
    <div class="mt-3">{{ $multas->links() }}</div>
</x-app>
