<x-cliente-acesso titulo="Entrar">
    <p class="mb-6 text-suave">Digite o celular que você deixou na oficina. Vamos mandar um código pelo WhatsApp.</p>
    <form method="post" action="{{ route('cliente.codigo.enviar') }}" class="space-y-4">
        @csrf
        <x-campo nome="celular" rotulo="Celular com DDD" tipo="tel" inputmode="tel" autocomplete="tel" placeholder="(19) 99999-9999" required autofocus />
        <button class="btn-primario w-full py-3">Receber código</button>
    </form>
    <p class="mt-6 text-sm text-suave">Não recebeu ou trocou de número? Fale com a oficina para atualizar o cadastro.</p>
</x-cliente-acesso>
