<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\LocadoraMulta;
use App\Models\MobilidadeCondutor;
use App\Models\MobilidadeFrota;
use App\Models\MobilidadeReserva;
use App\Models\User;
use App\Support\Perfil;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Locadora como área separada da oficina: menu, financeiro, frota, planos e multas. */
class LocadoraTest extends TestCase
{
    use RefreshDatabase;

    private function em(User $u, callable $fn): mixed
    {
        return Tenant::com($u->empresa_id, $fn);
    }

    private function comLocadora(string $nome = 'Auto Confiança'): User
    {
        $u = $this->novaEmpresa($nome);
        $u->empresa->forceFill(['modulo_locacao' => true, 'modulos' => ['condutores']])->save();

        return $u->fresh();
    }

    private function base(User $u): array
    {
        return $this->em($u, fn () => [
            Cliente::create(['nome' => 'Carla Motorista', 'cpf_cnpj' => '52998224725']),
            MobilidadeFrota::create(['placa' => 'LOC1A23', 'descricao' => 'Onix 1.0', 'km' => 20000, 'situacao' => 'disponivel',
                'diaria_centavos' => 12000, 'semanal_centavos' => 70000, 'mensal_centavos' => 250000]),
            MobilidadeCondutor::create(['nome' => 'Carla Motorista', 'categoria' => 'B', 'validade' => now()->addYear()->toDateString()]),
        ]);
    }

    private function saida(int $km = 20000): array
    {
        return ['km' => $km, 'combustivel' => 4, 'vistoria' => 'Sem avarias', 'aceite_nome' => 'Carla Motorista'];
    }

    private function alugar(User $u, array $extra): MobilidadeReserva
    {
        [$cliente, $carro, $condutor] = $this->base($u);
        $this->actingAs($u)->post('/locacao/reservas', $extra + [
            'modalidade' => 'aluguel', 'cliente_id' => $cliente->id, 'condutor_id' => $condutor->id, 'tipo' => 'frota', 'frota_id' => $carro->id,
            'inicio' => now()->subMinutes(5)->format('Y-m-d\TH:i'), 'fim' => now()->addDays(14)->subMinutes(5)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        return $this->em($u, fn () => MobilidadeReserva::latest('id')->firstOrFail());
    }

    public function test_dono_troca_de_area_e_cada_area_tem_seu_menu(): void
    {
        $u = $this->comLocadora();
        $this->actingAs($u)->get('/')->assertOk()->assertSee('Ordens de serviço')->assertDontSee('Multas');
        $this->post('/area', ['area' => 'locadora'])->assertRedirect('/locadora');
        $this->get('/')->assertRedirect('/locadora');
        $this->get('/locadora')->assertOk()->assertSee('Frota agora')->assertSee('Multas')->assertDontSee('Ordens de serviço');
        $this->post('/area', ['area' => 'oficina'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('No pátio agora');
    }

    public function test_sem_modulo_nao_existe_area_locadora(): void
    {
        $u = $this->novaEmpresa();
        $this->actingAs($u)->post('/area', ['area' => 'locadora'])->assertSessionHasErrors('area');
        $this->get('/locadora')->assertForbidden();
    }

    public function test_atendente_da_locadora_so_ve_a_locadora(): void
    {
        $dono = $this->comLocadora();
        $atendente = $this->em($dono, fn () => User::create(['empresa_id' => $dono->empresa_id, 'name' => 'Ana Balcão', 'email' => 'ana@exemplo.test',
            'password' => 'senha-forte-123', 'perfil' => Perfil::Locadora, 'ativo' => true]));
        $this->actingAs($atendente)->get('/')->assertRedirect('/locadora');
        $this->get('/locadora')->assertOk()->assertDontSee('Área do sistema');
        $this->get('/os')->assertForbidden();
        $this->get('/produtos')->assertForbidden();
        $this->post('/area', ['area' => 'oficina'])->assertSessionHasErrors('area');
        $this->get('/locacao/novo')->assertOk()->assertSee('Novo aluguel');
    }

    public function test_caixa_e_contas_sao_separados_por_area(): void
    {
        $u = $this->comLocadora();
        $this->em($u, function () {
            Caixa::create(['aberto_por' => null, 'aberto_em' => now(), 'saldo_inicial_centavos' => 5000]);
            ContaReceber::create(['descricao' => 'OS da oficina', 'valor_centavos' => 1000, 'vencimento' => today(), 'forma' => 'prazo']);
            ContaPagar::create(['categoria' => 'Outros', 'descricao' => 'Filtro da oficina', 'valor_centavos' => 2000, 'vencimento' => today()]);
            ContaReceber::create(['descricao' => 'Aluguel semana', 'valor_centavos' => 3000, 'vencimento' => today(), 'forma' => 'prazo', 'area' => 'locadora']);
        });

        $this->actingAs($u)->get('/financeiro/receber')->assertOk()->assertSee('OS da oficina')->assertDontSee('Aluguel semana');
        $this->post('/area', ['area' => 'locadora']);
        $this->get('/financeiro/receber')->assertOk()->assertSee('Aluguel semana')->assertDontSee('OS da oficina');
        $this->get('/financeiro/pagar')->assertOk()->assertDontSee('Filtro da oficina');
        // O caixa aberto da oficina não vale para a locadora.
        $this->get('/caixa')->assertOk()->assertSee('Abrir caixa');
        $this->post('/caixa/abrir', ['troco' => '100,00'])->assertRedirect();
        $this->assertSame(['locadora', 'oficina'], $this->em($u, fn () => Caixa::orderBy('area')->pluck('area')->all()));
        $this->post('/financeiro/pagar', ['categoria' => 'Frota: manutenção', 'descricao' => 'Pneus do Onix', 'valor' => '800,00', 'vencimento' => today()->toDateString()]);
        $this->assertSame('locadora', $this->em($u, fn () => ContaPagar::where('descricao', 'Pneus do Onix')->value('area')));
    }

    public function test_plano_semanal_gera_parcelas_na_saida_e_na_prorrogacao(): void
    {
        $u = $this->comLocadora();
        $r = $this->alugar($u, ['plano' => 'semanal', 'valor' => '700,00', 'km_livre' => 200, 'km_excedente' => '0,50']);
        $this->assertSame(['semanal', 70000, 0], [$r->plano, $r->valor_periodo_centavos, $r->diaria_centavos]);
        $this->assertSame(0, $this->em($u, fn () => ContaReceber::count()));

        $this->post("/locacao/reservas/{$r->id}/entregar", $this->saida())->assertSessionHas('ok');
        $parcelas = $this->em($u, fn () => ContaReceber::where('reserva_id', $r->id)->orderBy('vencimento')->get());
        $this->assertCount(2, $parcelas);
        $this->assertSame(['locadora', 'locadora'], $parcelas->pluck('area')->all());
        $this->assertSame([70000, 70000], $parcelas->pluck('valor_centavos')->all());

        $this->post("/locacao/reservas/{$r->id}/prorrogar", ['fim' => now()->addDays(21)->subMinutes(5)->format('Y-m-d\TH:i')])->assertSessionHas('ok');
        $this->assertSame(3, $this->em($u, fn () => ContaReceber::where('reserva_id', $r->id)->count()));

        // Devolve antes: 21 dias × 200 km livres = 4.200 km; rodou 5.000 → 800 km × R$ 0,50.
        $this->post("/locacao/reservas/{$r->id}/devolver", ['km' => 25000, 'taxas' => ''] + $this->saida(25000))->assertSessionHas('ok');
        $fechamento = $this->em($u, fn () => ContaReceber::where('reserva_id', $r->id)->latest('id')->first());
        $this->assertSame(40000, $fechamento->valor_centavos);
        $this->assertStringContainsString('km excedente', $fechamento->descricao);
        $this->get("/locacao/contratos/{$r->id}")->assertOk()->assertSee('3º semana')->assertSee('Encerrado');
    }

    public function test_frota_completa_e_carro_vai_para_a_oficina(): void
    {
        $u = $this->comLocadora();
        $this->actingAs($u)->post('/locadora/frota', [
            'placa' => 'frt-2b45', 'marca' => 'Fiat', 'descricao' => 'Mobi Like', 'ano' => 2023, 'cor' => 'Branco', 'combustivel' => 'flex',
            'renavam' => '0012.345.678-9', 'km' => 15000, 'situacao' => 'disponivel', 'diaria' => '99,90', 'semanal' => '600,00',
            'ipva_vencimento' => today()->addDays(10)->toDateString(), 'proxima_revisao_km' => 15300,
        ])->assertRedirect('/locadora/frota');
        $carro = $this->em($u, fn () => MobilidadeFrota::firstOrFail());
        $this->assertSame(['FRT2B45', '00123456789', 9990, 60000], [$carro->placa, $carro->renavam, $carro->diaria_centavos, $carro->semanal_centavos]);

        $this->post('/area', ['area' => 'locadora']);
        $this->get('/locadora')->assertOk()->assertSee('IPVA vence')->assertSee('Revisão aos 15.300 km');
        $this->get('/locadora/frota')->assertOk()->assertSee('Fiat Mobi Like 2023');
        $this->get('/locacao/novo')->assertOk()->assertSee('data-semanal="600,00"', false);

        $this->post("/locadora/frota/{$carro->id}/oficina")->assertRedirect('/recepcao?placa=FRT2B45');
        $this->assertSame('manutencao', $this->em($u, fn () => $carro->fresh()->situacao));
        $this->get('/')->assertOk()->assertSee('No pátio agora');
    }

    public function test_multa_acha_o_contrato_e_e_cobrada_do_cliente(): void
    {
        $u = $this->comLocadora();
        $r = $this->alugar($u, ['plano' => 'diaria', 'valor' => '120,00']);
        $this->post("/locacao/reservas/{$r->id}/entregar", $this->saida())->assertSessionHas('ok');
        $this->post('/area', ['area' => 'locadora']);

        $this->post('/locadora/multas', ['frota_id' => $r->frota_id, 'data_infracao' => now()->format('Y-m-d\TH:i'), 'descricao' => 'Avançar sinal',
            'valor' => '293,47', 'auto' => 'A123', 'lancar_pagar' => '1'])->assertSessionHas('ok');
        $multa = $this->em($u, fn () => LocadoraMulta::firstOrFail());
        $this->assertSame([$r->id, $r->cliente_id, 29347], [$multa->reserva_id, $multa->cliente_id, $multa->valor_centavos]);
        $this->assertSame('locadora', $this->em($u, fn () => ContaPagar::where('categoria', 'Multas de trânsito')->value('area')));

        $this->post("/locadora/multas/{$multa->id}/cobrar")->assertSessionHas('ok');
        $titulo = $this->em($u, fn () => ContaReceber::where('categoria', 'multa')->firstOrFail());
        $this->assertSame([29347, 'locadora', $r->cliente_id], [$titulo->valor_centavos, $titulo->area, $titulo->cliente_id]);
        $this->assertSame('cobrada', $this->em($u, fn () => $multa->fresh()->situacao));
        $this->post("/locadora/multas/{$multa->id}/cobrar")->assertStatus(422);
    }

    public function test_isolamento_entre_empresas_na_locadora(): void
    {
        $a = $this->comLocadora('Empresa A');
        $r = $this->alugar($a, ['plano' => 'diaria', 'valor' => '100,00']);
        $carroA = $this->em($a, fn () => MobilidadeFrota::firstOrFail());
        $this->post('/locadora/multas', ['frota_id' => $carroA->id, 'data_infracao' => now()->format('Y-m-d\TH:i'), 'descricao' => 'Estacionar', 'valor' => '100,00']);
        $multa = $this->em($a, fn () => LocadoraMulta::firstOrFail());

        $b = $this->comLocadora('Empresa B');
        $this->actingAs($b)->get("/locacao/contratos/{$r->id}")->assertNotFound();
        $this->get("/locadora/frota/{$carroA->id}/editar")->assertNotFound();
        $this->put("/locadora/frota/{$carroA->id}", ['placa' => 'LOC1A23', 'descricao' => 'X', 'km' => 1, 'situacao' => 'inativo'])->assertNotFound();
        $this->post("/locadora/frota/{$carroA->id}/oficina")->assertNotFound();
        $this->post("/locadora/multas/{$multa->id}/cobrar")->assertNotFound();
        $this->post('/locadora/multas', ['frota_id' => $carroA->id, 'data_infracao' => now()->format('Y-m-d\TH:i'), 'descricao' => 'X', 'valor' => '1,00'])
            ->assertSessionHasErrors('frota_id');
        $this->get('/locadora/frota')->assertOk()->assertDontSee('Onix 1.0');
        $this->get('/locadora/multas')->assertOk()->assertDontSee('Estacionar');
    }
}
