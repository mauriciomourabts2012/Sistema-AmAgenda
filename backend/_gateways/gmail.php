<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| GATEWAY GMAIL - SMTP AUTENTICADO (OTP POR E-MAIL)
|--------------------------------------------------------------------------
|
| Transporte privado para o Gmail oficial de suporte do AmAgenda. Usa SMTP com
| TLS (465 implícito ou 587 STARTTLS) e senha de app. Sem bibliotecas externas.
|
| Este arquivo nunca registra, devolve ou expõe a senha de app, o endereço de
| destino completo, o código OTP nem o texto da mensagem. Sem reenvio automático.
|
| Resultados (mesmo vocabulário do gateway Infobip):
| - aceita: o servidor confirmou o recebimento da mensagem (250 após DATA);
| - rejeitada: recusa definitiva (autenticação, destinatário ou conteúdo);
| - falha_conexao: nada foi enviado (conexão, TLS ou recusa temporária antes de DATA);
| - incerto: a conexão caiu depois de o conteúdo ser enviado, sem resposta final.
|
*/

const GMAIL_HOST_PERMITIDO = 'smtp.gmail.com';

/**
 * Valida a configuração privada. Devolve apenas códigos de erro, nunca valores.
 *
 * @param array<string, mixed> $bruta
 * @return array{ok: bool, erros: list<string>, config: array<string, mixed>}
 */
function gmailConfigValidar(array $bruta): array
{
    $erros = [];
    $texto = static fn (string $chave): string => is_string($bruta[$chave] ?? null) ? $bruta[$chave] : '';
    $semControle = static fn (string $v): bool => preg_match('/[\x00-\x1F\x7F]/', $v) !== 1;

    $host = strtolower($texto('host'));
    if ($host !== GMAIL_HOST_PERMITIDO) {
        $erros[] = 'HOST_INVALIDO';
    }

    $porta = $bruta['porta'] ?? null;
    if (!is_int($porta) || !in_array($porta, [465, 587], true)) {
        $erros[] = 'PORTA_INVALIDA';
    }

    $usuario = strtolower($texto('usuario'));
    if (filter_var($usuario, FILTER_VALIDATE_EMAIL) === false || strlen($usuario) > 160) {
        $erros[] = 'USUARIO_INVALIDO';
    }

    // Senha de app do Google: 16 letras, exibida em grupos de 4 separados por espaço.
    $senha = preg_replace('/\s+/', '', $texto('senha_app')) ?? '';
    if (preg_match('/^[A-Za-z]{16}$/', $senha) !== 1) {
        $erros[] = 'SENHA_APP_INVALIDA';
    }

    $remetente = strtolower($texto('remetente_email'));
    if (filter_var($remetente, FILTER_VALIDATE_EMAIL) === false || strlen($remetente) > 160) {
        $erros[] = 'REMETENTE_INVALIDO';
    }
    $nome = $texto('remetente_nome');
    if ($nome === '' || mb_strlen($nome) > 60 || !$semControle($nome) || preg_match('/["<>\\\\]/', $nome) === 1) {
        $erros[] = 'REMETENTE_NOME_INVALIDO';
    }

    $ambiente = $texto('ambiente');
    if (!in_array($ambiente, ['teste', 'producao'], true)) {
        $erros[] = 'AMBIENTE_INVALIDO';
    }

    $timeoutConexao = $bruta['timeout_conexao'] ?? null;
    $timeoutTotal = $bruta['timeout_total'] ?? null;
    if (!is_int($timeoutConexao) || $timeoutConexao < 1 || $timeoutConexao > 10) {
        $erros[] = 'TIMEOUT_CONEXAO_INVALIDO';
    }
    if (!is_int($timeoutTotal) || $timeoutTotal < 2 || $timeoutTotal > 30
        || (is_int($timeoutConexao) && $timeoutTotal < $timeoutConexao)) {
        $erros[] = 'TIMEOUT_TOTAL_INVALIDO';
    }

    return [
        'ok' => $erros === [],
        'erros' => $erros,
        'config' => [
            'habilitado' => ($bruta['habilitado'] ?? false) === true,
            'ambiente' => $ambiente,
            'host' => $host,
            'porta' => is_int($porta) ? $porta : 465,
            'usuario' => $usuario,
            'senha_app' => $senha,
            'remetente_email' => $remetente,
            'remetente_nome' => $nome,
            'timeout_conexao' => is_int($timeoutConexao) ? $timeoutConexao : 5,
            'timeout_total' => is_int($timeoutTotal) ? $timeoutTotal : 15,
        ],
    ];
}

/**
 * Carrega backend/_config/gmail.php. Ausente, inválido ou desabilitado resulta em
 * canal de e-mail indisponível (envio desligado).
 *
 * @return array{ok: bool, habilitado: bool, erros: list<string>, config: array<string, mixed>}
 */
function gmailConfigCarregar(?string $caminho = null): array
{
    $caminho ??= __DIR__ . '/../_config/gmail.php';
    $bruta = is_file($caminho) ? require $caminho : null;
    if (!is_array($bruta)) {
        return ['ok' => false, 'habilitado' => false, 'erros' => ['CONFIG_AUSENTE'], 'config' => []];
    }

    $validada = gmailConfigValidar($bruta);

    return [
        'ok' => $validada['ok'],
        'habilitado' => $validada['ok'] && $validada['config']['habilitado'] === true,
        'erros' => $validada['erros'],
        'config' => $validada['config'],
    ];
}

/** Endereço simples e seguro para cabeçalhos/envelope (sem CR/LF, espaços ou aspas). */
function gmailEnderecoValido(string $email): bool
{
    return strlen($email) <= 160
        && preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)+$/', $email) === 1
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Mensagem MIME text/plain UTF-8 em base64. Assunto e nome codificados (RFC 2047). */
function gmailMontarMensagem(array $config, string $messageId, string $destino, string $assunto, string $texto): string
{
    $dominio = substr((string)strrchr((string)$config['remetente_email'], '@'), 1);
    $cabecalhos = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: =?UTF-8?B?' . base64_encode((string)$config['remetente_nome']) . '?= <' . $config['remetente_email'] . '>',
        'To: <' . $destino . '>',
        'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
        'Message-ID: <' . $messageId . '@' . $dominio . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];

    return implode("\r\n", $cabecalhos) . "\r\n\r\n" . chunk_split(base64_encode($texto), 76, "\r\n");
}

/** Lê uma resposta SMTP (inclui linhas de continuação). @return array{codigo: int, texto: string}|null */
function gmailLerResposta($socket): ?array
{
    $ultima = '';
    while (($linha = fgets($socket, 1024)) !== false) {
        $ultima = rtrim($linha, "\r\n");
        if (strlen($ultima) < 4 || $ultima[3] !== '-') {
            break;
        }
    }
    if ($ultima === '' || preg_match('/^(\d{3})/', $ultima, $m) !== 1) {
        return null;
    }

    return ['codigo' => (int)$m[1], 'texto' => ''];
}

/**
 * Transporte SMTP padrão. Devolve somente fase e código numérico (sem textos do servidor).
 *
 * @param array<string, mixed> $req
 * @return array{fase: string, codigo: int, errno: int}
 */
function gmailTransporteSmtp(array $req): array
{
    $falha = static fn (string $fase, int $codigo = 0, int $errno = 1): array => ['fase' => $fase, 'codigo' => $codigo, 'errno' => $errno];

    $implicito = (int)$req['porta'] === 465;
    $contexto = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => (string)$req['host'],
        'allow_self_signed' => false,
    ]]);
    $socket = @stream_socket_client(
        ($implicito ? 'ssl://' : 'tcp://') . $req['host'] . ':' . $req['porta'],
        $errno,
        $errstr,
        (float)$req['timeout_conexao'],
        STREAM_CLIENT_CONNECT,
        $contexto
    );
    if (!is_resource($socket)) {
        return $falha('conexao');
    }
    stream_set_timeout($socket, (int)$req['timeout_total']);

    $enviar = static function (string $comando) use ($socket): bool {
        return @fwrite($socket, $comando . "\r\n") !== false;
    };
    $esperar = static function (array $codigos, string $fase) use ($socket, $falha): ?array {
        $r = gmailLerResposta($socket);
        if ($r === null) {
            return $falha($fase);
        }

        return in_array($r['codigo'], $codigos, true) ? null : $falha($fase, $r['codigo'], 0);
    };

    try {
        if (($erro = $esperar([220], 'conexao')) !== null) {
            return $erro;
        }
        if (!$enviar('EHLO amagenda.local') || ($erro = $esperar([250], 'conexao')) !== null) {
            return $erro ?? $falha('conexao');
        }
        if (!$implicito) {
            if (!$enviar('STARTTLS') || ($erro = $esperar([220], 'conexao')) !== null) {
                return $erro ?? $falha('conexao');
            }
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) !== true) {
                return $falha('conexao');
            }
            if (!$enviar('EHLO amagenda.local') || ($erro = $esperar([250], 'conexao')) !== null) {
                return $erro ?? $falha('conexao');
            }
        }
        $credencial = base64_encode("\0" . $req['usuario'] . "\0" . $req['senha_app']);
        if (!$enviar('AUTH PLAIN ' . $credencial) || ($erro = $esperar([235], 'auth')) !== null) {
            return $erro ?? $falha('auth');
        }
        unset($credencial);
        if (!$enviar('MAIL FROM:<' . $req['remetente_email'] . '>') || ($erro = $esperar([250], 'remetente')) !== null) {
            return $erro ?? $falha('remetente');
        }
        if (!$enviar('RCPT TO:<' . $req['destino'] . '>') || ($erro = $esperar([250, 251], 'destinatario')) !== null) {
            return $erro ?? $falha('destinatario');
        }
        if (!$enviar('DATA') || ($erro = $esperar([354], 'data')) !== null) {
            return $erro ?? $falha('data');
        }
        // Conteúdo em base64 não contém linha iniciada por ponto; o cabeçalho também não.
        if (@fwrite($socket, $req['mensagem'] . "\r\n.\r\n") === false) {
            return $falha('final');
        }
        $final = gmailLerResposta($socket);
        if ($final === null) {
            return $falha('final');
        }
        $enviar('QUIT');

        return ['fase' => 'final', 'codigo' => $final['codigo'], 'errno' => 0];
    } finally {
        @fclose($socket);
    }
}

/**
 * Classifica o resultado do transporte.
 *
 * @param array{fase: string, codigo: int, errno: int} $t
 * @return array{resultado: string, erro_codigo: string|null, smtp_codigo: int|null}
 */
function gmailClassificarResposta(array $t): array
{
    $fase = (string)$t['fase'];
    $codigo = (int)$t['codigo'];
    $smtp = $codigo > 0 ? $codigo : null;

    if ($fase === 'final') {
        if ($codigo === 0) {
            // Conteúdo enviado e sem resposta: o servidor pode ter aceitado.
            return ['resultado' => 'incerto', 'erro_codigo' => 'GMAIL_SEM_RESPOSTA_FINAL', 'smtp_codigo' => null];
        }
        if ($codigo >= 200 && $codigo < 300) {
            return ['resultado' => 'aceita', 'erro_codigo' => null, 'smtp_codigo' => $codigo];
        }

        return ['resultado' => 'rejeitada', 'erro_codigo' => 'GMAIL_FINAL_' . $codigo, 'smtp_codigo' => $smtp];
    }
    if ($fase === 'auth') {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'GMAIL_AUTENTICACAO', 'smtp_codigo' => $smtp];
    }
    if ($fase === 'destinatario') {
        return ['resultado' => 'rejeitada', 'erro_codigo' => 'GMAIL_DESTINATARIO', 'smtp_codigo' => $smtp];
    }
    // Conexão, TLS, remetente e DATA: nada chegou a ser enviado.
    return ['resultado' => 'falha_conexao', 'erro_codigo' => 'GMAIL_' . strtoupper($fase), 'smtp_codigo' => $smtp];
}

/**
 * Envia um e-mail. $transporte permite substituir o SMTP em testes.
 *
 * @param array<string, mixed> $config configuração validada
 * @param (callable(array): array)|null $transporte
 * @return array{resultado: string, erro_codigo: string|null, smtp_codigo: int|null}
 */
function gmailEnviar(array $config, string $messageId, string $destino, string $assunto, string $texto, ?callable $transporte = null): array
{
    $invalido = ['resultado' => 'rejeitada', 'erro_codigo' => 'GMAIL_PARAMETROS_INVALIDOS', 'smtp_codigo' => null];
    if (!gmailEnderecoValido($destino)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $messageId) !== 1
        || $texto === '' || $assunto === '' || preg_match('/[\r\n]/', $assunto) === 1) {
        return $invalido;
    }

    $requisicao = [
        'host' => (string)$config['host'],
        'porta' => (int)$config['porta'],
        'usuario' => (string)$config['usuario'],
        'senha_app' => (string)$config['senha_app'],
        'remetente_email' => (string)$config['remetente_email'],
        'destino' => $destino,
        'mensagem' => gmailMontarMensagem($config, $messageId, $destino, $assunto, $texto),
        'timeout_conexao' => (int)$config['timeout_conexao'],
        'timeout_total' => (int)$config['timeout_total'],
    ];

    try {
        $resposta = ($transporte ?? 'gmailTransporteSmtp')($requisicao);
    } catch (Throwable) {
        return ['resultado' => 'incerto', 'erro_codigo' => 'GMAIL_TRANSPORTE', 'smtp_codigo' => null];
    }

    return gmailClassificarResposta([
        'fase' => (string)($resposta['fase'] ?? 'conexao'),
        'codigo' => (int)($resposta['codigo'] ?? 0),
        'errno' => (int)($resposta['errno'] ?? 0),
    ]);
}
