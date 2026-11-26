<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Services\WhatsApp\WhatsApp;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Área do cliente: o cliente digita o celular cadastrado, recebe um código de 6 dígitos
 * pelo WhatsApp e entra no portal (PortalClienteController). Sem senha para lembrar.
 * Sistema de uma oficina só: a empresa é a primeira cadastrada.
 */
class AreaClienteController extends Controller
{
    private const VALIDADE_MINUTOS = 10;
    private const TENTATIVAS = 5;

    public function entrarForm(Request $request)
    {
        if (($id = $request->session()->get('area_cliente.id')) && ($portal = $this->irParaPortal($id))) {
            return $portal;
        }

        return view('area-cliente.entrar', ['whatsappLigado' => $this->whatsappLigado()]);
    }

    public function enviarCodigo(Request $request, WhatsApp $whats)
    {
        $dados = $request->validate(['celular' => ['required', 'string', 'max:20']], ['celular.required' => 'Digite o celular cadastrado na oficina.']);
        $empresa = $this->empresa();
        if (! $whats->disponivel($empresa)) {
            return back()->withInput()->with('erro', 'O envio do código pelo WhatsApp ainda não está ligado. Peça o link de acesso à oficina.');
        }
        $numero = self::digitos($dados['celular']);
        if (strlen($numero) < 10) {
            return back()->withInput()->withErrors(['celular' => 'Digite o celular com DDD.']);
        }

        $clientes = $this->clientesDoCelular($numero);
        $ids = $clientes->pluck('id')->all();
        $request->session()->forget('area_cliente');
        if ($ids) {
            $codigo = (string) random_int(100000, 999999);
            $request->session()->put('area_cliente.pendente', [
                'ids' => $ids, 'hash' => Hash::make($codigo), 'expira' => now()->addMinutes(self::VALIDADE_MINUTOS)->timestamp, 'tentativas' => 0,
            ]);
            // Envia para o número do cadastro (o que a oficina confirmou), não para o digitado
            $destino = $clientes->first()->whatsapp ?: $clientes->first()->telefone;
            $whats->enviarSigiloso($empresa, $destino, "Seu código de acesso à área do cliente {$empresa->nome_fantasia} é {$codigo}. Ele vale por ".self::VALIDADE_MINUTOS.' minutos. Não passe este código para ninguém.');
        }

        // Mesma resposta com ou sem cadastro, para não revelar quem é cliente.
        return redirect()->route('cliente.codigo')->with('ok', 'Se o número estiver cadastrado, você vai receber um código no WhatsApp.');
    }

    public function codigoForm(Request $request)
    {
        return view('area-cliente.codigo');
    }

    public function verificar(Request $request)
    {
        $dados = $request->validate(['codigo' => ['required', 'digits:6']], ['codigo.required' => 'Digite o código de 6 dígitos.', 'codigo.digits' => 'O código tem 6 dígitos.']);
        $p = $request->session()->get('area_cliente.pendente');
        if (! $p || now()->timestamp > $p['expira'] || $p['tentativas'] >= self::TENTATIVAS) {
            $request->session()->forget('area_cliente');

            return redirect()->route('cliente.entrar')->with('erro', 'O código venceu. Peça um novo.');
        }
        if (! Hash::check($dados['codigo'], $p['hash'])) {
            $request->session()->put('area_cliente.pendente.tentativas', $p['tentativas'] + 1);

            return back()->withErrors(['codigo' => 'Código incorreto.']);
        }

        $request->session()->forget('area_cliente.pendente');
        $request->session()->regenerate();
        if (count($p['ids']) > 1) {
            $request->session()->put('area_cliente.escolher', $p['ids']);

            return redirect()->route('cliente.escolher');
        }
        $request->session()->put('area_cliente.id', $p['ids'][0]);

        return $this->irParaPortal($p['ids'][0]);
    }

    /** O mesmo celular em mais de um cadastro (ex.: pessoa física e empresa). */
    public function escolherForm(Request $request)
    {
        $ids = $request->session()->get('area_cliente.escolher');
        abort_unless($ids, 403);
        Tenant::usar($this->empresa()->id);

        return view('area-cliente.escolher', ['clientes' => Cliente::whereIn('id', $ids)->orderBy('nome')->get(['id', 'nome'])]);
    }

    public function escolher(Request $request)
    {
        $ids = $request->session()->get('area_cliente.escolher');
        abort_unless($ids, 403);
        $id = (int) $request->input('cliente_id');
        abort_unless(in_array($id, $ids, true), 403);
        $request->session()->forget('area_cliente.escolher');
        $request->session()->put('area_cliente.id', $id);

        return $this->irParaPortal($id);
    }

    public function sair(Request $request)
    {
        $request->session()->forget('area_cliente');
        $request->session()->regenerate();

        return redirect()->away(config('oficina.dominio') ?: '/');
    }

    private function irParaPortal(int $id)
    {
        Tenant::usar($this->empresa()->id);
        $cliente = Cliente::find($id);
        if (! $cliente) {
            session()->forget('area_cliente');

            return null;
        }
        if (! $cliente->portal_token) {
            $cliente->forceFill(['portal_token' => Str::random(40)])->save();
        }

        return redirect()->route('portal.ver', $cliente->portal_token);
    }

    /** Clientes com este celular (WhatsApp ou telefone), comparando DDD + 8 últimos dígitos. */
    private function clientesDoCelular(string $numero): \Illuminate\Support\Collection
    {
        Tenant::usar($this->empresa()->id);
        $chave = self::chave($numero);

        return Cliente::query()->where(fn ($q) => $q->whereNotNull('whatsapp')->orWhereNotNull('telefone'))
            ->get(['id', 'whatsapp', 'telefone'])
            ->filter(fn ($c) => in_array($chave, [self::chave(self::digitos($c->whatsapp)), self::chave(self::digitos($c->telefone))], true))
            ->map(fn ($c) => tap($c, fn ($c) => $c->id = (int) $c->id))->values();
    }

    public static function digitos(?string $tel): string
    {
        $n = preg_replace('/\D/', '', (string) $tel);
        if (strlen($n) >= 12 && str_starts_with($n, '55')) {
            $n = substr($n, 2);
        }

        return $n;
    }

    /** DDD + 8 últimos dígitos: casa número com e sem o nono dígito. */
    public static function chave(string $n): ?string
    {
        return strlen($n) >= 10 ? substr($n, 0, 2).substr($n, -8) : null;
    }

    private function whatsappLigado(): bool
    {
        return app(WhatsApp::class)->disponivel($this->empresa());
    }

    private function empresa(): Empresa
    {
        return Empresa::query()->orderBy('id')->firstOrFail();
    }
}
