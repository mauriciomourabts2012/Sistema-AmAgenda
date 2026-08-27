<?php
declare(strict_types=1);

require_once __DIR__ . '/permissoes_usuario.php';

/** Resolve exclusivamente o ator autenticado e a empresa ativa no backend. */
function auditoriaResolverAtorSessao(mysqli $conexao): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $modoSuporte = (bool)($auth['modo_suporte'] ?? false);
    $ctx = permissoesContexto($conexao);

    if ($idUsuario <= 0 || !($ctx['valido'] ?? false)) {
        throw new RuntimeException('Contexto autenticado inválido para auditoria.');
    }

    $idEmpresa = (int)($ctx['id_empresa'] ?? 0);
    if ($idEmpresa <= 0) {
        throw new RuntimeException('Empresa da sessão ausente para auditoria.');
    }

    if ($tipoUsuario === 'super_admin') {
        if (!$modoSuporte || !($ctx['super_admin_suporte'] ?? false)) {
            throw new RuntimeException('Super Admin fora de um contexto válido de suporte.');
        }

        $stmt = $conexao->prepare("SELECT u.nome FROM usuario u INNER JOIN empresa e ON e.id_empresa=? AND e.status='ativo' WHERE u.id_usuario=? AND u.tipo_usuario='super_admin' AND u.status='ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao preparar validação do contexto de suporte.');
        $stmt->bind_param('ii', $idEmpresa, $idUsuario);
        $stmt->execute();
        $stmt->bind_result($atorNome);
        $valido = $stmt->fetch();
        $stmt->close();

        if (!$valido) throw new RuntimeException('Contexto de suporte não está mais ativo.');

        return [
            'ator_tipo' => 'super_admin',
            'id_ator' => $idUsuario,
            'ator_nome' => auditoriaLimitarTexto((string)$atorNome, 150),
            'ator_perfil' => 'super_admin',
            'id_empresa' => $idEmpresa,
            'modo_suporte' => true,
        ];
    }

    $stmt = $conexao->prepare("SELECT u.nome FROM usuario u WHERE u.id_usuario=? AND u.status='ativo' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao preparar validação do ator.');
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $stmt->bind_result($atorNome);
    $valido = $stmt->fetch();
    $stmt->close();

    if (!$valido) throw new RuntimeException('Ator da auditoria não está ativo.');

    return [
        'ator_tipo' => 'usuario',
        'id_ator' => $idUsuario,
        'ator_nome' => auditoriaLimitarTexto((string)$atorNome, 150),
        'ator_perfil' => (string)$ctx['perfil'],
        'id_empresa' => $idEmpresa,
        'modo_suporte' => false,
    ];
}

/** Contexto reservado a processos internos; nunca deve receber dados do navegador. */
function auditoriaResolverAtorSistema(mysqli $conexao, int $idEmpresa, string $nome = 'Sistema'): array
{
    if ($idEmpresa <= 0) throw new InvalidArgumentException('Empresa inválida para o ator de sistema.');

    $stmt = $conexao->prepare("SELECT 1 FROM empresa WHERE id_empresa=? AND status='ativo' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao preparar validação da empresa.');
    $stmt->bind_param('i', $idEmpresa);
    $stmt->execute();
    $stmt->store_result();
    $valido = $stmt->num_rows === 1;
    $stmt->close();

    if (!$valido) throw new RuntimeException('Empresa inválida ou inativa para auditoria.');

    return [
        'ator_tipo' => 'sistema',
        'id_ator' => null,
        'ator_nome' => auditoriaLimitarTexto($nome, 150) ?: 'Sistema',
        'ator_perfil' => 'sistema',
        'id_empresa' => $idEmpresa,
        'modo_suporte' => false,
    ];
}

if (!function_exists('auditoriaLimitarTexto')) {
    function auditoriaLimitarTexto(string $texto, int $limite): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        return mb_strlen($texto, 'UTF-8') > $limite ? mb_substr($texto, 0, $limite, 'UTF-8') : $texto;
    }
}

