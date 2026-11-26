<?php

namespace App\Http\Controllers;

use App\Support\Area;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Troca entre oficina e locadora (cada uma com menu, caixa e financeiro próprios). */
class AreaController extends Controller
{
    public function __invoke(Request $request)
    {
        $dados = $request->validate(['area' => ['required', Rule::in(Area::disponiveis($request->user()))]]);
        $request->session()->put('area', $dados['area']);

        return redirect()->route($dados['area'] === Area::LOCADORA ? 'locadora.painel' : 'painel');
    }
}
