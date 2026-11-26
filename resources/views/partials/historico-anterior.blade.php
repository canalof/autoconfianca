@if ($historico->isNotEmpty())
    <details class="border-t border-linha px-4 py-3">
        <summary class="cursor-pointer font-medium">Serviços no sistema anterior ({{ $historico->count() }})</summary>
        <div class="mt-2 space-y-3">
            @foreach ($historico as $h)
                <div class="border-b border-linha pb-2 last:border-b-0">
                    <div class="flex flex-wrap justify-between gap-2 text-sm">
                        <span class="font-medium">{{ $h->data->format('d/m/Y') }} · OS {{ $h->numero }}@if (! empty($mostrarCliente) && $h->cliente_nome) · {{ $h->cliente_nome }}@endif</span>
                        <span class="text-suave">{{ \App\Support\Dinheiro::formatar($h->total_centavos) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-suave">{{ collect($h->itens)->pluck('descricao')->filter()->join(', ') }}</p>
                </div>
            @endforeach
        </div>
    </details>
@endif
