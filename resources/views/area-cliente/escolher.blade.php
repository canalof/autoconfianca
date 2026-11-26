<x-cliente-acesso titulo="Qual cadastro?">
    <p class="mb-6 text-suave">Este celular está em mais de um cadastro. Escolha qual quer abrir.</p>
    <form method="post" action="{{ route('cliente.escolher.salvar') }}" class="space-y-3">
        @csrf
        @foreach ($clientes as $c)
            <button name="cliente_id" value="{{ $c->id }}" class="btn w-full justify-start py-3">{{ $c->nome }}</button>
        @endforeach
    </form>
</x-cliente-acesso>
