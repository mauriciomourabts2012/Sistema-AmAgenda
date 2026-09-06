<?php
declare(strict_types=1);

// Responsabilidade exclusiva: consultar os dados cadastrais do próprio
// cliente autenticado (não lista agendamentos e não salva nada).

require_once __DIR__ . '/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

try {
    // Cliente e empresa vêm sempre da sessão validada (isolamento multiempresa).
    $sessao = exigirSessaoCliente();
    $cliente = buscarClienteDaSessao($conexao, $sessao);

    // Nome e Telefone são os únicos dados cadastrais obrigatórios.
    $dadosObrigatoriosCompletos = $cliente !== null
        && trim((string)($cliente['nome_completo'] ?? '')) !== ''
        && trim((string)$sessao['telefone']) !== '';

    if ($cliente !== null) {
        $_SESSION['cliente_auth']['id_cliente'] = (int)$cliente['id_cliente'];
        $_SESSION['cliente_auth']['nome_completo'] = (string)($cliente['nome_completo'] ?? '');
        $_SESSION['cliente_auth']['cadastro_completo'] = $dadosObrigatoriosCompletos;
    }

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFILE_LOADED',
        'data' => [
            'id_cliente' => $cliente === null ? null : (int)$cliente['id_cliente'],
            'nome_completo' => (string)($cliente['nome_completo'] ?? ''),
            'whatsapp_celular' => (string)$sessao['telefone'],
            'email' => (string)($cliente['email'] ?? ''),
            'cpf' => (string)($cliente['cpf'] ?? ''),
            'data_nascimento' => (string)($cliente['data_nascimento'] ?? ''),
            'cep' => (string)($cliente['cep'] ?? ''),
            'logradouro' => (string)($cliente['logradouro'] ?? ''),
            'numero' => (string)($cliente['numero'] ?? ''),
            'bairro' => (string)($cliente['bairro'] ?? ''),
            'cidade' => (string)($cliente['cidade'] ?? ''),
            'uf' => (string)($cliente['uf'] ?? ''),
            'complemento' => (string)($cliente['complemento'] ?? ''),
            'cadastro_completo' => $cliente !== null && (int)($cliente['cadastro_completo'] ?? 0) === 1,
            'dados_obrigatorios_completos' => $dadosObrigatoriosCompletos,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[cliente_buscar_dados_cadastrais] ' . $e->getMessage());
    out([
        'ok' => false,
        'code' => 'CLIENT_PROFILE_LOAD_ERROR',
        'user_msg' => 'Não foi possível carregar seus dados agora.',
    ], 500);
}
