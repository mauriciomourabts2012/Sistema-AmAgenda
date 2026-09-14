<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');
documentosLegaisExigirSuperAdmin($conexao);

$stmtDocumentos = $conexao->prepare(
    "SELECT id_documento_legal,codigo,titulo,escopo,status
       FROM documento_legal
      WHERE status='ativo'
      ORDER BY titulo ASC,id_documento_legal ASC"
);
if (!$stmtDocumentos) throw new RuntimeException('Falha ao preparar a listagem dos documentos.');
if (!$stmtDocumentos->execute()) {
    $stmtDocumentos->close();
    throw new RuntimeException('Falha ao listar os documentos.');
}
$resultadoDocumentos = $stmtDocumentos->get_result();

$stmtTotais = $conexao->prepare(
    "SELECT COUNT(*) AS quantidade_versoes,
            COALESCE(SUM(status='publicado'),0) AS quantidade_publicadas,
            COALESCE(SUM(status='rascunho'),0) AS quantidade_rascunhos,
            COALESCE(SUM(status='arquivado'),0) AS quantidade_arquivadas
       FROM documento_legal_versao
      WHERE id_documento_legal=?"
);
if (!$stmtTotais) {
    $stmtDocumentos->close();
    throw new RuntimeException('Falha ao preparar o resumo dos documentos.');
}

$documentos = [];
$resumo = [
    'documentos_ativos' => 0,
    'versoes_publicadas' => 0,
    'rascunhos' => 0,
    'versoes_arquivadas' => 0,
];

while ($documento = $resultadoDocumentos?->fetch_assoc()) {
    $idDocumento = (int)$documento['id_documento_legal'];
    $stmtTotais->bind_param('i', $idDocumento);
    if (!$stmtTotais->execute()) {
        $stmtTotais->close();
        $stmtDocumentos->close();
        throw new RuntimeException('Falha ao resumir as versões do documento.');
    }
    $totais = $stmtTotais->get_result()?->fetch_assoc() ?: [];
    $publicado = documentosLegaisBuscarPublicado($conexao, (string)$documento['codigo']);

    $quantidadeVersoes = (int)($totais['quantidade_versoes'] ?? 0);
    $quantidadePublicadas = (int)($totais['quantidade_publicadas'] ?? 0);
    $quantidadeRascunhos = (int)($totais['quantidade_rascunhos'] ?? 0);
    $quantidadeArquivadas = (int)($totais['quantidade_arquivadas'] ?? 0);

    $documentos[] = [
        'id_documento_legal' => $idDocumento,
        'codigo' => (string)$documento['codigo'],
        'titulo' => (string)$documento['titulo'],
        'tipo' => documentosLegaisTipoPorCodigo((string)$documento['codigo']),
        'escopo' => (string)$documento['escopo'],
        'status' => (string)$documento['status'],
        'versao_publicada' => $publicado === null ? null : (string)$publicado['versao'],
        'id_versao_publicada' => $publicado === null ? null : (int)$publicado['id_documento_legal_versao'],
        'publicado_em' => $publicado['publicado_em'] ?? null,
        'vigencia_inicio' => $publicado['vigencia_inicio'] ?? null,
        'vigencia_fim' => $publicado['vigencia_fim'] ?? null,
        'exige_nova_manifestacao' => $publicado === null
            ? null
            : (int)$publicado['exige_nova_manifestacao'] === 1,
        'possui_rascunho' => $quantidadeRascunhos > 0,
        'quantidade_versoes' => $quantidadeVersoes,
    ];

    $resumo['documentos_ativos']++;
    $resumo['versoes_publicadas'] += $quantidadePublicadas;
    $resumo['rascunhos'] += $quantidadeRascunhos;
    $resumo['versoes_arquivadas'] += $quantidadeArquivadas;
}

$stmtTotais->close();
$stmtDocumentos->close();

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENTS_ADMIN_LISTED',
    'data' => [
        'resumo' => $resumo,
        'documentos' => $documentos,
    ],
]);

