# Locadora

A locadora é uma área separada da oficina dentro do mesmo sistema. Liga com o módulo **Locação** em *Empresa e módulos*.

## Como fica separado

- **Troca de área:** no topo do menu, *Oficina* | *Locadora* (só para quem tem acesso às duas: dono, gerente, consultor).
- **Atendente da locadora** (perfil novo em *Usuários*): só vê a locadora. Não abre OS, estoque nem o financeiro da oficina.
- **Dinheiro separado:** caixa, contas a receber, contas a pagar e resumo do mês têm uma versão para cada área (coluna `area` em `caixas`, `contas_receber` e `contas_pagar`, trait `SeparadoPorArea`). O caixa da oficina aberto não vale para a locadora.
- **Clientes e condutores** são um cadastro só, usado pelas duas áreas.
- Recebimento de OS sempre usa o caixa da oficina, mesmo se a pessoa estiver na tela da locadora.

## Telas

| Tela | Endereço | O que faz |
|---|---|---|
| Início | `/locadora` | Frota agora, saídas, devoluções e atrasos, dinheiro do mês, multas pendentes, documentos e revisão vencendo (30 dias) |
| Contratos | `/locacao` | Lista; `/locacao/novo` cria; `/locacao/contratos/{id}` mostra, registra saída, devolução, prorrogação, fotos e gera o contrato para imprimir |
| Frota | `/locadora/frota` | Cartões com foto, situação real (com cliente, reservado, manutenção), preços, avisos; cadastro completo (Renavam, chassi, preços, IPVA, licenciamento, seguro, próxima revisão) |
| Multas | `/locadora/multas` | Registra a multa, acha o contrato e o cliente da data da infração, cobra do cliente (contas a receber) e opcionalmente lança em contas a pagar |

## Planos do contrato

- **Diária:** cobra tudo na devolução (dias × diária + km excedente + taxas).
- **Semanal e mensal:** na saída do carro gera uma parcela por período, com vencimento no início de cada período. Prorrogar gera só as parcelas que faltam. Na devolução cobra só km excedente e taxas.
- **Km livres por dia:** em branco = livre. Excedente = (km rodado − km livres × dias) × valor do km.
- Os preços de tabela do carro preenchem o contrato sozinhos e podem ser mudados em cada contrato.
- Carro reserva (cliente da oficina, ligado a uma OS) continua existindo; a cobrança dele fica na **oficina**.

## Carro da locadora na oficina

Em *Frota*, **Levar para a oficina** marca o carro como em manutenção, troca para a área da oficina e abre a recepção com a placa. A OS segue normal.
