<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../_config/identidade_visual.php';
require_once __DIR__ . '/../../_config/conexao.php';

$idEmpresa = identidadeEmpresaIdSessao();
if ($idEmpresa <= 0) {
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_PADRAO', 'data' => identidadeFallback()], 200);
}

try {
    $stmt = $conexao->prepare("SELECT c.nome_exibicao, c.logo_empresa, c.imagem_login, c.imagem_login_escala, c.imagem_login_pos_x, c.imagem_login_pos_y FROM empresa e LEFT JOIN configuracao_geral_empresa c ON c.id_empresa = e.id_empresa WHERE e.id_empresa = ? AND e.status = 'ativo' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta pública.');
    }
    $stmt->bind_param('i', $idEmpresa);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_PUBLICA_OK', 'data' => identidadeFallback($row)], 200);
} catch (Throwable $e) {
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_PADRAO', 'data' => identidadeFallback()], 200);
}
