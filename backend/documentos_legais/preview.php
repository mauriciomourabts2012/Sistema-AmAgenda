<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');
$contexto = documentosLegaisContextoAutenticado($conexao);
if ($contexto['tipo_manifestante'] !== 'super_admin') {
    out(['ok' => false, 'code' => 'SUPER_ADMIN_REQUIRED', 'user_msg' => 'Acesso exclusivo do Super Admin.'], 403);
}

$codigoRaw = $_GET['codigo'] ?? null;
$versaoRaw = $_GET['versao'] ?? null;
$codigo = is_string($codigoRaw) ? trim($codigoRaw) : '';
$versao = is_string($versaoRaw) ? trim($versaoRaw) : '';
if (preg_match('/^[a-z][a-z0-9_]{2,59}$/', $codigo) !== 1
    || ($versao !== '' && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $versao) !== 1)) {
    out(['ok' => false, 'code' => 'DOCUMENT_INPUT_INVALID', 'user_msg' => 'Documento inválido.'], 422);
}

$sql = "SELECT d.id_documento_legal,d.codigo,d.titulo,d.escopo,
               v.id_documento_legal_versao,v.versao,v.conteudo_html,v.hash_sha256,
               v.status,v.exige_nova_manifestacao,v.vigencia_inicio,v.vigencia_fim
          FROM documento_legal d
          INNER JOIN documento_legal_versao v ON v.id_documento_legal=d.id_documento_legal
         WHERE d.codigo=? AND d.status='ativo' AND v.status='rascunho'";
if ($versao !== '') $sql .= ' AND v.versao=?';
$sql .= ' ORDER BY v.id_documento_legal_versao DESC LIMIT 1';
$stmt = $conexao->prepare($sql);
if (!$stmt) throw new RuntimeException('Falha ao preparar o preview.');
if ($versao !== '') $stmt->bind_param('ss', $codigo, $versao);
else $stmt->bind_param('s', $codigo);
$stmt->execute();
$documento = $stmt->get_result()?->fetch_assoc() ?: null;
$stmt->close();
if ($documento === null) {
    out(['ok' => false, 'code' => 'DRAFT_NOT_FOUND', 'user_msg' => 'Rascunho não encontrado.'], 404);
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
    'code' => 'LEGAL_DOCUMENT_DRAFT_PREVIEW',
    'data' => ['documento' => [
        'titulo' => (string)$documento['titulo'],
        'codigo' => (string)$documento['codigo'],
        'versao' => (string)$documento['versao'],
        'status' => (string)$documento['status'],
        'conteudo_html' => (string)$documento['conteudo_html'],
        'hash_sha256' => (string)$documento['hash_sha256'],
    ]],
]);
