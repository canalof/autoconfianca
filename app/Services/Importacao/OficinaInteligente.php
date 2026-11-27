<?php

namespace App\Services\Importacao;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\Fornecedor;
use App\Models\HistoricoOsAnterior;
use App\Models\Produto;
use App\Models\Veiculo;
use App\Support\Documento;
use App\Support\LeitorXlsx;
use App\Support\Placa;
use Illuminate\Support\Str;

/**
 * Importa a exportação do Oficina Inteligente (planilhas .xlsx) para a empresa atual (Tenant).
 * Pode ser rodada mais de uma vez: produtos casam pelo código, clientes e fornecedores pelo
 * código do sistema anterior, OS antigas pelo número e títulos pelo código + parcela.
 * O saldo de estoque NÃO é importado (no sistema antigo estava zerado ou negativo):
 * a oficina faz a contagem pelo Balanço de estoque.
 */
class OficinaInteligente
{
    /** Relatórios para conferência: nome do arquivo => linhas (a primeira é o cabeçalho). */
    public array $relatorios = [
        'ncm_invalido' => [['codigo', 'produto', 'ncm_no_sistema_antigo']],
        'documento_invalido' => [['tipo', 'codigo_anterior', 'nome', 'documento']],
        'placa_com_mais_de_um_dono' => [['placa', 'donos_nas_os', 'dono_escolhido']],
        'os_sem_cliente_no_cadastro' => [['os', 'data', 'cliente_na_os', 'placa', 'motivo']],
        'titulo_sem_cliente' => [['titulo', 'parcela', 'cliente', 'valor']],
        'conta_pagar_sem_fornecedor' => [['titulo', 'parcela', 'fornecedor', 'valor']],
        'conta_pagar_sem_valor' => [['titulo', 'parcela', 'fornecedor', 'descricao', 'vencimento']],
    ];

    public array $totais = [];

    public function produtos(string $arquivo): void
    {
        $cat = [];
        $c = $a = 0;
        foreach (LeitorXlsx::linhas($arquivo) as $l) {
            $codigo = trim((string) ($l['Codigo'] ?? ''));
            $nome = trim((string) ($l['Descricao'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }
            $aplicacao = trim((string) ($l['Aplicacao'] ?? ''));
            $ncm = preg_replace('/\D/', '', (string) ($l['NCM'] ?? ''));
            if ($ncm !== '' && strlen($ncm) !== 8) {
                $this->relatorios['ncm_invalido'][] = [$codigo, $nome, $l['NCM']];
                $ncm = '';
            }
            $grupo = trim((string) ($l['DescricaoDoGrupo'] ?? ''));
            if ($grupo !== '' && ! isset($cat[$grupo])) {
                $cat[$grupo] = Categoria::firstOrCreate(['nome' => mb_substr($grupo, 0, 80)], ['ativo' => true])->id;
            }
            $custo = self::centavos($l['PrecoDeCusto'] ?? null) ?: self::centavos($l['PrecoDeCompra'] ?? null);
            $dados = [
                'nome' => mb_substr($aplicacao !== '' ? "{$nome} - {$aplicacao}" : $nome, 0, 150),
                'categoria_id' => $cat[$grupo] ?? null,
                'marca' => self::texto($l['Marca'] ?? null, 60),
                'referencia_fabricante' => self::texto($l['Referencia'] ?? null, 40),
                'codigo_barras' => ($b = preg_replace('/\D/', '', (string) ($l['CodigoDeBarra'] ?? ''))) !== '' && strlen($b) <= 14 ? $b : null,
                'unidade' => mb_substr(mb_strtoupper(trim((string) ($l['Unidade'] ?? ''))) ?: 'UN', 0, 6),
                'ncm' => $ncm ?: null,
                'localizacao' => self::texto($l['Endereco'] ?? null, 40),
                'custo_centavos' => $custo,
                'preco_centavos' => self::centavos($l['PrecoDeVenda'] ?? null),
                'ativo' => mb_strtolower((string) ($l['Ativo'] ?? 'sim')) !== 'não',
            ];
            $p = Produto::where('codigo', $codigo)->first();
            if ($p) {
                $p->update($dados);
                $a++;
            } else {
                Produto::create($dados + ['codigo' => mb_substr($codigo, 0, 30)]);
                $c++;
            }
        }
        $this->totais['produtos'] = "{$c} criados, {$a} atualizados (saldo não importado)";
    }

    public function clientes(string $arquivo): void
    {
        $c = $a = 0;
        foreach (LeitorXlsx::linhas($arquivo) as $l) {
            $codigo = trim((string) ($l['Código'] ?? ''));
            $nome = trim((string) ($l['Nome/Fantasia'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }
            $doc = $this->documento('cliente', $codigo, $nome, $l['CPF/CNPJ'] ?? null);
            $obs = array_filter([
                ($r = trim((string) ($l['Razão Social'] ?? ''))) !== '' && $r !== $nome ? "Razão social: {$r}" : null,
                ($ap = trim((string) ($l['Apelido'] ?? ''))) !== '' ? "Apelido: {$ap}" : null,
                ($t = self::texto($l['Telefone 2'] ?? null, 20)) ? "Outro telefone: {$t}" : null,
                ($oe = trim((string) ($l['Outros endereços'] ?? ''))) !== '' ? "Outros endereços: {$oe}" : null,
                trim((string) ($l['Observação'] ?? '')) ?: null,
                "Cadastro no Oficina Inteligente: {$codigo}".(($d = LeitorXlsx::data($l['Data de Cadastro'] ?? null)) ? ' (desde '.date('d/m/Y', strtotime($d)).')' : ''),
            ]);
            $email = mb_strtolower(trim((string) ($l['E-mail'] ?? '')));
            $dados = [
                'nome' => mb_substr($nome, 0, 150),
                'tipo_pessoa' => $doc ? Documento::tipo($doc) : (mb_strtoupper(trim((string) ($l['Tipo (F/J)'] ?? 'F'))) === 'J' ? 'PJ' : 'PF'),
                'cpf_cnpj' => $doc,
                'ie' => self::texto($l['RG/Inscrição Estadual'] ?? null, 20),
                'whatsapp' => self::texto($l['Celular/WhatsApp'] ?? null, 20),
                'telefone' => self::texto($l['Telefone 1'] ?? null, 20) ?? self::texto($l['Telefone 3'] ?? null, 20),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'cep' => ($cep = preg_replace('/\D/', '', (string) ($l['CEP'] ?? ''))) !== '' ? substr($cep, 0, 8) : null,
                'logradouro' => self::texto($l['Logradouro'] ?? null, 150),
                'numero' => self::texto($l['Número'] ?? null, 20),
                'complemento' => self::texto($l['Complemento'] ?? null, 80),
                'bairro' => self::texto($l['Bairro'] ?? null, 80),
                'cidade' => self::texto($l['Cidade'] ?? null, 80),
                'uf' => ($uf = mb_strtoupper(trim((string) ($l['UF'] ?? '')))) !== '' ? mb_substr($uf, 0, 2) : null,
                'nascimento' => LeitorXlsx::data($l['Data de Nascimento'] ?? null),
                'observacoes' => implode("\n", $obs),
            ];
            $existente = Cliente::where('codigo_anterior', $codigo)->first();
            if ($doc && Cliente::where('cpf_cnpj', $doc)->when($existente, fn ($q) => $q->whereKeyNot($existente->id))->exists()) {
                $this->relatorios['documento_invalido'][] = ['cliente (documento repetido)', $codigo, $nome, $doc];
                $dados['cpf_cnpj'] = null;
            }
            if ($existente) {
                $existente->update($dados);
                $a++;
            } else {
                Cliente::create($dados + ['codigo_anterior' => $codigo]);
                $c++;
            }
        }
        $this->totais['clientes'] = "{$c} criados, {$a} atualizados";
    }

    public function fornecedores(string $arquivo): void
    {
        $c = $a = 0;
        foreach (LeitorXlsx::linhas($arquivo) as $l) {
            $codigo = trim((string) ($l['Código'] ?? ''));
            $nome = trim((string) ($l['Nome/Fantasia'] ?? ''));
            if ($codigo === '' || $nome === '') {
                continue;
            }
            $doc = $this->documento('fornecedor', $codigo, $nome, $l['CPF/CNPJ'] ?? null);
            $existente = Fornecedor::where('codigo_anterior', $codigo)->first();
            if ($doc && Fornecedor::where('cpf_cnpj', $doc)->when($existente, fn ($q) => $q->whereKeyNot($existente->id))->exists()) {
                $this->relatorios['documento_invalido'][] = ['fornecedor (documento repetido)', $codigo, $nome, $doc];
                $doc = null;
            }
            $email = mb_strtolower(trim((string) ($l['E-mail'] ?? '')));
            $dados = [
                'nome' => mb_substr($nome, 0, 150), 'cpf_cnpj' => $doc,
                'telefone' => self::texto($l['Celular/WhatsApp'] ?? null, 20) ?? self::texto($l['Telefone 1'] ?? null, 20),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'cidade' => self::texto($l['Cidade'] ?? null, 80),
                'uf' => ($uf = mb_strtoupper(trim((string) ($l['UF'] ?? '')))) !== '' ? mb_substr($uf, 0, 2) : null,
                'observacoes' => trim(implode("\n", array_filter([trim((string) ($l['Observação'] ?? '')) ?: null, "Cadastro no Oficina Inteligente: {$codigo}"]))),
            ];
            if ($existente) {
                $existente->update($dados);
                $a++;
            } else {
                Fornecedor::create($dados + ['codigo_anterior' => $codigo]);
                $c++;
            }
        }
        $this->totais['fornecedores'] = "{$c} criados, {$a} atualizados";
    }

    /**
     * OS com itens (um ou mais arquivos .xlsx, cabeçalho na linha 3): cria os veículos pela placa,
     * liga ao dono da OS mais recente e guarda cada OS no histórico.
     *
     * @param  string[]  $arquivos
     */
    public function ordensDeServico(array $arquivos): void
    {
        $os = [];
        foreach ($arquivos as $arquivo) {
            foreach (LeitorXlsx::linhas($arquivo, 3) as $l) {
                $l = array_combine(array_map(fn ($k) => preg_replace('/\s+/', ' ', (string) $k), array_keys($l)), $l);
                $n = (int) ($l['OS'] ?? 0);
                if ($n <= 0) {
                    continue;
                }
                $os[$n] ??= [
                    'numero' => $n, 'data' => LeitorXlsx::data($l['Data OS'] ?? null), 'cliente' => trim((string) ($l['Cliente'] ?? '')),
                    'placa' => Placa::normalizar($l['Placa'] ?? null), 'veiculo' => self::texto($l['Veículo'] ?? null, 80),
                    'responsavel' => self::texto($l['Responsável'] ?? null, 80), 'status' => self::texto($l['Status'] ?? null, 20), 'itens' => [],
                ];
                $os[$n]['itens'][] = [
                    'codigo' => trim((string) ($l['Código'] ?? '')), 'descricao' => trim((string) ($l['Descrição do Produto/Serviço'] ?? '')),
                    'grupo' => trim((string) ($l['Grupo'] ?? '')), 'quantidade' => (float) ($l['Qtd'] ?? 0),
                    'unitario_centavos' => self::centavos($l['RS Venda Unitário'] ?? null), 'total_centavos' => self::centavos($l['R$ Total'] ?? null),
                ];
            }
        }
        ksort($os);
        usort($os, fn ($x, $y) => [$x['data'], $x['numero']] <=> [$y['data'], $y['numero']]);

        $porNome = Cliente::get(['id', 'nome'])->groupBy(fn ($c) => self::chaveNome($c->nome));
        $donos = [];
        $h = 0;
        foreach ($os as $o) {
            $candidatos = $porNome->get(self::chaveNome($o['cliente']));
            $clienteId = $candidatos && $candidatos->count() === 1 ? $candidatos->first()->id : null;
            if (! $clienteId) {
                $this->relatorios['os_sem_cliente_no_cadastro'][] = [$o['numero'], $o['data'], $o['cliente'], $o['placa'],
                    $candidatos ? 'nome repetido no cadastro' : 'nome não encontrado no cadastro'];
            }
            $veiculoId = null;
            if (Placa::valida($o['placa'])) {
                $v = Veiculo::where('placa', $o['placa'])->first()
                    ?? Veiculo::create(['placa' => $o['placa'], 'modelo' => $o['veiculo'], 'cliente_id' => $clienteId,
                        'observacoes' => 'Criado a partir das OS do Oficina Inteligente.']);
                if ($clienteId) {
                    $donos[$o['placa']][$clienteId] = $o['cliente'];
                    if ($v->cliente_id !== $clienteId) {
                        $v->update(['cliente_id' => $clienteId]); // a OS mais recente define o dono atual
                    }
                }
                if (! $v->modelo && $o['veiculo']) {
                    $v->update(['modelo' => $o['veiculo']]);
                }
                $veiculoId = $v->id;
            }
            HistoricoOsAnterior::updateOrCreate(['numero' => $o['numero']], [
                'data' => $o['data'] ?? today()->toDateString(), 'cliente_id' => $clienteId, 'veiculo_id' => $veiculoId,
                'cliente_nome' => mb_substr($o['cliente'], 0, 150), 'placa' => $o['placa'] ?: null, 'veiculo' => $o['veiculo'],
                'responsavel' => $o['responsavel'], 'status' => $o['status'], 'itens' => $o['itens'],
                'total_centavos' => array_sum(array_column($o['itens'], 'total_centavos')),
            ]);
            $h++;
        }
        foreach ($donos as $placa => $lista) {
            if (count($lista) > 1) {
                $atual = Veiculo::where('placa', $placa)->first()?->cliente?->nome;
                $this->relatorios['placa_com_mais_de_um_dono'][] = [Placa::formatar($placa), implode(' | ', $lista), $atual];
            }
        }
        $this->totais['os_anteriores'] = "{$h} OS no histórico, ".Veiculo::count().' veículos';
    }

    public function contasAReceber(string $arquivo): void
    {
        $porNome = Cliente::get(['id', 'nome'])->groupBy(fn ($c) => self::chaveNome($c->nome));
        $c = 0;
        foreach (LeitorXlsx::linhas($arquivo) as $l) {
            $titulo = trim((string) ($l['Código do Título'] ?? ''));
            $valor = self::centavos($l['Valor (R$)'] ?? null);
            if ($titulo === '' || $valor <= 0) {
                continue;
            }
            $parcela = trim((string) ($l['Parcela'] ?? ''));
            $marca = "[OI {$titulo} {$parcela}]";
            if (ContaReceber::where('descricao', 'like', "%{$marca}%")->exists()) {
                continue;
            }
            $nome = trim((string) ($l['Cliente'] ?? ''));
            $cands = $porNome->get(self::chaveNome($nome));
            $clienteId = $cands && $cands->count() === 1 ? $cands->first()->id : null;
            if (! $clienteId) {
                $this->relatorios['titulo_sem_cliente'][] = [$titulo, $parcela, $nome, number_format($valor / 100, 2, ',', '')];
            }
            $desc = trim((string) ($l['Descrição'] ?? ''));
            $os = trim((string) ($l['OS (extraída da descrição)'] ?? ''));
            ContaReceber::create([
                'cliente_id' => $clienteId,
                'descricao' => mb_substr(trim(($desc !== '' && $desc !== 'PAGAMENTO EM ABERTO' ? $desc : 'Título do Oficina Inteligente').($os ? " (OS {$os})" : '').($clienteId ? '' : " · {$nome}")." {$marca}"), 0, 200),
                'parcela' => $parcela !== '' ? mb_substr($parcela, 0, 10) : null,
                'valor_centavos' => $valor,
                'vencimento' => LeitorXlsx::data($l['Vencimento'] ?? null) ?? today()->toDateString(),
                'forma' => 'prazo', // no sistema antigo: OS/RCB (tipo de lançamento, não forma de pagamento)
                'status' => 'aberta',
            ]);
            $c++;
        }
        $this->totais['contas_a_receber'] = "{$c} títulos lançados";
    }

    /**
     * Contas a pagar (pagas e em aberto). Casam pelo código do título + parcela, gravados na
     * descrição como "[OI-P 123 1/3]". Pagas entram como pagas, com a data do pagamento.
     * O plano de contas antigo vira uma das categorias do sistema e fica escrito na descrição.
     */
    public function contasAPagar(string $arquivo): void
    {
        $porNome = Fornecedor::get(['id', 'nome'])->groupBy(fn ($f) => self::chaveNome($f->nome));
        $abertas = $pagas = 0;
        foreach (LeitorXlsx::linhas($arquivo) as $l) {
            $titulo = trim((string) ($l['Código do Título'] ?? ''));
            if ($titulo === '') {
                continue;
            }
            $parcela = trim((string) ($l['Parcela'] ?? ''));
            $paga = mb_strtoupper(trim((string) ($l['Status'] ?? ''))) === 'PAG';
            // Faturas de cartão vêm com valor previsto zero; quando pagas, vale o valor pago.
            $valor = self::centavos($l['Valor (R$)'] ?? null) ?: ($paga ? self::centavos($l['Valor Pago (R$)'] ?? null) : 0);
            if ($valor <= 0) {
                $this->relatorios['conta_pagar_sem_valor'][] = [$titulo, $parcela, $l['Fornecedor'] ?? '', $l['Descrição'] ?? '', $l['Vencimento/Previsão'] ?? ''];

                continue;
            }
            $marca = "[OI-P {$titulo} {$parcela}]";
            if (ContaPagar::where('descricao', 'like', "%{$marca}%")->exists()) {
                continue;
            }
            $nome = trim((string) ($l['Fornecedor'] ?? ''));
            $cands = $nome !== '' ? $porNome->get(self::chaveNome($nome)) : null;
            $fornecedorId = $cands && $cands->count() === 1 ? $cands->first()->id : null;
            if (! $fornecedorId && $nome !== '') {
                $this->relatorios['conta_pagar_sem_fornecedor'][] = [$titulo, $parcela, $nome, number_format($valor / 100, 2, ',', '')];
            }
            $plano = trim((string) ($l['Plano de Conta'] ?? ''));
            $desc = trim((string) ($l['Descrição'] ?? ''));
            $texto = implode(' · ', array_filter([
                $desc !== '' ? $desc : 'Conta do Oficina Inteligente',
                $plano !== '' && mb_strtoupper($plano) !== mb_strtoupper($desc) ? $plano : null,
                $fornecedorId ? null : ($nome ?: null),
            ]));
            $vencimento = LeitorXlsx::data($l['Vencimento/Previsão'] ?? null) ?? today()->toDateString();
            ContaPagar::create([
                'fornecedor_id' => $fornecedorId,
                'categoria' => self::categoriaPagar($plano),
                'descricao' => mb_substr(mb_substr($texto, 0, 200 - mb_strlen($marca) - 1).' '.$marca, 0, 200),
                'documento' => self::texto($l['Nº Documento'] ?? null, 40),
                'valor_centavos' => $valor,
                'vencimento' => $vencimento,
                'status' => $paga ? 'paga' : 'aberta',
                'pago_em' => $paga ? (LeitorXlsx::data($l['Data Pagamento'] ?? null) ?? $vencimento).' 12:00:00' : null,
                'area' => 'oficina',
            ]);
            $paga ? $pagas++ : $abertas++;
        }
        $this->totais['contas_a_pagar'] = "{$abertas} em aberto e {$pagas} pagas lançadas";
    }

    /** Plano de contas do Oficina Inteligente → categoria de ContaPagar::CATEGORIAS. */
    public static function categoriaPagar(string $plano): string
    {
        $p = mb_strtolower(Str::ascii($plano));
        $regras = [
            'Frota: IPVA, licenciamento e seguro' => ['ipva', 'licenciamento', 'seguro de veiculo', 'dpvat'],
            'Frota: financiamento' => ['financiamento', 'consorcio'],
            'Multas de trânsito' => ['multa de veiculo'],
            'Frota: manutenção' => ['veiculo da empresa', 'manutencao do veiculo'],
            'Salários' => ['salario', '13', 'ferias', 'fgts', 'inss', 'rescisao', 'vale', 'hora extra', 'pro-labore', 'adiantamento', 'funcionario', 'cesta', 'plano de saude', 'exame', 'uniforme', 'participacao'],
            'Impostos' => ['das/', 'simples', 'darf', 'iss', 'iptu', 'iof', 'contribuicao sindical'],
            'Aluguel' => ['aluguel'],
            'Energia e água' => ['energia', 'agua'],
            'Internet e telefone' => ['internet', 'telefone', 'provedor', 'tv a cabo'],
            'Ferramentas e equipamentos' => ['ferramenta', 'maquina', 'equipamento', 'computador', 'moveis'],
            'Sistemas e serviços' => ['sistema', 'software', 'contador', 'consultoria', 'google', 'sms', 'advogado', 'alarme', 'propaganda', 'grafica', 'prestacao de servico', 'tarifa', 'taxa de adm', 'taxa de ant'],
            'Peças e fornecedores' => ['peca', 'distribuidor', 'concessionaria', 'estoque', 'frete', 'transportador', 'acacia', 'fatura'],
        ];
        foreach ($regras as $categoria => $palavras) {
            foreach ($palavras as $w) {
                if (str_contains($p, $w)) {
                    return $categoria;
                }
            }
        }

        return 'Outros';
    }

    private function documento(string $tipo, string $codigo, string $nome, ?string $doc): ?string
    {
        $d = Documento::normalizar($doc);
        if ($d === '') {
            return null;
        }
        if (! Documento::valido($d)) {
            $this->relatorios['documento_invalido'][] = [$tipo.' (inválido)', $codigo, $nome, $doc];

            return null;
        }

        return $d;
    }

    public static function centavos(mixed $v): int
    {
        $v = trim((string) $v);
        if ($v === '') {
            return 0;
        }

        return is_numeric($v) ? (int) round(((float) $v) * 100) : \App\Support\Dinheiro::paraCentavos($v);
    }

    private static function texto(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    public static function chaveNome(?string $nome): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim(Str::ascii((string) $nome))));
    }
}
