@php
    $o = config('oficina');
    $end = $o['endereco'];
    $base = $o['dominio'];
    $tel = 'tel:+55'.preg_replace('/\D/', '', $o['telefone']);
    $wa = fn (string $msg) => $o['whatsapp'] ? 'https://wa.me/'.$o['whatsapp'].'?text='.rawurlencode($msg) : $tel;
    $sintomas = collect($o['sintomas'])->where('servico', $s['slug']);
    $faq = [
        ['q' => 'Quanto custa '.mb_strtolower($s['nome']).'?', 'a' => 'Depende do modelo do carro e das peças. Mande o modelo e o ano pelo WhatsApp para receber o orçamento.'],
        ['q' => 'Preciso agendar?', 'a' => 'Agendar garante o horário. Escolha o dia e o período na página inicial ou chame no WhatsApp. Atendimento: '.collect($o['horarios'])->map(fn ($h) => mb_strtolower($h['dias']).', '.$h['horas'])->implode('; ').'.'],
    ];
    $ld = [
        ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => $base.'/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $s['nome'], 'item' => $base.'/servicos/'.$s['slug']],
        ]],
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect($faq)->map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]])->all()],
    ];
@endphp
<x-site :titulo="$s['nome'].' em '.$end['cidade'].' | '.$o['nome']" :descricao="$s['nome'].' em '.$end['cidade'].'/'.$end['uf'].'. '.$s['resumo'].' Orçamento pelo WhatsApp.'" :caminho="'/servicos/'.$s['slug']" :ld="$ld">
<section class="abertura abertura-curta">
  <div class="faixa">
    <p class="trilha"><a href="/">Início</a> / {{ $s['nome'] }}</p>
    <h1>{{ $s['nome'] }} em {{ $end['cidade'] }}</h1>
    <p class="abertura-sub">{{ $s['resumo'] }}</p>
    <a class="botao botao-sinal" href="{{ $wa('Olá! Quero um orçamento de '.mb_strtolower($s['nome']).'. Meu carro é: ') }}" rel="noopener">Pedir orçamento no WhatsApp</a>
  </div>
</section>
<section class="secao">
  <div class="faixa servico-detalhe">
    <div>
      <h2>O que fazemos</h2>
      <ul class="lista-check">@foreach ($s['itens'] as $i)<li>{{ $i }}</li>@endforeach</ul>
    </div>
    @if ($sintomas->isNotEmpty())
    <div>
      <h2>Sinais de que está na hora</h2>
      <ul class="lista-sinais">@foreach ($sintomas as $x)<li><strong>{{ $x['texto'] }}.</strong> {{ $x['explica'] }}</li>@endforeach</ul>
    </div>
    @endif
  </div>
</section>
<section class="secao secao-borda">
  <div class="faixa">
    <h2>Dúvidas frequentes</h2>
    @foreach ($faq as $f)<details class="pergunta"><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
    <h2 class="outros-titulo">Outros serviços</h2>
    <p class="outros">@foreach (collect($o['servicos'])->where('slug', '!=', $s['slug']) as $outro)<a href="{{ route('site.servico', $outro['slug']) }}">{{ $outro['nome'] }}</a>@endforeach</p>
  </div>
</section>
</x-site>
