<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\Fornecedor;
use App\Models\HistoricoOsAnterior;
use App\Models\Produto;
use App\Models\Veiculo;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/** Dados fictícios, montados aqui mesmo: nada de cliente real no repositório. */
class ImportacaoOficinaInteligenteTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/oi-'.uniqid();
        mkdir($this->dir.'/os', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/os/*') ?: []);
        array_map('unlink', glob($this->dir.'/*.xlsx') ?: []);
        @rmdir($this->dir.'/os');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Planilha .xlsx mínima (texto inline) com as linhas dadas a partir de $inicio. */
    private function xlsx(string $arquivo, array $linhas, int $inicio = 1): void
    {
        $col = fn (int $i) => chr(65 + $i % 26);
        $rows = '';
        foreach ($linhas as $k => $l) {
            $r = $inicio + $k;
            $cells = '';
            foreach (array_values($l) as $i => $v) {
                if ($v === null) {
                    continue;
                }
                $cells .= is_int($v) || is_float($v)
                    ? "<c r=\"{$col($i)}{$r}\"><v>{$v}</v></c>"
                    : "<c r=\"{$col($i)}{$r}\" t=\"inlineStr\"><is><t>".htmlspecialchars((string) $v, ENT_XML1).'</t></is></c>';
            }
            $rows .= "<row r=\"{$r}\">{$cells}</row>";
        }
        $z = new ZipArchive;
        $z->open($arquivo, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $z->close();
    }

    private function montarExportacao(): void
    {
        $cabCad = ['Código', 'Nome/Fantasia', 'Tipo (F/J)', 'CPF/CNPJ', 'RG/Inscrição Estadual', 'Data de Nascimento', 'Razão Social', 'Apelido', 'Celular/WhatsApp', 'Telefone 1', 'Telefone 2', 'Telefone 3', 'Telefone 4', 'E-mail', 'CEP', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Cidade', 'UF', 'Outros endereços', 'Ativo', 'Observação', 'Data de Cadastro'];
        $this->xlsx("{$this->dir}/Estoque_Completo_2026-11-26.xlsx", [
            ['DescricaoDoGrupo', 'Codigo', 'Descricao', 'Aplicacao', 'Estoque', 'PrecoDeVenda', 'PrecoDeVendaTotal', 'PrecoDeCompra', 'PrecoDeCompraTotal', 'PrecoDeCusto', 'PrecoDeCustoTotal', 'NCM', 'ANP', 'Unidade', 'ICMS', 'Referencia', 'CodigoDeBarra', 'Endereco', 'ConsumoMensal', 'OrigemDaMercadoria', 'Ativo', 'RegraDeEmissao', 'Marca'],
            ['FREIO', '100', 'PASTILHA DIANTEIRA', 'ONIX', -3, 131.866666, 0, 94.19, 0, 0, 0, '87083090', null, 'JG', 18, 'PD123', null, 'A1', 0, '0', 'Sim', null, 'MARCA X'],
            ['FILTROS', '200', 'FILTRO DE OLEO', null, 0, 25, 0, 12.5, 0, 11, 0, '8421230', null, 'PÇ', 18, null, null, null, 0, '0', 'Sim', null, null],
        ]);
        $this->xlsx("{$this->dir}/Clientes_2026-11-26.xlsx", [
            $cabCad,
            ['900', 'JOÃO TESTE', 'F', '529.982.247-25', null, null, null, 'Jão', '(19) 99000-1111', null, null, null, null, 'JOAO@EXEMPLO.COM', '13000-000', 'Rua A', '10', null, 'Centro', 'Campinas', 'SP', null, 'Sim', 'cliente antigo', '08/01/2026'],
            ['901', 'MARIA FICTÍCIA', 'F', '111.111.111-11', null, null, null, null, '(19) 99000-2222', null, null, null, null, null, null, null, null, null, null, null, null, null, 'Sim', null, null],
        ]);
        $this->xlsx("{$this->dir}/Fornecedores_2026-11-26.xlsx", [
            $cabCad,
            ['500', 'AUTOPEÇAS EXEMPLO', 'J', null, null, null, null, null, '(19) 3333-4444', null, null, null, null, null, null, null, null, null, null, 'Campinas', 'SP', null, 'Sim', null, null],
        ]);
        $this->xlsx("{$this->dir}/Contas_a_Receber_Em_Aberto_2026-11-26.xlsx", [
            ['Empresa', 'Código do Título', 'Parcela', 'Plano de Conta', 'Cliente', 'Descrição', 'OS (extraída da descrição)', 'Emissão', 'Vencimento', 'Tipo Documento / Forma', 'Nº Documento', 'Tipo', 'Status', 'Valor (R$)'],
            ['Oficina', '77', '1/2', 'PAGAMENTO EM ABERTO', 'JOÃO TESTE', 'PAGAMENTO EM ABERTO', '10', null, '10/12/2026', 'OS', null, 'LCTO', 'AND', 500.5],
            ['Oficina', '78', '1/1', 'PAGAMENTO EM ABERTO', 'ALGUÉM DE FORA', 'MANUTENÇÃO', null, null, '10/01/2027', 'RCB', null, 'LCTO', 'AND', 100],
        ]);
        $cabOs = ['OS', 'Data OS', 'Cliente', 'Pesquisa', 'Responsável', 'Placa', 'Veículo', 'Finalizada em', 'Status', 'Data do Faturamento', 'Código', 'Descrição do Produto/Serviço', 'Grupo', 'Área', "RS Venda\nUnitário", 'Qtd', 'R$ Total', 'RS Custo Unitário', 'R$ Total Custo', 'Executor'];
        $this->xlsx("{$this->dir}/os/OS_com_Itens_2026-10.xlsx", [
            $cabOs,
            [10, 46296, 'MARIA FICTÍCIA', 'Cliente', 'MECÂNICO', 'ABC1D23', 'ONIX LT', 46296.5, 'Finalizada', 46296, '100', 'PASTILHA DIANTEIRA', 'FREIO', 'FREIO', 131.87, 1, 131.87, 94.19, 94.19, null],
            [11, 46300, 'JOÃO TESTE', 'Cliente', 'MECÂNICO', 'ABC1D23', 'ONIX LT', 46300.5, 'Finalizada', 46300, '200', 'FILTRO DE OLEO', 'FILTROS', 'FILTROS', 25, 2, 50, 11, 22, null],
            [11, 46300, 'JOÃO TESTE', 'Cliente', 'MECÂNICO', 'ABC1D23', 'ONIX LT', 46300.5, 'Finalizada', 46300, 'M1', 'MAO DE OBRA', 'MAO DE OBRA', 'MAO DE OBRA', 80, 1, 80, 0, 0, null],
            [12, 46301, 'SEM CADASTRO', 'Cliente', 'MECÂNICO', null, null, 46301.5, 'Finalizada', 46301, 'M1', 'MAO DE OBRA', 'MAO DE OBRA', 'MAO DE OBRA', 40, 1, 40, 0, 0, null],
        ], 3);
    }

    public function test_importa_tudo_sem_saldo_e_pode_repetir(): void
    {
        $dono = $this->novaEmpresa('AutoConfiança');
        $this->montarExportacao();

        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir, '--os' => $this->dir.'/os'])->assertSuccessful();
        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir, '--os' => $this->dir.'/os'])->assertSuccessful(); // de novo: não duplica

        Tenant::usar($dono->empresa_id);
        $this->assertSame(2, Produto::count());
        $pastilha = Produto::where('codigo', '100')->first();
        $this->assertSame('PASTILHA DIANTEIRA - ONIX', $pastilha->nome);
        $this->assertSame(13187, $pastilha->preco_centavos);
        $this->assertSame(9419, $pastilha->custo_centavos, 'Sem custo, usa o preço de compra.');
        $this->assertEquals(0, $pastilha->estoque, 'O saldo negativo do sistema antigo não é importado.');
        $this->assertNull(Produto::where('codigo', '200')->value('ncm'), 'NCM com 7 dígitos fica vazio e vai para o relatório.');

        $this->assertSame(2, Cliente::count());
        $joao = Cliente::where('codigo_anterior', '900')->first();
        $this->assertSame(['52998224725', 'joao@exemplo.com', '(19) 99000-1111'], [$joao->cpf_cnpj, $joao->email, $joao->whatsapp]);
        $this->assertNull(Cliente::where('codigo_anterior', '901')->value('cpf_cnpj'), 'CPF inválido não é gravado.');
        $this->assertSame(1, Fornecedor::count());

        // A OS mais recente define o dono atual do carro; o histórico guarda as três OS
        $this->assertSame(1, Veiculo::count());
        $this->assertSame($joao->id, Veiculo::first()->cliente_id);
        $this->assertSame(3, HistoricoOsAnterior::count());
        $this->assertSame(13000, HistoricoOsAnterior::where('numero', 11)->value('total_centavos'));
        $this->assertNull(HistoricoOsAnterior::where('numero', 12)->value('cliente_id'));

        $this->assertSame(2, ContaReceber::count());
        $this->assertSame([50050, $joao->id], [ContaReceber::where('cliente_id', $joao->id)->value('valor_centavos'), ContaReceber::where('cliente_id', $joao->id)->value('cliente_id')]);
    }

    public function test_simular_nao_grava_nada(): void
    {
        $dono = $this->novaEmpresa('AutoConfiança');
        $this->montarExportacao();
        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir, '--os' => $this->dir.'/os', '--simular' => true])->assertSuccessful();
        Tenant::usar($dono->empresa_id);
        $this->assertSame(0, Produto::count() + Cliente::count() + HistoricoOsAnterior::count());
    }

    public function test_importa_so_na_primeira_empresa_e_portal_nao_mostra_dono_anterior(): void
    {
        $dono = $this->novaEmpresa('AutoConfiança');
        $outra = $this->novaEmpresa('Outra');
        $this->montarExportacao();
        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir, '--os' => $this->dir.'/os'])->assertSuccessful();

        Tenant::usar($outra->empresa_id);
        $this->assertSame(0, Produto::count() + Cliente::count() + Veiculo::count() + HistoricoOsAnterior::count());

        // João (dono atual) vê a OS 11 dele, mas não a OS 10 da Maria, dona anterior do carro
        Tenant::usar($dono->empresa_id);
        $joao = Cliente::where('codigo_anterior', '900')->first();
        $joao->forceFill(['portal_token' => str_repeat('a', 40)])->save();
        Tenant::usar(null);
        $this->get('/cliente/'.str_repeat('a', 40))->assertOk()
            ->assertSee('Serviços no sistema anterior (1)')->assertSee('FILTRO DE OLEO')->assertDontSee('PASTILHA DIANTEIRA');
        $this->actingAs($dono)->get('/veiculos/'.Tenant::com($dono->empresa_id, fn () => Veiculo::first()->id))
            ->assertOk()->assertSee('Serviços no sistema anterior (2)');
    }

    public function test_importa_contas_a_pagar_pagas_e_abertas_sem_duplicar(): void
    {
        $dono = $this->novaEmpresa('AutoConfiança');
        $outra = $this->novaEmpresa('Outra');
        $this->montarExportacao();
        $this->xlsx("{$this->dir}/Contas_a_Pagar_Todas_2025-03_a_2028.xlsx", [
            ['Empresa', 'Código do Título', 'Parcela', 'Plano de Conta', 'Fornecedor', 'Descrição', 'Nº Documento', 'Tipo', 'Vencimento/Previsão', 'Valor (R$)', 'Status', 'Data Pagamento', 'Valor Pago (R$)'],
            ['Oficina', '300', '1/2', 'Auto Peça', 'AUTOPEÇAS EXEMPLO', 'PNEU', '555/1', 'LCTO', '17/08/2026', 388.65, 'PAG', '18/08/2026', 388.65],
            ['Oficina', '300', '2/2', 'Auto Peça', 'AUTOPEÇAS EXEMPLO', 'PNEU', '555/2', 'LCTO', '17/09/2026', 388.65, 'AND', null, null],
            ['Oficina', '301', '1/1', 'Energia Elétrica', 'COMPANHIA DE LUZ', 'Energia Elétrica', null, 'LCTO', '10/12/2026', 250, 'ATR', null, null],
            ['Oficina', '302', '1/15', 'Fatura de contas', 'CARTÃO EXEMPLO', 'FATURA', null, 'LCTO', '05/10/2026', 0, 'PAG', '05/10/2026', 944.6],
            ['Oficina', '302', '2/15', 'Fatura de contas', 'CARTÃO EXEMPLO', 'FATURA', null, 'LCTO', '05/11/2026', 0, 'AND', null, null],
        ]);

        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir])->assertSuccessful();
        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir])->assertSuccessful(); // de novo: não duplica

        Tenant::usar($dono->empresa_id);
        $this->assertSame(4, ContaPagar::count(), 'Fatura em aberto sem valor não entra.');
        $this->assertSame(94460, ContaPagar::where('descricao', 'like', '%[OI-P 302 1/15]%')->value('valor_centavos'), 'Fatura paga usa o valor pago.');
        $fornecedor = Fornecedor::where('codigo_anterior', '500')->first();
        $paga = ContaPagar::where('descricao', 'like', '%[OI-P 300 1/2]%')->first();
        $this->assertSame(['paga', 38865, $fornecedor->id, 'Peças e fornecedores', '2026-08-18', 'oficina'],
            [$paga->status, $paga->valor_centavos, $paga->fornecedor_id, $paga->categoria, $paga->pago_em->toDateString(), $paga->area]);
        $this->assertSame('aberta', ContaPagar::where('descricao', 'like', '%[OI-P 300 2/2]%')->value('status'));
        $luz = ContaPagar::where('descricao', 'like', '%[OI-P 301 1/1]%')->first();
        $this->assertSame(['aberta', null, 'Energia e água'], [$luz->status, $luz->fornecedor_id, $luz->categoria]);
        $this->assertStringContainsString('COMPANHIA DE LUZ', $luz->descricao, 'Sem fornecedor no cadastro, o nome vai na descrição.');

        Tenant::usar($outra->empresa_id);
        $this->assertSame(0, ContaPagar::count());
    }

    public function test_plano_de_contas_vira_categoria(): void
    {
        $c = fn ($p) => \App\Services\Importacao\OficinaInteligente::categoriaPagar($p);
        $this->assertSame('Salários', $c('13º Salário'));
        $this->assertSame('Impostos', $c('DAS/Simples Nacional'));
        $this->assertSame('Aluguel', $c('Aluguel'));
        $this->assertSame('Frota: IPVA, licenciamento e seguro', $c('IPVA'));
        $this->assertSame('Peças e fornecedores', $c('Compra de peça para cliente final'));
        $this->assertSame('Outros', $c('Brinde'));
    }

    public function test_avisa_quando_os_arquivos_estao_em_xls(): void
    {
        $this->novaEmpresa('AutoConfiança');
        touch($this->dir.'/os/OS_com_Itens_2026-10.xls');
        $this->artisan('autoconfianca:importar', ['pasta' => $this->dir, '--os' => $this->dir.'/os'])
            ->expectsOutputToContain('converta para .xlsx')->assertFailed();
    }
}
