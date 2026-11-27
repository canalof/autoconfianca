# WhatsApp (Evolution API)

O sistema funciona sem WhatsApp automático (os botões abrem o WhatsApp com a mensagem pronta). Com a Evolution ligada, as mensagens abaixo saem sozinhas pelo número da oficina.

## Ligar

1. Na Evolution, crie a instância da oficina (ex.: `autoconfianca`) e leia o QR Code com o celular da oficina.
2. No `.env` do servidor:
   ```
   EVOLUTION_URL=https://endereco-da-evolution
   EVOLUTION_KEY=token-da-instancia        # use o token da instância, não a chave global
   WHATSAPP_AVISOS=19999990000,19988887777 # celulares da equipe que recebem "pedido novo" (não o da instância)
   ```
   Depois: `php artisan config:cache`.
3. No sistema, em **Empresa e módulos**, preencha "Nome da instância na Evolution API".

## O que é enviado

| Quando | Para quem | Mensagem |
|---|---|---|
| Pedido de agendamento no site (`POST /sistema/agendar`) | Cliente | Pedido recebido, com dia e período |
| Pedido no site ou na área do cliente | Equipe (`WHATSAPP_AVISOS`) | Nome, WhatsApp, carro, serviço, quando, link da agenda |
| Recepção responde o pedido na Agenda | Cliente | Confirmado (dia, hora, endereço), outro horário proposto, ou não conseguimos |
| Véspera do agendamento (`torque:lembretes`, 9h) | Cliente | Lembrete do horário |
| Lembretes de revisão e troca de óleo (9h) | Cliente | Lembrete |
| Área do cliente | Cliente | Código de acesso (não é gravado) |

Tudo (menos o código de acesso) fica em `mensagens_whatsapp`, com nova tentativa automática das que falharam (até 3, nas últimas 24 h).

## Agendamento pelo site

- O site é HTML puro, então a rota `/agendar` não usa token CSRF. Proteções: limite de 6 pedidos por hora por IP e 300 por dia no total, campo-armadilha (`site`), conferência da origem (só o próprio domínio) e no máximo 2 pedidos em aberto por número.
- Se o celular já está no cadastro (um cliente só), o pedido fica ligado ao cliente, e ao carro se a placa bater.
- Dias: a partir de amanhã, até 60 dias; segunda a sexta manhã ou tarde, sábado só de manhã.
- Se o sistema não responder, o site mostra um link para mandar o pedido pelo WhatsApp, como antes.
- Na Agenda, o pedido aparece em **Pedidos de agendamento**. Horário dentro do dia e período pedidos = confirmado; fora disso = proposta ao cliente.

## Cuidados

- Mande só mensagens que o cliente espera. Mensagem em massa pode bloquear o número.
- Um número por negócio. A Evolution deve ficar em HTTPS e com a porta liberada só para os servidores que usam.
