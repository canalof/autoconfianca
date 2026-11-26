<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Entrar · {{ config('oficina.nome') }}</title>
    <link rel="icon" href="/sistema/favicon.svg" type="image/svg+xml">
    @include('partials.pwa')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-asfalto">
<div class="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
    <section class="relative hidden overflow-hidden lg:flex lg:flex-col" aria-hidden="true">
        <svg class="absolute inset-0 h-full w-full" viewBox="0 0 800 900" preserveAspectRatio="xMidYMid slice">
            <rect width="800" height="900" fill="#0e1116"/>
            <g fill="#0b4fb5">
                <path d="M-120 900 150 0h150L30 900Z" opacity=".55"/>
                <path d="M210 900 480 0h96L306 900Z" opacity=".18"/>
            </g>
            <g fill="#e11b22">
                <path d="M690 900 960 0h34L724 900Z" opacity=".5"/>
                <path d="M604 900 874 0h18L622 900Z" opacity=".16"/>
            </g>
            <defs>
                <linearGradient id="escurecer" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#0e1116" stop-opacity="0"/>
                    <stop offset=".52" stop-color="#0e1116" stop-opacity=".82"/>
                    <stop offset="1" stop-color="#0e1116" stop-opacity=".98"/>
                </linearGradient>
                <linearGradient id="escurecer-topo" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#0e1116" stop-opacity=".92"/>
                    <stop offset=".62" stop-color="#0e1116" stop-opacity=".55"/>
                    <stop offset="1" stop-color="#0e1116" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <rect width="800" height="300" fill="url(#escurecer-topo)"/>
            <rect y="330" width="800" height="570" fill="url(#escurecer)"/>
        </svg>
        <div class="relative z-10 flex h-full flex-col justify-between p-12 xl:p-16">
            <img src="/sistema/marca-branco.svg" alt="{{ config('oficina.nome') }}" class="h-auto w-56 xl:w-64">
            <div class="max-w-md">
                <span class="inline-flex items-center rounded-sm bg-sinal px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white">Desde 2008 · Hortolândia/SP</span>
                <p class="mt-5 text-[2rem] font-semibold leading-[1.12] tracking-tight text-white xl:text-[2.4rem]">Recepção, ordem de serviço, estoque, caixa e notas fiscais no mesmo lugar.</p>
                <p class="mt-4 text-[15px] leading-relaxed text-[#a6b0bd]">A equipe da oficina entra por aqui. O cliente acompanha o veículo pela área do cliente.</p>
            </div>
        </div>
    </section>

    <section class="flex items-center justify-center bg-fundo px-5 py-12">
        <form method="post" action="{{ route('login.entrar') }}" class="w-full max-w-sm">
            @csrf
            <div class="mb-8">
                <img src="/sistema/marca-cor.svg" alt="{{ config('oficina.nome') }}" class="h-auto w-52 text-asfalto">
                <div class="mt-3 h-[3px] w-14 rounded-full marca-faixa"></div>
            </div>
            @if (session('ok'))<div class="mb-4 rounded-md border border-[#b7dcc6] bg-[#eef8f2] px-3 py-2 text-[15px] text-[#185c35]" role="status">{{ session('ok') }}</div>@endif
            <h1 class="mb-1">Entrar</h1>
            <p class="mb-6 text-suave">Acesso da equipe. Use o e-mail e a senha que o responsável cadastrou. Cliente? <a href="{{ route('cliente.entrar') }}" class="font-semibold text-marca hover:underline">Entre na área do cliente</a>.</p>

            <x-campo nome="email" rotulo="E-mail" tipo="email" autocomplete="username" required autofocus class="mb-4" />
            <label class="campo mb-4">
                <span>Senha</span>
                <input type="password" name="password" class="input" autocomplete="current-password" required>
            </label>
            <label class="mb-6 flex items-center gap-2 text-[15px]">
                <input type="checkbox" name="lembrar" value="1" class="h-4 w-4 accent-marca"> Manter conectado neste aparelho
            </label>
            <button class="btn-primario w-full py-3">Entrar</button>
            <div class="mt-5 flex flex-wrap justify-between gap-3 text-[15px]">
                <a href="{{ route('password.request') }}" class="font-semibold text-marca hover:underline">Esqueci a senha</a>
                <a href="{{ route('instalar') }}" class="font-semibold text-marca hover:underline">Instalar no celular</a>
            </div>
        </form>
    </section>
</div>
</body>
</html>
