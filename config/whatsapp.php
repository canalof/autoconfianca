<?php

return [
    // Evolution API (opcional). Sem isso, o sistema abre o WhatsApp com a mensagem pronta.
    'url' => env('EVOLUTION_URL'),
    'chave' => env('EVOLUTION_KEY'),
    // Números da equipe que recebem aviso de pedido novo (separados por vírgula). Use um número
    // diferente do da instância da oficina: mandar mensagem para o próprio número não funciona.
    'avisos' => env('WHATSAPP_AVISOS', ''),
    // Token que a Evolution manda no webhook (?token=... na URL ou cabeçalho X-Webhook-Token).
    // Sem token configurado, o webhook fica desligado.
    'webhook_token' => env('EVOLUTION_WEBHOOK_TOKEN'),
    // "digitando…" antes de cada mensagem, em milissegundos (0 desliga).
    'digitando_ms' => (int) env('WHATSAPP_DIGITANDO_MS', 1500),
    // Pausa entre as mensagens do envio diário de lembretes, em segundos.
    'intervalo_segundos' => (int) env('WHATSAPP_INTERVALO_SEGUNDOS', 6),
    // Lembretes atrasados e segundos avisos enviados por dia (os que vencem nos próximos 7 dias não entram na conta).
    'lembretes_atrasados_por_dia' => (int) env('WHATSAPP_LEMBRETES_ATRASADOS_POR_DIA', 20),
];
