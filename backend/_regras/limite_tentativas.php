<?php
declare(strict_types=1);

/**
 * ==========================================================
 * LIMITE DE TENTATIVAS — LOGIN POR SENHA DO CLIENTE (Auditoria A4)
 * ==========================================================
 * Usado somente por:
 * - backend/_auth/cliente_login.php (ação login_senha);
 * - backend/cliente_perfil/alterar_senha_perfil.php (limpeza após recuperação
 *   por código concluída com a nova senha gravada).
 *
 * Mesmo padrão do limitador do login central (backend/_auth/login.php), que NÃO
 * é alterado: prepared statements, chaves SHA-256 binárias, GET_LOCK e a tabela
 * login_limite_conta_ip (criada manualmente). As chaves do cliente usam prefixos
 * próprios e incluem empresa + telefone E.164, sem colisão com as do staff.
 * Telefone, IP e senha nunca são gravados em texto nem registrados em log.
 *
 * Contadores:
 * - Conta (empresa + telefone): 20 falhas em 15 min -> bloqueio de 15 min.
 *   Independe do IP; ativo em todos os ambientes.
 * - Conta + IP: 10 falhas em 15 min -> bloqueio de 5 min.
 *   Somente com origem confiável (ambiente local, ou produção após confirmar
 *   a topologia de proxy/CDN, alterando a constante abaixo).
 *
 * Regras:
 * - Verificado antes da busca do cliente e de password_verify.
 * - Telefone inexistente, cliente inativo, sem senha ou senha incorreta contam
 *   igual (a mensagem é definida pelo handler e não revela a conta).
 * - Tentativas recebidas durante o bloqueio não são contadas nem prolongam a
 *   penalidade. Terminado o bloqueio ou vencida a janela, a contagem recomeça.
 * - Senha correta, ou recuperação por código concluída, apaga a contagem.
 * - Concorrência: um GET_LOCK por conta serializa leitura, gravação e exclusão
 *   dos contadores da conta; é liberado em todas as saídas. Nenhum contador é
 *   apagado sem esse lock.
 * - Recuperação concluída: com o lock, apaga os contadores na hora; sem o lock
 *   (contenção), grava um marcador de liberação (gravação atômica, sem apagar
 *   nada), aplicado sob o lock pela próxima tentativa de login da conta, antes
 *   da leitura dos contadores. Assim o próximo login nunca encontra o bloqueio
 *   anterior à recuperação.
 * - Falha técnica: fail-open (o login segue sem limite nesta requisição), com
 *   registro técnico sem dados sensíveis.
 * - Afeta somente o login por senha: OTP, recuperação por código e agendamento
 *   não passam por aqui.
 */

const LIMITE_CLIENTE_CONTA_MAX_FALHAS = 20;
const LIMITE_CLIENTE_CONTA_BLOQUEIO_SEGUNDOS = 900;
const LIMITE_CLIENTE_CONTA_IP_MAX_FALHAS = 10;
const LIMITE_CLIENTE_CONTA_IP_BLOQUEIO_SEGUNDOS = 300;
const LIMITE_CLIENTE_JANELA_SEGUNDOS = 900;
const LIMITE_CLIENTE_ESPERA_LOCK_SEGUNDOS = 5;
const LIMITE_CLIENTE_ESPERA_LOCK_LIMPEZA_SEGUNDOS = 2;
const LIMITE_CLIENTE_RETRY_CONTENCAO_SEGUNDOS = 60;
// Conta + IP fora do ambiente local: ativar somente depois de confirmar que
// REMOTE_ADDR identifica o cliente real (topologia de proxy/CDN da produção).
const LIMITE_CLIENTE_IP_ATIVO_FORA_DO_LOCAL = false;

/** Executa uma consulta preparada do limitador; devolve as linhas (SELECT) e lança exceção em falha. */
function limiteTentativasConsultar(mysqli $conexao, string $sql, string $tipos, mixed ...$valores): array
{
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta do limite de tentativas.');
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao executar consulta do limite de tentativas.');
    }
    $resultado = $stmt->get_result();
    $linhas = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $linhas;
}

/** IP de origem (REMOTE_ADDR) validado; cabeçalhos de proxy são ignorados. */
function limiteTentativasIpOrigem(): ?string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/** IP usado na chave: IPv4 completo (inclusive IPv4 mapeado em IPv6) ou prefixo /64 do IPv6. */
function limiteTentativasIpChave(string $ip): string
{
    $binario = inet_pton($ip);
    if ($binario === false) {
        return $ip;
    }
    if (strlen($binario) === 16) {
        if (substr($binario, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string)inet_ntop(substr($binario, 12));
        }
        return (string)inet_ntop(substr($binario, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return (string)inet_ntop($binario);
}

/**
 * Ambiente local, decidido somente por dados do servidor (mesmo critério do login
 * central): IP remoto ou do servidor em faixa local/privada, nenhum cabeçalho de
 * proxy e host amagenda.local/localhost.
 */
function limiteTentativasAmbienteLocal(): bool
{
    $ehIpLocalOuPrivado = static function (string $ip): bool {
        $ip = strtolower(trim($ip));
        if ($ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $binario = inet_pton($ip);
        if ($binario === false || strlen($binario) !== 4) {
            return false;
        }
        $octetos = unpack('C4', $binario);
        return is_array($octetos) && (
            $octetos[1] === 127
            || $octetos[1] === 10
            || ($octetos[1] === 172 && $octetos[2] >= 16 && $octetos[2] <= 31)
            || ($octetos[1] === 192 && $octetos[2] === 168)
        );
    };

    if (!$ehIpLocalOuPrivado((string)($_SERVER['REMOTE_ADDR'] ?? ''))
        && !$ehIpLocalOuPrivado((string)($_SERVER['SERVER_ADDR'] ?? ''))) {
        return false;
    }
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_VIA'] as $cabecalho) {
        if (isset($_SERVER[$cabecalho])) {
            return false;
        }
    }
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:[0-9]{1,5}$/', '', $host) ?? '';
    return in_array($host, ['amagenda.local', 'localhost'], true);
}

/** Chave da conta do cliente (empresa + telefone E.164), independente do IP. */
function limiteTentativasClienteChaveConta(int $idEmpresa, string $telefoneE164): string
{
    return hash('sha256', "amagenda:cliente_senha:conta\n" . $idEmpresa . "\n" . $telefoneE164, true);
}

/**
 * Chave conta + IP. Devolve null quando a origem não é confiável (o contador
 * conta + IP não é usado nesse caso).
 */
function limiteTentativasClienteChaveContaIp(int $idEmpresa, string $telefoneE164): ?string
{
    $ip = limiteTentativasIpOrigem();
    if ($ip === null || !(LIMITE_CLIENTE_IP_ATIVO_FORA_DO_LOCAL || limiteTentativasAmbienteLocal())) {
        return null;
    }
    return hash(
        'sha256',
        "amagenda:cliente_senha:conta_ip\n" . $idEmpresa . "\n" . $telefoneE164 . "\n" . limiteTentativasIpChave($ip),
        true
    );
}

/** Marcador de liberação da conta, gravado pela recuperação concluída sem o lock. */
function limiteTentativasClienteMarcadorConta(int $idEmpresa, string $telefoneE164): string
{
    return hash('sha256', "amagenda:cliente_senha:liberar_conta\n" . $idEmpresa . "\n" . $telefoneE164, true);
}

/**
 * Marcador de liberação da conta + IP da origem atual. Devolve null quando a
 * origem não é confiável (o contador conta + IP não é usado nesse caso).
 */
function limiteTentativasClienteMarcadorContaIp(int $idEmpresa, string $telefoneE164): ?string
{
    $ip = limiteTentativasIpOrigem();
    if ($ip === null || !(LIMITE_CLIENTE_IP_ATIVO_FORA_DO_LOCAL || limiteTentativasAmbienteLocal())) {
        return null;
    }
    return hash(
        'sha256',
        "amagenda:cliente_senha:liberar_conta_ip\n" . $idEmpresa . "\n" . $telefoneE164 . "\n" . limiteTentativasIpChave($ip),
        true
    );
}

/** Grava (ou renova) um marcador de liberação. Gravação atômica; não apaga contadores. */
function limiteTentativasGravarMarcador(mysqli $conexao, string $marcador): void
{
    limiteTentativasConsultar(
        $conexao,
        'INSERT INTO login_limite_conta_ip (chave, falhas, primeira_falha_em, ultima_falha_em, bloqueado_ate)
         VALUES (?, 0, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6), NULL)
         ON DUPLICATE KEY UPDATE
             falhas = 0,
             primeira_falha_em = VALUES(primeira_falha_em),
             ultima_falha_em = VALUES(ultima_falha_em),
             bloqueado_ate = NULL',
        's',
        $marcador
    );
}

/**
 * Aplica um marcador de liberação pendente. Chamar somente com o lock da conta:
 * se o marcador existir, apaga o contador correspondente e o próprio marcador.
 */
function limiteTentativasAplicarMarcador(mysqli $conexao, string $marcador, string $chaveContador): void
{
    $linhas = limiteTentativasConsultar(
        $conexao,
        'SELECT 1 AS existe FROM login_limite_conta_ip WHERE chave = ? LIMIT 1',
        's',
        $marcador
    );
    if (!$linhas) {
        return;
    }
    limiteTentativasConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', $chaveContador);
    limiteTentativasConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', $marcador);
}

/** Nome do GET_LOCK da conta (serializa os dois contadores da mesma conta). */
function limiteTentativasClienteNomeLock(string $chaveConta): string
{
    return 'amagenda:cli_senha:' . substr(bin2hex($chaveConta), 0, 40);
}

/** Libera o lock, se houver (idempotente; o MySQL também libera ao fim da conexão). */
function limiteTentativasLiberarLock(mysqli $conexao, array &$limite): void
{
    $nomeLock = $limite['lock'] ?? null;
    if ($nomeLock === null) {
        return;
    }
    $limite['lock'] = null;
    try {
        limiteTentativasConsultar($conexao, 'SELECT RELEASE_LOCK(?) AS liberado', 's', $nomeLock);
    } catch (Throwable) {
        // O lock é liberado automaticamente quando a conexão termina.
    }
}

/** Lê o estado de um contador: ['falhas' => int, 'nova_janela' => bool, 'segundos_bloqueio' => int]. */
function limiteTentativasLerContador(mysqli $conexao, string $chave): array
{
    $linhas = limiteTentativasConsultar(
        $conexao,
        'SELECT falhas,
                TIMESTAMPDIFF(SECOND, primeira_falha_em, CURRENT_TIMESTAMP(6)) AS idade_janela,
                IF(bloqueado_ate IS NULL, NULL,
                   CEIL(TIMESTAMPDIFF(MICROSECOND, CURRENT_TIMESTAMP(6), bloqueado_ate) / 1000000)) AS segundos_bloqueio
           FROM login_limite_conta_ip
          WHERE chave = ?
          LIMIT 1',
        's',
        $chave
    );
    $registro = $linhas[0] ?? null;

    $estado = ['falhas' => 0, 'nova_janela' => true, 'segundos_bloqueio' => 0];
    if ($registro === null) {
        return $estado;
    }
    if ($registro['segundos_bloqueio'] !== null) {
        if ((int)$registro['segundos_bloqueio'] > 0) {
            $estado['segundos_bloqueio'] = (int)$registro['segundos_bloqueio'];
        }
        // Bloqueio encerrado: a contagem recomeça do zero.
        return $estado;
    }
    if ((int)$registro['idade_janela'] < LIMITE_CLIENTE_JANELA_SEGUNDOS) {
        $estado['falhas'] = max(0, (int)$registro['falhas']);
        $estado['nova_janela'] = false;
    }
    return $estado;
}

/** Grava uma falha no contador; devolve os segundos de bloqueio aplicados (0 = sem bloqueio). */
function limiteTentativasGravarFalha(mysqli $conexao, string $chave, array $estado, int $maxFalhas, int $bloqueioSegundos): int
{
    $novasFalhas = (int)$estado['falhas'] + 1;
    $bloquear = $novasFalhas >= $maxFalhas ? 1 : 0;
    $novaJanela = ($estado['nova_janela'] ?? true) ? 1 : 0;

    limiteTentativasConsultar(
        $conexao,
        'INSERT INTO login_limite_conta_ip (chave, falhas, primeira_falha_em, ultima_falha_em, bloqueado_ate)
         VALUES (?, ?, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6),
                 IF(? = 1, DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ? SECOND), NULL))
         ON DUPLICATE KEY UPDATE
             falhas = VALUES(falhas),
             primeira_falha_em = IF(? = 1, VALUES(primeira_falha_em), primeira_falha_em),
             ultima_falha_em = VALUES(ultima_falha_em),
             bloqueado_ate = VALUES(bloqueado_ate)',
        'siiii',
        $chave,
        $novasFalhas,
        $bloquear,
        $bloqueioSegundos,
        $novaJanela
    );

    return $bloquear === 1 ? $bloqueioSegundos : 0;
}

/** Texto do tempo de espera, arredondado para cima em minutos. */
function limiteTentativasMensagemBloqueio(int $segundos): string
{
    $minutos = max(1, (int)ceil($segundos / 60));
    return 'Muitas tentativas de acesso. Aguarde ' . $minutos . ($minutos === 1 ? ' minuto' : ' minutos') . ' e tente novamente.';
}

/** Resposta 429 de bloqueio, com Retry-After e data.retry_after em segundos. */
function limiteTentativasClienteResponderBloqueio(mysqli $conexao, array &$limite, int $segundos): void
{
    limiteTentativasLiberarLock($conexao, $limite);
    $segundos = max(1, $segundos);
    header('Retry-After: ' . $segundos);
    out([
        'ok' => false,
        'code' => 'CLIENT_LOGIN_TOO_MANY_ATTEMPTS',
        'user_msg' => limiteTentativasMensagemBloqueio($segundos),
        'data' => ['retry_after' => $segundos],
    ], 429);
}

/**
 * Inicia o limitador do login por senha: adquire o lock da conta e lê os
 * contadores. Em bloqueio vigente (ou contenção do lock) responde 429 e encerra.
 * Em falha técnica devolve um estado inativo (fail-open).
 */
function limiteTentativasClienteIniciar(mysqli $conexao, int $idEmpresa, string $telefoneE164): array
{
    $limite = [
        'ativo' => false,
        'lock' => null,
        'conta' => null,
        'conta_ip' => null,
        'estado_conta' => null,
        'estado_conta_ip' => null,
    ];

    $segundosBloqueio = 0;
    try {
        $chaveConta = limiteTentativasClienteChaveConta($idEmpresa, $telefoneE164);
        $chaveContaIp = limiteTentativasClienteChaveContaIp($idEmpresa, $telefoneE164);
        $nomeLock = limiteTentativasClienteNomeLock($chaveConta);

        $lock = limiteTentativasConsultar(
            $conexao,
            'SELECT GET_LOCK(?, ' . LIMITE_CLIENTE_ESPERA_LOCK_SEGUNDOS . ') AS adquirido',
            's',
            $nomeLock
        );
        $adquirido = $lock[0]['adquirido'] ?? null;
        if ($adquirido === null) {
            throw new RuntimeException('GET_LOCK indisponível.');
        }

        if ((int)$adquirido !== 1) {
            // Muitas tentativas simultâneas da mesma conta aguardando a vez.
            $segundosBloqueio = LIMITE_CLIENTE_RETRY_CONTENCAO_SEGUNDOS;
        } else {
            $limite['lock'] = $nomeLock;

            // Recuperação concluída sem o lock: aplica a liberação pendente
            // antes de ler os contadores (sempre sob o lock da conta).
            limiteTentativasAplicarMarcador(
                $conexao,
                limiteTentativasClienteMarcadorConta($idEmpresa, $telefoneE164),
                $chaveConta
            );
            if ($chaveContaIp !== null) {
                $marcadorContaIp = limiteTentativasClienteMarcadorContaIp($idEmpresa, $telefoneE164);
                if ($marcadorContaIp !== null) {
                    limiteTentativasAplicarMarcador($conexao, $marcadorContaIp, $chaveContaIp);
                }
            }

            $estadoConta = limiteTentativasLerContador($conexao, $chaveConta);
            $estadoContaIp = $chaveContaIp !== null
                ? limiteTentativasLerContador($conexao, $chaveContaIp)
                : null;

            $segundosBloqueio = max(
                (int)$estadoConta['segundos_bloqueio'],
                (int)($estadoContaIp['segundos_bloqueio'] ?? 0)
            );

            $limite['ativo'] = true;
            $limite['conta'] = $chaveConta;
            $limite['conta_ip'] = $chaveContaIp;
            $limite['estado_conta'] = $estadoConta;
            $limite['estado_conta_ip'] = $estadoContaIp;
        }
    } catch (Throwable $e) {
        error_log('[cliente_login_limite] ' . get_class($e) . ': limitador indisponível; login segue sem limite nesta requisição.');
        limiteTentativasLiberarLock($conexao, $limite);
        $limite['ativo'] = false;
        $segundosBloqueio = 0;
    }

    if ($segundosBloqueio > 0) {
        // Bloqueio vigente: nada é contado, e a penalidade não é prolongada.
        limiteTentativasClienteResponderBloqueio($conexao, $limite, $segundosBloqueio);
    }

    return $limite;
}

/**
 * Registra uma falha de credenciais nos contadores ativos. Quando a falha atinge
 * o limite de algum contador, responde 429 e encerra. Caso contrário, libera o
 * lock e retorna para o handler responder com a mensagem genérica de credenciais.
 */
function limiteTentativasClienteRegistrarFalha(mysqli $conexao, array &$limite): void
{
    if (($limite['ativo'] ?? false) === true && ($limite['lock'] ?? null) !== null) {
        $segundosBloqueio = 0;
        try {
            $segundosBloqueio = limiteTentativasGravarFalha(
                $conexao,
                (string)$limite['conta'],
                (array)$limite['estado_conta'],
                LIMITE_CLIENTE_CONTA_MAX_FALHAS,
                LIMITE_CLIENTE_CONTA_BLOQUEIO_SEGUNDOS
            );
            if ($limite['conta_ip'] !== null) {
                $segundosBloqueio = max($segundosBloqueio, limiteTentativasGravarFalha(
                    $conexao,
                    (string)$limite['conta_ip'],
                    (array)$limite['estado_conta_ip'],
                    LIMITE_CLIENTE_CONTA_IP_MAX_FALHAS,
                    LIMITE_CLIENTE_CONTA_IP_BLOQUEIO_SEGUNDOS
                ));
            }

            // Limpeza ocasional de registros antigos e sem bloqueio vigente.
            if (random_int(1, 100) === 1) {
                try {
                    limiteTentativasConsultar(
                        $conexao,
                        'DELETE FROM login_limite_conta_ip
                          WHERE ultima_falha_em < DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 DAY)
                            AND (bloqueado_ate IS NULL OR bloqueado_ate < CURRENT_TIMESTAMP(6))
                          LIMIT 500',
                        ''
                    );
                } catch (Throwable) {
                    error_log('[cliente_login_limite] Limpeza de registros antigos não concluída.');
                }
            }
        } catch (Throwable $e) {
            error_log('[cliente_login_limite] ' . get_class($e) . ': falha não contabilizada; login segue sem limite nesta requisição.');
            $segundosBloqueio = 0;
        }

        if ($segundosBloqueio > 0) {
            limiteTentativasClienteResponderBloqueio($conexao, $limite, $segundosBloqueio);
        }
    }

    limiteTentativasLiberarLock($conexao, $limite);
}

/** Senha correta: apaga os contadores da conta e libera o lock. */
function limiteTentativasClienteRegistrarSucesso(mysqli $conexao, array &$limite): void
{
    if (($limite['ativo'] ?? false) === true && ($limite['lock'] ?? null) !== null) {
        try {
            limiteTentativasConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', (string)$limite['conta']);
            if ($limite['conta_ip'] !== null) {
                limiteTentativasConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', (string)$limite['conta_ip']);
            }
        } catch (Throwable $e) {
            error_log('[cliente_login_limite] ' . get_class($e) . ': contagem não zerada após login válido.');
        }
    }
    limiteTentativasLiberarLock($conexao, $limite);
}

/**
 * Recuperação por código concluída (identidade comprovada e nova senha gravada):
 * libera o contador da conta e o contador conta + IP da origem atual, para que o
 * bloqueio anterior não impeça o próximo login por senha.
 * - Com o lock da conta: apaga os contadores (e marcadores pendentes) na hora.
 * - Sem o lock (contenção): não apaga nada; grava marcadores de liberação, que a
 *   próxima tentativa de login aplica sob o lock antes de ler os contadores.
 * Nunca lança exceção nem interrompe a recuperação.
 */
function limiteTentativasClienteLimparAposRecuperacao(mysqli $conexao, int $idEmpresa, string $telefoneE164): void
{
    $telefoneE164 = trim($telefoneE164);
    if ($idEmpresa <= 0 || $telefoneE164 === '') {
        return;
    }

    $limite = ['lock' => null];
    try {
        $chaveConta = limiteTentativasClienteChaveConta($idEmpresa, $telefoneE164);
        $chaveContaIp = limiteTentativasClienteChaveContaIp($idEmpresa, $telefoneE164);
        $marcadorConta = limiteTentativasClienteMarcadorConta($idEmpresa, $telefoneE164);
        $marcadorContaIp = limiteTentativasClienteMarcadorContaIp($idEmpresa, $telefoneE164);
        $nomeLock = limiteTentativasClienteNomeLock($chaveConta);

        // Espera curta pelo lock da conta, o mesmo usado pelo login por senha.
        $lock = limiteTentativasConsultar(
            $conexao,
            'SELECT GET_LOCK(?, ' . LIMITE_CLIENTE_ESPERA_LOCK_LIMPEZA_SEGUNDOS . ') AS adquirido',
            's',
            $nomeLock
        );

        if ((int)($lock[0]['adquirido'] ?? 0) === 1) {
            // Com o lock: nenhum login da conta está entre a leitura e a gravação
            // dos contadores; a exclusão é segura.
            $limite['lock'] = $nomeLock;
            $chaves = [$chaveConta, $marcadorConta];
            if ($chaveContaIp !== null) {
                $chaves[] = $chaveContaIp;
            }
            if ($marcadorContaIp !== null) {
                $chaves[] = $marcadorContaIp;
            }
            foreach ($chaves as $chave) {
                limiteTentativasConsultar($conexao, 'DELETE FROM login_limite_conta_ip WHERE chave = ?', 's', $chave);
            }
        } else {
            // Sem o lock: não apaga contadores. Registra a liberação para ser
            // aplicada, sob o lock, pela próxima tentativa de login da conta.
            limiteTentativasGravarMarcador($conexao, $marcadorConta);
            if ($chaveContaIp !== null && $marcadorContaIp !== null) {
                limiteTentativasGravarMarcador($conexao, $marcadorContaIp);
            }
        }
    } catch (Throwable $e) {
        error_log('[cliente_login_limite] ' . get_class($e) . ': contagem não zerada após recuperação de senha.');
    }
    limiteTentativasLiberarLock($conexao, $limite);
}
