<?php
declare(strict_types=1);

/*
 * Verifica somente o atributo data-pwa-contexto emitido pelo login central.
 * Cada caso roda em um processo PHP isolado, com sessão em diretório temporário,
 * sem banco e sem autenticação real.
 */

$pagina = realpath(__DIR__ . '/../../public/views/login-empresa.php');
if ($pagina === false) {
    fwrite(STDERR, "FAIL: login-empresa.php não encontrado.\n");
    exit(1);
}

function contextoEmitido(string $pagina, array $sessao): string
{
    $dir = sys_get_temp_dir() . '/pwa-login-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $codigo = 'session_save_path(' . var_export($dir, true) . ');'
        . 'session_start();'
        . '$_SESSION = ' . var_export($sessao, true) . ';'
        . 'ob_start(); include ' . var_export($pagina, true) . '; $html = ob_get_clean();'
        . 'echo preg_match(\'~RegistrarServiceWorker\.js[^"]*"\s+data-pwa-contexto="([a-z]+)"~\', $html, $m) ? $m[1] : "AUSENTE";';
    $processo = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $codigo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $saida = is_resource($processo) ? stream_get_contents($pipes[1]) : '';
    if (is_resource($processo)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($processo);
    }
    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
    return trim((string)$saida);
}

function assertContexto(string $nome, string $pagina, array $sessao, string $esperado): void
{
    $atual = contextoEmitido($pagina, $sessao);
    if ($atual !== $esperado) {
        fwrite(STDERR, "FAIL {$nome}: esperado {$esperado}, obtido {$atual}.\n");
        exit(1);
    }
}

$interno = [
    'logado' => true,
    'id_usuario' => 41,
    'tipo_usuario' => 'usuario',
    'status' => 'ativo',
    'empresa_id' => 7,
    'perfil_nome' => 'proprietario',
    'modo_suporte' => false,
];

assertContexto('visitante anônimo', $pagina, [], 'login');
assertContexto('usuário interno', $pagina, ['auth' => $interno], 'login');
assertContexto(
    'super admin em modo suporte',
    $pagina,
    [
        'auth' => array_replace($interno, ['tipo_usuario' => 'super_admin', 'perfil_nome' => 'super_admin', 'modo_suporte' => true]),
        'empresa_id' => 7,
        'empresa_nome' => 'Empresa Exemplo',
        'modo_suporte' => true,
    ],
    'bloqueado'
);
assertContexto(
    'super admin normal',
    $pagina,
    ['auth' => array_replace($interno, ['tipo_usuario' => 'super_admin', 'empresa_id' => 0, 'perfil_nome' => ''])],
    'bloqueado'
);

fwrite(STDOUT, "PASS: 4 contextos de registro no login central.\n");
