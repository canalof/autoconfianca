@props(['titulo'])
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $titulo }} · {{ config('oficina.nome') }}</title>
    <link rel="icon" href="/site/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-fundo">
    <header class="bg-asfalto text-white">
        <div class="mx-auto flex max-w-md items-center justify-between px-5 py-4">
            <a href="{{ route('site.inicio') }}" class="text-lg font-bold">{{ config('oficina.nome') }}</a>
            <a href="{{ route('site.inicio') }}" class="text-sm text-[#c5ced6] hover:underline">Voltar ao site</a>
        </div>
    </header>
    <main class="mx-auto max-w-md px-5 py-10">
        <p class="mb-1 text-sm font-semibold text-suave">Área do cliente</p>
        <h1 class="mb-2">{{ $titulo }}</h1>
        @if (session('ok'))<div class="mb-4 rounded-md border border-[#b7dcc6] bg-[#eef8f2] px-3 py-2 text-[15px] text-[#185c35]" role="status">{{ session('ok') }}</div>@endif
        @if (session('erro'))<div class="mb-4 rounded-md border border-[#ecc3bf] bg-[#fcf0ef] px-3 py-2 text-[15px] text-[#8c241d]" role="alert">{{ session('erro') }}</div>@endif
        {{ $slot }}
    </main>
</body>
</html>
