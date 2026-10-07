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
