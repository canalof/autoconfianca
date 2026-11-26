<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\ContaReceber;
use App\Models\Empresa;
use App\Models\OrcamentoAceite;
use App\Models\OrdemServico;
use App\Models\Plano;
use App\Models\Produto;
use App\Models\User;
use App\Models\Veiculo;
use App\Notifications\RedefinirSenha;
use App\Support\Perfil;
use App\Support\StatusOs;
use App\Support\Tenant;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlataformaSegurancaTest extends TestCase
{
    use RefreshDatabase;

    private function em(User $u, callable $fn): mixed
    {
        return Tenant::com($u->empresa_id, $fn);
    }

    private function osComItem(User $u, string $valor = '100,00', ?Produto $p = null): OrdemServico
    {
        $v = $this->em($u, fn () => Veiculo::create(['placa' => 'ABC1234', 'modelo' => 'Gol', 'cliente_id' => Cliente::create(['nome' => 'Ana', 'whatsapp' => '(19) 99999-1111'])->id]));
        $this->actingAs($u)->post('/os', ['veiculo_id' => $v->id, 'cliente_id' => $v->cliente_id]);
        $os = $this->em($u, fn () => OrdemServico::latest('id')->first());
        $this->post("/os/{$os->id}/itens", $p
            ? ['tipo' => 'peca', 'produto_id' => $p->id, 'quantidade' => 2, 'valor' => '']
            : ['tipo' => 'servico', 'descricao' => 'Revisão', 'quantidade' => 1, 'valor' => $valor]);

        return $this->em($u, fn () => OrdemServico::with('itens')->find($os->id));
    }

    // ---------- Orçamento público ----------

    public function test_link_expira_e_pode_ser_trocado(): void
    {
        $u = $this->novaEmpresa();
        $os = $this->osComItem($u);
        $this->assertSame(today()->addDays(15)->toDateString(), $os->token_expira_em->toDateString());
        auth()->logout();
        $this->get('/orcamento/'.$os->token)->assertOk();

        $this->travel(16)->days();
        $this->get('/orcamento/'.$os->token)->assertStatus(410)->assertSee('não vale mais');
        $this->post('/orcamento/'.$os->token, ['nome' => 'Ana', 'decisao' => 'aprovar', 'itens' => [$os->itens[0]->id], 'versao' => $os->versao]);
        $this->assertSame('pendente', $this->em($u, fn () => $os->itens()->first()->situacao));

        $antigo = $os->token;
        $this->actingAs($u)->post("/os/{$os->id}/acao/novo-link");
        $novo = $this->em($u, fn () => $os->fresh());
        $this->assertNotSame($antigo, $novo->token);
        auth()->logout();
        $this->get('/orcamento/'.$antigo)->assertNotFound();
        $this->get('/orcamento/'.$novo->token)->assertOk();
    }

    public function test_aceite_guarda_versao_exata_e_recusa_orcamento_alterado_depois(): void
    {
        $u = $this->novaEmpresa();
        $os = $this->osComItem($u);
        $versaoVista = $os->versao;

        // oficina muda o orçamento depois que o cliente abriu a página
        $this->actingAs($u)->put("/os/{$os->id}/desconto", ['desconto' => '10,00']);
        auth()->logout();
        $this->post('/orcamento/'.$os->token, ['nome' => 'Ana', 'decisao' => 'aprovar', 'itens' => [$os->itens[0]->id], 'versao' => $versaoVista])
            ->assertSessionHas('erro');
        $this->assertSame('pendente', $this->em($u, fn () => $os->itens()->first()->situacao));

        $atual = $this->em($u, fn () => $os->fresh());
        $this->post('/orcamento/'.$os->token, ['nome' => 'Ana Lima', 'decisao' => 'aprovar', 'itens' => [$os->itens[0]->id], 'versao' => $atual->versao], ['User-Agent' => 'Celular X'])
            ->assertSessionHas('ok');

        $aceite = $this->em($u, fn () => OrcamentoAceite::first());
        $this->assertSame(['link', 'Ana Lima (pelo link)', $atual->versao, 9000, 'Celular X'], [$aceite->canal, $aceite->nome, $aceite->versao, $aceite->total_centavos, $aceite->navegador]);
        $this->assertSame('aprovado', $aceite->itens[0]['decisao']);
        $this->assertTrue($aceite->integro());

        // adulterar o registro no banco é detectado
        \DB::table('orcamento_aceites')->where('id', $aceite->id)->update(['total_centavos' => 1]);
        $this->assertFalse($this->em($u, fn () => OrcamentoAceite::first())->integro());
    }

    public function test_os_cancelada_desativa_o_link(): void
    {
        $u = $this->novaEmpresa();
        $os = $this->osComItem($u);
        $this->actingAs($u)->post("/os/{$os->id}/acao/cancelar", ['motivo' => 'desistiu']);
        auth()->logout();
        $this->get('/orcamento/'.$os->token)->assertNotFound();
    }

    // ---------- Estoque ----------

    public function test_reserva_e_bloqueio_de_estoque_negativo(): void
    {
        $u = $this->novaEmpresa();
        Empresa::whereKey($u->empresa_id)->update(['estoque_negativo' => false]);
        $p = $this->em($u, function () {
            $p = Produto::create(['nome' => 'Filtro', 'preco_centavos' => 3000]);
            $p->movimentar(1, 'entrada', 'manual');

            return $p;
        });
        $os = $this->osComItem($u, p: $p);
        $this->post("/os/{$os->id}/acao/aprovar", ['itens' => [$os->itens[0]->id]]);
        $this->assertSame(2.0, $this->em($u, fn () => $p->reservado()));
        $this->assertSame(-1.0, $this->em($u, fn () => $p->disponivel()));

        $this->post("/os/{$os->id}/acao/concluir");
        $this->post("/os/{$os->id}/receber", ['pagamentos' => [['forma' => 'pix', 'valor' => '60,00']], 'entregar' => 1])
            ->assertSessionHas('erro');
        $this->assertSame(StatusOs::Pronta, $this->em($u, fn () => $os->fresh()->status));
        $this->assertSame(1.0, (float) $this->em($u, fn () => $p->fresh()->estoque));

        $this->em($u, fn () => $p->movimentar(5, 'entrada', 'manual'));
        // o recebimento anterior foi desfeito junto com a entrega bloqueada: receber de novo
        $this->post("/os/{$os->id}/receber", ['pagamentos' => [['forma' => 'pix', 'valor' => '60,00']], 'entregar' => 1])
            ->assertSessionHas('ok');
        $this->assertSame(4.0, (float) $this->em($u, fn () => $p->fresh()->estoque));
        $this->assertSame(0.0, $this->em($u, fn () => $p->reservado()));

        // reabrir devolve ao estoque
        $this->post("/os/{$os->id}/acao/reabrir", ['motivo' => 'entregue por engano'])->assertSessionHas('ok');
        $this->assertSame([StatusOs::Pronta, 6.0], $this->em($u, fn () => [$os->fresh()->status, (float) $p->fresh()->estoque]));
    }

    // ---------- Financeiro ----------

    public function test_estorno_de_recebimento_em_dinheiro_sai_do_caixa(): void
    {
        $u = $this->novaEmpresa();
        $this->actingAs($u)->post('/caixa/abrir', ['troco' => '0']);
        $conta = $this->em($u, fn () => ContaReceber::create(['descricao' => 'Venda', 'valor_centavos' => 5000, 'vencimento' => today(), 'forma' => 'prazo']));
        $this->post("/financeiro/receber/{$conta->id}/receber", ['forma' => 'dinheiro']);
        $this->assertSame(5000, $this->em($u, fn () => Caixa::aberto()->dinheiroEsperado()));

        $this->post("/financeiro/receber/{$conta->id}/estornar", ['motivo' => 'x'])->assertSessionHas('erro');
        $this->post("/financeiro/receber/{$conta->id}/estornar", ['motivo' => 'Cliente devolveu'])->assertSessionHas('ok');
        $this->assertSame(['estornada', 0], $this->em($u, fn () => [$conta->fresh()->status, Caixa::aberto()->dinheiroEsperado()]));
        $this->post("/financeiro/receber/{$conta->id}/estornar", ['motivo' => 'Duas vezes'])->assertSessionHas('erro');
    }

    public function test_exportacao_csv(): void
    {
        $u = $this->novaEmpresa();
        $this->em($u, fn () => ContaReceber::create(['descricao' => '=HYPERLINK("x")', 'valor_centavos' => 12345, 'vencimento' => today(), 'forma' => 'prazo']));
        $r = $this->actingAs($u)->get('/financeiro/exportar/receber');
        $r->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('123,45', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'fórmula neutralizada');
        $mec = $this->usuarioNaMesmaEmpresa($u, Perfil::Mecanico);
        $this->actingAs($mec)->get('/financeiro/exportar/os')->assertForbidden();
    }

    // ---------- Agenda ----------

    public function test_agenda_respeita_capacidade(): void
    {
        $u = $this->novaEmpresa();
        Empresa::whereKey($u->empresa_id)->update(['agenda_capacidade' => 1]);
        $dados = ['nome' => 'A', 'inicio' => '2026-11-10 09:00', 'duracao_minutos' => 60, 'servico' => 'Revisão'];
        $this->actingAs($u)->post('/agenda', $dados)->assertSessionHasNoErrors();
        $this->post('/agenda', ['nome' => 'B', 'inicio' => '2026-11-10 09:30'] + $dados)->assertSessionHasErrors('inicio');
        $this->post('/agenda', ['nome' => 'C', 'inicio' => '2026-11-10 10:00'] + $dados)->assertSessionHasNoErrors(); // depois do 1º
        $this->post('/agenda', ['nome' => 'D', 'inicio' => '2026-11-10 09:30', 'encaixar' => 1] + $dados)->assertSessionHasNoErrors();
        $this->assertSame(3, $this->em($u, fn () => Agendamento::count()));
    }

    // ---------- Senha e duas etapas ----------

    public function test_recuperar_senha(): void
    {
        Notification::fake();
        $u = $this->novaEmpresa(email: 'ana@oficina.com.br');
        $this->post('/esqueci-senha', ['email' => 'naoexiste@x.com'])->assertSessionHas('ok');
        $this->post('/esqueci-senha', ['email' => 'ANA@oficina.com.br'])->assertSessionHas('ok');

        $token = null;
        Notification::assertSentTo($u, RedefinirSenha::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->get('/redefinir-senha/'.$token.'?email=ana@oficina.com.br')->assertOk();
        $this->post('/redefinir-senha', ['token' => $token, 'email' => 'ana@oficina.com.br', 'password' => 'novasenha99', 'password_confirmation' => 'novasenha99'])
            ->assertRedirect('/entrar');
        $this->post('/entrar', ['email' => 'ana@oficina.com.br', 'password' => 'novasenha99'])->assertRedirect('/');
    }

    public function test_totp_e_login_em_duas_etapas(): void
    {
        // Vetores oficiais da RFC 6238 (segredo "12345678901234567890", SHA-1, 6 últimos dígitos)
        $seg = Totp::base32('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $seg);
        $this->assertSame('287082', Totp::codigo($seg, 59));
        $this->assertSame('081804', Totp::codigo($seg, 1111111109));
        $this->assertSame('005924', Totp::codigo($seg, 1234567890));
        $this->assertTrue(Totp::valido($seg, '287082', 59 + 30)); // tolera 30 s
        $this->assertFalse(Totp::valido($seg, '287082', 59 + 90));
        $this->assertSame('Hello!', Totp::decodificar(Totp::base32('Hello!')));

        $u = $this->novaEmpresa(email: 'dono@oficina.com.br');
        $this->actingAs($u)->get('/conta/seguranca')->assertOk();
        $segredo = session('novo_segredo');
        $this->post('/conta/seguranca/ativar', ['codigo' => '000000'])->assertSessionHasErrors('codigo');
        $this->post('/conta/seguranca/ativar', ['codigo' => Totp::codigo($segredo)])->assertSessionHas('codigos_novos');
        $codigos = session('codigos_novos');
        $this->assertTrue($u->fresh()->doisFatoresAtivo());
        $this->assertNotSame($segredo, \DB::table('users')->where('id', $u->id)->value('dois_fatores_segredo'), 'segredo criptografado');

        auth()->logout();
        $this->post('/entrar', ['email' => 'dono@oficina.com.br', 'password' => 'senha12345'])->assertRedirect('/entrar/codigo');
        $this->assertGuest();
        $this->post('/entrar/codigo', ['codigo' => '123456'])->assertSessionHasErrors('codigo');
        $this->post('/entrar/codigo', ['codigo' => Totp::codigo($segredo)])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);

        // código de recuperação funciona uma vez
        auth()->logout();
        $this->post('/entrar', ['email' => 'dono@oficina.com.br', 'password' => 'senha12345']);
        $this->post('/entrar/codigo', ['codigo' => $codigos[0]])->assertRedirect('/');
        auth()->logout();
        $this->post('/entrar', ['email' => 'dono@oficina.com.br', 'password' => 'senha12345']);
        $this->post('/entrar/codigo', ['codigo' => $codigos[0]])->assertSessionHasErrors('codigo');
    }

    // ---------- Plataforma SaaS ----------

    // ---------- Portal do cliente ----------

    public function test_portal_do_cliente(): void
    {
        $u = $this->novaEmpresa();
        $os = $this->osComItem($u);
        $c = $this->em($u, fn () => Cliente::first());
        $this->actingAs($u)->post("/clientes/{$c->id}/portal");
        $token = $this->em($u, fn () => $c->fresh()->portal_token);
        $this->assertSame(40, strlen($token));

        auth()->logout();
        $this->get('/cliente/'.$token)->assertOk()->assertSee('Olá, Ana')->assertSee('Ver e aprovar orçamento');
        $this->get('/cliente/'.str_repeat('a', 40))->assertNotFound();

        $this->actingAs($u)->delete("/clientes/{$c->id}/portal");
        auth()->logout();
        $this->get('/cliente/'.$token)->assertNotFound();
    }
}
