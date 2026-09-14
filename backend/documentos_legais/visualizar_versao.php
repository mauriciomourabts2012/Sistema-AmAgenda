<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');
$contexto = documentosLegaisExigirSuperAdmin($conexao);

$idVersao = documentosLegaisValidarIdPositivo($_GET['id_versao'] ?? null);
$documento = documentosLegaisLocalizarVersaoPorId($conexao, $idVersao);
if ($documento === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_VERSION_NOT_FOUND', 'user_msg' => 'Versão não encontrada.'], 404);
}
if (!documentosLegaisStatusPermitido((string)$documento['status'])) {
    out(['ok' => false, 'code' => 'DOCUMENT_VERSION_STATUS_INVALID', 'user_msg' => 'Versão indisponível.'], 409);
}
if (!documentosLegaisHashValido($documento)) {
    documentosLegaisAuditarIntegridade($conexao, $contexto, $documento);
    out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar o documento legal.'], 503);
}

auditoriaRegistrar($conexao, 'documentos_legais.preview_visualizado', [
    'ator' => $contexto['ator_auditoria'],
    'entidade_id' => (int)$documento['id_documento_legal_versao'],
    'entidade_rotulo' => (string)$documento['codigo'],
    'contexto' => [
        'documento_codigo' => (string)$documento['codigo'],
        'documento_versao' => (string)$documento['versao'],
        'documento_hash' => (string)$documento['hash_sha256'],
    ],
]);

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENT_VERSION_VIEWED',
    'data' => ['documento' => [
        'titulo' => (string)$documento['titulo'],
        'codigo' => (string)$documento['codigo'],
        'tipo' => documentosLegaisTipoPorCodigo((string)$documento['codigo']),
        'escopo' => (string)$documento['escopo'],
        'id_documento_legal' => (int)$documento['id_documento_legal'],
        'id_documento_legal_versao' => (int)$documento['id_documento_legal_versao'],
        'versao' => (string)$documento['versao'],
        'status' => (string)$documento['status'],
        'resumo_alteracoes' => (string)($documento['resumo_alteracoes'] ?? ''),
        'exige_nova_manifestacao' => (int)$documento['exige_nova_manifestacao'] === 1,
        'publicado_em' => $documento['publicado_em'],
        'vigencia_inicio' => $documento['vigencia_inicio'],
        'vigencia_fim' => $documento['vigencia_fim'],
        'hash_sha256' => (string)$documento['hash_sha256'],
        'conteudo_html' => (string)$documento['conteudo_html'],
    ]],
]);

