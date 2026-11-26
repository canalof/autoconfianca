@props(['titulo', 'descricao', 'caminho' => '/', 'ld' => []])
@php
    $o = config('oficina');
    $base = $o['dominio'];
    $end = $o['endereco'];
    $enderecoLinha = "{$end['rua']} – {$end['bairro']}, {$end['cidade']}/{$end['uf']}";
    $tel = 'tel:+55'.preg_replace('/\D/', '', $o['telefone']);
    $wa = fn (string $msg) => $o['whatsapp'] ? 'https://wa.me/'.$o['whatsapp'].'?text='.rawurlencode($msg) : $tel;
    $mapaQ = rawurlencode("{$o['nome']}, {$end['rua']}, {$end['cidade']} {$end['uf']}");
    $comoChegar = "https://www.google.com/maps/dir/?api=1&destination={$mapaQ}";
    $negocio = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'AutoRepair',
        '@id' => $base.'/#oficina',
        'name' => $o['nome'],
        'url' => $base.'/',
        'telephone' => $o['whatsapp'] ? '+'.$o['whatsapp'] : null,
        'image' => $base.'/site/og.jpg',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $end['rua'], 'addressLocality' => $end['cidade'], 'addressRegion' => $end['uf'], 'postalCode' => $end['cep'], 'addressCountry' => 'BR'],
        'openingHoursSpecification' => collect($o['horarios'])->map(fn ($h) => ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $h['schema'], 'opens' => $h['abre'], 'closes' => $h['fecha']])->all(),
        'areaServed' => ['@type' => 'City', 'name' => $end['cidade']],
        'sameAs' => $o['instagram'] ? [$o['instagram']] : null,
    ]);
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titulo }}</title>
<meta name="description" content="{{ $descricao }}">
<link rel="canonical" href="{{ $base.$caminho }}">
<meta name="robots" content="index,follow">
<meta property="og:type" content="website">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="{{ $o['nome'] }}">
<meta property="og:title" content="{{ $titulo }}">
<meta property="og:description" content="{{ $descricao }}">
<meta property="og:url" content="{{ $base.$caminho }}">
<meta property="og:image" content="{{ $base }}/site/og.jpg">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#23313D">
<link rel="preload" href="/site/fonts/barlow-condensed-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/site/styles.css?v=2">
<link rel="icon" href="/site/favicon.svg" type="image/svg+xml">
<script type="application/ld+json">{!! json_encode($negocio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@foreach ($ld ?? [] as $item)
<script type="application/ld+json">{!! json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endforeach
</head>
<body>
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<header class="topo">
  <div class="faixa">
    <a class="marca" href="/">{{ $o['nome'] }}</a>
    <nav aria-label="Menu principal">
      <a href="/#servicos">Serviços</a>
      <a href="/#agendar">Agendar</a>
      <a href="/#onde">Onde estamos</a>
    </nav>
    <a class="topo-cliente" href="{{ route('cliente.entrar') }}">Área do cliente</a>
    <a class="topo-tel" href="{{ $tel }}">{{ $o['telefone'] }}</a>
  </div>
</header>
<main id="conteudo">
{{ $slot }}
</main>
<footer class="rodape">
  <div class="faixa rodape-grade">
    <div>
      <p class="rodape-nome">{{ $o['nome'] }}</p>
      <p>{{ $enderecoLinha }}</p>
      <p><a href="{{ $tel }}">{{ $o['telefone'] }}</a>@if ($o['instagram']) · <a href="{{ $o['instagram'] }}" rel="noopener">Instagram</a>@endif</p>
    </div>
    <div>
      <p class="rodape-nome">Serviços</p>
      <ul>@foreach ($o['servicos'] as $s)<li><a href="{{ route('site.servico', $s['slug']) }}">{{ $s['nome'] }}</a></li>@endforeach</ul>
    </div>
    <div>
      <p class="rodape-nome">Acesso</p>
      <ul>
        <li><a href="{{ route('cliente.entrar') }}">Área do cliente</a></li>
        <li><a href="{{ route('login') }}">Entrar no sistema (equipe)</a></li>
      </ul>
    </div>
  </div>
</footer>
<div class="barra-fixa" role="navigation" aria-label="Contato rápido">
  <a href="{{ $tel }}">Ligar</a>
  <a class="barra-wa" href="{{ $wa('Olá! Vim pelo site e quero um orçamento.') }}" rel="noopener">WhatsApp</a>
  <a href="{{ $comoChegar }}" rel="noopener">Como chegar</a>
</div>
<script>window.OFICINA={!! json_encode(['whatsapp' => $o['whatsapp'], 'sintomas' => $o['sintomas'], 'servicos' => collect($o['servicos'])->map(fn ($s) => ['slug' => $s['slug'], 'nome' => $s['nome']])->all()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script src="/site/app.js?v=2" defer></script>
</body>
</html>
