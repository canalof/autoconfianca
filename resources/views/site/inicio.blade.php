@php
    $o = config('oficina');
    $end = $o['endereco'];
    $tel = 'tel:+55'.preg_replace('/\D/', '', $o['telefone']);
    $wa = fn (string $msg) => $o['whatsapp'] ? 'https://wa.me/'.$o['whatsapp'].'?text='.rawurlencode($msg) : $tel;
    $mapaQ = rawurlencode("{$o['nome']}, {$end['rua']}, {$end['cidade']} {$end['uf']}");
    $comoChegar = "https://www.google.com/maps/dir/?api=1&destination={$mapaQ}";
@endphp
<x-site :titulo="$o['nome'].' | Autocenter em '.$end['cidade']" :descricao="$o['slogan'].' em '.$end['cidade'].'/'.$end['uf'].'. Escolha o sintoma do carro e peça orçamento pelo WhatsApp, agende o serviço ou acompanhe seu carro na área do cliente.'" caminho="/">
<section class="abertura">
  <div class="faixa abertura-grade">
    <div class="abertura-texto">
      <h1>O que o seu carro está fazendo?</h1>
      <p class="abertura-sub">Escolha o sintoma, diga o modelo e mande direto para o WhatsApp da oficina. A gente responde com o que pode ser e quanto custa.</p>
      <ul class="abertura-fatos">
        <li>{{ $end['bairro'] }}, {{ $end['cidade'] }}</li>
        <li>{{ $o['horarios'][0]['dias'] }}, {{ $o['horarios'][0]['horas'] }}</li>
        @if ($o['anos_de_oficina'])<li>{{ $o['anos_de_oficina'] }} anos de oficina</li>@endif
      </ul>
    </div>

    <form class="os" id="diagnostico" aria-labelledby="os-titulo">
      <div class="os-topo">
        <span id="os-titulo">Ordem de serviço</span>
        <span class="os-num" aria-hidden="true">Nº {{ now()->format('dm') }}</span>
      </div>
      <fieldset class="os-campo">
        <legend>Sintoma</legend>
        <div class="sintomas">
          @foreach ($o['sintomas'] as $i => $s)
          <label class="sintoma"><input type="radio" name="sintoma" value="{{ $s['id'] }}" @if ($i === 0) required @endif><span>{{ $s['texto'] }}</span></label>
          @endforeach
        </div>
      </fieldset>
      <p class="os-diagnostico" id="os-diagnostico" aria-live="polite">Toque no sintoma mais parecido com o que está acontecendo.</p>
      <div class="os-linha">
        <label class="os-campo">
          <span>Carro e ano</span>
          <input type="text" name="carro" placeholder="Ex.: Onix 2019" autocomplete="off" required>
        </label>
      </div>
      <button class="botao botao-sinal" type="submit">Pedir orçamento no WhatsApp</button>
    </form>
  </div>
</section>

<section class="secao" id="servicos" aria-labelledby="servicos-titulo">
  <div class="faixa">
    <h2 id="servicos-titulo">Serviços</h2>
    <ul class="servicos">
      @foreach ($o['servicos'] as $s)
      <li class="servico">
        <h3><a href="{{ route('site.servico', $s['slug']) }}">{{ $s['nome'] }}</a></h3>
        <p>{{ $s['resumo'] }}</p>
        <a class="servico-wa" href="{{ $wa('Olá! Quero um orçamento de '.mb_strtolower($s['nome']).'.') }}" rel="noopener">Pedir orçamento</a>
      </li>
      @endforeach
    </ul>
  </div>
</section>

<section class="secao secao-cliente" aria-labelledby="cliente-titulo">
  <div class="faixa cliente-grade">
    <div>
      <h2 id="cliente-titulo">Acompanhe seu carro pela internet</h2>
      <p>Na área do cliente você vê o histórico dos serviços de cada carro, aprova orçamentos e pede agendamento. Para entrar, basta o celular cadastrado na oficina.</p>
    </div>
    <a class="botao botao-sinal" href="{{ route('cliente.entrar') }}">Entrar na área do cliente</a>
  </div>
</section>

<section class="secao secao-aco" id="agendar" aria-labelledby="agendar-titulo">
  <div class="faixa agendar-grade">
    <div>
      <h2 id="agendar-titulo">Agende o serviço</h2>
      <p>Escolha o dia e o período. A oficina confirma o horário pelo WhatsApp.</p>
    </div>
    <form class="formulario" id="agendamento">
      <label><span>Serviço</span>
        <select name="servico" required>
          <option value="">Escolha</option>
          @foreach ($o['servicos'] as $s)<option>{{ $s['nome'] }}</option>@endforeach
        </select>
      </label>
      <label><span>Carro e ano</span><input type="text" name="carro" placeholder="Ex.: HB20 2021" required></label>
      <label><span>Dia</span><input type="date" name="dia" required></label>
      <fieldset><legend>Período</legend>
        <label class="opcao"><input type="radio" name="periodo" value="manhã" required> Manhã</label>
        <label class="opcao"><input type="radio" name="periodo" value="tarde"> Tarde</label>
      </fieldset>
      <label><span>Seu nome</span><input type="text" name="nome" autocomplete="name" required></label>
      <button class="botao botao-sinal" type="submit">Enviar pedido de agendamento</button>
    </form>
  </div>
</section>

<section class="secao" id="revisao" aria-labelledby="revisao-titulo">
  <div class="faixa revisao-grade">
    <div>
      <h2 id="revisao-titulo">Quando é a próxima troca de óleo?</h2>
      <p>Informe a quilometragem e veja quanto falta. O intervalo certo está no manual do seu carro.</p>
    </div>
    <form class="formulario formulario-claro" id="hodometro">
      <label><span>Km atual</span><input type="number" name="atual" inputmode="numeric" min="0" placeholder="Ex.: 48500" required></label>
      <label><span>Km da última troca</span><input type="number" name="ultima" inputmode="numeric" min="0" placeholder="Ex.: 41000" required></label>
      <label><span>Intervalo do manual</span>
        <select name="intervalo">
          <option value="5000">5.000 km</option>
          <option value="7500">7.500 km</option>
          <option value="10000" selected>10.000 km</option>
          <option value="15000">15.000 km</option>
        </select>
      </label>
      <button class="botao botao-aco" type="submit">Calcular</button>
      <div class="hodometro-resultado" id="hodometro-resultado" aria-live="polite" hidden></div>
    </form>
  </div>
</section>

<section class="secao secao-borda" id="onde" aria-labelledby="onde-titulo">
  <div class="faixa onde-grade">
    <div>
      <h2 id="onde-titulo">Onde estamos</h2>
      <address>{{ $end['rua'] }}<br>{{ $end['bairro'] }} – {{ $end['cidade'] }}/{{ $end['uf'] }}<br>CEP {{ $end['cep'] }}</address>
      <table class="horarios">
        <caption>Horário de atendimento</caption>
        @foreach ($o['horarios'] as $h)<tr><th scope="row">{{ $h['dias'] }}</th><td>{{ $h['horas'] }}</td></tr>@endforeach
      </table>
      <p class="onde-acoes">
        <a class="botao botao-aco" href="{{ $comoChegar }}" rel="noopener">Abrir rota no mapa</a>
        @if ($o['google_avaliacoes'])<a class="botao botao-contorno" href="{{ $o['google_avaliacoes'] }}" rel="noopener">Ver avaliações no Google</a>@endif
      </p>
    </div>
    <iframe class="mapa" title="Mapa até {{ $o['nome'] }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q={{ $mapaQ }}&output=embed"></iframe>
  </div>
</section>
</x-site>
