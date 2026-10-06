# AmAgenda Fase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o entrypoint do PWA interno, substituir o Service Worker legado por uma política segura e registrar o worker somente nos fluxos internos autorizados.

**Architecture:** Um roteador PHP mínimo decide o destino usando apenas a sessão interna já consolidada, enquanto as páginas de destino mantêm a revalidação autoritativa existente. Um Service Worker de escopo raiz usa allowlist fechada, filtro por cliente controlado e estratégias separadas por tipo de asset; um registrador único propaga a versão central pelo URL do worker.

**Tech Stack:** PHP 8, JavaScript compatível com Service Worker, Node.js `node:test`/`vm`, PowerShell e JSON.

**Spec:** `docs/superpowers/specs/2026-10-04-pwa-service-worker-fase-2-design.md`

## Global Constraints

- Preservar todas as alterações atuais do working tree.
- Trabalhar no checkout atual porque as Fases 1 e 1B ainda não estão commitadas e fazem parte da base desta fase.
- Não criar worktree, commit, push ou deploy.
- Não abrir navegador.
- Não executar alteração de banco.
- Não alterar login ou painel de Super Admin.
- Não alterar fluxo de cliente ou Agenda Online.
- O PWA atende apenas Proprietário, Recepcionista e Profissional.
- Usar allowlist explícita para assets públicos cacheáveis.
- Não usar `Set-Cookie` como critério de segurança do cache.
- Não usar `skipWaiting()` nem `clients.claim()`.
- Não cachear navegação, HTML, API, sessão, autenticação, dados privados ou `app-version.php`.
- Encerrar após implementação e validações técnicas da Fase 2.

## Review Focus

- Sessão interna antiga ou malformada deve seguir para o login central, nunca criar ou trocar contexto de empresa.
- Um cliente controlado fora dos três fluxos internos deve receber rede direta, mesmo para um asset que esteja na allowlist.
- Falha real de rede em JavaScript pode usar cache; resposta HTTP de erro deve ser devolvida sem fallback antigo.
- Diretivas `Cache-Control: no-store` e `private` devem impedir escrita, independentemente de `Set-Cookie`.
- `activate` deve preservar qualquer cache sem prefixo `amagenda-`.

---

### Task 1: Entrypoint PWA baseado na sessão existente

**Files:**
- Create: `backend/_regras/pwa_entry.php`
- Create: `public/pwa.php`
- Create: `tools/tests/pwa-entry-test.php`

**Interfaces:**
- Produces: `pwaDestinoSessao(array $sessao): string`, que devolve somente um caminho interno permitido.
- Consumes: formato atual de `$_SESSION['auth']`, sem banco ou parâmetros de empresa.

- [ ] **Step 1: Escrever testes PHP que falham antes da implementação**

Cobrir com valores literais:

- sessão vazia → `/views/login-empresa.php?source=pwa`;
- `auth` malformado, usuário inativo, empresa ausente, cliente e Super Admin → login central;
- Proprietário ativo → painel administrativo;
- Profissional e Recepcionista ativos → agenda;
- modo de regularização interno → painel administrativo;
- `id_empresa`, slug ou empresa fora de `auth` não alteram o destino.

- [ ] **Step 2: Executar o teste e confirmar RED**

Run: `php tools/tests/pwa-entry-test.php`  
Expected: FAIL porque `backend/_regras/pwa_entry.php` ainda não existe.

- [ ] **Step 3: Implementar a função pura e o entrypoint mínimo**

`pwaDestinoSessao(array $sessao): string` valida apenas o contexto já consolidado. `public/pwa.php` inicia a sessão, envia `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache` e `Expires: 0`, calcula o destino, envia `Location` e encerra.

- [ ] **Step 4: Executar o teste e confirmar GREEN**

Run: `php tools/tests/pwa-entry-test.php`  
Expected: todos os casos passam.

- [ ] **Step 5: Validar sintaxe PHP**

Run: `php -l backend/_regras/pwa_entry.php; php -l public/pwa.php; php -l tools/tests/pwa-entry-test.php`  
Expected: nenhum erro de sintaxe.

---

### Task 2: Service Worker restritivo do AmAgenda

**Files:**
- Modify: `public/sw.js`
- Create: `tools/tests/pwa-service-worker.test.js`

**Interfaces:**
- Consumes: versão em `self.location.href` por `?v=<semver>`.
- Produces: listeners `install`, `activate` e `fetch`; cache `amagenda-assets-<versao>`.

- [ ] **Step 1: Escrever testes comportamentais do worker**

Executar o worker real em `vm` com implementações específicas de `self`, `caches`, `clients` e `fetch`. Cobrir:

- listeners obrigatórios e ausência de ativação forçada;
- métodos não GET, navegações e documentos não chamam `respondWith`;
- API, backend, `app-version.php`, `/_auth/`, Agenda Online e Super Admin usam rede sem Cache Storage;
- cliente fora de login interno, agenda ou painel usa rede sem Cache Storage;
- JS permitido usa rede primeiro, grava resposta `200` elegível e usa cache somente quando a rede rejeita;
- resposta HTTP de erro em JS é devolvida sem fallback de cache;
- CSS, imagem, fonte e manifest permitidos usam stale-while-revalidate;
- `no-store` e `private` não são gravados;
- presença de `Set-Cookie` não impede gravação de um asset público elegível;
- respostas diferentes de `200` não são gravadas;
- `activate` remove apenas caches `amagenda-` antigos e preserva caches alheios.

- [ ] **Step 2: Executar o teste e confirmar RED contra o worker legado**

Run: `node --test tools/tests/pwa-service-worker.test.js`  
Expected: FAIL por ausência de `install`/`activate`, namespace legado e política ampla.

- [ ] **Step 3: Implementar o worker mínimo que satisfaz a política**

Manter funções pequenas para: normalização de versão, cliente autorizado, denylist, allowlist, resposta cacheável, network-first e stale-while-revalidate. Avaliar denylist antes da allowlist. Navegações saem antes de `respondWith`.

- [ ] **Step 4: Executar os testes e confirmar GREEN**

Run: `node --test tools/tests/pwa-service-worker.test.js`  
Expected: todos os testes passam.

- [ ] **Step 5: Validar sintaxe do worker**

Run: `node --check public/sw.js`  
Expected: exit code 0.

---

### Task 3: Registro central, manifest e páginas autorizadas

**Files:**
- Create: `public/js/PWA/RegistrarServiceWorker.js`
- Modify: `public/manifest.json`
- Modify: `public/views/login-empresa.php`
- Modify: `public/views/agenda.html`
- Modify: `public/views/painel-administrativo/painel-administrativo.html`
- Create: `tools/tests/pwa-registration.test.js`

**Interfaces:**
- Consumes: parâmetro `v` do próprio `<script>` do registrador.
- Produces: registro de `/sw.js?v=<versao>` com `scope: "/"` e `updateViaCache: "none"`.

- [ ] **Step 1: Escrever testes comportamentais do registrador e do manifest**

Executar o registrador real em `vm` com `document.currentScript.src`, `window` e `navigator.serviceWorker` controlados. Verificar:

- navegador sem suporte não registra;
- versão semver do próprio script é transferida ao URL do worker;
- versão inválida não é propagada;
- registro usa escopo raiz e `updateViaCache: "none"`;
- falha de registro é absorvida sem alterar a página;
- manifest possui `start_url: "pwa.php?source=pwa"`, `id: "./"` e `scope: "./"`.

- [ ] **Step 2: Executar os testes e confirmar RED**

Run: `node --test tools/tests/pwa-registration.test.js`  
Expected: FAIL porque o registrador não existe e o manifest ainda aponta ao login.

- [ ] **Step 3: Implementar registrador, atualizar manifest e incluir somente nas três páginas autorizadas**

Usar caminhos relativos para o arquivo registrador, permitindo DEV com reescrita e produção com `public/` como DocumentRoot. Incluir `?v=1.0.0` para que a ferramenta central possa atualizá-lo.

- [ ] **Step 4: Executar testes e confirmar GREEN**

Run: `node --test tools/tests/pwa-registration.test.js`  
Expected: todos os testes passam.

- [ ] **Step 5: Validar sintaxe, JSON e versão central**

Run: `node --check public/js/PWA/RegistrarServiceWorker.js; node -e "JSON.parse(require('fs').readFileSync('public/manifest.json','utf8')); console.log('manifest ok')"; powershell -NoProfile -ExecutionPolicy Bypass -File tools/aplicar-versao-assets.ps1 -Mode Check`  
Expected: sintaxe válida, `manifest ok` e versão `1.0.0` confirmada nos arquivos configurados.

- [ ] **Step 6: Confirmar distribuição do registro**

Run: busca estática limitada aos HTML/PHP públicos.  
Expected: registrador presente somente em login-empresa, agenda e painel administrativo; ausente em cliente, Agenda Online, site público e Super Admin.

---

### Task 4: Validação integrada e revisão final

**Files:**
- Verify: todos os arquivos das Tasks 1–3
- Update: ledger de execução ignorado pelo Git

**Interfaces:**
- Consumes: testes e artefatos das tarefas anteriores.
- Produces: evidências finais e relatório da Fase 2 como `IMPLEMENTADA / EM VALIDAÇÃO`.

- [ ] **Step 1: Executar a suíte completa da Fase 2**

Run: `php tools/tests/pwa-entry-test.php; node --test tools/tests/pwa-service-worker.test.js tools/tests/pwa-registration.test.js`  
Expected: todos os testes passam.

- [ ] **Step 2: Executar todas as validações estáticas previstas**

Executar `php -l` em todo PHP novo, `node --check` em todo JS novo/alterado, validação JSON, checagem da versão central e buscas de segurança.

Expected:

- nenhum `conex-` no novo worker;
- somente namespace `amagenda-`;
- nenhuma API, método de escrita, navegação, HTML ou `app-version.php` cacheável;
- nenhuma resposta HTTP diferente de `200` gravada;
- ausência de `skipWaiting()` e `clients.claim()`;
- limpeza limitada ao prefixo AmAgenda.

- [ ] **Step 3: Executar verificações Git sem alterar o working tree**

Run: `git diff --check; git status --short; git diff -- public/sw.js public/manifest.json public/views/login-empresa.php public/views/agenda.html public/views/painel-administrativo/painel-administrativo.html`  
Expected: `git diff --check` sem erros; revisão mostra apenas mudanças intencionais da Fase 2 sobre as alterações preexistentes preservadas.

- [ ] **Step 4: Revisar arquivos novos e executar revisão final independente**

Revisar os arquivos não rastreados relevantes e solicitar uma única revisão final por agente em contexto novo, sem permitir que ele edite arquivos.

Expected: nenhum achado Critical ou Important pendente. Achados Critical/Important exigem um único ciclo adicional RED→GREEN; achados Minor são apenas relatados.

- [ ] **Step 5: Encerrar sem ações externas**

Confirmar no relatório:

- `FASE 2 — IMPLEMENTADA / EM VALIDAÇÃO`;
- Banco de dados: sem alteração;
- Commit, push e deploy: não realizados;
- navegador: não aberto;
- próxima fase: não iniciada.
