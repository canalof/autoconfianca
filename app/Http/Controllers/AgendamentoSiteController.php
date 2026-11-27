<?php

namespace App\Http\Controllers;

use App\Models\AgendaSolicitacao;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Services\Agenda\AvisosAgenda;
use App\Support\Placa;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Pedido de agendamento feito no site institucional (estático, sem login).
 * Vira um pedido na agenda da recepção e o cliente recebe a confirmação pelo WhatsApp.
 * Sem token CSRF (o site é HTML puro): por isso tem limite por IP, campo-armadilha,
 * conferência de origem e no máximo 2 pedidos em aberto por número.
 */
class AgendamentoSiteController extends Controller
{
    public function __invoke(Request $request, AvisosAgenda $avisos): JsonResponse
    {
        abort_unless($this->origemPermitida($request), 403);
        if (filled($request->input('site'))) { // campo-armadilha: gente não preenche
            return response()->json(['ok' => true, 'mensagem' => 'Pedido recebido.']);
        }
        $request->merge([
            'telefone' => AreaClienteController::digitos($request->input('telefone')),
            'placa' => Placa::normalizar($request->input('placa')) ?: null,
            'km' => preg_replace('/\D/', '', (string) $request->input('km')) ?: null,
        ]);
        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'telefone' => ['required', 'digits_between:10,11'],
            'email' => ['nullable', 'email', 'max:160'],
            'veiculo' => ['nullable', 'string', 'max:120'],
            'placa' => ['nullable', fn ($c, $v, $falhar) => Placa::valida($v) ?: $falhar('Placa inválida.')],
            'km' => ['nullable', 'integer', 'max:2000000'],
            'servico' => ['nullable', 'string', 'max:200'],
            'mensagem' => ['nullable', 'string', 'max:1000'],
            'dia' => ['required', 'date_format:Y-m-d', 'after:today', 'before:'.today()->addDays(61)->toDateString()],
            'periodo' => ['required', Rule::in(array_keys(AgendaSolicitacao::PERIODOS))],
        ], [
            'telefone.digits_between' => 'Informe o WhatsApp com DDD.',
            'dia.after' => 'Escolha a partir de amanhã.',
            'dia.before' => 'Escolha uma data nos próximos 60 dias.',
        ]);
        $dia = Carbon::parse($dados['dia']);
        if ($dia->isSunday() || ($dia->isSaturday() && $dados['periodo'] === 'tarde')) {
            return response()->json(['message' => 'Atendemos de segunda a sexta e sábado de manhã. Escolha outro dia ou período.',
                'errors' => ['dia' => ['Dia ou período fechado.']]], 422);
        }

        $empresa = Empresa::query()->orderBy('id')->firstOrFail();
        Tenant::usar($empresa->id);
        $emAberto = AgendaSolicitacao::where('telefone', $dados['telefone'])->where('status', 'pendente')->count();
        if ($emAberto >= 2) {
            return response()->json(['ok' => true, 'repetido' => true,
                'mensagem' => 'Você já tem pedidos aguardando resposta. A oficina vai falar com você pelo WhatsApp.']);
        }

        // Cliente já cadastrado com esse celular (um só) e, se veio a placa, o carro dele.
        $chave = AreaClienteController::chave($dados['telefone']);
        $clientes = Cliente::query()->where(fn ($q) => $q->whereNotNull('whatsapp')->orWhereNotNull('telefone'))->get(['id', 'whatsapp', 'telefone'])
            ->filter(fn ($c) => in_array($chave, [AreaClienteController::chave(AreaClienteController::digitos($c->whatsapp)),
                AreaClienteController::chave(AreaClienteController::digitos($c->telefone))], true));
        $cliente = $clientes->count() === 1 ? Cliente::find($clientes->first()->id) : null;
        $veiculo = $cliente && $dados['placa']
            ? $cliente->veiculos()->whereIn('placa', Placa::equivalentes($dados['placa']))->first() : null;

        $pedido = AgendaSolicitacao::create([
            'origem' => 'site', 'status' => 'pendente',
            'cliente_id' => $cliente?->id, 'veiculo_id' => $veiculo?->id,
            'nome' => $dados['nome'], 'telefone' => $dados['telefone'], 'email' => $dados['email'] ?? null,
            'carro' => $dados['veiculo'] ?? null, 'placa' => $dados['placa'], 'km' => $dados['km'] ?? null,
            'servico' => mb_substr($dados['servico'] ?? '', 0, 200) ?: 'Avaliação',
            'observacao' => $dados['mensagem'] ?? null,
            'periodo' => $dados['periodo'],
            'preferencia' => $dia->setTime($dados['periodo'] === 'manha' ? 8 : 13, 0),
        ]);

        $whatsapp = false;
        try {
            $whatsapp = $avisos->pedidoRecebido($empresa, $pedido);
            $avisos->avisarOficina($empresa, $pedido);
        } catch (\Throwable $e) {
            Log::warning('Aviso de agendamento não enviado', ['pedido' => $pedido->id, 'erro' => $e->getMessage()]);
        }

        return response()->json([
            'ok' => true, 'whatsapp' => $whatsapp,
            'mensagem' => $whatsapp
                ? 'Pedido recebido! Mandamos a confirmação no seu WhatsApp e a oficina vai confirmar o horário por lá.'
                : 'Pedido recebido! A oficina vai confirmar o horário pelo WhatsApp.',
        ]);
    }

    /** Só aceita pedidos vindos do próprio site (ou sem cabeçalho de origem, como alguns celulares). */
    private function origemPermitida(Request $request): bool
    {
        $origem = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (! $origem) {
            return true;
        }
        $host = parse_url($origem, PHP_URL_HOST);
        $permitidos = array_filter([$request->getHost(), parse_url((string) config('oficina.dominio'), PHP_URL_HOST)]);
        foreach ($permitidos as $p) {
            if ($host === $p || $host === 'www.'.$p || 'www.'.$host === $p) {
                return true;
            }
        }

        return false;
    }
}
