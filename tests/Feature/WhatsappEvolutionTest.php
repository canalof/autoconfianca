<?php

namespace Tests\Feature;

use App\Models\AgendaSolicitacao;
use App\Models\Agendamento;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\HistoricoOsAnterior;
use App\Models\Lembrete;
use App\Models\MensagemRecebida;
use App\Models\MensagemWhatsapp;
use App\Models\Servico;
use App\Models\User;
use App\Models\Veiculo;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** WhatsApp nos dois sentidos (Evolution): webhook, conexão, respostas, lembretes de revisão. Dados fictícios. */
class WhatsappEvolutionTest extends TestCase
{
    use RefreshDatabase;

    private array $enviados = [];

    private string $estadoEvolution = 'open';

    private function oficina(string $nome = 'AutoConfiança', string $instancia = 'oficina'): User
    {
        config(['whatsapp.url' => 'https://evo.exemplo.com', 'whatsapp.chave' => 'chave-evo', 'whatsapp.avisos' => '19 99999-0000',
            'whatsapp.webhook_token' => 'segredo-webhook', 'whatsapp.lembretes_atrasados_por_dia' => 20, 'whatsapp.digitando_ms' => 0]);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/instance/connectionState/')) {
                return Http::response(['instance' => ['instanceName' => 'oficina', 'state' => $this->estadoEvolution]]);
            }
            if (str_contains($r->url(), '/instance/connect/')) {
                return Http::response(str_contains($r->url(), 'number=')
                    ? ['pairingCode' => 'ABCD1234', 'code' => 'x']
                    : ['base64' => 'data:image/png;base64,QRQR', 'code' => 'x']);
            }
            $this->enviados[] = $r->data();

            return Http::response(['key' => ['id' => 'MSG'.count($this->enviados), 'fromMe' => true]], 201);
        });
        Http::preventStrayRequests();
        $dono = $this->novaEmpresa($nome);
        Empresa::whereKey($dono->empresa_id)->update(['whatsapp_instancia' => $instancia]);

        return $dono;
    }

    private function em(User $u, callable $fn): mixed
    {
        return Tenant::com($u->empresa_id, $fn);
    }

    private function carro(User $u, string $fone = '(19) 98888-7777', string $placa = 'ABC1D23'): array
    {
        return $this->em($u, function () use ($fone, $placa) {
            $c = Cliente::create(['nome' => 'Marcos Pereira', 'whatsapp' => $fone]);
            $v = Veiculo::create(['placa' => $placa, 'modelo' => 'Corolla', 'cliente_id' => $c->id]);

            return [$c, $v];
        });
    }

    private function webhook(array $corpo, string $token = 'segredo-webhook')
    {
        return $this->postJson('/webhook/evolution?token='.$token, $corpo);
    }

    private function mensagemDoCliente(string $texto, string $de = '5519988887777', string $id = 'IN1', string $instancia = 'oficina')
    {
        return $this->webhook(['event' => 'messages.upsert', 'instance' => $instancia, 'data' => [
            'key' => ['remoteJid' => $de.'@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
            'pushName' => 'Marcos', 'message' => ['conversation' => $texto], 'messageType' => 'conversation',
        ]]);
    }

    public function test_webhook_exige_token(): void
    {
        $this->oficina();
        $this->webhook(['event' => 'connection.update', 'instance' => 'oficina', 'data' => ['state' => 'close']], 'errado')->assertForbidden();
        $this->postJson('/webhook/evolution', ['event' => 'x'])->assertForbidden();
        config(['whatsapp.webhook_token' => null]);
        $this->webhook(['event' => 'connection.update', 'instance' => 'oficina', 'data' => ['state' => 'close']], '')->assertForbidden();
    }

    public function test_conexao_caida_aparece_no_painel_e_ao_voltar_reenvia_o_que_falhou(): void
    {
        $dono = $this->oficina();
        $this->webhook(['event' => 'CONNECTION_UPDATE', 'instance' => 'oficina', 'data' => ['state' => 'close']])->assertOk();
        $this->assertSame('close', Empresa::find($dono->empresa_id)->whatsapp_estado);
        $this->actingAs($dono)->get('/')->assertSee('WhatsApp da oficina desconectado');
        $this->get('/whatsapp')->assertOk()->assertSee('Desconectado');

        $falhou = $this->em($dono, fn () => MensagemWhatsapp::create(['empresa_id' => $dono->empresa_id, 'destino' => '5519988887777',
            'tipo' => 'lembrete', 'texto' => 'Oi', 'status' => 'falhou', 'tentativas' => 3]));
        $this->webhook(['event' => 'connection.update', 'instance' => 'oficina', 'data' => ['state' => 'open']])->assertOk();

        $this->assertSame('open', Empresa::find($dono->empresa_id)->whatsapp_estado);
        $this->assertSame('enviada', $this->em($dono, fn () => $falhou->fresh()->status));
        $this->actingAs($dono->fresh())->get('/')->assertDontSee('WhatsApp da oficina desconectado');
    }

    public function test_comando_confere_a_conexao(): void
    {
        $dono = $this->oficina();
        $this->estadoEvolution = 'close';
        $this->artisan('torque:whatsapp-estado')->assertSuccessful();
        $this->assertSame('close', Empresa::find($dono->empresa_id)->whatsapp_estado);
        $this->estadoEvolution = 'open';
        $this->artisan('torque:whatsapp-estado')->assertSuccessful();
        $this->assertSame('open', Empresa::find($dono->empresa_id)->whatsapp_estado);
    }

    public function test_marca_entregue_e_lida(): void
    {
        $dono = $this->oficina();
        [$c] = $this->carro($dono);
        $this->em($dono, fn () => app(\App\Services\WhatsApp\WhatsApp::class)->enviar(Empresa::find($dono->empresa_id), $c->whatsapp, 'Oi', 'lembrete'));
        $m = $this->em($dono, fn () => MensagemWhatsapp::firstOrFail());
        $this->assertSame('MSG1', $m->externo_id);

        $this->webhook(['event' => 'messages.update', 'instance' => 'oficina', 'data' => ['keyId' => 'MSG1', 'status' => 'DELIVERY_ACK']])->assertOk();
        $this->assertNotNull($this->em($dono, fn () => $m->fresh()->entregue_em));
        $this->webhook(['event' => 'messages.update', 'instance' => 'oficina', 'data' => ['keyId' => 'MSG1', 'status' => 'READ']])->assertOk();
        $this->assertNotNull($this->em($dono, fn () => $m->fresh()->lida_em));
    }

    public function test_parar_desliga_os_lembretes_e_evento_repetido_nao_duplica(): void
    {
        $dono = $this->oficina();
        [$c] = $this->carro($dono);
        $this->mensagemDoCliente('PARAR')->assertOk();
        $this->mensagemDoCliente('PARAR')->assertOk(); // mesmo id: a Evolution reenviou

        $this->assertFalse($this->em($dono, fn () => $c->fresh()->aceita_whatsapp));
        $this->assertSame(1, $this->em($dono, fn () => MensagemRecebida::count()));
        $this->assertSame('parou', $this->em($dono, fn () => MensagemRecebida::first()->acao));
        $this->assertCount(1, $this->enviados);
        $this->assertStringContainsString('não vai mais receber', $this->enviados[0]['text']);
    }

    public function test_cliente_confirma_ou_pede_para_remarcar_o_horario_de_amanha(): void
    {
        $dono = $this->oficina();
        [$c, $v] = $this->carro($dono);
        $a = $this->em($dono, fn () => Agendamento::create(['cliente_id' => $c->id, 'veiculo_id' => $v->id, 'nome' => 'Marcos Pereira',
            'inicio' => today()->addDay()->setTime(9, 0), 'servico' => 'Revisão', 'status' => 'agendado']));
        $this->artisan('torque:lembretes')->assertSuccessful();
        $this->assertStringContainsString('respondendo SIM', $this->enviados[0]['text']);

        $this->mensagemDoCliente('Sim, confirmado 👍', id: 'IN1')->assertOk();
        $this->assertSame('confirmado', $this->em($dono, fn () => $a->fresh()->status));
        $this->assertStringContainsString('confirmado', end($this->enviados)['text']);

        $this->mensagemDoCliente('Não vou conseguir, preciso remarcar', id: 'IN2')->assertOk();
        $this->assertSame('pediu_remarcar', $this->em($dono, fn () => MensagemRecebida::latest('id')->first()->acao));
        $this->assertTrue(collect($this->enviados)->contains(fn ($e) => $e['number'] === '5519999990000' && str_contains($e['text'], 'quer remarcar')));
        $this->actingAs($dono)->get('/')->assertSee('cliente quer remarcar pelo WhatsApp');
        $this->get('/whatsapp')->assertOk()->assertSee('Não vou conseguir')->assertSee('Quer remarcar');
    }

    public function test_resposta_ao_lembrete_vira_pedido_na_agenda_e_o_cliente_aceita_a_proposta(): void
    {
        $dono = $this->oficina();
        [$c, $v] = $this->carro($dono);
        $this->em($dono, fn () => Lembrete::create(['veiculo_id' => $v->id, 'cliente_id' => $c->id, 'descricao' => 'Troca de óleo e filtro', 'data_prevista' => today()->addDays(3)]));
        $this->artisan('torque:lembretes')->assertSuccessful();
        $this->assertStringContainsString('responda PARAR', $this->enviados[0]['text']);

        $this->mensagemDoCliente('Quero agendar sim')->assertOk();
        $pedido = $this->em($dono, fn () => AgendaSolicitacao::firstOrFail());
        $this->assertSame(['whatsapp', 'pendente', $c->id, $v->id, 'Troca de óleo e filtro'],
            [$pedido->origem, $pedido->status, $pedido->cliente_id, $pedido->veiculo_id, $pedido->servico]);
        $this->assertTrue(collect($this->enviados)->contains(fn ($e) => $e['number'] === '5519999990000' && str_contains($e['text'], 'pelo WhatsApp')));
        $this->actingAs($dono)->get('/agenda')->assertSee('WhatsApp')->assertSee('Marcos Pereira');

        // A recepção marca um horário: vai como proposta, e o cliente aceita respondendo
        $inicio = today()->addDays(2)->setTime(14, 0);
        $this->post("/agenda/solicitacoes/{$pedido->id}", ['decisao' => 'confirmar', 'inicio' => $inicio->format('Y-m-d\\TH:i'), 'duracao_minutos' => 60])->assertSessionHas('ok');
        $ag = $this->em($dono, fn () => Agendamento::firstOrFail());
        $this->assertSame('proposto', $ag->status);
        $this->assertStringContainsString('Podemos atender', end($this->enviados)['text']);

        $this->mensagemDoCliente('Pode ser', id: 'IN9')->assertOk();
        $this->assertSame(['confirmado', 'confirmada'], $this->em($dono, fn () => [$ag->fresh()->status, $pedido->fresh()->status]));
    }

    public function test_webhook_so_mexe_na_empresa_da_instancia(): void
    {
        $a = $this->oficina('Oficina A', 'inst-a');
        $b = $this->novaEmpresa('Oficina B');
        Empresa::whereKey($b->empresa_id)->update(['whatsapp_instancia' => 'inst-b']);
        [$clienteB] = $this->carro($b);

        $this->mensagemDoCliente('PARAR', instancia: 'inst-a')->assertOk();

        $this->assertTrue($this->em($b, fn () => $clienteB->fresh()->aceita_whatsapp), 'O cliente da outra oficina não é tocado.');
        $this->assertSame(0, $this->em($b, fn () => MensagemRecebida::count()));
        $this->assertSame([1, null], $this->em($a, fn () => [MensagemRecebida::count(), MensagemRecebida::first()->cliente_id]));
        $this->mensagemDoCliente('oi', id: 'X2', instancia: 'desconhecida')->assertOk();
        $this->assertSame(1, $this->em($a, fn () => MensagemRecebida::count()));
        $this->actingAs($b)->get('/whatsapp')->assertOk()->assertDontSee('PARAR');
    }

    public function test_atrasados_saem_aos_poucos_juntos_por_carro_e_com_segundo_aviso(): void
    {
        $dono = $this->oficina();
        config(['whatsapp.lembretes_atrasados_por_dia' => 2]);
        $this->em($dono, function () {
            foreach (range(1, 4) as $i) {
                $c = Cliente::create(['nome' => "Cliente {$i}", 'whatsapp' => "(19) 98888-000{$i}"]);
                $v = Veiculo::create(['placa' => "ABC1D2{$i}", 'modelo' => 'Gol', 'cliente_id' => $c->id]);
                Lembrete::create(['veiculo_id' => $v->id, 'cliente_id' => $c->id, 'descricao' => 'Troca de óleo e filtro', 'data_prevista' => today()->subDays(10 + $i)]);
                if ($i === 1) {
                    Lembrete::create(['veiculo_id' => $v->id, 'cliente_id' => $c->id, 'descricao' => 'Filtro de ar do motor', 'data_prevista' => today()->subDays(11)]);
                }
            }
            $parou = Cliente::create(['nome' => 'Não quer', 'whatsapp' => '(19) 98888-0009', 'aceita_whatsapp' => false]);
            $v = Veiculo::create(['placa' => 'ABC1D29', 'modelo' => 'Gol', 'cliente_id' => $parou->id]);
            Lembrete::create(['veiculo_id' => $v->id, 'cliente_id' => $parou->id, 'descricao' => 'Revisão preventiva', 'data_prevista' => today()->subDays(2)]);
        });

        $this->artisan('torque:lembretes')->assertSuccessful();
        $this->assertCount(2, $this->enviados, 'Só a cota do dia.');
        $this->assertStringContainsString('Filtro de ar do motor e Troca de óleo e filtro', $this->enviados[0]['text']);
        $this->assertStringContainsString('venceu em', $this->enviados[0]['text']);
        $this->assertSame(3, $this->em($dono, fn () => Lembrete::where('status', 'enviado')->count()));

        $this->artisan('torque:lembretes')->assertSuccessful();
        $this->assertCount(4, $this->enviados);
        $this->assertFalse(collect($this->enviados)->contains(fn ($e) => $e['number'] === '5519988880009'));

        // 14 dias depois, quem não voltou recebe o segundo aviso, uma vez só; quem agendou, não
        $this->travel(15)->days();
        $agendou = $this->em($dono, fn () => Veiculo::where('placa', 'ABC1D22')->first());
        $this->em($dono, fn () => Agendamento::create(['veiculo_id' => $agendou->id, 'nome' => 'Cliente 2', 'inicio' => now()->addDay(), 'servico' => 'Óleo']));
        $antes = count($this->enviados);
        $this->artisan('torque:lembretes')->assertSuccessful();
        $novos = array_slice($this->enviados, $antes);
        $this->assertNotEmpty($novos);
        $this->assertTrue(collect($novos)->every(fn ($e) => str_contains($e['text'], 'Passando para lembrar') || str_contains($e['text'], 'amanhã')));
        $this->assertFalse(collect($novos)->contains(fn ($e) => $e['number'] === '5519988880002' && str_contains($e['text'], 'Passando')));
        $this->artisan('torque:lembretes')->assertSuccessful(); // cota de 2 por dia: o terceiro sai hoje
        $this->artisan('torque:lembretes')->assertSuccessful(); // e nada mais: segundo aviso é uma vez só
        $segundos = collect($this->enviados)->filter(fn ($e) => str_contains($e['text'], 'Passando'))->pluck('number');
        $this->assertSame(['5519988880001', '5519988880003', '5519988880004'], $segundos->sort()->values()->all());
    }

    public function test_servicos_padrao_e_lembretes_a_partir_do_historico(): void
    {
        $dono = $this->oficina();
        $this->artisan('autoconfianca:servicos-padrao')->assertSuccessful();
        $this->artisan('autoconfianca:servicos-padrao')->assertSuccessful(); // não duplica
        $this->assertSame(1, $this->em($dono, fn () => Servico::where('nome', 'Troca de óleo e filtro')->count()));
        $this->assertSame(6, $this->em($dono, fn () => Servico::where('nome', 'Troca de óleo e filtro')->value('revisao_meses')));

        [$c, $v] = $this->carro($dono);
        [$antigo, $vendido] = $this->em($dono, function () {
            $antigo = Cliente::create(['nome' => 'Dono anterior', 'whatsapp' => '(19) 97777-0000']);
            $novo = Cliente::create(['nome' => 'Dono novo', 'whatsapp' => '(19) 96666-0000']);
            $vendido = Veiculo::create(['placa' => 'XYZ9A88', 'modelo' => 'Palio', 'cliente_id' => $novo->id]);

            return [$antigo, $vendido];
        });
        $os = fn ($n, $data, $cliente, $veiculo, $itens) => $this->em($dono, fn () => HistoricoOsAnterior::create(['numero' => $n, 'data' => $data,
            'cliente_id' => $cliente->id, 'veiculo_id' => $veiculo->id, 'cliente_nome' => $cliente->nome, 'placa' => $veiculo->placa,
            'itens' => array_map(fn ($d) => ['descricao' => $d, 'grupo' => ''], $itens), 'total_centavos' => 0]));
        $os(1, today()->subMonths(9)->toDateString(), $c, $v, ['ÓLEO 5W30 SINTÉTICO']);
        $os(2, today()->subMonths(5)->toDateString(), $c, $v, ['FILTRO DE ÓLEO', 'MÃO DE OBRA']);
        $os(3, today()->subMonths(5)->toDateString(), $c, $v, ['FLUIDO DE FREIO DOT 4', 'ÓLEO DE CÂMBIO']);
        $os(4, today()->subMonths(3)->toDateString(), $antigo, $vendido, ['TROCA DE ÓLEO']); // carro mudou de dono depois
        $os(5, today()->subYears(3)->toDateString(), $c, $v, ['CORREIA DENTADA']); // antigo demais? 48 meses: vence daqui a 1 ano

        $this->artisan('autoconfianca:lembretes-historico', ['--simular' => true])->expectsOutputToContain('seriam criados')->assertSuccessful();
        $this->assertSame(0, $this->em($dono, fn () => Lembrete::count()));

        $this->artisan('autoconfianca:lembretes-historico')->assertSuccessful();
        $this->artisan('autoconfianca:lembretes-historico')->assertSuccessful(); // não duplica
        $l = $this->em($dono, fn () => Lembrete::orderBy('descricao')->get()->keyBy('descricao'));
        $this->assertSame(['Correia dentada', 'Fluido de freio', 'Troca de óleo e filtro'], $l->keys()->all());
        $this->assertSame(today()->subMonths(5)->addMonths(6)->toDateString(), $l['Troca de óleo e filtro']->data_prevista->toDateString(), 'Conta da última troca.');
        $this->assertSame(['historico', $c->id], [$l['Troca de óleo e filtro']->origem, $l['Troca de óleo e filtro']->cliente_id]);
        $this->assertFalse($this->em($dono, fn () => Lembrete::where('veiculo_id', $vendido->id)->exists()), 'Carro com outro dono depois da OS: sem lembrete.');

        $outra = $this->novaEmpresa('Outra');
        $this->assertSame(0, $this->em($outra, fn () => Lembrete::count() + Servico::count()));
    }

    public function test_conectar_mostra_o_qr_e_avisa_quando_conecta(): void
    {
        $dono = $this->oficina();
        $this->estadoEvolution = 'close';

        $this->actingAs($dono)->get('/whatsapp')->assertSee('Conectar WhatsApp (QR Code)');
        $this->get('/whatsapp/conectar')->assertOk()->assertSee('Aparelhos conectados');
        $this->getJson('/whatsapp/conectar/qr')->assertOk()->assertJson(['estado' => 'connecting', 'qr' => 'data:image/png;base64,QRQR']);
        $this->getJson('/whatsapp/conectar/qr?numero=(19) 99484-7163')->assertOk()->assertJson(['codigo' => 'ABCD1234']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'number=5519994847163'));
        $this->getJson('/whatsapp/conectar/qr?so_estado=1')->assertOk()->assertJson(['estado' => 'close']);

        $this->estadoEvolution = 'open';
        $this->getJson('/whatsapp/conectar/qr')->assertOk()->assertJson(['estado' => 'open']);
        $this->assertSame('open', Empresa::find($dono->empresa_id)->whatsapp_estado);

        $atendente = $this->usuarioNaMesmaEmpresa($dono, \App\Support\Perfil::Consultor);
        $this->actingAs($atendente)->getJson('/whatsapp/conectar/qr')->assertForbidden();
        $outra = $this->novaEmpresa('Outra');
        $this->actingAs($outra)->getJson('/whatsapp/conectar/qr')->assertNotFound(); // sem WhatsApp configurado
    }

    public function test_mensagem_de_teste(): void
    {
        $dono = $this->oficina();
        Empresa::whereKey($dono->empresa_id)->update(['whatsapp_estado' => 'open']);
        $this->actingAs($dono->fresh())->get('/whatsapp')->assertSee('Enviar mensagem de teste');
        $this->post('/whatsapp/teste', ['numero' => '(19) 97407-3250'])->assertSessionHas('ok');
        $this->assertSame('5519974073250', $this->enviados[0]['number']);
        $this->assertStringContainsString('está funcionando', $this->enviados[0]['text']);
        $consultor = $this->usuarioNaMesmaEmpresa($dono, \App\Support\Perfil::Consultor);
        $this->actingAs($consultor)->post('/whatsapp/teste', ['numero' => '(19) 97407-3250'])->assertForbidden();
    }
}
