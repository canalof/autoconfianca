<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Site público da oficina (sem login). O conteúdo vem de config/oficina.php.
 */
class SiteController extends Controller
{
    public function inicio()
    {
        // O site institucional é estático, servido na raiz do domínio.
        return redirect()->away(config('oficina.dominio') ?: '/');
    }

    public function servico(string $slug)
    {
        $servico = collect(config('oficina.servicos'))->firstWhere('slug', $slug);
        abort_unless($servico, 404);

        return view('site.servico', ['s' => $servico]);
    }

    public function sitemap(): Response
    {
        $base = config('oficina.dominio');
        $hoje = now()->toDateString();
        $urls = collect(['/', '/area-do-cliente/entrar'])
            ->merge(collect(config('oficina.servicos'))->map(fn ($s) => '/servicos/'.$s['slug']));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$urls->map(fn ($u) => "  <url><loc>{$base}{$u}</loc><lastmod>{$hoje}</lastmod></url>")->implode("\n")
            ."\n</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $base = config('oficina.dominio');
        $txt = "User-agent: *\nAllow: /\nDisallow: /sistema\nDisallow: /area-do-cliente\nDisallow: /entrar\nDisallow: /orcamento/\nDisallow: /cliente/\n\nSitemap: {$base}/sitemap.xml\n";

        return response($txt, 200, ['Content-Type' => 'text/plain']);
    }
}
