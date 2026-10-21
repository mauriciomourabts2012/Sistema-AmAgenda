<?php
declare(strict_types=1);

/**
 * AmAgenda - Configuracao central e segura do cookie de sessao PHP (A7).
 *
 * Deve ser incluido e executado ANTES de qualquer session_start().
 * Nao altera session.gc_maxlifetime, o nome (PHPSESSID) nem a logica de negocio.
 */

/**
 * Ambiente DEV local sobre HTTP, identificado somente por dados definidos pelo
 * servidor (nunca por cabecalho controlavel pelo cliente). TODAS as condicoes
 * precisam ser verdadeiras; qualquer duvida resulta em "nao local" (Secure ligado).
 *  - requisicao nao e HTTPS (sem HTTPS, porta diferente de 443);
 *  - origem (REMOTE_ADDR) e destino (SERVER_ADDR) ambos em loopback: conexao
 *    originada na propria maquina;
 *  - nenhum cabecalho de proxy/encaminhamento presente;
 *  - DOCUMENT_ROOT (configuracao do Apache) dentro de uma instalacao XAMPP;
 *  - Host solicitado restrito a localhost / amagenda.local (so pode restringir).
 */
function amagendaAmbienteLocalHttp(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return false;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return false;
    }

    $loopback = static function (string $ip): bool {
        $ip = strtolower(trim($ip));
        return $ip === '::1' || $ip === '0:0:0:0:0:0:0:1' || $ip === '127.0.0.1' || strpos($ip, '127.') === 0
            || strpos($ip, '::ffff:127.') === 0;
    };
    if (!$loopback((string)($_SERVER['REMOTE_ADDR'] ?? '')) || !$loopback((string)($_SERVER['SERVER_ADDR'] ?? ''))) {
        return false;
    }

    foreach ([
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
        'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_VIA',
    ] as $cabecalho) {
        if (isset($_SERVER[$cabecalho])) {
            return false;
        }
    }

    $raiz = strtolower(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
    if (strpos($raiz, '/xampp/htdocs') === false) {
        return false;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', 'amagenda.local'], true);
}

/**
 * Aplica os parametros seguros do cookie de sessao. Idempotente e sem efeito
 * se a sessao ja estiver ativa (nesse caso os parametros nao podem mais mudar).
 */
function amagendaConfigurarSessaoSegura(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !amagendaAmbienteLocalHttp(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
