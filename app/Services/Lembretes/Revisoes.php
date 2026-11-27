<?php

namespace App\Services\Lembretes;

use App\Models\CategoriaServico;
use App\Models\HistoricoOsAnterior;
use App\Models\Lembrete;
use App\Models\Servico;
use App\Models\Veiculo;
use App\Services\WhatsApp\EnvioLembretes;
use Illuminate\Support\Str;

/**
 * Serviços com prazo de revisão (troca de óleo, revisão, filtros...) e os lembretes que vêm deles.
 *
 * - servicosPadrao(): cadastra no catálogo os serviços de manutenção periódica com prazo em meses e km.
 *   Não mexe no preço nem em prazo que a oficina já preencheu.
 * - lembretesDoHistorico(): olha as OS antigas (historico_os_anteriores) e cria o lembrete da próxima
 *   troca/revisão para o dono atual de cada carro. O prazo vem do catálogo (a oficina pode ajustar antes).
 *   Pode rodar de novo: não duplica.
 */
class Revisoes
{
    /** nome no catálogo => [categoria, meses, km, padrão para achar nas OS antigas (texto sem acento, maiúsculo), exceções] */
    public const SERVICOS = [
        'Troca de óleo e filtro' => ['Troca de óleo e filtros', 6, 10000, '/\bOLEO\b/', '/CAMBIO|FREIO|DIRECAO|HIDRAUL|DIFERENCIAL|\bATF\b|AMORTEC|VAZAMENTO|RETENTOR|JUNTA|CARTER|SENSOR|BOMBA/'],
        'Revisão preventiva' => ['Revisão preventiva', 12, 10000, '/^(MAO DE OBRA )?(DA |DE )?REVISAO\b(?!.*(CAIXA|CHICOTE|FREIO|SUSPENS))/', null],
        'Filtro de ar do motor' => ['Troca de óleo e filtros', 12, 15000, '/FILTRO (DE |DO )?AR\b/', '/COND|CABINE|POLEN|MANGUEIRA|CAIXA/'],
        'Filtro de cabine (ar-condicionado)' => ['Ar-condicionado', 12, 15000, '/FILTRO.*(CABINE|CONDICIONADO|CONDIONADO|POLEN)/', null],
        'Fluido de freio' => ['Freios', 24, null, '/(FLUIDO|OLEO) (DE |DO )?FREIO|\bDOT ?[345]\b/', '/RESERVATORIO|VALVULA|SENSOR/'],
        'Correia dentada' => ['Motor e diagnóstico', 48, 50000, '/CORREIA DENTADA|KIT (DE |DA )?CORREIA|KIT (DE )?DISTRIBUICAO/', '/CAPA/'],
        'Líquido de arrefecimento' => ['Motor e diagnóstico', 24, null, '/LIQUIDO (DO |DE |PARA )?(ARREFEC|RADIADOR)|ADITIVO (DE |DO |PARA )?(RADIADOR|ARREFEC)|LIMPEZA DO SISTEMA DE ARREFEC|PARAFLU|TROCA (DO |DE )?(LIQUIDO|ADITIVO|ARREFEC)/', null],
        'Alinhamento e balanceamento' => ['Alinhamento e balanceamento', 6, 10000, '/ALINHAMENTO|BALANCEAMENTO/', null],
    ];

    /** @return array{criados: string[], prazos: string[]} */
    public function servicosPadrao(): array
    {
        $criados = $prazos = [];
        foreach (self::SERVICOS as $nome => [$categoria, $meses, $km]) {
            $s = Servico::all(['id', 'nome', 'revisao_meses', 'revisao_km'])
                ->first(fn ($x) => self::chave($x->nome) === self::chave($nome));
            if (! $s) {
                $cat = CategoriaServico::firstOrCreate(['nome' => $categoria], ['ativo' => true]);
                Servico::create(['nome' => $nome, 'preco_centavos' => 0, 'revisao_meses' => $meses, 'revisao_km' => $km,
                    'ativo' => true, 'categoria_servico_id' => $cat->id]);
                $criados[] = $nome;
            } elseif (! $s->revisao_meses && ! $s->revisao_km) {
                $s->update(['revisao_meses' => $meses, 'revisao_km' => $km]);
                $prazos[] = $nome;
            }
        }

        return ['criados' => $criados, 'prazos' => $prazos];
    }

    /**
     * @return array{criados: int, por_servico: array<string,int>, atrasados: int, proximos_30: int, depois: int,
     *               ignorados_antigos: int, ignorados_dono: int, sem_catalogo: string[], linhas: array<int, array<int, string>>}
     */
    public function lembretesDoHistorico(): array
    {
        $catalogo = [];
        $semCatalogo = [];
        foreach (self::SERVICOS as $nome => [, , , $padrao, $excecao]) {
            $s = Servico::all(['id', 'nome', 'revisao_meses', 'revisao_km'])->first(fn ($x) => self::chave($x->nome) === self::chave($nome));
            if (! $s || ! $s->revisao_meses) {
                $semCatalogo[] = $nome;

                continue;
            }
            $catalogo[$s->nome] = [$s->revisao_meses, $padrao, $excecao];
        }

        // Última data de cada serviço por carro, e o cliente da OS mais recente do carro
        $ultimo = [];
        $donoNaUltimaOs = [];
        HistoricoOsAnterior::whereNotNull('veiculo_id')->orderBy('data')->orderBy('numero')
            ->chunk(500, function ($lote) use (&$ultimo, &$donoNaUltimaOs, $catalogo) {
                foreach ($lote as $os) {
                    $donoNaUltimaOs[$os->veiculo_id] = $os->cliente_id;
                    foreach ((array) $os->itens as $item) {
                        // Só a descrição: o grupo (ex.: ARREFECIMENTO) junta peças que não são a troca em si
                        $texto = trim(mb_strtoupper(Str::ascii((string) ($item['descricao'] ?? ''))));
                        foreach ($catalogo as $nome => [, $padrao, $excecao]) {
                            if (preg_match($padrao, $texto) && (! $excecao || ! preg_match($excecao, $texto))) {
                                $ultimo[$os->veiculo_id][$nome] = $os->data->toDateString();
                            }
                        }
                    }
                }
            });

        $r = ['criados' => 0, 'por_servico' => [], 'atrasados' => 0, 'proximos_30' => 0, 'depois' => 0,
            'ignorados_antigos' => 0, 'ignorados_dono' => 0, 'sem_catalogo' => $semCatalogo,
            'linhas' => [['placa', 'cliente', 'servico', 'ultima_vez', 'proximo', 'situacao']]];
        $limite = today()->subDays(EnvioLembretes::DIAS_ATRASO_MAX);
        $veiculos = Veiculo::with('cliente')->whereKey(array_keys($ultimo))->get()->keyBy('id');

        foreach ($ultimo as $veiculoId => $servicos) {
            $v = $veiculos->get($veiculoId);
            if (! $v || ! $v->cliente_id || $donoNaUltimaOs[$veiculoId] !== $v->cliente_id) {
                $r['ignorados_dono'] += count($servicos); // carro sem dono certo ou com outro dono depois da última OS

                continue;
            }
            foreach ($servicos as $nome => $data) {
                $proximo = \Carbon\Carbon::parse($data)->addMonths($catalogo[$nome][0]);
                if ($proximo->lt($limite)) {
                    $r['ignorados_antigos']++;

                    continue;
                }
                $existe = Lembrete::where('veiculo_id', $veiculoId)->where('descricao', $nome)
                    ->where(fn ($q) => $q->whereIn('status', ['pendente', 'enviado'])->orWhere('origem', 'historico'))->exists();
                if ($existe) {
                    continue;
                }
                Lembrete::create(['veiculo_id' => $veiculoId, 'cliente_id' => $v->cliente_id, 'descricao' => $nome,
                    'data_prevista' => $proximo->toDateString(), 'origem' => 'historico']);
                $r['criados']++;
                $r['por_servico'][$nome] = ($r['por_servico'][$nome] ?? 0) + 1;
                $situacao = $proximo->lt(today()) ? 'atrasado' : ($proximo->lte(today()->addDays(30)) ? 'proximos_30_dias' : 'depois');
                $r[match ($situacao) { 'atrasado' => 'atrasados', 'proximos_30_dias' => 'proximos_30', default => 'depois' }]++;
                $r['linhas'][] = [$v->placaFormatada(), (string) $v->cliente?->nome, $nome, date('d/m/Y', strtotime($data)),
                    $proximo->format('d/m/Y'), $situacao];
            }
        }
        arsort($r['por_servico']);

        return $r;
    }

    private static function chave(string $nome): string
    {
        return mb_strtolower(trim(Str::ascii($nome)));
    }
}
