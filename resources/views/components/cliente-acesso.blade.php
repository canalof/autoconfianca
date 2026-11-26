@props(['titulo'])
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $titulo }} · {{ config('oficina.nome') }}</title>
    <link rel="icon" href="/sistema/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <link rel="manifest" href="/sistema/manifest-cliente.webmanifest">
    <meta name="theme-color" content="#0B4FB5">
    <link rel="apple-touch-icon" href="/sistema/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Área do Cliente">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sistema/sw.js').catch(function () {});
            });
        }
        var __promptInstalar = null;
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            __promptInstalar = e;
            var b = document.getElementById('btn-instalar');
            if (b) { b.hidden = false; }
            var d = document.getElementById('dica-instalar');
            if (d) { d.hidden = true; }
        });
        document.addEventListener('DOMContentLoaded', function () {
            var b = document.getElementById('btn-instalar');
            if (b && __promptInstalar) { b.hidden = false; }
            var d = document.getElementById('dica-instalar');
            if (d && __promptInstalar) { d.hidden = true; }
        });
        function instalarApp() {
            if (!__promptInstalar) { return; }
            __promptInstalar.prompt();
            __promptInstalar.userChoice.then(function () {
                __promptInstalar = null;
                var b = document.getElementById('btn-instalar');
                if (b) { b.hidden = true; }
            });
        }
    </script>
</head>
<body class="min-h-screen bg-fundo text-texto">
<div class="flex min-h-screen flex-col lg:flex-row">

    {{-- Painel da marca (some no celular, onde vira uma barra compacta) --}}
    <aside class="relative hidden w-[46%] shrink-0 flex-col justify-between overflow-hidden bg-asfalto px-12 py-10 text-white lg:flex xl:w-[50%] xl:px-16">
        <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 700 900" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <g fill="#0B4FB5">
                <path d="M-140 900 120 0h150L-10 900Z" opacity=".38"/>
                <path d="M180 900 440 0h70L250 900Z" opacity=".12"/>
            </g>
            <g fill="#E11B22">
                <path d="M600 900 860 0h40L640 900Z" opacity=".42"/>
                <path d="M520 900 780 0h16L538 900Z" opacity=".14"/>
            </g>
        </svg>

        <div class="relative">
            <a href="{{ config('oficina.dominio') }}" aria-label="{{ config('oficina.nome') }} — site">
                <img src="/sistema/marca-branco.svg" width="184" height="53" alt="{{ config('oficina.nome') }}" class="h-auto w-[184px]">
            </a>
        </div>

        <div class="relative max-w-[26rem]">
            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-[#E11B22]">Área do cliente</p>
            <h2 class="mt-4 text-[2rem] font-semibold leading-[1.15] tracking-tight xl:text-[2.35rem]">
                Acompanhe o seu carro sem precisar ligar.
            </h2>
            <p class="mt-4 text-[15px] leading-relaxed text-[#a9b4c0]">
                Entre com o celular que você deixou na oficina e acompanhe cada etapa do serviço.
            </p>
            <ul class="mt-7 space-y-3 text-[15px] text-[#d7dee5]">
                <li class="flex items-start gap-3"><span class="mt-[3px] flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span> Andamento do serviço em tempo real</li>
                <li class="flex items-start gap-3"><span class="mt-[3px] flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span> Histórico de revisões do veículo</li>
                <li class="flex items-start gap-3"><span class="mt-[3px] flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span> Aprovação do serviço sem sair de casa</li>
            </ul>
        </div>

        <div class="relative border-t border-white/10 pt-5 text-[13px] leading-relaxed text-[#8f9ba8]">
            <p class="font-semibold text-white">{{ config('oficina.nome') }}</p>
            <p class="mt-1">{{ config('oficina.endereco.rua') }} — {{ config('oficina.endereco.bairro') }}, {{ config('oficina.endereco.cidade') }}/{{ config('oficina.endereco.uf') }}</p>
            <p class="mt-1">{{ config('oficina.telefone') }}@foreach (config('oficina.horarios', []) as $h) · {{ $h['dias'] }}, {{ $h['horas'] }}@endforeach</p>
        </div>
    </aside>

    <div class="flex min-h-screen flex-1 flex-col">
        {{-- Barra do topo: marca no celular, navegação no computador --}}
        <header class="flex items-center justify-between gap-4 border-b border-linha bg-asfalto px-5 py-3.5 text-white lg:border-b-0 lg:bg-transparent lg:px-10 lg:py-6 lg:text-texto">
            <a href="{{ config('oficina.dominio') }}" class="lg:hidden" aria-label="{{ config('oficina.nome') }} — site">
                <img src="/sistema/marca-branco.svg" width="132" height="38" alt="{{ config('oficina.nome') }}" class="h-auto w-[132px]">
            </a>
            <a href="{{ config('oficina.dominio') }}" class="hidden items-center gap-2 text-sm font-medium text-suave hover:text-marca lg:inline-flex">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                Voltar ao site
            </a>
            <a href="{{ config('oficina.dominio') }}" class="text-[13px] font-medium text-[#c5ced6] hover:text-white lg:hidden">Voltar ao site</a>
        </header>

        <main class="flex flex-1 items-center justify-center px-5 py-10 lg:px-10">
            <div class="w-full max-w-[24rem]">
                <div class="mb-6 flex items-center gap-2.5 lg:hidden">
                    <img src="/sistema/marca-cor.svg" alt="" class="h-auto w-[132px]">
                    <span class="h-4 w-px bg-linha"></span>
                    <span class="text-[10px] font-semibold uppercase tracking-[0.16em] text-suave">Área do cliente</span>
                </div>

                <p class="hidden text-[11px] font-semibold uppercase tracking-[0.2em] text-marca lg:block">Área do cliente</p>
                <h1 class="mt-1.5 text-[1.65rem] leading-tight">{{ $titulo }}</h1>

                @if (session('ok'))<div class="mt-5 rounded-md border border-[#b7dcc6] bg-[#eef8f2] px-3 py-2 text-[14px] text-[#185c35]" role="status">{{ session('ok') }}</div>@endif
                @if (session('erro'))<div class="mt-5 rounded-md border border-[#ecc3bf] bg-[#fcf0ef] px-3 py-2 text-[14px] text-[#8c241d]" role="alert">{{ session('erro') }}</div>@endif

                <div class="mt-6">
                    {{ $slot }}
                </div>

                <div class="mt-8 flex items-center gap-2 border-t border-linha pt-5 text-[13px] text-suave">
                    <svg class="h-4 w-4 shrink-0 text-[#25D366]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2m0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 4.54 0 8.23 3.69 8.23 8.23s-3.69 8.24-8.23 8.24m4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.22.25-.85.83-.85 2.03s.87 2.35.99 2.51c.12.17 1.71 2.61 4.15 3.66.58.25 1.03.4 1.39.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29"/></svg>
                    <span>Dúvidas? <a href="https://wa.me/{{ config('oficina.whatsapp') }}" target="_blank" rel="noopener" class="font-semibold text-texto hover:text-marca">Fale com a oficina</a> no WhatsApp.</span>
                </div>

                <div class="mt-5">
                    <button id="btn-instalar" type="button" onclick="instalarApp()" hidden class="btn-primario w-full py-3">Instalar aplicativo no celular</button>
                    <p id="dica-instalar" class="mt-3 text-[12px] leading-relaxed text-suave">Android (Chrome): menu ⋮ → Instalar aplicativo. iPhone (Safari): Compartilhar → Adicionar à Tela de Início.</p>
                </div>
            </div>
        </main>

        <footer class="px-5 pb-6 text-[12px] leading-relaxed text-suave lg:px-10">
            <p class="border-t border-linha pt-4">{{ config('oficina.nome') }} · CNPJ 10.480.054/0001-55 · {{ config('oficina.endereco.cidade') }}/{{ config('oficina.endereco.uf') }}</p>
        </footer>
    </div>
</div>
</body>
</html>
