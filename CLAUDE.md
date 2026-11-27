# AutoConfiança: convenções do projeto

Site, área do cliente e sistema de gestão da oficina AutoConfiança (confiancarev.com.br), feito a partir do Torque. Sistema de **uma oficina só**, sem a parte SaaS (planos, assinatura, painel da plataforma). Laravel 13, PHP 8.3, Blade + Tailwind v4, MySQL em produção, SQLite nos testes.

O isolamento por `empresa_id` continua (a oficina é a primeira empresa cadastrada) e os testes de isolamento continuam valendo.

## Regras que não podem ser quebradas

- **Toda tabela de dados de oficina tem `empresa_id`** e o model usa o trait `PertenceAEmpresa`. Sem empresa definida (`Tenant::id()` nulo), consultas não retornam nada e gravações lançam exceção. Nunca use `withoutGlobalScopes()` em código de tela. Exceções documentadas: busca por token público (`OrdemServico::porToken`, portal do cliente), que em seguida fixa a empresa com `Tenant::usar`.
- Validações de `exists`/`unique` em formulários precisam do filtro `->where('empresa_id', Tenant::id())`.
- Models com dados de negócio usam `Auditavel`.
- Permissões ficam em `App\Support\Perfil::permissoes()` e viram Gates (`can:clientes.editar`). Controller chama `$this->authorize(...)`.
- Placa sempre passa por `Placa::normalizar()`; busca por placa usa `Placa::equivalentes()` (antiga e Mercosul).
- CPF/CNPJ são guardados só com números/letras (`Documento::normalizar`).
- Chaves de API só no `.env`, nunca no front. Exceção: o token fiscal de cada oficina fica em `empresas.fiscal_token` com cast `encrypted` e nunca volta para a tela.
- Valores em dinheiro em centavos (inteiro), colunas terminadas em `_centavos`.

## Idioma

Código de domínio, telas, mensagens e testes em português. Textos de tela curtos, verbo no infinitivo nos botões ("Salvar cliente"), sem jargão técnico.

## Testes

`vendor/bin/phpunit`. Helpers em `tests/TestCase.php`: `novaEmpresa()` e `usuarioNaMesmaEmpresa()`. Toda funcionalidade nova precisa de teste de isolamento entre empresas.

## Visual

Tokens em `resources/css/app.css`: identidade AutoConfiança (grafite `asfalto`, azul `marca` para estrutura, links e ação principal; vermelho `sinal` para destaque e chamadas como recepção/novo contrato; fundo papel quente). Fonte Inter; placas em Barlow Condensed. Componente `<x-placa>` para mostrar placas.

## Módulos

- Estoque: `Produto::movimentar()` é o único jeito de mexer no saldo (grava o histórico). Notas de compra: `Services/Estoque`.
- Dinheiro: `Services/Financeiro/Financeiro` (receber OS, receber/pagar contas, caixa).
- Entrega da OS: `Services/Os/AoEntregar` (baixa estoque, comissão, lembretes).
- Notas fiscais: `Services/Fiscal` (Focus NFe ou demonstração).
- WhatsApp automático: `Services/WhatsApp` (Evolution API, opcional). Mensagens da agenda em `Services/Agenda/AvisosAgenda`; avisos à equipe vão para `WHATSAPP_AVISOS`. Detalhes em `docs/whatsapp.md`.
- Agendamento pelo site: `POST /agendar` (`AgendamentoSiteController`, sem login e sem CSRF; limite `agendar-site`, campo-armadilha `site`, origem conferida) cria `AgendaSolicitacao` com `origem = site` (cliente e veículo opcionais). Formulários do `portal/` com `data-agendar` enviam por fetch e caem no WhatsApp se falhar.
- Agendador: `routes/console.php` (`torque:notas` a cada 5 min, `torque:lembretes` às 9h).
- Em Blade, use sempre `@php ... @endphp` (não misture com `@php(...)` no mesmo arquivo).
- **Site institucional é estático** (pasta `portal/`, HTML/CSS/JS sem build), servido na raiz do domínio a partir de `/var/www/autocentro`. O Laravel roda em **`/sistema`** (link simbólico `portal/sistema` → `public/`), então dentro do Laravel as rotas são relativas: painel em `/`, área do cliente em `/area-do-cliente`, troca de área em `POST /area`. Arquivos públicos do sistema são referenciados como `/sistema/...` (marca, ícones, manifest, `sw.js`). `APP_URL` em produção termina em `/sistema`; `oficina.dominio` (`OFICINA_SITE_URL`) é a raiz do site. O deploy envia `portal/` para `PORTAL_PATH` sem apagar os links simbólicos. As views antigas do site em Laravel (`SiteController`, `resources/views/site`) não são mais usadas; `/institucional` só redireciona.
- Área do cliente: `AreaClienteController` (`/area-do-cliente`): celular + código de 6 dígitos pelo WhatsApp (`WhatsApp::enviarSigiloso`, nunca grava o código), 10 min e 5 tentativas; depois abre o portal (`PortalClienteController`, `/cliente/{token}`). Mesma resposta com ou sem cadastro.
- Importação do Oficina Inteligente: `Services/Importacao/OficinaInteligente` + `php artisan autoconfianca:importar` (`docs/importacao-oficina-inteligente.md`). Lê .xlsx com `Support/LeitorXlsx` (sem dependências). Não importa saldo de estoque. OS antigas ficam em `historico_os_anteriores` (só consulta). Dados reais nunca entram no repositório nem nos testes.
- Instalação na máquina nova: `docs/instalacao.md` (script `scripts/instalar-servidor.sh`).
- Áreas Oficina e Locadora: `Support/Area` (sessão, middleware `DefinirArea`), troca em `POST /sistema/area`. `Caixa`, `ContaReceber` e `ContaPagar` usam o trait `SeparadoPorArea` (coluna `area`): nas telas só aparece a área atual; o que for criado recebe a área atual, salvo `area` explícita. Coisa de OS é sempre `oficina` (`Financeiro::receberOs` força). Perfil `Locadora` (atendente) só vê a locadora. Menu por área no layout. Detalhes em `docs/locadora.md`.
- Locadora: `LocadoraController` (painel, frota, multas) + `LocacaoController` (contratos) + `Services/Mobilidade/Reservas` (planos diária/semanal/mensal, parcelas com `reserva_id`, km excedente). Multas em `locadora_multas` (`LocadoraMulta`).
- Orçamento: toda mudança de itens/desconto chama `OrdemServico::alterouOrcamento()` (versão). Aceite fica em `orcamento_aceites` (imutável, com hash).
- Estoque: `movimentar()` grava `saldo_anterior` e recalcula o custo médio nas entradas com custo; telas exigem motivo. Origens: manual, compra, os, ajuste, perda, devolucao_cliente, devolucao_fornecedor.
- Histórico de cadastro: `HistoricoController` lê a `auditoria`; para um model novo aparecer, inclua em `TIPOS` e os rótulos em `ROTULOS`.
- Importar/exportar planilha: `ImportacaoController` (CSV com BOM e `;`; valores começando com = + - @ recebem apóstrofo).
- Documentação de operação em `docs/` (permissões, testes, runbook, auditoria, deploy). Atualize-a junto com o código.
- Módulos opcionais: `Empresa::MODULOS` e `temModulo()`; JSON `empresas.modulos` (condutores, bancos, comissoes, fiscal) + `modulo_locacao`. Permissões `mobilidade.*`, `comissoes.*`, `notas.*`, `locacao.*` dependem do módulo (`User::pode`); Bancos/Pix e CNH usam o middleware `modulo:<chave>`. Item novo de menu de módulo leva a chave no 5º campo do array do menu.
- Locação (módulo por empresa, `empresas.modulo_locacao`): `LocacaoController` + `Services/Mobilidade/Reservas`. Modalidades `carro_reserva` (exige OS) e `aluguel` (exige cliente, sem OS). Permissões `locacao.*` só valem com o módulo ligado (`User::pode`). Títulos da devolução com `categoria = locacao`. Condutores, CNH e retirada são da oficina (`MobilidadeController`, permissões `mobilidade.*`).
- CNH: fornecedores em `Services/Cnh/CatalogoCnh` (`IMPLEMENTADOS`), chaves por oficina em `CnhIntegracao` (cifradas). Toda consulta passa por `ConsultaCnh::executar` (registra em `cnh_consultas`, sem CPF). Só SENATRAN em produção é oficial; Vio (SERPRO) e VIO API privada (`VioApiPrivada`, vio.api.br) são documentais.
- Deploy: push na `main` → Testes → Deploy por SSH (`docs/deploy.md`). Nunca editar direto no servidor; migration nova sempre com `down()`.
