<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_config/identidade_visual.php';
require_once __DIR__ . '/../../_config/conexao.php';
$permissao = identidadeExigirProprietario($conexao);

$idEmpresa = (int)$permissao['id_empresa'];
$nome = trim((string)($_POST['nome_exibicao'] ?? ''));
if (mb_strlen($nome, 'UTF-8') > 80) {
    out(['ok' => false, 'code' => 'VALIDATION_ERROR', 'user_msg' => 'O nome exibido deve ter no máximo 80 caracteres.', 'field_errors' => ['nome_exibicao' => 'Máximo de 80 caracteres.']], 422);
}
$nomeDb = $nome !== '' ? $nome : null;
$escala = max(60, min(150, (int)($_POST['imagem_login_escala'] ?? 100)));
$posX = max(-30, min(30, (int)($_POST['imagem_login_pos_x'] ?? 0)));
$posY = max(-30, min(30, (int)($_POST['imagem_login_pos_y'] ?? 0)));
$novos = [];

try {
    $atual = identidadeBuscar($conexao, $idEmpresa);
    $logoNovo = identidadeValidarESalvarUpload('logo_empresa', 'logo', $idEmpresa);
    if ($logoNovo) $novos[] = $logoNovo['fisico'];
    $loginNovo = identidadeValidarESalvarUpload('imagem_login', 'login', $idEmpresa);
    if ($loginNovo) $novos[] = $loginNovo['fisico'];

    $logoDb = $logoNovo['url'] ?? (trim((string)($atual['logo_empresa'] ?? '')) ?: null);
    $loginDb = $loginNovo['url'] ?? (trim((string)($atual['imagem_login'] ?? '')) ?: null);

    $conexao->begin_transaction();
    $sql = "INSERT INTO configuracao_geral_empresa (id_empresa, nome_exibicao, logo_empresa, imagem_login, imagem_login_escala, imagem_login_pos_x, imagem_login_pos_y) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome_exibicao = VALUES(nome_exibicao), logo_empresa = VALUES(logo_empresa), imagem_login = VALUES(imagem_login), imagem_login_escala = VALUES(imagem_login_escala), imagem_login_pos_x = VALUES(imagem_login_pos_x), imagem_login_pos_y = VALUES(imagem_login_pos_y), status = 'ativo'";
    $stmt = $conexao->prepare($sql);
    if (!$stmt) throw new RuntimeException('Falha ao preparar atualização.');
    $stmt->bind_param('isssiii', $idEmpresa, $nomeDb, $logoDb, $loginDb, $escala, $posX, $posY);
    if (!$stmt->execute()) throw new RuntimeException('Falha ao atualizar a identidade.');
    $stmt->close();
    $conexao->commit();

    if ($logoNovo) identidadeRemoverArquivoSeguro($atual['logo_empresa'] ?? null, $idEmpresa);
    if ($loginNovo) identidadeRemoverArquivoSeguro($atual['imagem_login'] ?? null, $idEmpresa);

    $data = identidadeFallback(['nome_exibicao' => $nomeDb, 'logo_empresa' => $logoDb, 'imagem_login' => $loginDb, 'imagem_login_escala' => $escala, 'imagem_login_pos_x' => $posX, 'imagem_login_pos_y' => $posY]);
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_SAVED', 'user_msg' => 'Identidade visual atualizada com sucesso.', 'data' => $data], 200);
} catch (InvalidArgumentException $e) {
    foreach ($novos as $arquivo) if (is_file($arquivo)) @unlink($arquivo);
    out(['ok' => false, 'code' => 'UPLOAD_VALIDATION_ERROR', 'user_msg' => $e->getMessage()], 422);
} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    foreach ($novos as $arquivo) if (is_file($arquivo)) @unlink($arquivo);
    out(['ok' => false, 'code' => 'IDENTIDADE_VISUAL_SAVE_ERROR', 'user_msg' => 'Não foi possível salvar a identidade visual.'], 500);
}
