<?php
declare(strict_types=1);

/*
 * Testes de tools/release-version.php. Tudo roda em diretórios temporários
 * (fixtures); o projeto real nunca é alterado.
 * Uso: php tools/tests/release-version-test.php
 */

require_once __DIR__ . '/../release-version.php';

$falhas = [];
$casos = 0;

function caso(string $nome, callable $teste): void
{
    global $falhas, $casos;
    $casos++;
    try {
        $teste();
    } catch (Throwable $e) {
        $falhas[] = "{$nome}: " . $e->getMessage();
    }
}

function confirmar(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

function esperarErro(callable $acao, string $trecho): RvErro
{
    try {
        $acao();
    } catch (RvErro $e) {
        confirmar(strpos($e->getMessage(), $trecho) !== false, "mensagem inesperada: {$e->getMessage()}");
        return $e;
    }
    throw new RuntimeException("era esperado erro contendo '{$trecho}'");
}

const ARQUIVOS_FIXTURE = [
    'public/views/login.php' => 'todos',
    'public/views/agenda.html' => 'todos',
    'public/views/cliente.html' => 'pwa',
];

/** Cria um projeto mínimo. CRLF e acentos de propósito, para provar preservação. */
function criarFixture(string $versao = '1.0.10'): string
{
    $raiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rv-' . bin2hex(random_bytes(6));
    mkdir($raiz . '/backend/_config', 0777, true);
    mkdir($raiz . '/public/views', 0777, true);
    file_put_contents($raiz . '/backend/_config/app-version.json', "{\n  \"version\": \"{$versao}\"\n}\n");
    file_put_contents($raiz . '/public/views/login.php',
        "<?php\r\ndeclare(strict_types=1);\r\n// sessão e autenticação\r\n?>\r\n"
        . "<link rel=\"stylesheet\" href=\"../css/login.css?v={$versao}\" />\r\n"
        . "<script src=\"../js/PWA/RegistrarServiceWorker.js?v={$versao}\" data-pwa-contexto=\"login\"></script>\r\n");
    file_put_contents($raiz . '/public/views/agenda.html',
        "<!doctype html>\n<title>Agenda • Ação</title>\n"
        . "<link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/x/all.min.css\">\n"
        . "<script src=\"../js/a.js?v={$versao}\"></script>\n"
        . "<script src=\"../js/b.js?x=1&v={$versao}\"></script>\n");
    file_put_contents($raiz . '/public/views/cliente.html',
        "<!doctype html>\n<link rel=\"stylesheet\" href=\"../css/cliente.css?v=20260905\">\n"
        . "<script src=\"../js/PWA/InstalarPWA.js?v={$versao}\"></script>\n");
    return $raiz;
}

function removerFixture(string $raiz): void
{
    $itens = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($itens as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($raiz);
}

function instantaneo(string $raiz): array
{
    $mapa = [];
    foreach (array_merge(array_keys(ARQUIVOS_FIXTURE), [RV_ARQUIVO_VERSAO]) as $rel) {
        $caminho = rvCaminho($raiz, $rel);
        $mapa[$rel] = is_file($caminho) ? hash_file('sha256', $caminho) : null;
    }
    return $mapa;
}

function comFixture(callable $teste, string $versao = '1.0.10'): void
{
    $raiz = criarFixture($versao);
    try {
        $teste($raiz);
    } finally {
        removerFixture($raiz);
    }
}

function versaoCentral(string $raiz): string
{
    return json_decode((string)file_get_contents(rvCaminho($raiz, RV_ARQUIVO_VERSAO)), true)['version'];
}

foreach ([['patch', '1.0.11'], ['minor', '1.1.0'], ['major', '2.0.0']] as [$tipo, $esperada]) {
    caso("{$tipo}: 1.0.10 -> {$esperada}", function () use ($tipo, $esperada) {
        comFixture(function (string $raiz) use ($tipo, $esperada) {
            $r = rvLiberar($raiz, ARQUIVOS_FIXTURE, $tipo, false);
            confirmar($r['novaVersao'] === $esperada, "nova versão {$r['novaVersao']}");
            confirmar(versaoCentral($raiz) === $esperada, 'app-version.json não atualizado');
            $v = rvVerificar($raiz, ARQUIVOS_FIXTURE);
            confirmar($v['erros'] === [], implode(' | ', $v['erros']));
            confirmar($v['referencias'] === 5, "referências: {$v['referencias']}");
            $cliente = (string)file_get_contents($raiz . '/public/views/cliente.html');
            confirmar(strpos($cliente, 'cliente.css?v=20260905') !== false, 'escopo pwa alterou asset fora de /PWA/');
            $agenda = (string)file_get_contents($raiz . '/public/views/agenda.html');
            confirmar(strpos($agenda, 'b.js?x=1&v=' . $esperada) !== false, 'query existente não preservada');
            confirmar(strpos($agenda, 'cdnjs.cloudflare.com/x/all.min.css"') !== false, 'asset externo alterado');
            confirmar(strpos($agenda, 'Agenda • Ação') !== false, 'acentuação alterada');
        });
    });
}

caso('versão preserva o restante do arquivo (CRLF, PHP, formatação do JSON)', function () {
    comFixture(function (string $raiz) {
        $antes = (string)file_get_contents($raiz . '/public/views/login.php');
        rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false);
        $depois = (string)file_get_contents($raiz . '/public/views/login.php');
        confirmar(str_replace('v=1.0.10', 'v=1.0.11', $antes) === $depois, 'conteúdo além da versão foi alterado');
        confirmar(file_get_contents(rvCaminho($raiz, RV_ARQUIVO_VERSAO)) === "{\n  \"version\": \"1.0.11\"\n}\n", 'JSON reformatado');
    });
});

caso('dry-run não altera nenhum arquivo', function () {
    comFixture(function (string $raiz) {
        $antes = instantaneo($raiz);
        foreach (['patch', 'minor', 'major'] as $tipo) {
            $r = rvLiberar($raiz, ARQUIVOS_FIXTURE, $tipo, true);
            confirmar($r['alteradas'] === 5, "alteradas: {$r['alteradas']}");
        }
        confirmar(instantaneo($raiz) === $antes, 'dry-run modificou arquivos');
        confirmar(glob($raiz . '/public/views/*.release-tmp-*') === [], 'sobrou arquivo temporário');
    });
});

caso('check aprova configuração consistente', function () {
    comFixture(function (string $raiz) {
        $v = rvVerificar($raiz, ARQUIVOS_FIXTURE);
        confirmar($v['erros'] === [] && $v['versao'] === '1.0.10' && $v['arquivos'] === 3 && $v['referencias'] === 5, json_encode($v));
    });
});

caso('check e rvMain não alteram arquivos e retornam 0', function () {
    comFixture(function (string $raiz) {
        $antes = instantaneo($raiz);
        ob_start();
        $codigo = rvMain(['release-version.php', 'check'], $raiz, ARQUIVOS_FIXTURE);
        $saida = (string)ob_get_clean();
        confirmar($codigo === RV_EXIT_OK, "exit {$codigo}");
        confirmar(strpos($saida, 'Resultado: APROVADO') !== false, 'saída sem APROVADO');
        confirmar(instantaneo($raiz) === $antes, 'check modificou arquivos');
    });
});

foreach (['1', '1.0', 'v1.0.1', '1.0.a', '1.0.1.2', '01.0.0', ''] as $invalida) {
    caso("versão inválida '{$invalida}' falha", function () use ($invalida) {
        confirmar(!rvVersaoValida($invalida), 'aceitou versão inválida');
        comFixture(function (string $raiz) use ($invalida) {
            file_put_contents(rvCaminho($raiz, RV_ARQUIVO_VERSAO), json_encode(['version' => $invalida]));
            $antes = instantaneo($raiz);
            esperarErro(fn() => rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false), 'inválida');
            esperarErro(fn() => rvVerificar($raiz, ARQUIVOS_FIXTURE), 'inválida');
            confirmar(instantaneo($raiz) === $antes, 'arquivos alterados após versão inválida');
        });
    });
}

caso('referência antiga reprova o check (exit != 0)', function () {
    comFixture(function (string $raiz) {
        $arquivo = $raiz . '/public/views/agenda.html';
        file_put_contents($arquivo, str_replace('a.js?v=1.0.10', 'a.js?v=1.0.9', (string)file_get_contents($arquivo)));
        $v = rvVerificar($raiz, ARQUIVOS_FIXTURE);
        confirmar(count($v['erros']) === 1 && strpos($v['erros'][0], 'v=1.0.9') !== false, implode(' | ', $v['erros']));
        ob_start();
        $codigo = rvMain(['x', 'check'], $raiz, ARQUIVOS_FIXTURE);
        ob_end_clean();
        confirmar($codigo !== RV_EXIT_OK, 'check aprovou referência antiga');
    });
});

caso('referência sem versão reprova o check', function () {
    comFixture(function (string $raiz) {
        $arquivo = $raiz . '/public/views/agenda.html';
        file_put_contents($arquivo, str_replace('a.js?v=1.0.10', 'a.js', (string)file_get_contents($arquivo)));
        confirmar(rvVerificar($raiz, ARQUIVOS_FIXTURE)['erros'] !== [], 'aprovou referência sem versão');
    });
});

caso('arquivo ausente falha sem alterar nada', function () {
    comFixture(function (string $raiz) {
        unlink($raiz . '/public/views/cliente.html');
        $antes = instantaneo($raiz);
        esperarErro(fn() => rvVerificar($raiz, ARQUIVOS_FIXTURE), 'não encontrado');
        esperarErro(fn() => rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false), 'não encontrado');
        confirmar(instantaneo($raiz) === $antes, 'arquivos alterados');
    });
});

caso('UTF-8 inválido falha sem alterar nada', function () {
    comFixture(function (string $raiz) {
        file_put_contents($raiz . '/public/views/agenda.html', "<script src=\"a.js?v=1.0.10\"></script>\xE3\x28");
        $antes = instantaneo($raiz);
        esperarErro(fn() => rvVerificar($raiz, ARQUIVOS_FIXTURE), 'UTF-8');
        esperarErro(fn() => rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false), 'UTF-8');
        confirmar(instantaneo($raiz) === $antes, 'arquivo inválido foi regravado');
    });
});

caso('BOM é detectado pelo check', function () {
    comFixture(function (string $raiz) {
        $arquivo = $raiz . '/public/views/login.php';
        file_put_contents($arquivo, RV_BOM . file_get_contents($arquivo));
        $v = rvVerificar($raiz, ARQUIVOS_FIXTURE);
        confirmar(count($v['erros']) === 1 && strpos($v['erros'][0], 'BOM') !== false, implode(' | ', $v['erros']));
    });
});

caso('release grava sem BOM (inclusive removendo BOM existente)', function () {
    comFixture(function (string $raiz) {
        $arquivo = $raiz . '/public/views/login.php';
        file_put_contents($arquivo, RV_BOM . file_get_contents($arquivo));
        rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false);
        foreach (array_merge(array_keys(ARQUIVOS_FIXTURE), [RV_ARQUIVO_VERSAO]) as $rel) {
            $bytes = (string)file_get_contents(rvCaminho($raiz, $rel));
            confirmar(strncmp($bytes, RV_BOM, 3) !== 0, "{$rel} gravado com BOM");
        }
        confirmar(strncmp((string)file_get_contents($arquivo), '<?php', 5) === 0, 'PHP não inicia em <?php');
    });
});

caso('falha antes da gravação não altera nada (arquivo sem referências)', function () {
    comFixture(function (string $raiz) {
        file_put_contents($raiz . '/public/views/cliente.html', "<!doctype html>\n<p>sem assets</p>\n");
        $antes = instantaneo($raiz);
        $chamadas = 0;
        esperarErro(fn() => rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false, function () use (&$chamadas) {
            $chamadas++;
        }), 'nenhuma referência');
        confirmar($chamadas === 0, 'gravador foi chamado');
        confirmar(instantaneo($raiz) === $antes, 'arquivos alterados');
    });
});

caso('falha durante a gravação executa rollback completo', function () {
    comFixture(function (string $raiz) {
        $antes = instantaneo($raiz);
        $gravacoes = 0;
        $gravadorComFalha = function (string $caminho, string $bytes) use (&$gravacoes) {
            $gravacoes++;
            if ($gravacoes === 3) {
                throw new RvErro('disco cheio (simulado)');
            }
            rvGravarAtomico($caminho, $bytes);
        };
        $erro = esperarErro(function () use ($raiz, $gravadorComFalha) {
            rvLiberar($raiz, ARQUIVOS_FIXTURE, 'patch', false, $gravadorComFalha);
        }, 'Rollback executado');
        confirmar($erro->getCode() === RV_EXIT_GRAVACAO, "código {$erro->getCode()}");
        confirmar($gravacoes === 3, "gravações: {$gravacoes}");
        confirmar(instantaneo($raiz) === $antes, 'rollback não restaurou o estado anterior');
        confirmar(versaoCentral($raiz) === '1.0.10', 'versão central ficou alterada');
        confirmar(glob($raiz . '/public/views/*.release-tmp-*') === [], 'sobrou arquivo temporário');
    });
});

caso('rvMain: uso inválido retorna exit 2', function () {
    comFixture(function (string $raiz) {
        foreach ([['x'], ['x', 'foo'], ['x', 'check', '--dry-run'], ['x', 'patch', 'minor']] as $argv) {
            ob_start();
            $codigo = rvMain($argv, $raiz, ARQUIVOS_FIXTURE, fopen('php://memory', 'w'));
            ob_end_clean();
            confirmar($codigo === RV_EXIT_USO, json_encode($argv) . " => {$codigo}");
        }
    });
});

caso('script não usa caminhos ou shells específicos de Windows', function () {
    $codigo = (string)file_get_contents(__DIR__ . '/../release-version.php');
    confirmar(preg_match('/[A-Z]:\\\\\\\\|powershell|cmd\.exe|\.bat\b|shell_exec|exec\(|system\(/i', $codigo) !== 1, 'dependência de plataforma encontrada');
});

if ($falhas !== []) {
    foreach ($falhas as $falha) {
        fwrite(STDERR, "FAIL: {$falha}\n");
    }
    fwrite(STDERR, count($falhas) . " de {$casos} casos falharam.\n");
    exit(1);
}
echo "PASS: {$casos} casos do release-version.php.\n";
