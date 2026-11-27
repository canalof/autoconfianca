<?php

namespace Tests\Feature;

use App\Models\AgendaSolicitacao;
use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\User;
use App\Models\Veiculo;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Agendamento pelo site institucional: cai na agenda e avisa pelo WhatsApp. */
class AgendamentoSiteTest extends TestCase
{
    use RefreshDatabase;

    private array $enviados = [];

    private function oficina(bool $whatsapp = true): User
    {
        config(['whatsapp.url' => $whatsapp ? 'https://evo.exemplo.com' : null, 'whatsapp.chave' => 'chave-evo',
            'whatsapp.avisos' => '19 99999-0000', 'oficina.dominio' => 'https://confiancarev.com.br']);
        Http::fake(function (Request $r) {
            $this->enviados[] = $r->data();

            return Http::response(['ok' => true]);
        });
        Http::preventStrayRequests();
        $dono = $this->novaEmpresa('AutoConfiança');
        Empresa::whereKey($dono->empresa_id)->update(['whatsapp_instancia' => 'oficina']);

        return $dono;
    }

    private function terca(): Carbon
    {
        return today()->next(Carbon::TUESDAY);
    }

    private function pedir(array $extra = [], array $cabecalhos = ['Origin' => 'https://confiancarev.com.br'])
    {
        return $this->withHeaders($cabecalhos + ['Accept' => 'application/json'])->post('/agendar', $extra + [
            'nome' => 'Marcos Pereira', 'telefone' => '(19) 98888-7777', 'veiculo' => 'Corolla 2021', 'placa' => 'abc1d23',
            'km' => '48.000', 'servico' => 'Troca de óleo', 'dia' => $this->terca()->toDateString(), 'periodo' => 'manha',
        ]);
    }

    public function test_pedido_do_site_cai_na_agenda_e_avisa_cliente_e_equipe(): void
    {
        $dono = $this->oficina();
        $this->pedir()->assertOk()->assertJson(['ok' => true, 'whatsapp' => true]);

        $pedido = Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::firstOrFail());
        $this->assertSame(['site', 'pendente', '19988887777', 'ABC1D23', 48000, 'manha', null], [$pedido->origem, $pedido->status,
            $pedido->telefone, $pedido->placa, $pedido->km, $pedido->periodo, $pedido->cliente_id]);
        $this->assertSame($this->terca()->setTime(8, 0)->toDateTimeString(), $pedido->preferencia->toDateTimeString());

        $this->assertCount(2, $this->enviados);
        $this->assertSame('5519988887777', $this->enviados[0]['number']);
        $this->assertStringContainsString('Recebemos seu pedido', $this->enviados[0]['text']);
        $this->assertSame('5519999990000', $this->enviados[1]['number']);
        $this->assertStringContainsString('Marcos Pereira', $this->enviados[1]['text']);

        $this->actingAs($dono)->get('/agenda')->assertOk()->assertSee('Pedidos de agendamento')->assertSee('Marcos Pereira')
            ->assertSee('Corolla 2021')->assertSee('sem cadastro');
    }

    public function test_sem_whatsapp_o_pedido_entra_do_mesmo_jeito(): void
    {
        $dono = $this->oficina(false);
        $this->pedir()->assertOk()->assertJson(['ok' => true, 'whatsapp' => false]);
        $this->assertSame(1, Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::count()));
        $this->assertSame([], $this->enviados);
    }

    public function test_regras_de_dia_origem_armadilha_e_limite(): void
    {
        $dono = $this->oficina();
        $this->pedir(['dia' => today()->next(Carbon::SUNDAY)->toDateString()])->assertStatus(422);
        $this->pedir(['dia' => today()->next(Carbon::SATURDAY)->toDateString(), 'periodo' => 'tarde'])->assertStatus(422);
        $this->pedir(['dia' => today()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('dia');
        $this->pedir(['telefone' => '1234'])->assertStatus(422)->assertJsonValidationErrors('telefone');
        $this->pedir([], ['Origin' => 'https://golpe.example'])->assertForbidden();
        $this->pedir(['site' => 'robô'])->assertOk();
        $this->assertSame(0, Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::count()));
        $this->pedir()->assertStatus(429); // limite por IP: 6 tentativas por hora
        \Illuminate\Support\Facades\Cache::flush();

        $this->pedir()->assertOk();
        $this->pedir()->assertOk();
        $this->pedir()->assertOk()->assertJson(['repetido' => true]);
        $this->assertSame(2, Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::count()));
    }

    public function test_cliente_cadastrado_e_reconhecido_pelo_celular_e_placa(): void
    {
        $dono = $this->oficina();
        [$cliente, $carro] = Tenant::com($dono->empresa_id, function () {
            $c = Cliente::create(['nome' => 'Marcos Pereira', 'whatsapp' => '19 8888-7777']);

            return [$c, Veiculo::create(['cliente_id' => $c->id, 'placa' => 'ABC1323', 'modelo' => 'Corolla'])];
        });
        $this->pedir()->assertOk();
        $pedido = Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::firstOrFail());
        $this->assertSame([$cliente->id, $carro->id], [$pedido->cliente_id, $pedido->veiculo_id]);
    }

    public function test_recepcao_confirma_propoe_ou_recusa_e_o_cliente_recebe_no_whatsapp(): void
    {
        $dono = $this->oficina();
        $this->pedir()->assertOk();
        $this->pedir(['nome' => 'Rita Gomes', 'telefone' => '19977776666'])->assertOk();
        $this->pedir(['nome' => 'Paulo Lima', 'telefone' => '19966665555'])->assertOk();
        [$a, $b, $c] = Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::orderBy('id')->get()->all());
        $this->enviados = [];

        $this->actingAs($dono)->post("/agenda/solicitacoes/{$a->id}", ['decisao' => 'confirmar',
            'inicio' => $this->terca()->setTime(9, 30)->format('Y-m-d\TH:i'), 'duracao_minutos' => 60])->assertSessionHas('ok');
        $ag = Tenant::com($dono->empresa_id, fn () => Agendamento::where('nome', 'Marcos Pereira')->firstOrFail());
        $this->assertSame(['confirmado', '19988887777', 'ABC1D23'], [$ag->status, $ag->telefone, $ag->placa]);
        $this->assertStringContainsString('Pedido pelo site', $ag->observacoes);
        $this->assertStringContainsString('está confirmado', $this->enviados[0]['text']);

        $this->post("/agenda/solicitacoes/{$b->id}", ['decisao' => 'confirmar',
            'inicio' => $this->terca()->setTime(15, 0)->format('Y-m-d\TH:i'), 'duracao_minutos' => 60])->assertSessionHas('ok');
        $this->assertSame('proposto', Tenant::com($dono->empresa_id, fn () => Agendamento::where('nome', 'Rita Gomes')->value('status')));
        $this->assertStringContainsString('Podemos atender', $this->enviados[1]['text']);

        $this->post("/agenda/solicitacoes/{$c->id}", ['decisao' => 'recusar'])->assertSessionHas('ok');
        $this->assertStringContainsString('não conseguimos atender', $this->enviados[2]['text']);
        $this->assertSame('5519966665555', $this->enviados[2]['number']);
    }

    public function test_isolamento_outra_empresa_nao_ve_nem_responde_pedido(): void
    {
        $dono = $this->oficina();
        $this->pedir()->assertOk();
        $pedido = Tenant::com($dono->empresa_id, fn () => AgendaSolicitacao::firstOrFail());

        $outra = $this->novaEmpresa('Outra Oficina');
        $this->actingAs($outra)->get('/agenda')->assertOk()->assertDontSee('Marcos Pereira');
        $this->post("/agenda/solicitacoes/{$pedido->id}", ['decisao' => 'recusar'])->assertNotFound();
        $this->assertSame('pendente', Tenant::com($dono->empresa_id, fn () => $pedido->fresh()->status));
    }
}
