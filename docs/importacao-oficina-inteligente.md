# Importação do Oficina Inteligente

O Oficina Inteligente não tem backup: os dados saem pelos relatórios. Use a exportação de 26/11/2026 (ou uma nova, com os mesmos nomes de arquivo).

**Os dados dos clientes nunca vão para o GitHub.** Copie os arquivos direto para o servidor (WinSCP ou `scp`) numa pasta fora do sistema, por exemplo `/root/importacao/`.

## Arquivos

| Arquivo | Vai para |
|---|---|
| `Estoque_Completo_*.xlsx` | Produtos (código, nome + aplicação, grupo como categoria, marca, referência, NCM, unidade, custo, preço) |
| `Clientes_*.xlsx` | Clientes (com o código antigo, para casar com as OS) |
| `Fornecedores_*.xlsx` | Fornecedores |
| `Contas_a_Receber_Em_Aberto_*.xlsx` | Contas a receber em aberto |
| `OS_com_Itens_*.xlsx` (pasta separada) | Veículos (pela placa) e histórico das OS antigas |

Os relatórios de OS vieram em `.xls`. Converta antes (no servidor, com o LibreOffice instalado):

```bash
cd /root/importacao/os && soffice --headless --convert-to xlsx *.xls
```

## Rodar

Primeiro simule (não grava nada, só mostra os totais e gera os relatórios):

```bash
cd /var/www/autoconfianca
php artisan autoconfianca:importar /root/importacao --os=/root/importacao/os --simular
```

Se estiver tudo certo, rode de verdade (sem `--simular`). Pode rodar de novo depois, com uma exportação mais nova: produtos casam pelo código, clientes e fornecedores pelo código antigo, OS pelo número e títulos pelo código + parcela. Nada é duplicado.

Na exportação de 26/11/2026: 4.366 produtos, 872 clientes, 156 fornecedores, 665 veículos, 1.528 OS antigas e 14 títulos.

## O que NÃO é importado, de propósito

- **Saldo do estoque.** No sistema antigo nenhum produto tinha saldo positivo (2.437 zerados e 1.929 negativos). Todos entram com saldo zero. No primeiro dia, faça a contagem em **Estoque › Balanço** (dá para lançar por planilha).
- **OS antigas como OS do sistema.** Elas ficam como histórico só para consulta: aparecem na ficha do veículo (todas) e na área do cliente (só as do próprio cliente; o cliente não vê serviços de um dono anterior do carro).

## Relatórios para conferir

Ficam em `storage/app/importacao/<data>/` (abrem no Excel):

| Relatório | O que fazer |
|---|---|
| `placa_com_mais_de_um_dono.csv` | O carro ficou com o dono da OS mais recente. Confira se foi venda do carro ou erro de digitação. |
| `os_sem_cliente_no_cadastro.csv` | OS cujo cliente não foi achado pelo nome (ou o nome se repete no cadastro). A OS fica no histórico do veículo, sem cliente. |
| `documento_invalido.csv` | CPF/CNPJ inválido ou repetido: o cliente entra sem documento. |
| `ncm_invalido.csv` | NCM sem 8 dígitos: o produto entra sem NCM. Precisa corrigir antes de emitir nota. |
| `titulo_sem_cliente.csv` | Título sem cliente achado: entra com o nome na descrição. |

Na exportação de 26/11/2026: 21 placas com mais de um dono, 13 OS sem cliente, nenhum NCM ou documento inválido.
