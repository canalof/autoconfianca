# AutoConfiança

Site, área do cliente e sistema de gestão da oficina AutoConfiança, em **confiancarev.com.br**. Feito a partir do Torque, só para esta oficina.

| Endereço | O que é |
|---|---|
| `/` | Site: sintoma do carro → orçamento pelo WhatsApp, serviços, agendamento, troca de óleo, mapa |
| `/area-do-cliente` | Cliente entra com o celular e um código pelo WhatsApp: carros, OS, orçamentos para aprovar, garantias, revisões e pedido de agendamento |
| `/entrar` → `/sistema` | Equipe: recepção pela placa, OS, estoque (com balanço), compras, caixa, financeiro, notas fiscais, agenda e lembretes |

## Documentação

- `docs/instalacao.md`: instalar na máquina nova (Ubuntu, Nginx, MySQL, HTTPS).
- `docs/importacao-oficina-inteligente.md`: trazer produtos, clientes, fornecedores, veículos, OS antigas e contas a receber do Oficina Inteligente.
- `docs/deploy.md`: deploy automático a cada push na `main`.
- `docs/permissoes.md`, `docs/runbook.md`, `docs/auditoria.md`: operação do dia a dia.

## Desenvolvimento

```bash
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
vendor/bin/phpunit
```
