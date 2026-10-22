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
