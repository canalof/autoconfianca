<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\MensagemWhatsapp;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteEAreaClienteTest extends TestCase
{
    use RefreshDatabase;

    private array $enviados = [];

    public function test_site_publico_abre_sem_login(): void
    {
        config(['oficina.nome' => 'AutoConfiança', 'oficina.endereco.cidade' => 'Campinas']);
        $this->get('/')->assertOk()->assertSee('O que o seu carro está fazendo?')->assertSee('AutoConfiança')->assertSee('Área do cliente');
        $this->get('/servicos/freios')->assertOk()->assertSee('Freios em Campinas');
        $this->get('/servicos/nao-existe')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/servicos/suspensao');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /sistema');
    }

    public function test_sistema_fica_em_barra_sistema_e_exige_login(): void
    {
        $this->get('/sistema')->assertRedirect('/entrar');
        $u = $this->novaEmpresa();
        $this->actingAs($u)->get('/entrar')->assertRedirect('/sistema');
    }

    private function prepararWhats(): \App\Models\User
    {
        config(['whatsapp.url' => 'https://evo.exemplo.com', 'whatsapp.chave' => 'chave-evo']);
        Http::fake(function (Request $r) {
            $this->enviados[] = $r->data();

            return Http::response(['ok' => true]);
        });
        Http::preventStrayRequests();
        $dono = $this->novaEmpresa('AutoConfiança');
        Empresa::whereKey($dono->empresa_id)->update(['whatsapp_instancia' => 'oficina']);

        return $dono;
    }

    private function cliente(int $empresaId, string $nome, string $whats): Cliente
    {
        return Tenant::com($empresaId, fn () => Cliente::create(['nome' => $nome, 'whatsapp' => $whats]));
    }

    public function test_login_por_celular_e_codigo_abre_o_portal(): void
    {
        $dono = $this->prepararWhats();
        $ana = $this->cliente($dono->empresa_id, 'Ana Souza', '(19) 99118-9202');

        // Digitado sem o nono dígito e com DDI: casa pelo DDD + 8 últimos dígitos
        $this->post('/area-do-cliente/entrar', ['celular' => '+55 19 9118-9202'])->assertRedirect('/area-do-cliente/codigo');
        $this->assertCount(1, $this->enviados);
        $this->assertSame('5519991189202', $this->enviados[0]['number'], 'Envia para o número do cadastro.');
        preg_match('/é (\d{6})\./', $this->enviados[0]['text'], $m);
        $this->assertSame(0, MensagemWhatsapp::withoutGlobalScopes()->count(), 'O código não pode ficar gravado no banco.');

        $this->post('/area-do-cliente/codigo', ['codigo' => $m[1] === '000000' ? '111111' : '000000'])->assertSessionHasErrors('codigo');
        $r = $this->post('/area-do-cliente/codigo', ['codigo' => $m[1]]);
        $token = Tenant::com($dono->empresa_id, fn () => $ana->fresh()->portal_token);
        $this->assertNotNull($token);
        $r->assertRedirect('/cliente/'.$token);
        $this->get('/cliente/'.$token)->assertOk()->assertSee('Olá, Ana!')->assertSee('Sair');

        // Já logado, "Área do cliente" leva direto ao portal; "Sair" encerra
        $this->get('/area-do-cliente')->assertRedirect('/cliente/'.$token);
        $this->post('/area-do-cliente/sair')->assertRedirect('/');
        $this->get('/area-do-cliente/entrar')->assertOk()->assertSee('Receber código');
    }

    public function test_celular_nao_cadastrado_tem_a_mesma_resposta_e_nao_envia(): void
    {
        $dono = $this->prepararWhats();
        $this->post('/area-do-cliente/entrar', ['celular' => '(11) 98888-7777'])
            ->assertRedirect('/area-do-cliente/codigo')->assertSessionHas('ok', 'Se o número estiver cadastrado, você vai receber um código no WhatsApp.');
        $this->assertCount(0, $this->enviados);
        $this->post('/area-do-cliente/codigo', ['codigo' => '123456'])->assertRedirect('/area-do-cliente/entrar');
    }

    public function test_codigo_vence_e_tem_limite_de_tentativas(): void
    {
        $dono = $this->prepararWhats();
        $this->cliente($dono->empresa_id, 'Bia', '19 99999-0000');
        $this->post('/area-do-cliente/entrar', ['celular' => '19999990000']);
        preg_match('/é (\d{6})\./', $this->enviados[0]['text'], $m);
        $errado = $m[1] === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/area-do-cliente/codigo', ['codigo' => $errado]);
        }
        $this->post('/area-do-cliente/codigo', ['codigo' => $m[1]])->assertRedirect('/area-do-cliente/entrar');

        $this->post('/area-do-cliente/entrar', ['celular' => '19999990000']);
        preg_match('/é (\d{6})\./', $this->enviados[1]['text'], $m);
        $this->travel(11)->minutes();
        $this->post('/area-do-cliente/codigo', ['codigo' => $m[1]])->assertRedirect('/area-do-cliente/entrar');
    }

    public function test_nao_encontra_cliente_de_outra_empresa(): void
    {
        $dono = $this->prepararWhats();
        $outra = $this->novaEmpresa('Outra oficina');
        $this->cliente($outra->empresa_id, 'Carlos', '(19) 98321-0357');
        $this->post('/area-do-cliente/entrar', ['celular' => '(19) 98321-0357']);
        $this->assertCount(0, $this->enviados);
    }

    public function test_mesmo_celular_em_dois_cadastros_pede_escolha(): void
    {
        $dono = $this->prepararWhats();
        $this->cliente($dono->empresa_id, 'Davi Lima', '(19) 97777-1111');
        $empresa = $this->cliente($dono->empresa_id, 'Lima Transportes', '19977771111');
        $this->post('/area-do-cliente/entrar', ['celular' => '19 97777-1111']);
        preg_match('/é (\d{6})\./', $this->enviados[0]['text'], $m);
        $this->post('/area-do-cliente/codigo', ['codigo' => $m[1]])->assertRedirect('/area-do-cliente/escolher');
        $this->get('/area-do-cliente/escolher')->assertOk()->assertSee('Davi Lima')->assertSee('Lima Transportes');
        $this->post('/area-do-cliente/escolher', ['cliente_id' => 999999])->assertForbidden();
        $this->post('/area-do-cliente/escolher', ['cliente_id' => $empresa->id])->assertRedirect();
        $this->assertSame($empresa->id, session('area_cliente.id'));
    }

    public function test_sem_whatsapp_ligado_orienta_a_pedir_o_link(): void
    {
        $this->novaEmpresa('AutoConfiança');
        $this->post('/area-do-cliente/entrar', ['celular' => '19 99999-0000'])
            ->assertSessionHas('erro', 'O envio do código pelo WhatsApp ainda não está ligado. Peça o link de acesso à oficina.');
    }
}
