<?php
declare(strict_types=1);

require_once __DIR__ . '/../_config/conexao.php';
require_once __DIR__ . '/../_auth/csrf.php';
require_once __DIR__ . '/../_servicos/auditoria.php';

const DOCUMENTOS_LEGAIS_PUBLICOS = [
    'termos_empresa',
    'termos_usuario',
    'termos_cliente',
    'politica_privacidade',
];

function documentosLegaisMetodo(string $esperado): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $esperado) {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }
}

function documentosLegaisPerfil(string $perfil): string
{
    $perfil = mb_strtolower(trim($perfil), 'UTF-8');
    return match ($perfil) {
        'proprietário', 'proprietario' => 'proprietario',
        'profissionais', 'profissional' => 'profissional',
        'recepção', 'recepcao', 'recepcionista' => 'recepcionista',
        default => $perfil,
    };
}

/**
 * Reconstrói identidade, empresa e papel exclusivamente da sessão e do banco.
 * Quando $exigir for falso, uma sessão ausente ou inválida apenas retorna null.
 */
function documentosLegaisContextoAutenticado(mysqli $conexao, bool $exigir = true): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idUsuario = (int)($auth['id_usuario'] ?? 0);

    if ($idUsuario > 0) {
        $stmt = $conexao->prepare("SELECT id_usuario,nome,email,tipo_usuario,status FROM usuario WHERE id_usuario=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao preparar a validação do usuário.');
        $stmt->bind_param('i', $idUsuario);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Falha ao validar o usuário.');
        }
        $usuario = $stmt->get_result()?->fetch_assoc() ?: null;
        $stmt->close();

        if (!$usuario || (string)$usuario['status'] !== 'ativo') {
            if (!$exigir) return null;
            out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
        }

        if ((string)$usuario['tipo_usuario'] === 'super_admin') {
            $modoSuporte = (bool)($auth['modo_suporte'] ?? false);
            $idEmpresaSuporte = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);
            if ($modoSuporte) {
                $stmt = $conexao->prepare("SELECT nome FROM empresa WHERE id_empresa=? AND status='ativo' LIMIT 1");
                if (!$stmt) throw new RuntimeException('Falha ao preparar o contexto de suporte.');
                $stmt->bind_param('i', $idEmpresaSuporte);
                $stmt->execute();
                $stmt->bind_result($empresaSuporteNome);
                $empresaSuporteValida = $stmt->fetch();
                $stmt->close();
                if (!$empresaSuporteValida) {
                    if (!$exigir) return null;
                    out(['ok' => false, 'code' => 'SESSION_COMPANY_LINK_INVALID', 'user_msg' => 'O contexto de suporte não está ativo.'], 403);
                }
                $atorAuditoria = auditoriaResolverAtorSessao($conexao);
            } else {
                $idEmpresaSuporte = 0;
                $empresaSuporteNome = null;
                $atorAuditoria = auditoriaResolverAtorSuperAdmin($conexao);
            }

            return [
                'tipo_manifestante' => 'super_admin',
                'id_usuario' => $idUsuario,
                'id_cliente' => null,
                'id_empresa' => null,
                'papel' => 'super_admin',
                'nome' => (string)$usuario['nome'],
                'identificador' => (string)$usuario['email'],
                'empresa_nome' => null,
                'origem_manifestacao' => 'painel_super_admin',
                'ator_auditoria' => $atorAuditoria,
                'modo_suporte' => $modoSuporte,
                'id_empresa_suporte' => $idEmpresaSuporte > 0 ? $idEmpresaSuporte : null,
            ];
        }

        $idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);
        $stmt = $conexao->prepare(
            "SELECT p.nome,e.nome
               FROM empresa_usuario eu
               INNER JOIN perfil p ON p.id_perfil=eu.id_perfil AND p.status='ativo'
               INNER JOIN empresa e ON e.id_empresa=eu.id_empresa AND e.status='ativo'
              WHERE eu.id_usuario=? AND eu.id_empresa=? AND eu.status='ativo'
              LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('Falha ao preparar o vínculo empresarial.');
        $stmt->bind_param('ii', $idUsuario, $idEmpresa);
        $stmt->execute();
        $stmt->bind_result($perfilDb, $empresaNome);
        $vinculoValido = $stmt->fetch();
        $stmt->close();
        if (!$vinculoValido) {
            if (!$exigir) return null;
            out(['ok' => false, 'code' => 'SESSION_COMPANY_LINK_INVALID', 'user_msg' => 'Seu vínculo com a empresa não está ativo.'], 403);
        }

        $perfil = documentosLegaisPerfil((string)$perfilDb);
        $tipoManifestante = $perfil === 'proprietario' ? 'representante_empresa' : 'usuario_empresa';

        return [
            'tipo_manifestante' => $tipoManifestante,
            'id_usuario' => $idUsuario,
            'id_cliente' => null,
            'id_empresa' => $idEmpresa,
            'papel' => $perfil,
            'nome' => (string)$usuario['nome'],
            'identificador' => (string)$usuario['email'],
            'empresa_nome' => (string)$empresaNome,
            'origem_manifestacao' => 'painel_empresa',
            'ator_auditoria' => [
                'ator_tipo' => 'usuario',
                'id_ator' => $idUsuario,
                'ator_nome' => (string)$usuario['nome'],
                'ator_perfil' => $perfil,
                'id_empresa' => $idEmpresa,
                'modo_suporte' => false,
                'origem' => 'empresa',
            ],
            'modo_suporte' => false,
            'id_empresa_suporte' => null,
        ];
    }

    $clienteAuth = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];
    $idCliente = (int)($clienteAuth['id_cliente'] ?? 0);
    $idEmpresa = (int)($clienteAuth['id_empresa'] ?? 0);
    $empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);
    if ($idCliente > 0 && $idEmpresa > 0 && $idEmpresa === $empresaSessao
        && (string)($clienteAuth['tipo_usuario'] ?? $clienteAuth['tipo'] ?? '') === 'cliente'
        && ($clienteAuth['telefone_verificado'] ?? false) === true) {
        $stmt = $conexao->prepare(
            "SELECT c.nome_completo,c.whatsapp_celular,e.nome
               FROM cliente c
               INNER JOIN empresa e ON e.id_empresa=c.id_empresa AND e.status='ativo'
              WHERE c.id_cliente=? AND c.id_empresa=? AND c.status='ativo'
              LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('Falha ao preparar a validação do cliente.');
        $stmt->bind_param('ii', $idCliente, $idEmpresa);
        $stmt->execute();
        $stmt->bind_result($clienteNome, $clienteTelefone, $empresaNome);
        $clienteValido = $stmt->fetch();
        $stmt->close();
        if ($clienteValido && trim((string)$clienteNome) !== '') {
            $atorAuditoria = [
                'ator_tipo' => 'cliente',
                'id_ator' => $idCliente,
                'ator_nome' => (string)$clienteNome,
                'ator_perfil' => 'cliente',
                'id_empresa' => $idEmpresa,
                'modo_suporte' => false,
                'origem' => 'empresa',
            ];

            return [
                'tipo_manifestante' => 'cliente',
                'id_usuario' => null,
                'id_cliente' => $idCliente,
                'id_empresa' => $idEmpresa,
                'papel' => 'cliente',
                'nome' => (string)$clienteNome,
                'identificador' => (string)$clienteTelefone,
                'empresa_nome' => (string)$empresaNome,
                'origem_manifestacao' => 'area_cliente',
                'ator_auditoria' => $atorAuditoria,
                'modo_suporte' => false,
                'id_empresa_suporte' => null,
            ];
        }
    }

    if (!$exigir) return null;
    if ($clienteAuth !== []) {
        out(['ok' => false, 'code' => 'CLIENT_PROFILE_REQUIRED', 'user_msg' => 'Complete seu cadastro para continuar.'], 422);
    }
    out(['ok' => false, 'code' => 'NOT_AUTHENTICATED', 'user_msg' => 'Sessão expirada. Faça login novamente.'], 401);
}

function documentosLegaisObrigatorios(string $tipoManifestante): array
{
    return match ($tipoManifestante) {
        'representante_empresa' => [
            ['codigo' => 'termos_empresa', 'tipo_manifestacao' => 'aceite'],
            ['codigo' => 'politica_privacidade', 'tipo_manifestacao' => 'ciencia'],
        ],
        'usuario_empresa' => [
            ['codigo' => 'termos_usuario', 'tipo_manifestacao' => 'aceite'],
            ['codigo' => 'politica_privacidade', 'tipo_manifestacao' => 'ciencia'],
        ],
        'cliente' => [
            ['codigo' => 'termos_cliente', 'tipo_manifestacao' => 'aceite'],
            ['codigo' => 'politica_privacidade', 'tipo_manifestacao' => 'ciencia'],
        ],
        'super_admin' => [
            ['codigo' => 'termo_super_admin', 'tipo_manifestacao' => 'aceite'],
            ['codigo' => 'politica_privacidade', 'tipo_manifestacao' => 'ciencia'],
        ],
        default => [],
    };
}

function documentosLegaisEscopoAplicavel(string $tipoManifestante, string $escopo): bool
{
    $escopoEsperado = match ($tipoManifestante) {
        'representante_empresa' => 'empresa',
        'usuario_empresa' => 'usuario_empresa',
        'cliente' => 'cliente',
        'super_admin' => 'super_admin',
        default => null,
    };

    return $escopo === 'todos' || ($escopoEsperado !== null && $escopo === $escopoEsperado);
}

function documentosLegaisBuscarPublicado(mysqli $conexao, string $codigo, bool $bloquear = false): ?array
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare(
        "SELECT d.id_documento_legal,d.codigo,d.titulo,d.escopo,
                v.id_documento_legal_versao,v.versao,v.conteudo_html,v.hash_sha256,
                v.exige_nova_manifestacao,v.vigencia_inicio,v.vigencia_fim
           FROM documento_legal d
           INNER JOIN documento_legal_versao v ON v.id_documento_legal=d.id_documento_legal
          WHERE d.codigo=? AND d.status='ativo' AND v.status='publicado'
            AND v.vigencia_inicio<=CURRENT_TIMESTAMP(6)
            AND (v.vigencia_fim IS NULL OR v.vigencia_fim>CURRENT_TIMESTAMP(6))
          LIMIT 1" . $sufixo
    );
    if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta do documento.');
    $stmt->bind_param('s', $codigo);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar o documento.');
    }
    $documento = $stmt->get_result()?->fetch_assoc() ?: null;
    $stmt->close();
    return $documento;
}

function documentosLegaisHashValido(array $documento): bool
{
    $armazenado = mb_strtolower(trim((string)($documento['hash_sha256'] ?? '')), 'UTF-8');
    $calculado = hash('sha256', (string)($documento['conteudo_html'] ?? ''));
    return preg_match('/^[0-9a-f]{64}$/', $armazenado) === 1 && hash_equals($armazenado, $calculado);
}

function documentosLegaisAuditarIntegridade(mysqli $conexao, array $contexto, array $documento): void
{
    try {
        auditoriaRegistrar($conexao, 'documentos_legais.falha_integridade', [
            'ator' => $contexto['ator_auditoria'],
            'entidade_id' => (int)($documento['id_documento_legal_versao'] ?? 0),
            'entidade_rotulo' => (string)($documento['codigo'] ?? 'documento_legal'),
            'contexto' => [
                'documento_codigo' => (string)($documento['codigo'] ?? ''),
                'documento_versao' => (string)($documento['versao'] ?? ''),
                'documento_hash' => (string)($documento['hash_sha256'] ?? ''),
                'motivo' => 'hash_sha256_invalido',
            ],
        ]);
    } catch (Throwable) {
        error_log('[documentos_legais] Não foi possível auditar uma falha de integridade.');
    }
}

function documentosLegaisManifestacaoExiste(mysqli $conexao, array $contexto, array $documento, string $tipoManifestacao, bool $bloquear = false): ?int
{
    $sufixo = $bloquear ? ' FOR UPDATE' : '';
    $tipoManifestante = (string)$contexto['tipo_manifestante'];
    $idVersao = (int)$documento['id_documento_legal_versao'];

    if ($tipoManifestante === 'cliente') {
        $sql = "SELECT m.id_documento_legal_manifestacao
                  FROM documento_legal_manifestacao m
                 WHERE m.id_documento_legal_versao=? AND m.tipo_manifestacao=? AND m.tipo_manifestante='cliente'
                   AND m.id_cliente=? AND m.id_empresa=? AND m.revogado_em IS NULL
                 ORDER BY m.id_documento_legal_manifestacao DESC LIMIT 1" . $sufixo;
        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta da manifestação.');
        $idCliente = (int)$contexto['id_cliente'];
        $idEmpresa = (int)$contexto['id_empresa'];
        $stmt->bind_param('isii', $idVersao, $tipoManifestacao, $idCliente, $idEmpresa);
    } elseif ($tipoManifestante === 'super_admin') {
        $sql = "SELECT m.id_documento_legal_manifestacao
                  FROM documento_legal_manifestacao m
                 WHERE m.id_documento_legal_versao=? AND m.tipo_manifestacao=? AND m.tipo_manifestante='super_admin'
                   AND m.id_usuario=? AND m.id_empresa IS NULL AND m.revogado_em IS NULL
                 ORDER BY m.id_documento_legal_manifestacao DESC LIMIT 1" . $sufixo;
        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta da manifestação.');
        $idUsuario = (int)$contexto['id_usuario'];
        $stmt->bind_param('isi', $idVersao, $tipoManifestacao, $idUsuario);
    } else {
        $sql = "SELECT m.id_documento_legal_manifestacao
                  FROM documento_legal_manifestacao m
                 WHERE m.id_documento_legal_versao=? AND m.tipo_manifestacao=? AND m.tipo_manifestante=?
                   AND m.id_usuario=? AND m.id_empresa=? AND m.revogado_em IS NULL
                 ORDER BY m.id_documento_legal_manifestacao DESC LIMIT 1" . $sufixo;
        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar a consulta da manifestação.');
        $idUsuario = (int)$contexto['id_usuario'];
        $idEmpresa = (int)$contexto['id_empresa'];
        $stmt->bind_param('issii', $idVersao, $tipoManifestacao, $tipoManifestante, $idUsuario, $idEmpresa);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar a manifestação.');
    }
    $stmt->bind_result($idManifestacao);
    $existe = $stmt->fetch();
    $stmt->close();
    return $existe ? (int)$idManifestacao : null;
}

function documentosLegaisPendencias(mysqli $conexao, array $contexto, bool $incluirConteudo = false): array
{
    $documentos = [];
    foreach (documentosLegaisObrigatorios((string)$contexto['tipo_manifestante']) as $regra) {
        $documento = documentosLegaisBuscarPublicado($conexao, $regra['codigo']);
        if ($documento === null) continue;
        if (!documentosLegaisEscopoAplicavel((string)$contexto['tipo_manifestante'], (string)$documento['escopo'])) continue;
        if (!documentosLegaisHashValido($documento)) {
            documentosLegaisAuditarIntegridade($conexao, $contexto, $documento);
            throw new UnexpectedValueException('DOCUMENT_INTEGRITY_ERROR');
        }

        $manifestacaoId = documentosLegaisManifestacaoExiste($conexao, $contexto, $documento, $regra['tipo_manifestacao']);
        $item = [
            'codigo' => (string)$documento['codigo'],
            'titulo' => (string)$documento['titulo'],
            'versao' => (string)$documento['versao'],
            'hash_sha256' => (string)$documento['hash_sha256'],
            'tipo_manifestacao' => $regra['tipo_manifestacao'],
            'exige_nova_manifestacao' => (int)$documento['exige_nova_manifestacao'] === 1,
            'pendente' => $manifestacaoId === null,
        ];
        if ($incluirConteudo) $item['conteudo_html'] = (string)$documento['conteudo_html'];
        $documentos[] = $item;
    }
    return $documentos;
}

/** Função reutilizável chamada pela fronteira central da API. */
function documentosLegaisTemPendencias(mysqli $conexao): bool
{
    $contexto = documentosLegaisContextoAutenticado($conexao, false);
    if ($contexto === null) return false;
    foreach (documentosLegaisPendencias($conexao, $contexto) as $documento) {
        if ($documento['pendente']) return true;
    }
    return false;
}

function documentosLegaisEntradaPost(): array
{
    $contentType = mb_strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]), 'UTF-8');
    if (!in_array($contentType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
        out(['ok' => false, 'code' => 'CONTENT_TYPE_UNSUPPORTED', 'user_msg' => 'Formato de conteúdo não suportado.'], 415);
    }

    if ($contentType === 'application/json') {
        try {
            $entrada = json_decode((string)file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            out(['ok' => false, 'code' => 'JSON_INVALID', 'user_msg' => 'Os dados enviados são inválidos.'], 422);
        }
    } else {
        $entrada = $_POST;
    }

    if (!is_array($entrada)) {
        out(['ok' => false, 'code' => 'INPUT_INVALID', 'user_msg' => 'Os dados enviados são inválidos.'], 422);
    }
    $permitidos = ['documentos' => true, 'declaracao_maioridade' => true];
    foreach (array_keys($entrada) as $campo) {
        if (!isset($permitidos[(string)$campo])) {
            out(['ok' => false, 'code' => 'INPUT_FIELD_NOT_ALLOWED', 'user_msg' => 'Os dados enviados contêm campos não permitidos.'], 422);
        }
    }
    return $entrada;
}
