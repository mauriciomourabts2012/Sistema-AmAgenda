<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_config/identidade_visual.php';
require_once __DIR__ . '/../../_config/conexao.php';
require_once __DIR__ . '/../../_servicos/auditoria.php';
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
    $antes = ['nome_exibicao' => $atual['nome_exibicao'] ?? null, 'logo' => empty($atual['logo_empresa']) ? 'padrao' : 'personalizada', 'imagem_login' => empty($atual['imagem_login']) ? 'padrao' : 'personalizada', 'imagem_login_escala' => (int)($atual['imagem_login_escala'] ?? 100), 'imagem_login_pos_x' => (int)($atual['imagem_login_pos_x'] ?? 0), 'imagem_login_pos_y' => (int)($atual['imagem_login_pos_y'] ?? 0)];
    $depois = ['nome_exibicao' => null, 'logo' => 'padrao', 'imagem_login' => 'padrao', 'imagem_login_escala' => 100, 'imagem_login_pos_x' => 0, 'imagem_login_pos_y' => 0];
    $alteracoes=[];foreach($antes as $campo=>$valor)if(!auditoriaValoresIguais($valor,$depois[$campo]))$alteracoes[$campo]=['antes'=>$valor,'depois'=>$depois[$campo]];
    if($alteracoes!==[])auditoriaRegistrar($conexao,'empresa.identidade_visual_restaurada',['entidade_id'=>$idEmpresa,'entidade_rotulo'=>(string)($atual['nome_exibicao']??'Empresa'),'descricao'=>'Restaurou a identidade visual padrão da empresa.','alteracoes'=>$alteracoes,'contexto'=>['origem'=>'configuracoes_empresa']]);
    $conexao->commit();

    identidadeRemoverArquivoSeguro($atual['logo_empresa'] ?? null, $idEmpresa);
    identidadeRemoverArquivoSeguro($atual['imagem_login'] ?? null, $idEmpresa);
    out(['ok' => true, 'code' => 'IDENTIDADE_VISUAL_RESTORED', 'user_msg' => 'Identidade padrão do AmAgenda restaurada.', 'data' => identidadeFallback()], 200);
} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    out(['ok' => false, 'code' => 'IDENTIDADE_VISUAL_RESTORE_ERROR', 'user_msg' => 'Não foi possível restaurar a identidade visual.'], 500);
}
