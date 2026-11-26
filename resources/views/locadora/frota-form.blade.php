@php use App\Models\MobilidadeFrota; use App\Support\Dinheiro; @endphp
<x-app :titulo="$carro->exists ? 'Editar carro' : 'Cadastrar carro'">
    <div class="mb-5 flex items-center justify-between gap-3">
        <h1>{{ $carro->exists ? 'Editar carro' : 'Cadastrar carro' }}</h1>
        <div class="flex gap-2">
            @if ($carro->exists && auth()->user()->can('auditoria.ver'))<a href="{{ route('historico', ['carro-frota', $carro->id]) }}" class="btn">Ver histórico</a>@endif
            <a class="btn" href="{{ route('locadora.frota.index') }}">Voltar para a frota</a>
        </div>
    </div>
    @if ($errors->any())<p class="erro-msg mb-3" role="alert">Confira os campos marcados.</p>@endif
    <form method="post" enctype="multipart/form-data" action="{{ $carro->exists ? route('locadora.frota.update', $carro) : route('locadora.frota.store') }}" class="space-y-4">
        @csrf @if ($carro->exists) @method('put') @endif
        <section class="card card-corpo space-y-3">
            <h2>Carro</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <x-campo nome="placa" rotulo="Placa" :valor="$carro->placa" mascara="placa" required maxlength="8" />
                <x-campo nome="marca" rotulo="Marca" :valor="$carro->marca" maxlength="40" placeholder="Chevrolet" />
                <x-campo nome="descricao" rotulo="Modelo e versão" :valor="$carro->descricao" required maxlength="120" placeholder="Onix 1.0 LT" />
                <x-campo nome="ano" rotulo="Ano" tipo="number" :valor="$carro->ano" min="1950" :max="now()->year + 1" />
                <x-campo nome="cor" rotulo="Cor" :valor="$carro->cor" maxlength="30" />
                <label class="campo"><span>Combustível</span><select class="input" name="combustivel">
                    <option value="">—</option>
                    @foreach (MobilidadeFrota::COMBUSTIVEIS as $v => $r)<option value="{{ $v }}" @selected(old('combustivel', $carro->combustivel) === $v)>{{ $r }}</option>@endforeach
                </select></label>
                <x-campo nome="renavam" rotulo="Renavam" :valor="$carro->renavam" inputmode="numeric" maxlength="11" />
                <x-campo nome="chassi" rotulo="Chassi" :valor="$carro->chassi" maxlength="17" />
                <x-campo nome="km" rotulo="Quilometragem atual" tipo="number" :valor="$carro->km ?? 0" min="0" required />
                <label class="campo"><span>Situação</span><select class="input" name="situacao" required>
                    @foreach (MobilidadeFrota::SITUACOES as $v => $r)<option value="{{ $v }}" @selected(old('situacao', $carro->situacao) === $v)>{{ $r }}</option>@endforeach
                </select></label>
                <label class="campo sm:col-span-2"><span>Foto</span><input class="input" type="file" name="foto" accept="image/jpeg,image/png,image/webp">
                    @if ($carro->foto)<small class="mt-1 block text-xs text-suave">Já tem foto. Envie outra só para trocar.</small>@endif
                    @error('foto')<p class="erro-msg">{{ $message }}</p>@enderror
                </label>
            </div>
        </section>
        <section class="card card-corpo space-y-3">
            <h2>Preços de tabela</h2>
            <p class="text-sm text-suave">Preenchem o contrato sozinhos. Dá para mudar em cada contrato.</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <x-campo nome="diaria" rotulo="Diária (R$)" :valor="($carro->diaria_centavos ? Dinheiro::campo($carro->diaria_centavos) : "")" mascara="dinheiro" inputmode="decimal" />
                <x-campo nome="semanal" rotulo="Semana (R$)" :valor="($carro->semanal_centavos ? Dinheiro::campo($carro->semanal_centavos) : "")" mascara="dinheiro" inputmode="decimal" />
                <x-campo nome="mensal" rotulo="Mês (R$)" :valor="($carro->mensal_centavos ? Dinheiro::campo($carro->mensal_centavos) : "")" mascara="dinheiro" inputmode="decimal" />
                <x-campo nome="caucao" rotulo="Caução (R$)" :valor="($carro->caucao_centavos ? Dinheiro::campo($carro->caucao_centavos) : "")" mascara="dinheiro" inputmode="decimal" />
                <x-campo nome="km_livre_dia" rotulo="Km livres por dia" tipo="number" :valor="$carro->km_livre_dia" min="1" ajuda="Em branco: quilometragem livre." />
                <x-campo nome="km_excedente" rotulo="Valor do km excedente (R$)" :valor="($carro->km_excedente_centavos ? Dinheiro::campo($carro->km_excedente_centavos) : "")" mascara="dinheiro" inputmode="decimal" />
            </div>
        </section>
        <section class="card card-corpo space-y-3">
            <h2>Vencimentos</h2>
            <p class="text-sm text-suave">O painel da locadora avisa 30 dias antes.</p>
            <div class="grid gap-3 sm:grid-cols-4">
                <x-campo nome="ipva_vencimento" rotulo="IPVA" tipo="date" :valor="$carro->ipva_vencimento?->format('Y-m-d')" />
                <x-campo nome="licenciamento_vencimento" rotulo="Licenciamento" tipo="date" :valor="$carro->licenciamento_vencimento?->format('Y-m-d')" />
                <x-campo nome="seguro_vencimento" rotulo="Seguro" tipo="date" :valor="$carro->seguro_vencimento?->format('Y-m-d')" />
                <x-campo nome="proxima_revisao_km" rotulo="Próxima revisão (km)" tipo="number" :valor="$carro->proxima_revisao_km" min="0" />
            </div>
            <label class="campo"><span>Observações</span><textarea class="input" name="observacoes" rows="2" maxlength="4000">{{ old('observacoes', $carro->observacoes) }}</textarea></label>
            <label class="campo"><span>Histórico de manutenção</span><textarea class="input" name="manutencao" rows="2" maxlength="4000">{{ old('manutencao', $carro->manutencao) }}</textarea></label>
        </section>
        <button class="btn-primario">Salvar carro</button>
    </form>
</x-app>
