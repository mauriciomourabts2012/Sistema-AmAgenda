<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');
documentosLegaisExigirSuperAdmin($conexao);

$codigo = documentosLegaisValidarCodigo($_GET['codigo'] ?? null);
$documento = documentosLegaisLocalizarDocumentoPorCodigo($conexao, $codigo);
if ($documento === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado.'], 404);
}

$idDocumento = (int)$documento['id_documento_legal'];
$stmt = $conexao->prepare(
    "SELECT id_documento_legal_versao,versao,status,resumo_alteracoes,
            exige_nova_manifestacao,publicado_em,vigencia_inicio,vigencia_fim,
            criado_em,atualizado_em,hash_sha256
       FROM documento_legal_versao
      WHERE id_documento_legal=?
      ORDER BY id_documento_legal_versao DESC"
);
if (!$stmt) throw new RuntimeException('Falha ao preparar a listagem de versões.');
$stmt->bind_param('i', $idDocumento);
if (!$stmt->execute()) {
    $stmt->close();
    throw new RuntimeException('Falha ao listar as versões.');
}
$resultado = $stmt->get_result();
$versoes = [];
while ($versao = $resultado?->fetch_assoc()) {
    $status = (string)$versao['status'];
    if (!documentosLegaisStatusPermitido($status)) {
        $stmt->close();
        throw new RuntimeException('Status de versão inválido.');
    }
    $versoes[] = [
        'id_documento_legal_versao' => (int)$versao['id_documento_legal_versao'],
        'versao' => (string)$versao['versao'],
        'status' => $status,
        'resumo_alteracoes' => (string)($versao['resumo_alteracoes'] ?? ''),
        'exige_nova_manifestacao' => (int)$versao['exige_nova_manifestacao'] === 1,
        'publicado_em' => $versao['publicado_em'],
        'vigencia_inicio' => $versao['vigencia_inicio'],
        'vigencia_fim' => $versao['vigencia_fim'],
        'criado_em' => $versao['criado_em'],
        'atualizado_em' => $versao['atualizado_em'],
        'hash_sha256' => (string)$versao['hash_sha256'],
    ];
}
$stmt->close();

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENT_VERSIONS_LISTED',
    'data' => [
        'documento' => [
            'id_documento_legal' => $idDocumento,
            'codigo' => (string)$documento['codigo'],
            'titulo' => (string)$documento['titulo'],
            'tipo' => documentosLegaisTipoPorCodigo((string)$documento['codigo']),
            'escopo' => (string)$documento['escopo'],
            'status' => (string)$documento['status'],
        ],
        'versoes' => $versoes,
    ],
]);

