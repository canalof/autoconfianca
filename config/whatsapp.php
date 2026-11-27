<?php

return [
    // Evolution API (opcional). Sem isso, o sistema abre o WhatsApp com a mensagem pronta.
    'url' => env('EVOLUTION_URL'),
    'chave' => env('EVOLUTION_KEY'),
    // Números da equipe que recebem aviso de pedido novo (separados por vírgula). Use um número
    // diferente do da instância da oficina: mandar mensagem para o próprio número não funciona.
    'avisos' => env('WHATSAPP_AVISOS', ''),
];
