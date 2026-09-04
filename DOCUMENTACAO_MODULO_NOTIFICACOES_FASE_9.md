# AMAGENDA — Fase 9 — Encerramento do módulo inicial de notificações

## 1. Identificação

- Projeto: `C:\xampp\htdocs\Sistema-AmAgenda`
- Data da consolidação: 03/09/2026
- Escopo: registro do estado final das Fases 1 a 8 e das correções realizadas antes do encerramento do módulo inicial de notificações.
- Estado: módulo inicial implementado e integrado. A divergência do limite de Proprietários do plano Premium permanece como pendência de configuração no banco efetivamente usado pela aplicação.

Esta fase é exclusivamente documental. Não cria funcionalidade, não altera código, não altera tabelas e não altera dados.

## 2. Fontes verificadas

O registro abaixo foi conferido diretamente no estado atual do projeto e, quando aplicável, no banco aberto por `backend/_config/conexao.php`.

Principais pontos de implementação consultados:

- `backend/_servicos/notificacao.php`;
- `public/api/api_central.php`;
- `public/js/notificacoes/notificacoes.js`;
- `backend/_auth/login.php`;
- `backend/_auth/sessao.php`;
- `backend/_auth/require_auth.php`;
- `backend/perfil_usuario/alterar_senha_perfil.php`;
- `backend/perfil_usuario/alterar_foto_perfil.php`;
- `backend/painel_administrativo/usuario/cadastrar_usuario.php`;
- `backend/painel_administrativo/usuario/editar_usuario.php`;
- `backend/super_admin/usuario/cadastrar_usuario.php`;
- `backend/_regras/limites_plano.php`;
- telas e estilos do menu lateral nos painéis Administrativo, Super Admin e Agenda.

## 3. Fases 1 a 8 consolidadas

### Fase 1 — Persistência e modelo da notificação

Estado registrado: concluída.

A tabela `notificacao` mantém empresa, tipo e identificador do destinatário, origem, código, categoria, título, mensagem, prioridade, obrigatoriedade, ação, contexto, prazo e os estados de leitura, conclusão e cancelamento. Também possui chave única de deduplicação e índices por empresa, destinatário, código, prazo e pendência.

O modelo permite destinatários dos tipos `usuario`, `cliente` e `super_admin`, e origens dos tipos `sistema`, `super_admin` e `usuario`.

### Fase 2 — Serviço central de notificações

Estado registrado: concluída.

`backend/_servicos/notificacao.php` é a camada central reutilizável. O serviço:

- valida tipos, identificadores, textos, datas, contexto e prioridade;
- rejeita dados sensíveis no contexto da notificação;
- valida o destinatário e o escopo da empresa antes da gravação;
- cria notificações usando a conexão recebida, sem abrir ou confirmar transação própria;
- aplica deduplicação por `chave_deduplicacao`;
- lista pendências;
- marca como lida;
- conclui ou cancela notificações.

Para destinatário do tipo `usuario` com empresa informada, a validação exige vínculo entre o usuário e exatamente aquela empresa. Para clientes, o identificador também é validado dentro da empresa. Esse comportamento preserva o isolamento SaaS.

### Fase 3 — API autenticada

Estado registrado: concluída.

As rotas públicas do módulo são:

- `GET notificacoes/listar`;
- `POST notificacoes/marcar-lida`.

As rotas usam o usuário autenticado como destinatário e resolvem o contexto empresarial pelo backend. O navegador não escolhe livremente o destinatário nem a empresa da consulta ou atualização.

A listagem retorna somente notificações não concluídas e não canceladas do destinatário e do contexto validados. A alteração do estado também exige correspondência de notificação, destinatário e empresa.

### Fase 4 — Centro visual de notificações

Estado registrado: concluída.

O cliente `public/js/notificacoes/notificacoes.js` integra o sino, a listagem, a quantidade pendente, o estado de leitura e as ações conhecidas. As ações iniciais autorizadas são:

- `perfil.alterar_senha`;
- `perfil.alterar_foto`.

O componente está carregado no Painel Administrativo, Painel Super Admin e Agenda. Os estilos responsivos estão integrados ao menu lateral para web e mobile.

### Fase 5 — Senha temporária

Estado registrado: concluída.

Usuários novos criados pelos fluxos empresariais integrados recebem:

- `usuario.deve_alterar_senha = 1`;
- `usuario.data_senha_temporaria = CURRENT_TIMESTAMP`;
- notificação `seguranca.senha_temporaria`;
- prazo calculado em 24 horas;
- ação `perfil.alterar_senha`.

Login e consulta de sessão expõem `deve_alterar_senha` e `senha_temporaria_vencida`. A validade é recalculada no backend a partir dos dados atuais do usuário.

### Fase 6 — Bloqueio por expiração e recuperação permitida

Estado registrado: concluída.

Quando a senha temporária vence, as APIs autenticadas retornam `SENHA_TEMPORARIA_EXPIRADA`, exceto as rotas mínimas necessárias para autenticação, consulta de sessão, logout e alteração da própria senha. O frontend reage ao código e direciona o usuário à correção obrigatória.

Ao alterar a senha com sucesso:

- a senha é atualizada;
- `deve_alterar_senha` passa para `0`;
- `data_senha_temporaria` passa para `NULL`;
- notificações pendentes `seguranca.senha_temporaria` do mesmo destinatário e contexto são concluídas;
- o estado correspondente da sessão é atualizado.

A atualização e a conclusão das notificações participam da mesma transação de banco.

### Fase 7 — Foto de perfil pendente

Estado registrado: concluída.

Quando o usuário novo não possui `foto_perfil`, é criada a notificação `perfil.foto_pendente`, com ação `perfil.alterar_foto`.

Depois do envio bem-sucedido da foto, as notificações pendentes desse código, destinatário e contexto são concluídas na mesma transação da atualização do usuário. Em falha, a transação é revertida e o arquivo recém-movido é removido.

### Fase 8 — Integrações finais e consistência operacional

Estado registrado: concluída com a pendência de configuração de plano descrita na seção 7.

Foram consolidados:

- criação das notificações iniciais no cadastro empresarial normal;
- renovação da notificação de senha temporária quando uma senha é redefinida administrativamente;
- cancelamento da ocorrência anterior antes da nova ocorrência de senha;
- conclusão automática das pendências ao cumprir a ação correspondente;
- revalidação autoritativa da senha temporária nas requisições autenticadas;
- isolamento por empresa e destinatário;
- integração visual nas telas principais;
- integração do cadastro de Proprietário executado pelo Super Admin;
- validação dinâmica de limites de usuários e perfis a partir do plano vinculado à empresa.

## 4. Correções finais registradas

### 4.1 Cadastro de Proprietário pelo Super Admin

O frontend do Painel Super Admin usa a rota `superadmin/usuario/cadastrar`, mapeada para `backend/super_admin/usuario/cadastrar_usuario.php`.

Para usuário global realmente novo, esse handler:

1. valida a empresa selecionada e o perfil Proprietário;
2. bloqueia a empresa dentro da transação para serializar a verificação de limites;
3. valida o limite total de usuários e o limite específico do perfil;
4. cria o usuário com `deve_alterar_senha = 1` e `data_senha_temporaria = CURRENT_TIMESTAMP`;
5. cria o vínculo `empresa_usuario` usando a empresa validada;
6. reutiliza `backend/_servicos/notificacao.php`;
7. cria `seguranca.senha_temporaria` para o novo usuário;
8. cria `perfil.foto_pendente` quando `foto_perfil` está nula ou vazia;
9. confirma cadastro, vínculo, auditoria e notificações na mesma transação.

As notificações usam:

- `id_empresa`: empresa validada no cadastro;
- `destinatario_tipo`: `usuario`;
- `destinatario_id`: identificador do usuário recém-criado;
- `origem_tipo`: `sistema`.

O bloco não é executado quando o Super Admin apenas vincula um usuário global já existente. Assim, senha, data de senha temporária e foto de usuários antigos não são modificadas por esse fluxo.

### 4.2 Limites de plano

A fonte autoritativa é `empresa.plano_id`, relacionado a `plano.id_plano`. O backend carrega dinamicamente:

- `plano.limite_usuarios`;
- `plano.limite_proprietarios`;
- `plano.limite_profissionais`;
- `plano.limite_recepcionistas`.

O nome comercial do plano é usado somente na apresentação da mensagem. Não existe autorização para definir limites pelo texto `Premium`, por observações do plano, por constantes ou por arrays duplicados de configuração.

Na entrada de um novo vínculo que conta no plano, a regra valida simultaneamente:

1. capacidade total em `limite_usuarios`;
2. capacidade específica de `limite_proprietarios`, `limite_profissionais` ou `limite_recepcionistas`, conforme o perfil validado.

A contagem de Proprietários usa vínculos da empresa informada e perfil com nome normalizado para Proprietário. Não inclui usuários de outras empresas, Super Admin global ou profissionais. Vínculos marcados com `bloqueado_plano = 1` ficam fora do consumo disponível adotado pela regra atual. Os status de vínculo e usuário considerados pela regra atual são `ativo` e `bloqueado`.

## 5. Estado verificado do banco

Consulta de conferência executada em 03/09/2026, sem escrita, usando a conexão efetiva de `backend/_config/conexao.php`.

Resultado para a empresa `Studio Moura`:

| Campo | Valor confirmado |
|---|---:|
| `empresa.id_empresa` | 1 |
| `empresa.plano_id` | 3 |
| `plano.nome` | Premium |
| `plano.status` | ativo |
| `plano.limite_usuarios` | 14 |
| `plano.limite_proprietarios` | **1** |
| `plano.limite_profissionais` | 10 |
| `plano.limite_recepcionistas` | 3 |

O valor solicitado de `limite_proprietarios = 2` não foi confirmado no banco efetivamente usado pela aplicação e, por isso, não é registrado neste documento como valor atual.

Também foram confirmadas duas notificações persistidas para o usuário vinculado à Studio Moura:

- `seguranca.senha_temporaria`;
- `perfil.foto_pendente`.

Ambas possuem `id_empresa = 1` e o destinatário correto.

## 6. Garantias do estado final

- O backend é a fonte de verdade para autenticação, destinatário, empresa e limites.
- O frontend não define nem amplia limites de plano.
- O nome do plano não determina capacidade.
- A criação da notificação reutiliza uma conexão fornecida pelo chamador.
- O serviço não executa `commit` ou `rollback` próprio.
- Operações compostas mantêm cadastro, alteração e ciclo da notificação na transação do caso de uso.
- Chaves de deduplicação evitam duplicidade acidental da mesma ocorrência.
- Contextos de notificação não aceitam chaves reconhecidas como dados sensíveis.
- Senhas e hashes não são armazenados no payload da notificação.

## 7. Pendências futuras

### 7.1 Regularizar e confirmar o limite do Premium

Pendência operacional: verificar em qual servidor, banco ou registro foi realizado o ajuste anunciado para `limite_proprietarios = 2`. No banco efetivamente aberto pela aplicação, o valor continua igual a 1.

Depois de uma alteração de banco executada por processo autorizado fora desta fase, repetir a consulta e atualizar esta seção somente quando o resultado efetivo for:

| Campo | Valor esperado, ainda não confirmado |
|---|---:|
| `limite_usuarios` | 14 |
| `limite_proprietarios` | 2 |
| `limite_profissionais` | 10 |
| `limite_recepcionistas` | 3 |

### 7.2 Evoluções fora do módulo inicial

Possíveis categorias adicionais, canais externos, preferências de entrega, filas, retentativas, notificações em tempo real ou rotinas de limpeza não pertencem ao módulo inicial encerrado. Qualquer evolução deverá ser tratada como novo escopo, com regras, autorização e testes próprios.

## 8. Encerramento da Fase 9

- Documentação criada: este arquivo.
- Fases registradas: 1 a 8.
- Correções finais registradas: cadastro pelo Super Admin, senha temporária, foto pendente, serviço reutilizado e limites dinâmicos.
- Código alterado nesta fase: não.
- Banco alterado nesta fase: não.
- Arquivos novos nesta fase: somente este documento Markdown.
- Commit realizado: não.
- Push realizado: não.
