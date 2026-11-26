<?php

namespace App\Http\Middleware;

use App\Support\Area;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Define a área (oficina ou locadora) da pessoa logada, guardada na sessão. */
class DefinirArea
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }
        $disponiveis = Area::disponiveis($user);
        $area = $request->session()->get('area');
        if (! in_array($area, $disponiveis, true)) {
            $area = $disponiveis[0];
            $request->session()->put('area', $area);
        }

        return Area::com($area, fn () => $next($request));
    }
}
