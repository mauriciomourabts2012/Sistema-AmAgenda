<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_config/identidade_visual.php';
$permissao = identidadeExigirProprietario($conexao);

try {
    $row = identidadeBuscar($conexao, (int)$permissao['id_empresa']);
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_OK', 'data' => identidadeFallback($row)], 200);
} catch (Throwable $e) {
    out(['ok' => false, 'code' => 'IDENTIDADE_VISUAL_ERROR', 'user_msg' => 'Não foi possível carregar a identidade visual.'], 500);
}
