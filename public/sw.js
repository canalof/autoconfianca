self.addEventListener('fetch', (evento) => {
    if (evento.request.method !== 'GET' || evento.request.mode !== 'navigate') return;

    evento.respondWith(fetch(evento.request).catch(() => new Response(
        '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AutoConfiança sem conexão</title><body style="font-family:system-ui,sans-serif;background:#f6f4f1;color:#14171a;padding:40px"><h1>AutoConfiança sem conexão</h1><p>Conecte-se à internet e recarregue para continuar.</p></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } },
    )));
});
