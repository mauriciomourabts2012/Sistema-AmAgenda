# AmAgenda — Versionamento

Ferramenta oficial: `tools/release-version.php` (PHP puro, Windows e Ubuntu).

## 1. Fonte da versão

`backend/_config/app-version.json` é a **única** fonte. Formato obrigatório: `MAJOR.MINOR.PATCH` (ex.: `1.0.10`).
`public/app-version.php` publica essa versão e o `AtualizarPWA.js` a compara com a versão da página aberta.

Arquivos controlados (lista em `rvArquivosPadrao()`):

| Arquivo | Referências `?v=` controladas |
|---|---|
| `public/views/login-empresa.php` | todos os `.js`/`.css` locais |
| `public/views/agenda.html` | todos os `.js`/`.css` locais |
| `public/views/painel-administrativo/painel-administrativo.html` | todos os `.js`/`.css` locais |
| `public/views/login-cliente.php` | todos os `.js`/`.css` locais |
| `public/views/cliente-agendamento.html` | todos os `.js`/`.css` locais |
| `public/views/cliente-perfil.html` | todos os `.js`/`.css` locais |

## 2–4. Nova versão

| Comando | Exemplo |
|---|---|
| `patch` | 1.0.10 → 1.0.11 (correções) |
| `minor` | 1.0.10 → 1.1.0 (funcionalidade nova) |
| `major` | 1.0.10 → 2.0.0 (mudança grande/incompatível) |

## 5. Check

Só valida, não altera nada: versão central válida, arquivos existentes, todas as referências na versão central, UTF-8 válido e sem BOM.

## 6. Dry-run

`patch --dry-run` (ou `minor`/`major`) mostra a versão atual, a nova e quantas referências mudariam. Não grava nada.

## 7. Windows (DEV)

```
cd C:\xampp\htdocs\Sistema-AmAgenda
C:\xampp\php\php.exe tools\release-version.php check
C:\xampp\php\php.exe tools\release-version.php patch --dry-run
C:\xampp\php\php.exe tools\release-version.php patch
```

## 8. Ubuntu

```
php tools/release-version.php check
php tools/release-version.php patch
```

## 9. Fluxo DEV

implementação concluída → `patch` (ou `minor`/`major`) → `check` → testes → commit → deploy.

## 10. Fluxo produção

Produção **não gera versão**: recebe os arquivos já versionados pelo commit.
No servidor, use apenas `php tools/release-version.php check` (ex.: validação no deploy). Nunca rode `patch`/`minor`/`major` em produção.

## 11. Erros e rollback

- Exit code `0` = sucesso; `1` = validação reprovada; `2` = comando inválido; `3` = falha de gravação.
- Tudo é lido, validado e preparado em memória **antes** da primeira gravação. Qualquer erro nessa etapa encerra sem alterar arquivos.
- Cada arquivo é gravado de forma atômica (arquivo temporário + `rename`), sempre UTF-8 **sem BOM**; `app-version.json` é o último.
- Se uma gravação ou a conferência final falhar, os arquivos já gravados são restaurados ao conteúdo original. A mensagem informa se algum arquivo não pôde ser restaurado.
- Após corrigir a causa (arquivo bloqueado, permissão, referência inválida), rode `check` e repita o comando.

Testes: `php tools/tests/release-version-test.php` (usa fixtures temporárias; não altera o projeto).

`tools/aplicar-versao-assets.ps1` permanece apenas como legado/comparação.

## 12. Uploads de imagens no Ubuntu

Limites funcionais do AmAgenda:

| Campo | Limite | Formatos |
|---|---:|---|
| Foto de perfil de usuário ou cliente | 10 MB | JPEG, PNG e WebP |
| Logo da empresa | 15 MB | JPEG, PNG e WebP |
| Imagem lateral do login | 5 MB | JPEG, PNG e WebP |

O backend valida o MIME real e o conteúdo da imagem. A configuração PHP deve ser maior que o maior arquivo aceito; `post_max_size` também precisa comportar logo e imagem lateral enviados na mesma requisição, além do overhead de `multipart/form-data`.

### 12.1 Identificar o PHP efetivamente usado pelo Apache

Execute os diagnósticos no servidor antes de editar qualquer arquivo:

```bash
apache2ctl -M | grep -E 'php|proxy_fcgi'
ls -l /etc/apache2/mods-enabled/php*.load /etc/apache2/conf-enabled/php*-fpm.conf 2>/dev/null
grep -R --line-number -E 'SetHandler.*php|proxy:unix:/run/php|LimitRequestBody' /etc/apache2/apache2.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled 2>/dev/null
find /etc/php -mindepth 3 -maxdepth 3 -type f -name php.ini -print
php --ini
php -r 'foreach (["file_uploads", "upload_max_filesize", "post_max_size", "memory_limit", "upload_tmp_dir"] as $k) echo $k, "=", ini_get($k), PHP_EOL;'
```

Se `php_module` aparecer, use o `php.ini` da versão apontada por `/etc/apache2/mods-enabled/php*.load`, normalmente `/etc/php/<versão>/apache2/php.ini`. Se aparecer `proxy_fcgi_module`, use a versão indicada pelo arquivo `php*-fpm.conf`, normalmente `/etc/php/<versão>/fpm/php.ini`. O resultado de `php --ini` vale somente para a CLI e não prova qual arquivo o Apache/FPM utiliza.

### 12.2 Configuração recomendada

Edite manualmente o `php.ini` real identificado na etapa anterior com `sudoedit`:

```ini
file_uploads = On
upload_max_filesize = 16M
post_max_size = 32M
memory_limit = 256M
upload_tmp_dir = /var/lib/php/amagenda-upload-tmp
```

Crie a pasta temporária privada e confirme os módulos necessários, substituindo `<versão>` pela versão efetivamente identificada:

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/lib/php/amagenda-upload-tmp
php -m | grep -E '^(fileinfo|gd|mbstring|mysqli)$'
PHP_VERSION=8.3 # substitua pelo número efetivamente identificado
sudo apt-get install "php${PHP_VERSION}-gd" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-mysql"
```

`fileinfo` normalmente integra o PHP; ele ainda deve aparecer na lista de módulos. A extensão GD é necessária para gerar os ícones PWA derivados da logo. Após editar, valide e recarregue apenas o serviço realmente ativo:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
# Somente quando o Apache usar PHP-FPM:
PHP_VERSION=8.3 # substitua pelo número efetivamente identificado
sudo systemctl restart "php${PHP_VERSION}-fpm"
sudo systemctl reload apache2
```

### 12.3 Diretórios e permissões

Entre primeiro no diretório real da implantação. Os comandos abaixo derivam o caminho absoluto e preservam o proprietário atual, concedendo escrita apenas ao grupo `www-data`:

```bash
cd /caminho/real/da/implantacao
APP_DIR="$(pwd -P)"
APP_OWNER="$(stat -c '%U' "$APP_DIR")"

sudo install -d -o "$APP_OWNER" -g www-data -m 2775 \
  "$APP_DIR/public/uploads/usuarios" \
  "$APP_DIR/public/uploads/clientes" \
  "$APP_DIR/public/uploads/empresas"

sudo chgrp -R www-data \
  "$APP_DIR/public/uploads/usuarios" \
  "$APP_DIR/public/uploads/clientes" \
  "$APP_DIR/public/uploads/empresas"

sudo find "$APP_DIR/public/uploads/usuarios" "$APP_DIR/public/uploads/clientes" "$APP_DIR/public/uploads/empresas" -type d -exec chmod 2775 {} +
sudo find "$APP_DIR/public/uploads/usuarios" "$APP_DIR/public/uploads/clientes" "$APP_DIR/public/uploads/empresas" -type f -exec chmod 0664 {} +

sudo -u www-data test -w "$APP_DIR/public/uploads/usuarios"
sudo -u www-data test -w "$APP_DIR/public/uploads/clientes"
sudo -u www-data test -w "$APP_DIR/public/uploads/empresas"
sudo -u www-data test -w /var/lib/php/amagenda-upload-tmp
```

Não use `chmod 777`. Mantenha o `.htaccess` de `public/uploads`, que bloqueia execução de scripts e listagem de diretórios. Se o diagnóstico encontrar `LimitRequestBody`, configure no VirtualHost ou bloco `Directory` específico do AmAgenda um valor de pelo menos `33554432` bytes; não remova limites ou proteções globais sem revisar os demais sites do servidor.

As fotos novas de usuários e clientes são gravadas em `public/uploads/usuarios` e `public/uploads/clientes`. Os caminhos antigos em `public/imagens/usuarios` e `public/imagens/clientes` continuam somente para leitura compatível; não os apague nem altere registros existentes durante esta implantação. Caso uma migração futura seja autorizada, faça backup do banco e dos arquivos, copie e valide os arquivos (incluindo hashes), atualize apenas os caminhos correspondentes em transação e mantenha os originais até a homologação terminar.

### 12.4 Diagnóstico e homologação

Depois da implantação, confira os valores do SAPI do Apache/FPM e os logs enquanto reproduz uma única tentativa autorizada:

```bash
sudo journalctl -u apache2 -n 100 --no-pager
sudo tail -n 100 /var/log/apache2/error.log
# Se usar FPM, substitua pela unidade real identificada:
PHP_VERSION=8.3 # substitua pelo número efetivamente identificado
sudo journalctl -u "php${PHP_VERSION}-fpm" -n 100 --no-pager
```

Checklist obrigatório de homologação, em ambiente autorizado:

- foto válida JPEG, PNG e WebP, até 10 MB;
- foto acima de 10 MB;
- logo válida até 15 MB e logo acima de 15 MB;
- imagem lateral válida até 5 MB e acima de 5 MB;
- arquivo com extensão permitida e conteúdo inválido;
- diretório sem escrita para `www-data`;
- requisição acima de `post_max_size`, esperando HTTP 413 e mensagem de limite;
- confirmação de que uma falha não altera o banco, não remove o arquivo anterior e não é exibida como sucesso;
- conferência de `storage/api_errors.log`, `error.log` do Apache e journal do PHP-FPM, sem expor esses detalhes na resposta ao usuário.

## 13. Testes financeiros e homologação controlada de Subscriptions

Preparação auditada em 26/10/2026. Publicação e testes financeiros são executados
exclusivamente pelo responsável, após autorização expressa. A homologação será em
produção com contrato exclusivo de R$ 1,99; não exige outro servidor público.
Esta seção não autoriza agentes a acessar banco, APIs financeiras ou o Google Drive.

### 13.1 Execução segura de testes

- Não executar suites PHP por glob, descoberta automática ou loop sem revisar os
  pontos de entrada e todos os includes alcançáveis.
- `tools/tests/cancelamento-programado.test.php` está em quarentena: encerra com
  código 2 antes dos includes, em CLI e HTTP. Não possui bypass de configuração.
  Seu corpo legado depende da empresa 26 e assinatura 28 e contém INSERTs reais;
  rollback não é isolamento. Substituir por fixtures isoladas antes de reativar.
- Os demais testes encontrados em `tools/tests` cobrem PWA, upload e versionamento.
  Sua classificação é estática; não é autorização para executar automaticamente
  versões futuras. Alguns criam fixtures temporárias e processos PHP filhos.
- `php -l arquivo.php` somente analisa sintaxe. Usar lista explícita de arquivos.
- A conciliação CLI, inclusive seu diagnóstico, carrega o banco configurado.
  Não é um teste isolado. No modo Subscriptions, `--executar` também envia PUTs
  do outbox. Com `--modo=subscriptions --empresa=ID`, o diagnóstico, a rotina, o
  outbox, a sincronização e a reabertura ficam restritos à empresa informada
  (implementado e testado offline; sem `--empresa` o processamento é global).

### 13.2 Impedimentos antes da primeira operação financeira

1. Entrada autenticada: IMPLEMENTADA e testada offline. A rota
   `painel/faturamento/pagamento/cartao/autorizar` (POST, permissão
   `faturamento.pagar`, CSRF, empresa e e-mail da sessão) chama
   `assinaturaServicoPreapprovalIniciar` quando o backend escolhe o fluxo
   Preapproval; preço, plano, empresa e data de cobrança vêm sempre do contrato
   interno, nunca do navegador. Contratos com vínculo Orders seguem no Card Payment
   Brick; com `subscriptions.habilitado=false` e sem vínculo, o fluxo legado é
   mantido e nenhuma Preapproval é criada. Falta: restringir o teste ao contrato de
   homologação pela preparação dos dados (ver 13.3) e validar no Mercado Pago real.
2. Retomada e retorno: IMPLEMENTADOS e testados offline. `JA_VINCULADA` consulta o
   provedor e recupera o `init_point` de uma autorização pendente, sem novo POST;
   o link só sai ao navegador se for HTTPS do checkout de assinaturas do Mercado Pago
   (validado no backend e de novo no JS). O `back_url` foi corrigido e aponta para
   `/public/views/painel-administrativo/painel-administrativo.html?mp_assinatura_retorno=1`
   (base `url_publica`). O retorno nunca prova autorização: o JS faz POST com CSRF em
   `cartao/status`, e o servidor consulta o provedor. Falta homologar com o Mercado
   Pago real: o host do `init_point` no sandbox e o comportamento do redirecionamento.
3. Confirmar com o Mercado Pago o contrato de notificações de Preapproval. A página
   de Webhooks lista os tópicos, mas exclui Assinaturas da configuração pelo painel;
   a referência de POST /preapproval não confirma `notification_url`.
   Não presumir suporte nem trocar para IPN sem HMAC.
4. CHECK `chk_aspr_slot_ativo`: RESOLVIDO no banco pelo responsável. A definição
   vigente (aplicada manualmente; o dump estrutural de 26/10/2026 é anterior e está
   desatualizado) inclui `suspensa` entre os estados do motor preapproval com
   `slot_ativo=1`, usando `<=>` para tratar NULL. O código mantém `slot_ativo=1` e
   `cobrar_a_partir_de` em `suspensa` e `cancelamento_pendente` até a confirmação
   externa do encerramento (testado offline com essa definição). Antes de ativar, o
   responsável deve conferir a definição no banco publicado (`SHOW CREATE TABLE
   assinatura_pagamento_recorrente`); qualquer SQL é preparado pelo ChatGPT e
   executado pelo responsável.
5. Recuperação restrita: IMPLEMENTADA e testada offline. Use sempre
   `--modo=subscriptions --empresa=ID` na homologação. O escopo vale para
   diagnóstico, rotina, outbox, sincronização, faturas e reabertura; sem `--empresa`
   a rotina é global e `--limite=1` não equivale a seleção individual.
6. Definir janela da primeira cobrança: o código exige período pago vigente e
   agenda `start_date` para o dia seguinte ao fim desse período. Não há cobrança
   imediata garantida. O corpo atual não envia `end_date` para limitar renovação.
   R$ 1,99 é o valor recorrente pretendido; aceitação do valor pelo provedor ainda
   não foi validada. A documentação de pagamento autorizado prevê uma verificação
   de cartão com valor mínimo e devolução; não prometer débito total limitado a
   R$ 1,99 nem ausência de lançamentos temporários durante a autorização.

### 13.3 Procedimento do responsável, após resolver os impedimentos

1. Fazer backup e registrar versão publicada, responsável, janela, empresa,
   contrato e limite de gasto. Preservar credenciais, PIX e Orders de produção.
2. Conferir manualmente o esquema publicado contra o dump aprovado. Não importar
   o dump completo em produção: ele contém DROP TABLE. Não executar teste legado.
3. Preparar empresa/contrato exclusivo, sem vínculo Orders/Preapproval concorrente,
   sem troca de plano ou dívida, com `assinatura.valor_contratado=1.99`, modalidade
   paga e período pago vigente. Não alterar preços comerciais em `plano`.
   O estado financeiro deve ser legítimo e documentado; não simular quitação.
4. Confirmar tópicos `subscription_preapproval`, `subscription_authorized_payment`
   e `payment`, URL pública HTTPS, HMAC e recursos consultados na mesma aplicação.
   Registrar evidência de entrega/validação antes da autorização do cartão.
5. Manter Subscriptions desligado na publicação; somente o responsável poderá
   habilitar na janela aprovada e restringir o acesso à empresa de homologação.
6. Criar UMA Preapproval pending. Registrar ID local/externo, referência, valor BRL
   1,99, frequência, start_date e próxima cobrança. Verificar no provedor antes de
   abrir init_point. Em timeout, conciliar pela mesma referência; não repetir POST.
7. O proprietário autoriza no Mercado Pago. No retorno, consultar estado externo;
   parâmetros do navegador não provam autorização. Confirmar authorized e vínculo.
8. Acompanhar a primeira fatura e seu Payment ID. Confirmar R$ 1,99, BRL, vínculo,
   período e pagamento approved/accredited com fatura processed. Conferir UMA baixa
   local, sem duplicidade por reenvio. Retry/processamento não equivale a quitação.
9. Encerrar o ensaio: solicitar cancelamento e processar somente a operação desse
   vínculo. O cancelamento local apenas registra intenção; o cancelamento externo
   usa PUT /preapproval/{id} com status canceled e exige leitura de confirmação.
10. Confirmar canceled no provedor e cancelada localmente, slot NULL e operação
    concluída. Inspecionar também faturas/pagamentos já emitidos, pendências,
    processing/recycling e tentativas agendadas. Cancelar assinatura não comprova
    cancelamento de um pagamento já emitido nem efetua reembolso.
11. Se houver pendência, resolver pelo provedor e conciliar o vínculo. Verificar
    novamente após a janela de processamento informada pelo provedor e após a
    antiga data da próxima renovação. Somente encerrar com evidências de ausência
    de débitos pendentes e novas cobranças; uma próxima data histórica não prova
    sozinha que a recorrência continua ativa ou que foi encerrada.
12. Depois da confirmação externa e local, devolver a chave para false. Desligar
    Subscriptions, publicar rollback ou remover CRON NÃO cancela a assinatura no
    Mercado Pago. Arquivar evidências sem segredos e atualizar o RAG pelo responsável.

### 13.4 Recuperação

- Criação incerta: guardar referência/IDs; pesquisar e conferir contrato/valor antes
  de retomar. Não criar outra assinatura para contornar erro local.
- Falha de webhook: consultar recursos autoritativos e conciliar só o vínculo alvo;
  não forjar evento nem baixar pagamento manualmente como se fosse confirmado.
- Falha de cancelamento: manter o caso aberto e cancelar a mesma Preapproval no
  Mercado Pago pelo canal oficial disponível ao responsável; consultar até confirmar
  canceled, depois sincronizar o estado local e tratar pagamentos em trânsito.
- Se o AmAgenda estiver indisponível: cancelar no provedor primeiro; restaurar o
  serviço para conciliar depois. Não apagar vínculos, transações ou operação incerta.
- Cobrança indevida: cancelar recorrência e tratar cancelamento/reembolso do
  pagamento separadamente, com autorização do responsável e conciliação posterior.

Fontes oficiais consultadas (contratos não confirmados permanecem pendentes):
[pagamento pendente](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/integration-configuration/subscription-no-associated-plan/pending-payments),
[criar Preapproval](https://www.mercadopago.com.br/developers/pt/reference/online-payments/subscriptions/create-preapproval/post),
[pagamento autorizado](https://www.mercadopago.com.br/developers/en/docs/subscriptions/integration-configuration/subscription-no-associated-plan/authorized-payments),
[Webhooks](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks),
[gerenciamento](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/subscription-management).
