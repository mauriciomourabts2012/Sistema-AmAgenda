<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

documentosLegaisMetodo('POST');
$entrada = documentosLegaisEntradaPost(['id_documento_legal_versao']);
$contexto = documentosLegaisExigirSuperAdmin($conexao);
csrfValidarSessao();
$idVersao = documentosLegaisValidarIdPositivo($entrada['id_documento_legal_versao'] ?? null);
$documentoComFalha = null;

try {
    if (!$conexao->begin_transaction()) throw new RuntimeException('Falha ao iniciar a transação.');
    $alvo = documentosLegaisLocalizarVersaoPorId($conexao, $idVersao, true);
    if ($alvo === null) throw new DomainException('DOCUMENT_VERSION_NOT_FOUND');
    if ((string)$alvo['status'] !== 'rascunho') throw new DomainException('DOCUMENT_VERSION_NOT_PUBLISHABLE');
    if (!documentosLegaisHashValido($alvo)) {
        $documentoComFalha = $alvo;
        throw new DomainException('DOCUMENT_INTEGRITY_ERROR');
    }

    $idDocumento = (int)$alvo['id_documento_legal'];
    $publicadas = documentosLegaisVersoesPublicadasPorDocumento($conexao, $idDocumento, true);
    if (count($publicadas) > 1) throw new DomainException('DOCUMENT_PUBLICATION_STATE_INVALID');
    $anterior = $publicadas[0] ?? null;
    $instante = documentosLegaisInstanteAtual($conexao);

    if ($anterior !== null) {
        $idAnterior = (int)$anterior['id_documento_legal_versao'];
        $statusArquivado = 'arquivado';
        $stmtAnterior = $conexao->prepare(
            "UPDATE documento_legal_versao
                SET status=?,vigencia_fim=?,id_documento_publicado=NULL
              WHERE id_documento_legal_versao=? AND id_documento_legal=? AND status='publicado'"
        );
        if (!$stmtAnterior) throw new RuntimeException('Falha ao preparar o encerramento da versão anterior.');
        $stmtAnterior->bind_param('ssii', $statusArquivado, $instante, $idAnterior, $idDocumento);
        if (!$stmtAnterior->execute() || $stmtAnterior->affected_rows !== 1) {
            $stmtAnterior->close();
            throw new RuntimeException('Falha ao encerrar a versão anterior.');
        }
        $stmtAnterior->close();
    }

    $statusPublicado = 'publicado';
    $idPublicador = (int)$contexto['id_usuario'];
    $stmtPublicar = $conexao->prepare(
        "UPDATE documento_legal_versao
            SET status=?,publicado_por=?,publicado_em=?,vigencia_inicio=?,vigencia_fim=NULL,id_documento_publicado=?
          WHERE id_documento_legal_versao=? AND id_documento_legal=? AND status='rascunho'"
    );
    if (!$stmtPublicar) throw new RuntimeException('Falha ao preparar a publicação do rascunho.');
    $stmtPublicar->bind_param('sissiii', $statusPublicado, $idPublicador, $instante, $instante, $idDocumento, $idVersao, $idDocumento);
    if (!$stmtPublicar->execute() || $stmtPublicar->affected_rows !== 1) {
        $stmtPublicar->close();
        throw new RuntimeException('Falha ao publicar o rascunho.');
    }
    $stmtPublicar->close();

    $alteracoes = [
        'status' => ['antes' => 'rascunho', 'depois' => 'publicado'],
        'vigencia_inicio' => ['antes' => null, 'depois' => $instante],
        'exige_nova_manifestacao' => ['antes' => null, 'depois' => (int)$alvo['exige_nova_manifestacao'] === 1],
    ];
    if ($anterior !== null) {
        $alteracoes['vigencia_fim'] = ['antes' => $anterior['vigencia_fim'], 'depois' => $instante];
        $alteracoes['versao_anterior_afetada'] = ['antes' => null, 'depois' => (int)$anterior['id_documento_legal_versao']];
    }
    auditoriaRegistrar($conexao, 'documentos_legais.versao_publicada', [
        'ator' => $contexto['ator_auditoria'],
        'entidade_id' => $idVersao,
        'entidade_rotulo' => (string)$alvo['codigo'],
        'alteracoes' => $alteracoes,
        'contexto' => [
            'documento_codigo' => (string)$alvo['codigo'],
            'documento_versao' => (string)$alvo['versao'],
        ],
    ]);
    if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar a publicação.');
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    if ($documentoComFalha !== null) {
        documentosLegaisAuditarIntegridade($conexao, $contexto, $documentoComFalha);
        out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar a integridade do rascunho.'], 503);
    }
    if ($e instanceof DomainException) {
        $respostas = [
            'DOCUMENT_VERSION_NOT_FOUND' => [404, 'DOCUMENT_VERSION_NOT_FOUND', 'Versão não encontrada.'],
            'DOCUMENT_VERSION_NOT_PUBLISHABLE' => [409, 'DOCUMENT_VERSION_NOT_PUBLISHABLE', 'Somente rascunhos podem ser publicados.'],
            'DOCUMENT_PUBLICATION_STATE_INVALID' => [409, 'DOCUMENT_PUBLICATION_STATE_INVALID', 'O documento possui um estado de publicação inconsistente.'],
        ];
        $resposta = $respostas[$e->getMessage()] ?? null;
        if ($resposta !== null) out(['ok' => false, 'code' => $resposta[1], 'user_msg' => $resposta[2]], $resposta[0]);
    }
    error_log('[documentos_legais] Falha ao publicar versão: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'DOCUMENT_PUBLICATION_ERROR', 'user_msg' => 'Não foi possível publicar a versão.'], 500);
}

out([
    'ok' => true,
    'code' => 'DOCUMENT_VERSION_PUBLISHED',
    'data' => [
        'id_documento_legal_versao' => $idVersao,
        'codigo' => (string)$alvo['codigo'],
        'versao' => (string)$alvo['versao'],
        'status' => 'publicado',
        'publicado_em' => $instante,
        'vigencia_inicio' => $instante,
    ],
]);
