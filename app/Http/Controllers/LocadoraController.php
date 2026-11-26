<?php

namespace App\Http\Controllers;

use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\LocadoraMulta;
use App\Models\MobilidadeFrota;
use App\Models\MobilidadeReserva;
use App\Support\Area;
use App\Support\Dinheiro;
use App\Support\Placa;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Locadora: painel, frota e multas. Contratos ficam no LocacaoController. */
class LocadoraController extends Controller
{
    public function painel()
    {
        $this->authorize('locacao.ver');
        $frota = MobilidadeFrota::orderBy('descricao')->get();
        $comCliente = MobilidadeReserva::where('status', 'entregue')->whereNotNull('frota_id')->pluck('frota_id');
        $receber = ContaReceber::where('status', 'aberta');

        return view('locadora.painel', [
            'contagem' => [
                'total' => $frota->where('situacao', '!=', 'inativo')->count(),
                'disponiveis' => $frota->where('situacao', 'disponivel')->whereNotIn('id', $comCliente)->count(),
                'alugados' => $comCliente->count(),
                'manutencao' => $frota->where('situacao', 'manutencao')->count(),
            ],
            'saidasHoje' => MobilidadeReserva::with(['cliente', 'frota'])->where('status', 'reservada')->whereDate('inicio', '<=', today())->orderBy('inicio')->get(),
            'devolucoes' => MobilidadeReserva::with(['cliente', 'frota'])->where('status', 'entregue')->whereDate('fim', '<=', today()->addDay())->orderBy('fim')->get(),
            'receberVencido' => (int) (clone $receber)->where('vencimento', '<', today())->sum('valor_centavos'),
            'receberSemana' => (int) (clone $receber)->whereBetween('vencimento', [today(), today()->addDays(7)])->sum('valor_centavos'),
            'recebidoMes' => (int) ContaReceber::where('status', 'recebida')->where('recebido_em', '>=', now()->startOfMonth())->sum('valor_centavos'),
            'multasAbertas' => LocadoraMulta::with('frota')->whereIn('situacao', ['aberta', 'indicada'])->orderBy('prazo_indicacao')->get(),
            'avisosFrota' => $frota->filter(fn ($c) => $c->situacao !== 'inativo' && ($c->documentosVencendo() || $c->revisaoProxima())),
            'caixa' => \App\Models\Caixa::aberto(),
        ]);
    }

    public function frota(Request $request)
    {
        $this->authorize('locacao.ver');
        $situacao = $request->query('situacao');
        $abertas = MobilidadeReserva::with('cliente')->whereIn('status', ['reservada', 'entregue'])->whereNotNull('frota_id')
            ->orderBy('inicio')->get()->groupBy('frota_id');
        $frota = MobilidadeFrota::orderByRaw("case situacao when 'inativo' then 1 else 0 end")->orderBy('descricao')->get()
            ->each(function ($c) use ($abertas) {
                $c->contratoAtual = $abertas->get($c->id)?->firstWhere('status', 'entregue');
                $c->proximaReserva = $abertas->get($c->id)?->firstWhere('status', 'reservada');
            });
        if ($situacao === 'alugados') {
            $frota = $frota->filter(fn ($c) => $c->contratoAtual);
        } elseif ($situacao === 'disponiveis') {
            $frota = $frota->filter(fn ($c) => ! $c->contratoAtual && $c->situacao === 'disponivel');
        } elseif ($situacao === 'manutencao') {
            $frota = $frota->where('situacao', 'manutencao');
        }

        return view('locadora.frota', ['frota' => $frota, 'situacao' => $situacao]);
    }

    public function frotaForm(?MobilidadeFrota $frota = null)
    {
        $this->authorize('locacao.editar');

        return view('locadora.frota-form', ['carro' => $frota ?? new MobilidadeFrota(['situacao' => 'disponivel', 'km' => 0])]);
    }

    public function salvarFrota(Request $request, ?MobilidadeFrota $frota = null)
    {
        $this->authorize('locacao.editar');
        $request->merge(['placa' => Placa::normalizar($request->input('placa')),
            'renavam' => preg_replace('/\D/', '', (string) $request->input('renavam')) ?: null,
            'chassi' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('chassi'))) ?: null]);
        $dados = $request->validate([
            'placa' => ['required', fn ($campo, $valor, $falhar) => Placa::valida($valor) ?: $falhar('Placa inválida.'),
                Rule::unique('mobilidade_frota', 'placa')->where('empresa_id', Tenant::id())->ignore($frota?->id)],
            'marca' => ['nullable', 'string', 'max:40'],
            'descricao' => ['required', 'string', 'max:120'],
            'ano' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'cor' => ['nullable', 'string', 'max:30'],
            'combustivel' => ['nullable', Rule::in(array_keys(MobilidadeFrota::COMBUSTIVEIS))],
            'renavam' => ['nullable', 'digits_between:9,11'],
            'chassi' => ['nullable', 'size:17'],
            'km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'situacao' => ['required', Rule::in(array_keys(MobilidadeFrota::SITUACOES))],
            'diaria' => ['nullable', 'string', 'max:20'],
            'semanal' => ['nullable', 'string', 'max:20'],
            'mensal' => ['nullable', 'string', 'max:20'],
            'caucao' => ['nullable', 'string', 'max:20'],
            'km_livre_dia' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'km_excedente' => ['nullable', 'string', 'max:20'],
            'ipva_vencimento' => ['nullable', 'date'],
            'licenciamento_vencimento' => ['nullable', 'date'],
            'seguro_vencimento' => ['nullable', 'date'],
            'proxima_revisao_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'observacoes' => ['nullable', 'string', 'max:4000'],
            'manutencao' => ['nullable', 'string', 'max:4000'],
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ], ['chassi.size' => 'O chassi tem 17 letras e números.', 'renavam.digits_between' => 'O Renavam tem 9 a 11 números.']);
        foreach (['diaria', 'semanal', 'mensal', 'caucao', 'km_excedente'] as $campo) {
            $dados[$campo.'_centavos'] = $this->centavos($dados[$campo] ?? null);
            unset($dados[$campo]);
        }
        unset($dados['foto']);
        if ($request->hasFile('foto')) {
            $dados['foto'] = $request->file('foto')->store('frota/'.Tenant::id(), 'local');
            if ($frota?->foto) {
                Storage::disk('local')->delete($frota->foto);
            }
        }
        $frota?->exists ? $frota->update($dados) : $frota = MobilidadeFrota::create($dados);

        return redirect()->route('locadora.frota.index')->with('ok', 'Carro '.Placa::formatar($frota->placa).' salvo.');
    }

    public function foto(MobilidadeFrota $frota)
    {
        $this->authorize('locacao.ver');
        abort_unless($frota->foto && Storage::disk('local')->exists($frota->foto), 404);

        return Storage::disk('local')->response($frota->foto, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Leva o carro para a oficina: fica "em manutenção" e abre a recepção com a placa. */
    public function paraOficina(Request $request, MobilidadeFrota $frota)
    {
        $this->authorize('locacao.editar');
        abort_if(MobilidadeReserva::where('frota_id', $frota->id)->where('status', 'entregue')->exists(), 422, 'O carro está com um cliente.');
        $frota->update(['situacao' => 'manutencao']);
        if (! in_array(Area::OFICINA, Area::disponiveis($request->user()), true) || ! $request->user()->can('veiculos.ver')) {
            return back()->with('ok', 'Carro marcado como em manutenção. Avise a oficina.');
        }
        $request->session()->put('area', Area::OFICINA);

        return redirect()->route('recepcao', ['placa' => $frota->placa])->with('ok', 'Carro da locadora na recepção da oficina. Abra a OS normalmente.');
    }

    public function multas(Request $request)
    {
        $this->authorize('locacao.ver');
        $situacao = $request->query('situacao', 'pendentes');

        return view('locadora.multas', [
            'situacao' => $situacao,
            'multas' => LocadoraMulta::with(['frota', 'cliente', 'reserva'])
                ->when($situacao === 'pendentes', fn ($q) => $q->whereIn('situacao', ['aberta', 'indicada']))
                ->when($situacao !== 'pendentes' && $situacao !== 'todas', fn ($q) => $q->where('situacao', $situacao))
                ->latest('data_infracao')->paginate(30)->withQueryString(),
            'frota' => MobilidadeFrota::orderBy('descricao')->get(['id', 'placa', 'descricao']),
        ]);
    }

    public function salvarMulta(Request $request)
    {
        $this->authorize('locacao.editar');
        $dados = $request->validate([
            'frota_id' => ['required', Rule::exists('mobilidade_frota', 'id')->where('empresa_id', Tenant::id())],
            'data_infracao' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:now'],
            'auto' => ['nullable', 'string', 'max:30'],
            'descricao' => ['required', 'string', 'max:200'],
            'local' => ['nullable', 'string', 'max:200'],
            'valor' => ['required', 'string', 'max:20'],
            'vencimento' => ['nullable', 'date'],
            'prazo_indicacao' => ['nullable', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'lancar_pagar' => ['nullable', 'boolean'],
        ]);
        $dados['valor_centavos'] = $this->centavos($dados['valor']);
        abort_if($dados['valor_centavos'] <= 0, 422, 'Informe o valor da multa.');
        $quando = Carbon::parse($dados['data_infracao']);
        // Contrato em vigor na hora da infração: saiu antes e não tinha voltado.
        $contrato = MobilidadeReserva::where('frota_id', $dados['frota_id'])->whereIn('status', ['entregue', 'devolvida'])
            ->where('inicio', '<=', $quando)->orderByDesc('inicio')->get()
            ->first(fn ($r) => $r->status === 'entregue' || Carbon::parse($r->devolucao['quando'] ?? $r->fim)->gte($quando));

        $multa = DB::transaction(function () use ($dados, $contrato, $quando) {
            $multa = LocadoraMulta::create([
                'frota_id' => $dados['frota_id'], 'reserva_id' => $contrato?->id, 'cliente_id' => $contrato?->cliente_id,
                'data_infracao' => $quando, 'auto' => $dados['auto'] ?? null, 'descricao' => $dados['descricao'],
                'local' => $dados['local'] ?? null, 'valor_centavos' => $dados['valor_centavos'],
                'vencimento' => $dados['vencimento'] ?? null, 'prazo_indicacao' => $dados['prazo_indicacao'] ?? null,
                'observacao' => $dados['observacao'] ?? null,
            ]);
            if (! empty($dados['lancar_pagar'])) {
                ContaPagar::create([
                    'categoria' => 'Multas de trânsito', 'area' => Area::LOCADORA,
                    'descricao' => 'Multa '.($multa->auto ?: '#'.$multa->id).' · '.Placa::formatar($multa->frota->placa),
                    'valor_centavos' => $multa->valor_centavos, 'vencimento' => $multa->vencimento ?? today()->addDays(30),
                ]);
            }

            return $multa;
        });

        return redirect()->route('locadora.multas.index')->with('ok', $contrato
            ? 'Multa registrada no contrato #'.$contrato->id.($contrato->cliente ? ' ('.$contrato->cliente->nome.')' : '').'.'
            : 'Multa registrada. Nenhum contrato em vigor nessa data: o carro estava na locadora.');
    }

    public function situacaoMulta(Request $request, LocadoraMulta $multa)
    {
        $this->authorize('locacao.editar');
        $dados = $request->validate(['situacao' => ['required', Rule::in(['aberta', 'indicada', 'paga', 'cancelada'])]]);
        $multa->update($dados);

        return back()->with('ok', 'Multa: '.LocadoraMulta::SITUACOES[$dados['situacao']].'.');
    }

    /** Lança a multa em contas a receber do cliente do contrato. */
    public function cobrarMulta(LocadoraMulta $multa)
    {
        $this->authorize('locacao.editar');
        abort_unless($multa->cliente_id && ! $multa->conta_receber_id, 422, 'Esta multa não tem cliente ou já foi cobrada.');
        DB::transaction(function () use ($multa) {
            $titulo = ContaReceber::create([
                'cliente_id' => $multa->cliente_id, 'reserva_id' => $multa->reserva_id, 'categoria' => 'multa', 'area' => Area::LOCADORA,
                'descricao' => 'Multa de trânsito '.($multa->auto ?: '#'.$multa->id).' · '.Placa::formatar($multa->frota->placa).' · '.$multa->data_infracao->format('d/m/Y'),
                'valor_centavos' => $multa->valor_centavos, 'vencimento' => today()->addDays(7), 'forma' => 'prazo',
            ]);
            $multa->update(['conta_receber_id' => $titulo->id, 'situacao' => 'cobrada']);
        });

        return back()->with('ok', 'Multa lançada em contas a receber do cliente.');
    }

    private function centavos(?string $valor): int
    {
        $centavos = Dinheiro::paraCentavos($valor ?? '');
        abort_unless($centavos >= 0 && $centavos <= 99999999, 422, 'Valor inválido.');

        return $centavos;
    }
}
