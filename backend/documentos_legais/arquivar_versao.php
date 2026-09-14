<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

documentosLegaisMetodo('POST');
$entrada = documentosLegaisEntradaPost(['id_documento_legal_versao']);
$contexto = documentosLegaisExigirSuperAdmin($conexao);
csrfValidarSessao();
$idVersao = documentosLegaisValidarIdPositivo($entrada['id_documento_legal_versao'] ?? null);

try {
    if (!$conexao->begin_transaction()) throw new RuntimeException('Falha ao iniciar a transação.');
    $versao = documentosLegaisLocalizarVersaoPorId($conexao, $idVersao, true);
    if ($versao === null) throw new DomainException('DOCUMENT_VERSION_NOT_FOUND');
    $statusAtual = (string)$versao['status'];
    if ($statusAtual === 'arquivado') {
        if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar o arquivamento.');
        out(['ok' => true, 'code' => 'DOCUMENT_VERSION_ALREADY_ARCHIVED', 'data' => ['id_documento_legal_versao' => $idVersao, 'idempotente' => true]]);
    }
    if ($statusAtual === 'publicado') throw new DomainException('DOCUMENT_VERSION_ARCHIVE_NOT_ALLOWED');
    if ($statusAtual !== 'rascunho') throw new DomainException('DOCUMENT_VERSION_STATUS_INVALID');

    $statusArquivado = 'arquivado';
    $stmt = $conexao->prepare(
        "UPDATE documento_legal_versao
            SET status=?,id_documento_publicado=NULL
          WHERE id_documento_legal_versao=? AND status='rascunho'"
    );
    if (!$stmt) throw new RuntimeException('Falha ao preparar o arquivamento.');
    $stmt->bind_param('si', $statusArquivado, $idVersao);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('Falha ao arquivar a versão.');
    }
    $stmt->close();

    auditoriaRegistrar($conexao, 'documentos_legais.versao_arquivada', [
        'ator' => $contexto['ator_auditoria'],
        'entidade_id' => $idVersao,
        'entidade_rotulo' => (string)$versao['codigo'],
        'alteracoes' => ['status' => ['antes' => 'rascunho', 'depois' => 'arquivado']],
        'contexto' => [
            'documento_codigo' => (string)$versao['codigo'],
            'documento_versao' => (string)$versao['versao'],
        ],
    ]);
    if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar o arquivamento.');
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    if ($e instanceof DomainException) {
        $respostas = [
            'DOCUMENT_VERSION_NOT_FOUND' => [404, 'DOCUMENT_VERSION_NOT_FOUND', 'Versão não encontrada.'],
            'DOCUMENT_VERSION_ARCHIVE_NOT_ALLOWED' => [409, 'DOCUMENT_VERSION_ARCHIVE_NOT_ALLOWED', 'A versão publicada vigente não pode ser arquivada.'],
            'DOCUMENT_VERSION_STATUS_INVALID' => [409, 'DOCUMENT_VERSION_STATUS_INVALID', 'A versão não pode ser arquivada.'],
        ];
        $resposta = $respostas[$e->getMessage()] ?? null;
        if ($resposta !== null) out(['ok' => false, 'code' => $resposta[1], 'user_msg' => $resposta[2]], $resposta[0]);
    }
    error_log('[documentos_legais] Falha ao arquivar versão: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'DOCUMENT_VERSION_ARCHIVE_ERROR', 'user_msg' => 'Não foi possível arquivar a versão.'], 500);
}

out([
    'ok' => true,
    'code' => 'DOCUMENT_VERSION_ARCHIVED',
    'data' => ['id_documento_legal_versao' => $idVersao, 'status' => 'arquivado', 'idempotente' => false],
]);
