<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');

$codigoRaw = $_GET['codigo'] ?? null;
$codigo = is_string($codigoRaw) ? trim($codigoRaw) : '';
if (!in_array($codigo, DOCUMENTOS_LEGAIS_PUBLICOS, true)) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado.'], 404);
}

$documento = documentosLegaisBuscarPublicado($conexao, $codigo);
if ($documento === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado.'], 404);
}
if (!documentosLegaisHashValido($documento)) {
    documentosLegaisAuditarIntegridade($conexao, [
        'ator_auditoria' => auditoriaResolverAtorNaoAutenticado($conexao),
    ], $documento);
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado.'], 404);
}

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENT_PUBLIC',
    'data' => ['documento' => [
        'titulo' => (string)$documento['titulo'],
        'codigo' => (string)$documento['codigo'],
        'versao' => (string)$documento['versao'],
        'conteudo_html' => (string)$documento['conteudo_html'],
        'hash_sha256' => (string)$documento['hash_sha256'],
    ]],
]);
