<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('POST');
$entrada = documentosLegaisEntradaPost();
$contexto = documentosLegaisContextoAutenticado($conexao);
csrfValidarSessao();

$documentosEnviados = $entrada['documentos'] ?? null;
if (!is_array($documentosEnviados)) {
    out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_REQUIRED', 'user_msg' => 'Selecione todos os documentos obrigatórios.'], 422);
}
$codigosEnviados = [];
foreach ($documentosEnviados as $codigo) {
    if (!is_string($codigo) || preg_match('/^[a-z][a-z0-9_]{2,59}$/', $codigo) !== 1) {
        out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_INVALID', 'user_msg' => 'A seleção de documentos é inválida.'], 422);
    }
    $codigosEnviados[] = $codigo;
}
$codigosEnviados = array_values(array_unique($codigosEnviados));
sort($codigosEnviados);
$regras = documentosLegaisObrigatorios((string)$contexto['tipo_manifestante']);
$codigosObrigatorios = array_column($regras, 'codigo');
sort($codigosObrigatorios);
if ($codigosEnviados !== $codigosObrigatorios) {
    out(['ok' => false, 'code' => 'LEGAL_DOCUMENTS_INCOMPLETE', 'user_msg' => 'É necessário manifestar todos os documentos obrigatórios.'], 422);
}

$declaracaoMaioridade = $entrada['declaracao_maioridade'] ?? 0;
if ($contexto['tipo_manifestante'] === 'cliente'
    && !($declaracaoMaioridade === 1 || $declaracaoMaioridade === '1')) {
    out(['ok' => false, 'code' => 'ADULT_DECLARATION_REQUIRED', 'user_msg' => 'Confirme que possui 18 anos ou mais para continuar.'], 422);
}
$declaracaoMaioridade = $contexto['tipo_manifestante'] === 'cliente' ? 1 : 0;
$requestId = auditoriaRequestId();
$ip = auditoriaNormalizarIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$userAgent = auditoriaLimitarTexto((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
$userAgent = $userAgent === '' ? null : $userAgent;
$novas = [];
$todas = [];
$documentoComFalha = null;

try {
    if (!$conexao->begin_transaction()) throw new RuntimeException('Falha ao iniciar a transação.');

    foreach ($regras as $regra) {
        $documento = documentosLegaisBuscarPublicado($conexao, $regra['codigo'], true);
        if ($documento === null) {
            throw new DomainException('DOCUMENT_NOT_AVAILABLE');
        }
        if (!documentosLegaisHashValido($documento)) {
            $documentoComFalha = $documento;
            throw new UnexpectedValueException('DOCUMENT_INTEGRITY_ERROR');
        }

        $existente = documentosLegaisManifestacaoExiste($conexao, $contexto, $documento, $regra['tipo_manifestacao'], true);
        if ($existente !== null) {
            $todas[] = ['id_manifestacao' => $existente, 'codigo' => $regra['codigo'], 'nova' => false];
            continue;
        }

        $sql = "INSERT INTO documento_legal_manifestacao
                    (id_documento_legal_versao,tipo_manifestacao,tipo_manifestante,id_empresa,id_usuario,id_cliente,
                     papel_snapshot,nome_manifestante_snapshot,identificador_manifestante_snapshot,empresa_nome_snapshot,
                     documento_codigo_snapshot,documento_versao_snapshot,documento_hash_snapshot,declaracao_maioridade,
                     ip,user_agent,origem,request_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,INET6_ATON(?),?,?,?)
                ON DUPLICATE KEY UPDATE id_documento_legal_manifestacao=LAST_INSERT_ID(id_documento_legal_manifestacao)";
        $stmt = $conexao->prepare($sql);
        if (!$stmt) throw new RuntimeException('Falha ao preparar a manifestação.');
        $idVersao = (int)$documento['id_documento_legal_versao'];
        $tipoManifestacao = (string)$regra['tipo_manifestacao'];
        $tipoManifestante = (string)$contexto['tipo_manifestante'];
        $idEmpresa = $contexto['id_empresa'];
        $idUsuario = $contexto['id_usuario'];
        $idCliente = $contexto['id_cliente'];
        $papel = (string)$contexto['papel'];
        $nome = (string)$contexto['nome'];
        $identificador = (string)$contexto['identificador'];
        $empresaNome = $contexto['empresa_nome'];
        $codigo = (string)$documento['codigo'];
        $versao = (string)$documento['versao'];
        $hash = (string)$documento['hash_sha256'];
        $origem = (string)$contexto['origem_manifestacao'];
        $stmt->bind_param(
            'issiiisssssssissss',
            $idVersao, $tipoManifestacao, $tipoManifestante, $idEmpresa, $idUsuario, $idCliente,
            $papel, $nome, $identificador, $empresaNome, $codigo, $versao, $hash, $declaracaoMaioridade,
            $ip, $userAgent, $origem, $requestId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Falha ao registrar a manifestação.');
        }
        $nova = $stmt->affected_rows === 1;
        $idManifestacao = (int)$conexao->insert_id;
        $stmt->close();
        if ($idManifestacao <= 0) throw new RuntimeException('Manifestação sem identificador.');

        $item = ['id_manifestacao' => $idManifestacao, 'codigo' => $codigo, 'nova' => $nova];
        $todas[] = $item;
        if ($nova) {
            $novas[] = $item;
            $evento = $tipoManifestacao === 'aceite'
                ? 'documentos_legais.termos_aceitos'
                : 'documentos_legais.politica_ciencia_registrada';
            auditoriaRegistrar($conexao, $evento, [
                'ator' => $contexto['ator_auditoria'],
                'entidade_id' => $idManifestacao,
                'entidade_rotulo' => $codigo,
                'contexto' => [
                    'documento_codigo' => $codigo,
                    'documento_versao' => $versao,
                    'documento_hash' => $hash,
                    'tipo_manifestacao' => $tipoManifestacao,
                    'tipo_manifestante' => $tipoManifestante,
                ],
            ]);
        }
    }

    if ($novas !== []) {
        auditoriaRegistrar($conexao, 'documentos_legais.manifestacao_registrada', [
            'ator' => $contexto['ator_auditoria'],
            'entidade_id' => (int)$novas[0]['id_manifestacao'],
            'entidade_rotulo' => 'Termo e Política de Privacidade',
            'contexto' => [
                'quantidade_afetada' => count($novas),
                'tipo_manifestante' => (string)$contexto['tipo_manifestante'],
            ],
        ]);
    }

    if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar a transação.');
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    if ($documentoComFalha !== null) {
        documentosLegaisAuditarIntegridade($conexao, $contexto, $documentoComFalha);
        out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar os documentos legais.'], 503);
    }
    if ($e instanceof DomainException && $e->getMessage() === 'DOCUMENT_NOT_AVAILABLE') {
        out(['ok' => false, 'code' => 'DOCUMENT_NOT_AVAILABLE', 'user_msg' => 'Os documentos obrigatórios ainda não estão disponíveis.'], 409);
    }
    error_log('[documentos_legais] Falha ao registrar manifestação: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'LEGAL_MANIFESTATION_ERROR', 'user_msg' => 'Não foi possível registrar sua manifestação.'], 500);
}

out([
    'ok' => true,
    'code' => $novas === [] ? 'LEGAL_MANIFESTATION_ALREADY_REGISTERED' : 'LEGAL_MANIFESTATION_REGISTERED',
    'data' => [
        'registrada' => $novas !== [],
        'idempotente' => $novas === [],
        'manifestacoes' => $todas,
        'request_id' => $requestId,
    ],
]);
