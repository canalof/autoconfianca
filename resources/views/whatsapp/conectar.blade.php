<x-app titulo="Conectar WhatsApp">
    <h1 class="mb-1">Conectar o WhatsApp da oficina</h1>
    <p class="mb-5 text-suave">Use o celular com o WhatsApp Business da oficina. A imagem se renova sozinha; não precisa recarregar a página.</p>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="card card-corpo text-center">
            <div id="wa-status" class="mb-3 font-semibold">Buscando o QR Code…</div>
            <img id="wa-qr" alt="QR Code para conectar o WhatsApp" class="mx-auto hidden h-72 w-72 rounded-md border border-linha bg-white p-2">
            <div id="wa-codigo" class="mx-auto hidden w-fit rounded-md border border-linha bg-fundo px-5 py-3 font-mono text-3xl tracking-[0.3em]"></div>
            <div id="wa-ok" class="hidden rounded-md border border-[#bfe3c8] bg-[#eefaf1] px-4 py-6">
                <div class="text-lg font-semibold">WhatsApp conectado</div>
                <a href="{{ route('whatsapp.index') }}" class="btn-primario mt-3 inline-flex">Voltar para o WhatsApp</a>
            </div>
            <p id="wa-erro" class="mt-3 hidden text-sm text-erro"></p>
        </div>

        <div class="card card-corpo">
            <h2 class="mb-3">Como ler o QR Code</h2>
            <ol class="list-decimal space-y-2 pl-5">
                <li>No celular da oficina, abra o <strong>WhatsApp Business</strong>.</li>
                <li>Toque nos <strong>três pontinhos</strong> (Android) ou em <strong>Configurações</strong> (iPhone).</li>
                <li>Entre em <strong>Aparelhos conectados</strong> e toque em <strong>Conectar um aparelho</strong>.</li>
                <li>Aponte a câmera para o QR Code ao lado.</li>
            </ol>
            <div class="mt-6 border-t border-linha pt-4">
                <h3 class="mb-1 font-semibold">O QR não funcionou?</h3>
                <p class="mb-3 text-sm text-suave">Gere um código de 8 dígitos. No celular, em "Conectar um aparelho", toque em <strong>Conectar com número de telefone</strong> e digite o código.</p>
                <form id="wa-form-codigo" class="flex gap-2">
                    <input id="wa-numero" class="input flex-1" placeholder="Celular da oficina, ex.: (19) 99999-9999" inputmode="tel" maxlength="20">
                    <button class="btn">Gerar código</button>
                </form>
            </div>
            <p class="mt-4 text-xs text-suave">Instância: {{ $instancia }}</p>
        </div>
    </div>

    <script>
    (() => {
        const url = @json(route('whatsapp.qr'));
        const $ = (id) => document.getElementById(id);
        let numero = null, timer = null, parado = false, comCodigo = false;
        async function buscar() {
            if (parado) return;
            try {
                // Com código na tela, só confere se conectou: pedir de novo geraria outro código
                const q = comCodigo ? '?so_estado=1' : (numero ? '?numero=' + encodeURIComponent(numero) : '');
                const r = await fetch(url + q, { headers: { Accept: 'application/json' } });
                const d = await r.json();
                $('wa-erro').classList.toggle('hidden', !d.erro);
                $('wa-erro').textContent = d.erro || '';
                if (d.estado === 'open') {
                    parado = true;
                    ['wa-qr', 'wa-codigo', 'wa-status'].forEach((id) => $(id).classList.add('hidden'));
                    $('wa-ok').classList.remove('hidden');
                    return;
                }
                if (d.codigo && numero && !comCodigo) {
                    comCodigo = true;
                    $('wa-codigo').textContent = d.codigo.replace(/(.{4})(.{4})/, '$1-$2');
                    $('wa-codigo').classList.remove('hidden');
                    $('wa-qr').classList.add('hidden');
                    $('wa-status').textContent = 'Digite este código no celular';
                } else if (d.qr) {
                    $('wa-qr').src = d.qr;
                    $('wa-qr').classList.remove('hidden');
                    $('wa-status').textContent = 'Leia com o celular da oficina';
                }
            } catch (e) {
                $('wa-erro').textContent = 'Não consegui falar com o sistema. Tentando de novo…';
                $('wa-erro').classList.remove('hidden');
            }
            // QR novo a cada 25 s; com código, só confere se conectou (o código vale mais tempo)
            timer = setTimeout(buscar, comCodigo ? 5000 : 25000);
        }
        $('wa-form-codigo').addEventListener('submit', (e) => {
            e.preventDefault();
            numero = $('wa-numero').value.trim() || null;
            comCodigo = false;
            clearTimeout(timer);
            buscar();
        });
        buscar();
    })();
    </script>
</x-app>
