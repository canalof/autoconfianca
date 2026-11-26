@php
    use App\Models\MobilidadeReserva;
    use App\Support\Dinheiro;
    use App\Support\Placa;
    $reserva = $modalidade === 'carro_reserva';
@endphp
<x-app :titulo="$reserva ? 'Novo carro reserva' : 'Novo aluguel'">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1>{{ $reserva ? 'Novo carro reserva' : 'Novo aluguel' }}</h1>
        <a class="btn" href="{{ route('locacao.index') }}">Voltar para contratos</a>
    </div>
    @if (session('erro'))<p class="erro-msg mb-3" role="alert">{{ session('erro') }}</p>@endif
    @if ($errors->any())<p class="erro-msg mb-3" role="alert">{{ $errors->first() }}</p>@endif

    <form method="post" action="{{ route('locacao.reserva.store') }}" class="space-y-4" data-contrato>
        @csrf
        <input type="hidden" name="modalidade" value="{{ $modalidade }}">
        <section class="card card-corpo space-y-3">
            <h2>{{ $reserva ? 'Cliente da oficina' : 'Cliente' }}</h2>
            @if ($reserva)
                <label class="campo"><span>Ordem de serviço</span><select class="input" name="ordem_servico_id" required>
                    <option value="">Selecione a OS</option>
                    @foreach ($ordens as $ordem)
                        <option value="{{ $ordem->id }}" @selected((int) old('ordem_servico_id', $os?->id) === $ordem->id)>{{ $ordem->numeroFormatado() }} · {{ Placa::formatar($ordem->veiculo?->placa) }} · {{ $ordem->cliente?->nome }}</option>
                    @endforeach
                </select></label>
            @else
                <label class="campo"><span>Cliente</span><select class="input" name="cliente_id" required>
                    <option value="">Selecione o cliente</option>
                    @foreach ($clientes as $c)<option value="{{ $c->id }}" @selected((int) old('cliente_id', $cliente?->id) === $c->id)>{{ $c->nome }}{{ $c->cpf_cnpj ? ' · '.\App\Support\Documento::formatar($c->cpf_cnpj) : '' }}</option>@endforeach
                </select></label>
                @can('clientes.editar')<p class="text-sm text-suave">Cliente novo? <a class="underline" href="{{ route('clientes.create') }}">Cadastre aqui</a> e volte.</p>@endcan
            @endif
            <label class="campo"><span>Condutor (quem vai dirigir)</span><select class="input" name="condutor_id" required>
                <option value="">Selecione</option>
                @foreach ($condutores as $condutor)<option value="{{ $condutor->id }}" @selected((int) old('condutor_id') === $condutor->id)>{{ $condutor->nome }}{{ $condutor->categoria ? ' · CNH '.$condutor->categoria : '' }}{{ $condutor->validade ? ' · validade '.$condutor->validade->format('d/m/Y') : '' }}</option>@endforeach
            </select></label>
            @can('mobilidade.editar')<p class="text-sm text-suave">Condutor novo? <a class="underline" href="{{ route('mobilidade.index') }}#condutores">Cadastre com a CNH</a> e volte.</p>@endcan
        </section>

        <section class="card card-corpo space-y-3">
            <h2>Carro e período</h2>
            @if ($reserva)
                <label class="campo"><span>Origem do carro</span><select class="input" name="tipo">
                    @foreach (MobilidadeReserva::ORIGENS as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('tipo', 'frota') === $valor)>{{ $rotulo }}</option>@endforeach
                </select></label>
            @else
                <input type="hidden" name="tipo" value="frota">
            @endif
            <label class="campo"><span>Carro da frota{{ $reserva ? ' (em branco se for de locadora parceira)' : '' }}</span><select class="input" name="frota_id" @unless ($reserva) required @endunless data-carro>
                <option value="">Selecione</option>
                @foreach ($frota as $carro)
                    <option value="{{ $carro->id }}" @selected((int) old('frota_id', request('frota')) === $carro->id)
                        data-diaria="{{ ($carro->diaria_centavos ? Dinheiro::campo($carro->diaria_centavos) : "") }}" data-semanal="{{ ($carro->semanal_centavos ? Dinheiro::campo($carro->semanal_centavos) : "") }}" data-mensal="{{ ($carro->mensal_centavos ? Dinheiro::campo($carro->mensal_centavos) : "") }}"
                        data-caucao="{{ ($carro->caucao_centavos ? Dinheiro::campo($carro->caucao_centavos) : "") }}" data-km-livre="{{ $carro->km_livre_dia }}" data-km-excedente="{{ ($carro->km_excedente_centavos ? Dinheiro::campo($carro->km_excedente_centavos) : "") }}">{{ Placa::formatar($carro->placa) }} · {{ $carro->nomeCompleto() }}</option>
                @endforeach
            </select></label>
            @if ($frota->isEmpty())<p class="text-sm text-suave">Nenhum carro disponível. <a class="underline" href="{{ route('locadora.frota.index') }}">Ver a frota</a>.</p>@endif
            @if ($reserva)
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-campo nome="parceira" rotulo="Locadora parceira" maxlength="120" />
                    <x-campo nome="contrato_externo" rotulo="Nº do contrato na parceira" maxlength="120" />
                </div>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <x-campo nome="inicio" rotulo="Saída" tipo="datetime-local" :valor="now()->format('Y-m-d\TH:i')" required />
                <x-campo nome="fim" rotulo="Devolução prevista" tipo="datetime-local" required />
            </div>
        </section>

        <section class="card card-corpo space-y-3">
            <h2>Valores</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="campo"><span>Plano</span><select class="input" name="plano" data-plano>
                    @foreach (MobilidadeReserva::PLANOS as $valor => $rotulo)<option value="{{ $valor }}" @selected(old('plano', 'diaria') === $valor)>{{ $rotulo }}</option>@endforeach
                </select>
                <small class="mt-1 block text-xs text-suave">Semanal e mensal geram uma parcela por período na saída do carro.</small></label>
                <x-campo nome="valor" rotulo="Valor por período (R$)" mascara="dinheiro" inputmode="decimal" :ajuda="$reserva ? 'Em branco se o carro reserva for cortesia.' : null" />
                <x-campo nome="caucao" rotulo="Caução (R$)" mascara="dinheiro" inputmode="decimal" />
                <x-campo nome="km_livre" rotulo="Km livres por dia" tipo="number" min="1" ajuda="Em branco: quilometragem livre." />
                <x-campo nome="km_excedente" rotulo="Valor do km excedente (R$)" mascara="dinheiro" inputmode="decimal" />
            </div>
        </section>
        <button class="btn-primario">{{ $reserva ? 'Registrar carro reserva' : 'Registrar aluguel' }}</button>
    </form>
    <script>
        (() => {
            const form = document.querySelector('[data-contrato]');
            const carro = form.querySelector('[data-carro]'), plano = form.querySelector('[data-plano]');
            const campo = (n) => form.querySelector(`[name="${n}"]`);
            function preencher(forcar) {
                const op = carro.selectedOptions[0];
                if (!op || !op.value) return;
                const valor = op.dataset[plano.value] || '';
                const definir = (n, v) => { const c = campo(n); if (c && (forcar || !c.value) && v) c.value = v; };
                definir('valor', valor); definir('caucao', op.dataset.caucao); definir('km_livre', op.dataset.kmLivre); definir('km_excedente', op.dataset.kmExcedente);
            }
            carro.addEventListener('change', () => preencher(true));
            plano.addEventListener('change', () => { const op = carro.selectedOptions[0]; if (op && op.value) campo('valor').value = op.dataset[plano.value] || ''; });
            preencher(false);
        })();
    </script>
</x-app>
