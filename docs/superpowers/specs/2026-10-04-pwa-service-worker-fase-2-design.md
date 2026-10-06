# AmAgenda — Fase 2 — Entrada PWA e Service Worker seguro

Data: 04/10/2026  
Status: design aprovado; implementação pendente  
Projeto: `C:\xampp\htdocs\Sistema-AmAgenda`

## 1. Objetivo

Criar a entrada segura do PWA interno do AmAgenda, substituir o Service Worker legado do Conex por um worker restritivo e registrar esse worker somente nos fluxos internos autorizados.

O PWA permanece destinado exclusivamente a Proprietário, Recepcionista e Profissional. Cliente, Agenda Online e Super Admin não passam a integrar a experiência PWA.

Esta fase não implementa instalação personalizada, atualização forçada, modo offline funcional, sincronização, push ou alterações de banco.

## 2. Estado confirmado no código

- O manifest atual usa `id: "./"`, `scope: "./"` e `start_url: "views/login-empresa.php?source=pwa"`.
- `public/views/login-empresa.php` é o login central e não redireciona uma sessão interna já existente.
- O login atual seleciona automaticamente uma única empresa acessível e usa pré-sessão mais seletor quando há múltiplas empresas.
- O backend decide a empresa, o perfil, a assinatura e o destino após a autenticação.
- Proprietário segue normalmente para o painel administrativo.
- Profissional e Recepcionista seguem normalmente para a agenda.
- O destino de regularização continua sendo o painel administrativo.
- `public/sw.js` usa o cache legado `conex-v2`, cache-first amplo e não possui ciclo controlado de `install` e `activate`.
- Não existe registro atual desse Service Worker.
- DEV usa o VirtualHost `amagenda.local`, com DocumentRoot na raiz do projeto e reescrita para `public/`.
- Em produção, espera-se que `public/` seja o DocumentRoot.

## 3. Entrada do PWA

Será criado `public/pwa.php` como roteador público mínimo.

O arquivo:

- inicia ou reutiliza a sessão PHP existente;
- envia cabeçalhos `no-store`;
- não consulta banco;
- não cria sessão;
- não autentica credenciais;
- não altera contexto de empresa;
- não aceita empresa, slug ou ID por URL;
- não atende sessão de cliente nem sessão de Super Admin;
- decide somente entre o login central e a área interna indicada pelo contexto operacional já consolidado.

Fluxo:

1. Sem `$_SESSION['auth']` interna completa: redirecionar para `/views/login-empresa.php?source=pwa`.
2. Sessão interna com Proprietário: redirecionar para `/views/painel-administrativo/painel-administrativo.html`.
3. Sessão interna com Profissional ou Recepcionista: redirecionar para `/views/agenda.html`.
4. Sessão em modo de regularização: redirecionar para o painel administrativo.
5. Perfil, tipo ou empresa inconsistentes: redirecionar para o login central.

O entrypoint não será uma nova fronteira de autorização. As páginas internas continuarão revalidando a sessão pelo mecanismo autoritativo já existente em `_auth/session`. Uma sessão revogada ou obsoleta que ainda exista no cookie será recusada por esse guard e voltará ao login.

O manifest passará a usar `pwa.php?source=pwa`. O `id` e o `scope` atuais serão preservados.

## 4. Registro controlado

Será criado `public/js/PWA/RegistrarServiceWorker.js`.

O registrador será incluído somente em:

- `public/views/login-empresa.php`;
- `public/views/agenda.html`;
- `public/views/painel-administrativo/painel-administrativo.html`.

Não será incluído em:

- login de cliente;
- páginas de cliente;
- Agenda Online;
- login ou painel de Super Admin;
- site público.

O registro usará `/sw.js` sem domínio hardcoded e `updateViaCache: "none"`. A versão do worker será propagada pelo parâmetro `v` já aplicado ao arquivo registrador pela fonte central de versão. O registrador reutilizará essa versão no URL do worker.

Não haverá `beforeinstallprompt`, botão de instalação, reload automático nem comunicação de atualização nesta fase.

## 5. Limitação de escopo do navegador

As áreas internas e as áreas excluídas compartilham o mesmo origin e o mesmo namespace de URLs. Não existe um prefixo de caminho exclusivo que contenha simultaneamente login interno, agenda e painel administrativo sem também alcançar outras páginas.

Por isso, o escopo técnico do registro precisa continuar na raiz. O isolamento será aplicado em duas camadas:

1. somente as três páginas autorizadas carregam o registrador;
2. o worker usa allowlist fechada e passa diretamente para a rede toda requisição proveniente de cliente controlado fora dos fluxos internos autorizados.

Navegações nunca serão cacheadas, independentemente da página de origem.

## 6. Namespace e versionamento dos caches

O worker usará exclusivamente o prefixo:

`amagenda-`

O cache de runtime seguirá o formato conceitual:

`amagenda-assets-<versao>`

A versão será obtida do próprio URL versionado do Service Worker, com valor de fallback sanitizado. O endpoint `app-version.php` nunca será consultado nem armazenado pelo worker.

Nenhum cache `conex-` será criado ou mantido pelo novo código.

## 7. Install e activate

### Install

O evento `install` será declarado sem pré-cache. Nenhuma página, API ou lista extensa de URLs será aberta durante a instalação.

Não será usado `self.skipWaiting()`.

### Activate

O evento `activate` listará as chaves existentes e excluirá somente caches cujo nome comece com `amagenda-` e não corresponda ao cache atual.

Caches de outras aplicações ou com outros prefixos nunca serão removidos.

Não será usado `clients.claim()`.

## 8. Política fechada de requisições

Uma requisição só poderá participar de estratégia de cache quando cumprir simultaneamente todos os requisitos:

- método `GET`;
- protocolo HTTP ou HTTPS;
- mesma origem do worker;
- não ser navegação nem documento HTML;
- pertencer a cliente interno autorizado;
- caminho e extensão presentes na allowlist explícita;
- não corresponder a nenhuma rota, termo ou endpoint sensível da denylist;
- resposta de rede com status `200` e tipo same-origin compatível;
- resposta sem diretiva `Cache-Control: no-store` ou `Cache-Control: private`.

Por decisão expressa do responsável, a presença de `Set-Cookie` não será usada como critério de segurança ou elegibilidade do cache. A segurança será determinada pela allowlist explícita de caminhos públicos, tipo de request, origem, status e diretivas de cache.

## 9. Allowlist de assets públicos

A allowlist aceitará somente arquivos estáticos públicos com extensões conhecidas e localizados nos diretórios públicos previstos pela aplicação, considerando as duas formas de URL existentes em DEV e produção.

Categorias permitidas:

- JavaScript público em `/js/` e `/public/js/`;
- CSS público em `/css/` e `/public/css/`;
- imagens públicas em `/imagens/` e `/public/imagens/`;
- fontes locais em `/fonts/` e `/public/fonts/`, se existentes;
- o caminho exato `/manifest.json`.

Extensões permitidas serão limitadas às necessárias para essas categorias, por exemplo `.js`, `.css`, `.png`, `.jpg`, `.jpeg`, `.webp`, `.svg`, `.ico`, `.woff`, `.woff2`, `.ttf` e `.otf`.

Arquivos em `/_auth/` ou `/public/_auth/` não entrarão na allowlist de runtime, mesmo quando forem JavaScript.

## 10. Denylist obrigatória

Serão sempre enviados à rede, sem leitura ou escrita no Cache Storage:

- `/api/` e `/public/api/`;
- qualquer caminho de backend;
- `app-version.php`;
- sessão, autenticação, login e logout;
- OTP e recuperação de acesso;
- clientes;
- empresas;
- profissionais;
- agenda e agendamentos quando forem endpoints ou documentos;
- permissões;
- planos;
- pagamentos e faturamento;
- notificações;
- Super Admin;
- Agenda Online e `/agendar/`;
- qualquer requisição `POST`, `PUT`, `PATCH`, `DELETE` ou outro método diferente de `GET`;
- qualquer navegação ou request com destino `document`;
- respostas `401`, `403`, `404`, `500` ou qualquer resposta diferente de `200`.

A denylist será avaliada antes da allowlist.

## 11. Estratégias de cache

### JavaScript

JavaScript permitido usará network-first:

1. tentar a rede;
2. se houver resposta cacheável, devolvê-la e atualizar o cache;
3. somente em falha real de rede, procurar a mesma requisição no cache;
4. se não houver cache, propagar a falha.

Uma resposta HTTP de erro não será substituída silenciosamente por JavaScript antigo.

### CSS, imagens, fontes locais e manifest

Assets permitidos usarão stale-while-revalidate:

1. responder imediatamente do cache quando existir;
2. consultar a rede em paralelo;
3. atualizar o cache somente com resposta elegível;
4. quando não houver item em cache, aguardar a rede.

Essa estratégia evita cache-first indefinido e mantém os assets públicos atualizáveis.

## 12. Navegação e comportamento offline

O worker não chamará `respondWith` para navegações e não armazenará HTML.

Consequências intencionais:

- páginas internas autenticadas sempre dependem da rede;
- uma falha de rede não revela HTML antigo de outro usuário ou empresa;
- não existe fallback offline nesta fase;
- não existe criação, edição ou sincronização offline de dados;
- APIs falham normalmente quando a rede está indisponível.

## 13. Arquivos previstos

Novos:

- `public/pwa.php`;
- `public/js/PWA/RegistrarServiceWorker.js`;
- testes comportamentais estáticos do worker, se necessários para provar a política.

Alterados:

- `public/sw.js`;
- `public/manifest.json`;
- `public/views/login-empresa.php`;
- `public/views/agenda.html`;
- `public/views/painel-administrativo/painel-administrativo.html`;
- `tools/aplicar-versao-assets.ps1`, somente se necessário para incluir o novo registrador no fluxo central de versão.

Nenhum arquivo de cliente, Agenda Online, Super Admin ou banco será alterado.

## 14. Validações previstas

- teste inicial demonstrando que a política legada falha nos requisitos de segurança;
- testes do worker para métodos não GET, APIs, `app-version.php`, navegação, erros HTTP, allowlist, network-first, limpeza limitada por prefixo e ausência de ativação forçada;
- `php -l public/pwa.php`;
- `node --check public/sw.js`;
- `node --check public/js/PWA/RegistrarServiceWorker.js`;
- validação JSON de `public/manifest.json`;
- `tools/aplicar-versao-assets.ps1 -Mode Check`;
- buscas estáticas por `conex-`, `skipWaiting`, `clients.claim`, rotas sensíveis e cache de HTML;
- `git diff --check`;
- revisão integral de `git diff` e da lista de arquivos não rastreados.

Não será aberto navegador nesta fase.

## 15. Segurança e dados

- APIs não serão cacheadas.
- Sessões e respostas de autenticação não serão cacheadas.
- Dados privados não serão cacheados.
- `app-version.php` não será cacheado.
- HTML autenticado não será cacheado nem usado como fallback.
- Caches de outras aplicações não serão removidos.
- O PWA não fixará empresa por ID, slug ou nome.
- O isolamento multiempresa continuará sendo responsabilidade do backend e da sessão existente.
- Banco de dados: sem alteração.

## 16. Condição de parada

Após implementar o entrypoint, o Service Worker, o registro controlado, a política documentada e as validações técnicas, o trabalho deve parar.

Não iniciar botão de instalação, `beforeinstallprompt`, fluxo de iPhone, ícone maskable, aviso de atualização, atualização entre abas, offline completo, background sync ou push.

O RAG deverá registrar somente `FASE 2 — IMPLEMENTADA / EM VALIDAÇÃO`, nunca `VALIDADA`, até confirmação do responsável.
