# AmAgenda — Instrucoes oficiais para agentes de IA

## Projeto
- Projeto pessoal AmAgenda, independente do Sistema GESTAO e do Site Conex.
- Repositorio DEV Windows: C:\xampp\htdocs.
- Respeitar arquitetura, componentes e convencoes existentes.
- Nao copiar regras de negocio ou integracoes de outros projetos.

## RAG oficial — Google Drive
- Fonte: RAG / PROJETOS / SISTEMA-AMAGENDA.
- Consultar primeiro a documentacao relevante para a tarefa.
- Informar quais documentos foram efetivamente consultados.
- Nao afirmar que consultou o Drive sem acesso real e verificavel.
- Se o Drive estiver inacessivel, informar a limitacao.
- Ler somente os documentos necessarios para economizar tokens.
- Para schema, conferir o dump SQL atual; para decisoes estruturais,
  confrontar documentacao com codigo e banco atuais.
- Distinguir implementado, validado, homologado e planejado.
- Atualizar o RAG oficial apos cada etapa efetivamente validada.
- Nunca registrar segredos, credenciais ou tokens no RAG.

## Modulos e regras criticas
- Preservar separacao entre Super Admin, Proprietario,
  Profissional, Recepcao e Cliente.
- Respeitar isolamento multiempresa, sessoes e permissoes.
- Preservar protecoes CSRF, limites de tentativas e auditoria.
- Nao alterar autenticacao ou recuperacao de acesso sem analisar
  todos os fluxos afetados.
- Tratar OTP SMS e e-mail com os limites e estados reais do sistema.
- Preservar contratos de integracao e fluxos de pagamento Mercado Pago.
- Verificar impacto em agenda online, agendamentos e dados de clientes.
- Respeitar aceite legal, privacidade e protecao de dados.

## Regras operacionais
- Trabalhar preferencialmente em DEV.
- Antes de modificar, investigar o escopo e apresentar plano.
- Nao executar SQL automaticamente. Fornecer SQL para execucao manual.
- Nao fazer commit, push, merge ou deploy sem autorizacao.
- Nao alterar configuracoes Apache sem autorizacao.
- Nao ler ou divulgar credenciais desnecessariamente.
- Nao executar testes reais externos sem autorizacao.
- Preservar alteracoes locais e nao apagar arquivos sem aprovacao.
- Implementar mudancas somente apos autorizacao.
- Validar tecnicamente e apresentar resultados antes de encerrar a etapa.

## Economia de tokens
- Localizar arquivos com buscas direcionadas.
- Ler somente arquivos e trechos relevantes.
- Evitar auditorias repetidas e leitura integral do repositorio.
- Preferir respostas objetivas com arquivos, riscos e pendencias.
- Claude Code: usar o subagente pesquisa-economica para buscas pequenas
  e independentes quando a delegacao compensar.
- Nao usar varios agentes quando isso aumentar custo sem beneficio.
- Seguranca e corretude prevalecem sobre economia de tokens.