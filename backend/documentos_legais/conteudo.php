<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');

$contexto = documentosLegaisContextoAutenticado($conexao);
$codigoRaw = $_GET['codigo'] ?? null;
$codigo = is_string($codigoRaw) ? trim($codigoRaw) : '';
$regra = null;
foreach (documentosLegaisObrigatorios((string)$contexto['tipo_manifestante']) as $candidata) {
    if ($candidata['codigo'] === $codigo) $regra = $candidata;
}
if ($regra === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_APPLICABLE', 'user_msg' => 'Documento não disponível para este acesso.'], 403);
}

$documento = documentosLegaisBuscarPublicado($conexao, $codigo);
if ($documento === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado.'], 404);
}
if (!documentosLegaisEscopoAplicavel((string)$contexto['tipo_manifestante'], (string)$documento['escopo'])) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_APPLICABLE', 'user_msg' => 'Documento não disponível para este acesso.'], 403);
}
if (!documentosLegaisHashValido($documento)) {
    documentosLegaisAuditarIntegridade($conexao, $contexto, $documento);
    out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar o documento legal.'], 503);
}

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENT_CONTENT',
    'data' => ['documento' => [
        'titulo' => (string)$documento['titulo'],
        'codigo' => (string)$documento['codigo'],
        'versao' => (string)$documento['versao'],
        'conteudo_html' => (string)$documento['conteudo_html'],
        'hash_sha256' => (string)$documento['hash_sha256'],
        'tipo_manifestacao' => $regra['tipo_manifestacao'],
    ], 'csrf_token' => csrfTokenSessao()],
]);
