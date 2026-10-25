# RAG/DRIVE PENDENTE DE ATUALIZAÇÃO — Fase 1A (reativação administrativa)

Este arquivo contém o texto pronto para entrar no RAG. Ele **não foi gravado no Google Drive**.

Motivo: o conector do Drive desta sessão só cria arquivos e altera metadados (título, pasta, trava). Não existe operação para editar o conteúdo de um Google Doc existente. Criar documento novo exigiria autorização, e duplicaria o registro.

## Onde aplicar (Google Drive > RAG > PROJETOS > SISTEMA-AMAGENDA)

| Documento existente | Pasta | Ação sugerida |
|---|---|---|
| `06_ATUALIZACAO_PLANEJAMENTO_FASE_9D_AMAGENDA_2026-10-04` (registro financeiro corrente, em blocos datados) | `06_PLANEJAMENTO` | **Anexar o bloco A** ao final. Este é o único ponto necessário agora. |
| `04_SEGURANCA_PLANO_CORTESIA_E_REGRAS_DE_COBRANCA_AMAGENDA_2026-10-04` | `04_SEGURANCA_ARQUITETURA` | Após homologação: acrescentar 2 linhas sobre locks e bloqueio seguro (bloco B). |
| `03_REGRAS_DE_NEGOCIO_AMAGENDA_2026-10-01.pdf` (PDF) e `01_MANUAL_TECNICO_CONSOLIDADO_AMAGENDA_2026-10-01.pdf` (PDF) | `03_REGRAS_NEGOCIO`, `01_DOCUMENTACAO` | Consolidar somente após homologação. São PDFs, portanto exigem reexportação. |

Não há documento financeiro dedicado em `03_REGRAS_NEGOCIO` nas páginas listadas. A listagem das pastas veio paginada, então pode existir outro documento que não apareceu.

---

## BLOCO A — anexar a `06_ATUALIZACAO_PLANEJAMENTO_FASE_9D…`

**ATUALIZAÇÃO 24/10/2026 — FASE 1A: REATIVAÇÃO ADMINISTRATIVA DE ASSINATURAS**

STATUS: **IMPLEMENTADA — AGUARDANDO HOMOLOGAÇÃO FUNCIONAL.** Verificação estática aprovada. Não declarar VALIDADA nem HOMOLOGADA antes da confirmação do responsável.

Arquivo alterado (único): `backend/super_admin/assinatura/gerenciar_assinatura.php`, ação `reativar` de `assinaturaAlterarStatus`. Novas funções no mesmo arquivo: `assinaturaReativacaoConsulta` e `assinaturaReativacaoBloqueio`. Diff real +122/−2 linhas (o diff cru aparece inteiro por diferença de fim de linha CRLF/LF).

Diagnóstico: a reativação só validava transição de status, assinatura ativa duplicada e plano divergente. Não verificava origem da suspensão, teste expirado, dívida nem pagamento incerto.

**Origem da suspensão** (campo existente `assinatura.motivo_suspensao`; nenhum campo criado):

| Valor | Gravado por | Reativação administrativa |
|---|---|---|
| `manual` | Suspensão pelo Super Admin | Permitida se passar nas demais verificações |
| `inadimplencia` | Serviço de assinatura (estorno ou cancelamento de pagamento da cobrança de conversão) | Permitida somente sem pendência financeira |
| `teste_expirado` | Expiração do teste grátis | Bloqueada |
| nulo ou outro valor | Legado ou não identificável | Bloqueada (bloqueio seguro) |

**Regras, em ordem:**
1. Assinatura `suspensa` (existente).
2. Sem outra assinatura `ativa` da empresa (existente).
3. Plano da assinatura igual ao plano atual da empresa (existente).
4. Teste: bloqueia se o motivo é `teste_expirado`, ou se a modalidade é `teste` e `teste_expira_em` já passou, ou se as datas do teste estão ausentes ou inválidas. A reativação nunca altera `teste_expira_em` nem cria novo teste.
5. Modalidade deve ser `paga` ou `teste`.
6. Motivo deve ser `manual` ou `inadimplencia`.
7. Empresa com status `ativo`.
8. Dívida impeditiva: existe cobrança da empresa com `status = 'pendente'` e `data_vencimento < CURDATE()` (mesma definição de "vencida" do financeiro). Cobrança futura não bloqueia. Cobrança `cancelada` é ignorada. Estorno confirmado devolve a cobrança a `pendente` e cai nesta regra.
9. Pagamento incerto: existe `transacao_pagamento` de cobrança não cancelada da empresa com status `criada`, `enviando`, `aguardando`, `processando` ou `conciliacao`, ou com `requer_conciliacao = 1`, ou `confirmada` sem `id_pagamento` interno. Transação criada ou enviada não é pagamento confirmado.

**Respostas HTTP 409** (campo `code`): `TESTE_EXPIRADO`, `ASSINATURA_TESTE_INCONSISTENTE`, `ASSINATURA_MODALIDADE_INVALIDA`, `SUSPENSAO_ORIGEM_INDEFINIDA`, `EMPRESA_INATIVA`, `COBRANCA_VENCIDA_PENDENTE`, `PAGAMENTO_AGUARDANDO_CONFIRMACAO`, `FINANCEIRO_EM_ATUALIZACAO` (deadlock ou timeout de lock; nada alterado, basta repetir). As mensagens não expõem dados de pagamento. Todo bloqueio faz rollback: não altera a assinatura, não grava auditoria e não toca o histórico financeiro.

**Tabelas:** `empresa` e `assinatura` (leitura com `FOR UPDATE`, já existente, agora também com `status` da empresa e campos do teste); `cobranca` (leitura com `FOR UPDATE`, nova); `transacao_pagamento` (leitura sem bloqueio, nova; um dado desatualizado só pode resultar em bloqueio); `auditoria` (escrita existente via `auditoriaRegistrar`, somente na reativação efetiva). Nenhuma alteração estrutural e nenhum SQL de escrita executado.

**Consistência e auditoria:** tudo na transação existente. Os locks (empresa, assinatura e cobranças da empresa) serializam a decisão com baixa, estorno e conciliação. Clique repetido: o segundo pedido recebe `TRANSICAO_INVALIDA`; o `UPDATE` mantém a guarda de status e `affected_rows = 1`. Auditoria `assinatura.reativada` só na transição real, com empresa, assinatura e responsável.

**Limitações conhecidas:**
- **Dívida por empresa, não por assinatura.** Cobrança vencida de assinatura antiga já cancelada bloqueia a reativação, porque o cancelamento da assinatura não cancela suas cobranças em `cobranca`.
- **Motivos legados.** Suspensões com `motivo_suspensao` nulo ou fora de `manual`/`inadimplencia`/`teste_expirado` ficam bloqueadas até regularização pelo suporte.
- **Ordem de locks.** A reativação trava assinatura e depois cobranças. A reavaliação da cobrança de conversão (webhook ou Super Admin) trava cobrança e depois assinatura. Pode haver deadlock raro; o efeito é a resposta `FINANCEIRO_EM_ATUALIZACAO`, sem alteração.
- **Estados de transação** levantados do código (gateway, webhook, conciliação). Se o dump SQL atual divergir, o dump prevalece.
- **Regra central de inadimplência não existe ainda.** O único caminho que grava `inadimplencia` é a reavaliação da cobrança de conversão do trial. Cobranças pagas vencidas não suspendem a assinatura (tema da Fase 1B).

**Inalterados:** PIX e QR Code, webhook, conciliação, gateway Mercado Pago, recorrência por cartão, CRONs (código e agendamento), cadastro de empresas, troca de plano, cancelamento, recontratação, regras gerais de acesso. Sem arquivo novo, sem alteração de banco, sem commit nem push.

**Homologação funcional pendente (DEV):**
1. Suspensão manual de empresa em dia → reativar → sucesso e uma linha de auditoria.
2. Suspensão manual com cobrança vencida pendente → `COBRANCA_VENCIDA_PENDENTE`; status e auditoria inalterados.
3. Suspensão manual com cobrança futura não vencida → sucesso.
4. Transação em `aguardando`, `processando` ou com `requer_conciliacao = 1` → `PAGAMENTO_AGUARDANDO_CONFIRMACAO`.
5. Suspensão por `teste_expirado` → `TESTE_EXPIRADO`; `teste_expira_em` inalterado.
6. Teste vigente suspenso manualmente → reativar → sucesso sem alterar as datas do teste.
7. Outra assinatura ativa → continua bloqueando (`ASSINATURA_ATIVA_EXISTENTE`).
8. Suspensão por `inadimplencia`: com pendência bloqueia; após quitação integral e sem transação em andamento, reativa.
9. Duplo clique em Reativar → um único evento de auditoria.
10. Conferir no `git diff` que PIX, webhook, gateway, recorrência e CRONs não mudaram.

---

## BLOCO B — 04_SEGURANCA (somente após homologação)

A reativação administrativa decide sobre estado financeiro lido sob lock na mesma transação. Dúvida financeira (dívida, pagamento incerto, origem desconhecida) resulta sempre em bloqueio, nunca em liberação. Ordem de locks da reativação difere da reavaliação da conversão do trial; deadlock resulta em resposta repetível sem alteração.

---

## FASE 1B — escopo econômico proposto (não implementar sem autorização)

IA/MODELO RECOMENDADO: **SOL** (nível alta complexidade). Motivo: transações, locks e regra financeira central. TERRA só se a Fase 1B for limitada ao item 1B.1.
ESCALAR/PARAR SE: surgir decisão financeira não aprovada ou necessidade de alterar banco.

**Base confirmada no código:** o único caminho que suspende por `inadimplencia` é `assinaturaServicoReavaliarConversaoTrial` (`backend/_servicos/assinatura.php`), e só para a cobrança de conversão do trial. O gerador de cobranças (`gerar_cobrancas_automaticas.php`) só suspende teste expirado. Não existe regra que suspenda assinatura paga com cobrança vencida.

**1B.1 — Predicado central de dívida impeditiva (sem decisão financeira nova).** Mover a lógica de dívida e pagamento incerto da Fase 1A para uma função do serviço de assinatura, reutilizada pela reativação e pelas regras seguintes. Mantém o comportamento atual da 1A. Arquivos previstos: `backend/_servicos/assinatura.php` e `backend/super_admin/assinatura/gerenciar_assinatura.php`.

**1B.2 — Suspensão por inadimplência de assinatura paga.** Suspender `ativa`/`paga` com cobrança vencida impeditiva, gravando `motivo_suspensao = 'inadimplencia'` e auditoria `assinatura.suspensa`. **Depende de decisões do responsável:** carência em dias; quais cobranças contam (periódicas, conversão, ambas); gatilho (CRON existente, sem mudar o agendamento, ou outro). Planos com `gera_cobranca = 0` (Cortesia) nunca entram. Arquivos previstos: `backend/_servicos/assinatura.php`; `gerar_cobrancas_automaticas.php` só se o gatilho for o CRON existente (exige autorização de ampliação, pois é script de CRON).

**1B.3 — Reativação automática por quitação geral.** Hoje só a cobrança de conversão reativa sozinha. Estender para a quitação de qualquer dívida que causou a suspensão por `inadimplencia`, na mesma transação do recálculo (webhook e baixa manual). **Depende da aprovação do item 1B.2** e toca o fluxo do webhook, o que exige autorização específica. Arquivos previstos: `backend/_servicos/assinatura.php` (chamado por `webhook_mercado_pago.php` e `gerenciar_cobranca.php` sem mudar seus contratos).

**1B.4 — Estados contratuais legados (somente diagnóstico).** Consulta somente leitura para listar `assinatura` suspensa com `motivo_suspensao` nulo ou desconhecido e cobranças vencidas de assinaturas canceladas. Qualquer correção de dados seria SQL **manual** entregue ao responsável. Sem arquivo PHP.

**Fora do escopo da 1B:** PIX, gateway, recorrência por cartão, agendamento dos CRONs, cancelamento, troca de plano, recontratação e migração Subscriptions (Parte 2).

---

## Fontes consultadas

Código atual do repositório; Prompt Mestre AmAgenda de 07/10/2026 (Drive); Instrução Inicial do RAG de 01/10/2026 (Drive, PDF); `06_ATUALIZACAO_PLANEJAMENTO_FASE_9D…` e `01_PLANO_CORTESIA_E_REGRAS_DE_COBRANCA…` (Drive).

**Não localizada no Drive:** Instrução Inicial RAG V3 de 02/10/2026. Também não foram localizados o RAG financeiro dedicado, a auditoria consolidada de 24/10/2026 e o plano técnico Subscriptions. Nada foi presumido sobre o conteúdo deles.
