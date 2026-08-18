<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_config/identidade_visual.php';
require_once __DIR__ . '/../../_config/conexao.php';
$permissao = identidadeExigirProprietario($conexao);

$idEmpresa = (int)$permissao['id_empresa'];
try {
    $atual = identidadeBuscar($conexao, $idEmpresa);
    $conexao->begin_transaction();
    $stmt = $conexao->prepare('UPDATE configuracao_geral_empresa SET nome_exibicao = NULL, logo_empresa = NULL, imagem_login = NULL, imagem_login_escala = 100, imagem_login_pos_x = 0, imagem_login_pos_y = 0 WHERE id_empresa = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Falha ao preparar restauração.');
    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) throw new RuntimeException('Falha ao restaurar identidade.');
    $stmt->close();
    $conexao->commit();

    identidadeRemoverArquivoSeguro($atual['logo_empresa'] ?? null, $idEmpresa);
    identidadeRemoverArquivoSeguro($atual['imagem_login'] ?? null, $idEmpresa);
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_RESTORED', 'user_msg' => 'Identidade padrão do AmAgenda restaurada.', 'data' => identidadeFallback()], 200);
} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    out(['ok' => false, 'code' => 'IDENTIDADE_VISUAL_RESTORE_ERROR', 'user_msg' => 'Não foi possível restaurar a identidade visual.'], 500);
}
