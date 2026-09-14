<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

documentosLegaisMetodo('POST');
$entrada = documentosLegaisEntradaPost([
    'codigo', 'id_documento_legal', 'id_documento_legal_versao', 'versao',
    'conteudo_html', 'resumo_alteracoes', 'exige_nova_manifestacao',
]);
$contexto = documentosLegaisExigirSuperAdmin($conexao);
csrfValidarSessao();

function documentosLegaisRascunhoTexto(mixed $valor, string $campo, int $limite, bool $obrigatorio = false): string
{
    if (!is_string($valor)) {
        out(['ok' => false, 'code' => 'DOCUMENT_DRAFT_INPUT_INVALID', 'user_msg' => 'Os dados do rascunho são inválidos.'], 422);
    }
    $texto = $campo === 'resumo_alteracoes'
        ? (preg_replace('/\s+/u', ' ', trim($valor)) ?? '')
        : $valor;
    if (($obrigatorio && trim($texto) === '') || mb_strlen($texto, 'UTF-8') > $limite) {
        out(['ok' => false, 'code' => 'DOCUMENT_DRAFT_INPUT_INVALID', 'user_msg' => 'Os dados do rascunho são inválidos.'], 422);
    }
    return $texto;
}

function documentosLegaisRascunhoManifestacao(mixed $valor): int
{
    if (!($valor === 0 || $valor === 1 || $valor === '0' || $valor === '1')) {
        out(['ok' => false, 'code' => 'DOCUMENT_DRAFT_INPUT_INVALID', 'user_msg' => 'Os dados do rascunho são inválidos.'], 422);
    }
    return (int)$valor;
}

function documentosLegaisRascunhoDocumento(mysqli $conexao, array $entrada, bool $bloquear): array
{
    $temCodigo = array_key_exists('codigo', $entrada);
    $temId = array_key_exists('id_documento_legal', $entrada);
    if ($temCodigo === $temId) {
        out(['ok' => false, 'code' => 'DOCUMENT_INPUT_INVALID', 'user_msg' => 'Informe um documento válido.'], 422);
    }
    $documento = $temCodigo
        ? documentosLegaisLocalizarDocumentoPorCodigo($conexao, documentosLegaisValidarCodigo($entrada['codigo']), $bloquear)
        : documentosLegaisLocalizarDocumentoPorId($conexao, documentosLegaisValidarIdPositivo($entrada['id_documento_legal'], 'DOCUMENT_INPUT_INVALID'), $bloquear);
    if ($documento === null) {
        out(['ok' => false, 'code' => 'DOCUMENT_NOT_FOUND', 'user_msg' => 'Documento não encontrado ou indisponível.'], 404);
    }
    return $documento;
}

$idVersao = array_key_exists('id_documento_legal_versao', $entrada)
    ? documentosLegaisValidarIdPositivo($entrada['id_documento_legal_versao'])
    : null;
$conteudo = documentosLegaisRascunhoTexto($entrada['conteudo_html'] ?? null, 'conteudo_html', 500000, true);
$resumo = documentosLegaisRascunhoTexto($entrada['resumo_alteracoes'] ?? null, 'resumo_alteracoes', 1000);
$exigeManifestacao = documentosLegaisRascunhoManifestacao($entrada['exige_nova_manifestacao'] ?? null);
$versaoEnviada = array_key_exists('versao', $entrada) ? documentosLegaisValidarVersao($entrada['versao']) : null;
$documentoValidado = documentosLegaisRascunhoDocumento($conexao, $entrada, false);
if ($idVersao === null && $versaoEnviada === null) {
    out(['ok' => false, 'code' => 'DOCUMENT_VERSION_INPUT_INVALID', 'user_msg' => 'Informe uma versão válida.'], 422);
}

try {
    if (!$conexao->begin_transaction()) throw new RuntimeException('Falha ao iniciar a transação.');
    $documento = documentosLegaisLocalizarDocumentoPorId($conexao, (int)$documentoValidado['id_documento_legal'], true);
    if ($documento === null) throw new DomainException('DOCUMENT_NOT_FOUND');
    $idDocumento = (int)$documento['id_documento_legal'];
    $codigo = (string)$documento['codigo'];
    $hash = hash('sha256', $conteudo);

    if ($idVersao === null) {
        $stmtExistente = $conexao->prepare(
            'SELECT id_documento_legal_versao FROM documento_legal_versao WHERE id_documento_legal=? AND versao=? LIMIT 1'
        );
        if (!$stmtExistente) throw new RuntimeException('Falha ao preparar a validação da versão.');
        $stmtExistente->bind_param('is', $idDocumento, $versaoEnviada);
        $stmtExistente->execute();
        $existe = $stmtExistente->get_result()?->fetch_assoc() ?: null;
        $stmtExistente->close();
        if ($existe !== null) {
            throw new DomainException('DOCUMENT_VERSION_ALREADY_EXISTS');
        }

        $status = 'rascunho';
        $stmtInserir = $conexao->prepare(
            'INSERT INTO documento_legal_versao (id_documento_legal,versao,conteudo_html,resumo_alteracoes,hash_sha256,status,exige_nova_manifestacao) VALUES (?,?,?,?,?,?,?)'
        );
        if (!$stmtInserir) throw new RuntimeException('Falha ao preparar a criação do rascunho.');
        $stmtInserir->bind_param('isssssi', $idDocumento, $versaoEnviada, $conteudo, $resumo, $hash, $status, $exigeManifestacao);
        if (!$stmtInserir->execute()) {
            $stmtInserir->close();
            throw new RuntimeException('Falha ao criar o rascunho.');
        }
        $idVersaoResultado = (int)$conexao->insert_id;
        $stmtInserir->close();
        if ($idVersaoResultado <= 0) throw new RuntimeException('Rascunho sem identificador.');

        auditoriaRegistrar($conexao, 'documentos_legais.rascunho_criado', [
            'ator' => $contexto['ator_auditoria'],
            'entidade_id' => $idVersaoResultado,
            'entidade_rotulo' => $codigo,
            'alteracoes' => [
                'resumo_alteracoes' => ['antes' => null, 'depois' => $resumo],
                'exige_nova_manifestacao' => ['antes' => null, 'depois' => $exigeManifestacao === 1],
                'conteudo_alterado' => ['antes' => false, 'depois' => true],
            ],
            'contexto' => ['documento_codigo' => $codigo, 'documento_versao' => $versaoEnviada],
        ]);
        $alterado = true;
        $versaoResultado = $versaoEnviada;
    } else {
        $versaoAtual = documentosLegaisLocalizarVersaoPorId($conexao, $idVersao, true);
        if ($versaoAtual === null || (int)$versaoAtual['id_documento_legal'] !== $idDocumento) {
            throw new DomainException('DOCUMENT_VERSION_NOT_FOUND');
        }
        if ((string)$versaoAtual['status'] !== 'rascunho') {
            throw new DomainException('DOCUMENT_VERSION_NOT_EDITABLE');
        }
        if ($versaoEnviada !== null && $versaoEnviada !== (string)$versaoAtual['versao']) {
            throw new DomainException('DOCUMENT_VERSION_IMMUTABLE');
        }

        $conteudoAlterado = (string)$versaoAtual['conteudo_html'] !== $conteudo;
        $resumoAlterado = (string)($versaoAtual['resumo_alteracoes'] ?? '') !== $resumo;
        $manifestacaoAlterada = (int)$versaoAtual['exige_nova_manifestacao'] !== $exigeManifestacao;
        $alterado = $conteudoAlterado || $resumoAlterado || $manifestacaoAlterada;
        $idVersaoResultado = $idVersao;
        $versaoResultado = (string)$versaoAtual['versao'];

        if ($alterado) {
            $stmtAtualizar = $conexao->prepare(
                'UPDATE documento_legal_versao SET conteudo_html=?,resumo_alteracoes=?,hash_sha256=?,exige_nova_manifestacao=? WHERE id_documento_legal_versao=? AND status=\'rascunho\''
            );
            if (!$stmtAtualizar) throw new RuntimeException('Falha ao preparar a atualização do rascunho.');
            $stmtAtualizar->bind_param('sssii', $conteudo, $resumo, $hash, $exigeManifestacao, $idVersao);
            if (!$stmtAtualizar->execute() || $stmtAtualizar->affected_rows !== 1) {
                $stmtAtualizar->close();
                throw new RuntimeException('Falha ao atualizar o rascunho.');
            }
            $stmtAtualizar->close();

            $alteracoes = [];
            if ($resumoAlterado) $alteracoes['resumo_alteracoes'] = ['antes' => (string)($versaoAtual['resumo_alteracoes'] ?? ''), 'depois' => $resumo];
            if ($manifestacaoAlterada) $alteracoes['exige_nova_manifestacao'] = ['antes' => (int)$versaoAtual['exige_nova_manifestacao'] === 1, 'depois' => $exigeManifestacao === 1];
            if ($conteudoAlterado) $alteracoes['conteudo_alterado'] = ['antes' => false, 'depois' => true];
            auditoriaRegistrar($conexao, 'documentos_legais.rascunho_editado', [
                'ator' => $contexto['ator_auditoria'],
                'entidade_id' => $idVersao,
                'entidade_rotulo' => $codigo,
                'alteracoes' => $alteracoes,
                'contexto' => ['documento_codigo' => $codigo, 'documento_versao' => $versaoResultado],
            ]);
        }
    }

    if (!$conexao->commit()) throw new RuntimeException('Falha ao confirmar o rascunho.');
} catch (Throwable $e) {
    try {
        $conexao->rollback();
    } catch (Throwable) {
    }
    if ($e instanceof DomainException) {
        $respostas = [
            'DOCUMENT_NOT_FOUND' => [404, 'DOCUMENT_NOT_FOUND', 'Documento não encontrado ou indisponível.'],
            'DOCUMENT_VERSION_INPUT_INVALID' => [422, 'DOCUMENT_VERSION_INPUT_INVALID', 'Informe uma versão válida.'],
            'DOCUMENT_VERSION_ALREADY_EXISTS' => [409, 'DOCUMENT_VERSION_ALREADY_EXISTS', 'Esta versão já existe para o documento selecionado.'],
            'DOCUMENT_VERSION_NOT_FOUND' => [404, 'DOCUMENT_VERSION_NOT_FOUND', 'Versão não encontrada para o documento informado.'],
            'DOCUMENT_VERSION_NOT_EDITABLE' => [409, 'DOCUMENT_VERSION_NOT_EDITABLE', 'Somente rascunhos podem ser editados.'],
            'DOCUMENT_VERSION_IMMUTABLE' => [422, 'DOCUMENT_VERSION_IMMUTABLE', 'A identificação da versão não pode ser alterada.'],
        ];
        $resposta = $respostas[$e->getMessage()] ?? null;
        if ($resposta !== null) out(['ok' => false, 'code' => $resposta[1], 'user_msg' => $resposta[2]], $resposta[0]);
    }
    error_log('[documentos_legais] Falha ao salvar rascunho: ' . $e->getMessage());
    out(['ok' => false, 'code' => 'DOCUMENT_DRAFT_SAVE_ERROR', 'user_msg' => 'Não foi possível salvar o rascunho.'], 500);
}

out([
    'ok' => true,
    'code' => $alterado ? 'DOCUMENT_DRAFT_SAVED' : 'DOCUMENT_DRAFT_UNCHANGED',
    'data' => [
        'id_documento_legal_versao' => $idVersaoResultado,
        'codigo' => $codigo,
        'versao' => $versaoResultado,
        'status' => 'rascunho',
        'alterado' => $alterado,
    ],
]);
