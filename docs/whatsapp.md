# WhatsApp (Evolution API)

O sistema funciona sem WhatsApp automático (os botões abrem o WhatsApp com a mensagem pronta). Com a Evolution ligada, as mensagens abaixo saem sozinhas pelo número da oficina.

## Ligar

1. Na Evolution, crie a instância da oficina (ex.: `autoconfianca`) e leia o QR Code com o celular da oficina.
2. No `.env` do servidor:
   ```
   EVOLUTION_URL=https://endereco-da-evolution
   EVOLUTION_KEY=token-da-instancia        # use o token da instância, não a chave global
   WHATSAPP_AVISOS=19999990000,19988887777 # celulares da equipe que recebem "pedido novo" (não o da instância)
   EVOLUTION_WEBHOOK_TOKEN=token-longo-aleatorio # openssl rand -hex 32; o mesmo vai na URL do webhook
   ```
   Depois: `php artisan config:cache`.
3. No sistema, em **Empresa e módulos**, preencha "Nome da instância na Evolution API".
   Para ler o QR Code: **Agenda › WhatsApp › Conectar WhatsApp (QR Code)** (perfil com `empresa.configurar`). A imagem se renova sozinha; se o QR não funcionar, a mesma tela gera o código de 8 dígitos ("Conectar com número de telefone" no celular).
4. Na Evolution, configure o webhook da instância (v2):
   ```bash
   curl -X POST https://EVO/webhook/set/INSTANCIA -H "apikey: CHAVE_GLOBAL" -H "Content-Type: application/json" \
     -d '{"webhook":{"enabled":true,"url":"https://confiancarev.com.br/sistema/webhook/evolution?token=EVOLUTION_WEBHOOK_TOKEN","byEvents":false,"base64":false,"events":["CONNECTION_UPDATE","MESSAGES_UPSERT","MESSAGES_UPDATE","SEND_MESSAGE"]}}'
   ```
   O token também pode ir no cabeçalho `X-Webhook-Token`. Sem `EVOLUTION_WEBHOOK_TOKEN` no `.env`, o webhook responde 403.
5. Lembretes de revisão (uma vez):
   ```bash
   php artisan autoconfianca:servicos-padrao             # catálogo com prazos (confira preços e prazos depois)
   php artisan autoconfianca:lembretes-historico --simular
   php artisan autoconfianca:lembretes-historico         # cria os lembretes a partir das OS antigas
   ```

## O que é enviado

| Quando | Para quem | Mensagem |
|---|---|---|
| Pedido de agendamento no site (`POST /sistema/agendar`) | Cliente | Pedido recebido, com dia e período |
| Pedido no site ou na área do cliente | Equipe (`WHATSAPP_AVISOS`) | Nome, WhatsApp, carro, serviço, quando, link da agenda |
| Recepção responde o pedido na Agenda | Cliente | Confirmado (dia, hora, endereço), outro horário proposto, ou não conseguimos |
| Véspera do agendamento (`torque:lembretes`, 9h) | Cliente | Lembrete do horário (pode confirmar respondendo SIM) |
| Lembretes de revisão e troca de óleo (9h) | Cliente | Lembrete; atrasados aos poucos; segundo aviso 14 dias depois se o carro não voltou |
| Resposta do cliente entendida pelo sistema | Cliente | Confirmação curta (horário confirmado, pedido recebido, PARAR) |
| Cliente quer remarcar ou recusou a proposta | Equipe (`WHATSAPP_AVISOS`) | Nome, número e a mensagem |
| Área do cliente | Cliente | Código de acesso (não é gravado) |

Tudo (menos o código de acesso) fica em `mensagens_whatsapp`, com nova tentativa automática das que falharam (até 3, nas últimas 24 h).

## O que chega da Evolution (webhook)

Rota `POST /sistema/webhook/evolution` (`EvolutionWebhookController`), sem login e sem CSRF, protegida pelo token. A empresa é achada pelo nome da instância e fixada com `Tenant::com`. O processamento fica em `Services/WhatsApp/Recebidas`.

| Evento | O que o sistema faz |
|---|---|
| `connection.update` | Guarda se o celular está conectado (`empresas.whatsapp_estado`). Desconectado = aviso no painel. Ao reconectar, reenvia o que falhou nas últimas 24 h. |
| `messages.update` | Marca entregue / lida na mensagem enviada (`mensagens_whatsapp.externo_id`). |
| `messages.upsert` | Guarda a mensagem do cliente em `mensagens_recebidas` (grupos e números da equipe são ignorados) e entende as respostas abaixo. |

Respostas entendidas (olhando a última mensagem automática para aquele número nos últimos 7 dias):

- **PARAR** (ou "não quero receber"): desmarca "aceita WhatsApp" em todo cadastro com esse número e confirma.
- Ao lembrete da véspera: **sim / confirmo / 👍** confirma o agendamento; **não posso / remarcar / cancelar** avisa a equipe.
- A um horário proposto pela recepção: **sim / pode ser** confirma; **não** recusa e avisa a equipe.
- A um lembrete de revisão: **sim / quero / agendar** vira pedido em **Agenda › Pedidos de agendamento** (origem WhatsApp). O horário que a recepção marcar vai como proposta, e o cliente confirma respondendo.
- O resto fica só registrado. A tela **Agenda › WhatsApp** mostra as respostas, o que o sistema fez e a situação das mensagens enviadas (enviada, entregue, lida, falhou).

Além do webhook, `torque:whatsapp-estado` confere a conexão a cada 10 minutos.

## Lembretes de revisão

- Nascem na entrega da OS, para serviços do catálogo com prazo (`Servico.revisao_meses` / `revisao_km`) e para itens reconhecidos pela descrição (ex.: "FILTRO DE ÓLEO", "ALINHAMENTO", mesmo lançados só como peça), com o prazo do serviço de revisão do catálogo; ou das OS antigas do Oficina Inteligente (`autoconfianca:lembretes-historico`, regras em `Services/Lembretes/Revisoes::SERVICOS`; só para o dono atual do carro e só o que venceu há no máximo 120 dias).
- `torque:lembretes` (9h): todos os que vencem nos próximos 7 dias; os atrasados (até 120 dias) e os segundos avisos no máximo `WHATSAPP_LEMBRETES_ATRASADOS_POR_DIA` (padrão 20) por dia; vários lembretes do mesmo carro numa mensagem só; pausa de `WHATSAPP_INTERVALO_SEGUNDOS` (padrão 6) entre mensagens e "digitando…" de `WHATSAPP_DIGITANDO_MS` (padrão 1500).
- Segundo aviso: uma vez, 14 dias depois do primeiro, se o carro não teve OS nem agendamento desde então.
- Não envia para quem respondeu PARAR nem se o carro mudou de dono. Toda mensagem termina com "Para não receber mais lembretes, responda PARAR."

## Agendamento pelo site

- O site é HTML puro, então a rota `/agendar` não usa token CSRF. Proteções: limite de 6 pedidos por hora por IP e 300 por dia no total, campo-armadilha (`site`), conferência da origem (só o próprio domínio) e no máximo 2 pedidos em aberto por número.
- Se o celular já está no cadastro (um cliente só), o pedido fica ligado ao cliente, e ao carro se a placa bater.
- Dias: a partir de amanhã, até 60 dias; segunda a sexta manhã ou tarde, sábado só de manhã.
- Se o sistema não responder, o site mostra um link para mandar o pedido pelo WhatsApp, como antes.
- Na Agenda, o pedido aparece em **Pedidos de agendamento**. Horário dentro do dia e período pedidos = confirmado; fora disso = proposta ao cliente.

## Cuidados

- Mande só mensagens que o cliente espera. Mensagem em massa pode bloquear o número.
- Um número por negócio. A Evolution deve ficar em HTTPS e com a porta liberada só para os servidores que usam.
