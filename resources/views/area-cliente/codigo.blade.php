<x-cliente-acesso titulo="Digite o código">
    <p class="mb-6 text-suave">O código tem 6 dígitos e vale por 10 minutos.</p>
    <form method="post" action="{{ route('cliente.codigo.verificar') }}" class="space-y-4">
        @csrf
        <x-campo nome="codigo" rotulo="Código" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
        <button class="btn-primario w-full py-3">Entrar</button>
    </form>
    <p class="mt-6 text-sm"><a href="{{ route('cliente.entrar') }}" class="font-semibold text-asfalto-3 hover:underline">Pedir outro código</a></p>
</x-cliente-acesso>
