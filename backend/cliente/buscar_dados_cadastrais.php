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

    // C3.1: contatos da conta (celular e/ou e-mail). Com cadastro, valem os do banco; sem cadastro
    // (primeiro acesso), o contato comprovado na sessão. Nunca há telefone fictício.
    $telefoneConta = $cliente !== null
        ? trim((string)($cliente['whatsapp_celular'] ?? ''))
        : (string)$sessao['telefone'];
    $emailConta = $cliente !== null
        ? trim((string)($cliente['email'] ?? ''))
        : (string)$sessao['email'];
    $telefoneValidado = $cliente !== null
        ? ($telefoneConta !== '' && ($cliente['telefone_verificado_em'] ?? null) !== null)
        : ($telefoneConta !== '' && $sessao['telefone_comprovado'] === true);
    $emailValidado = $cliente !== null
        ? ($emailConta !== '' && ($cliente['email_verificado_em'] ?? null) !== null)
        : ($emailConta !== '' && $sessao['email_comprovado'] === true);

    // Obrigatórios: Nome e ao menos um contato (celular ou e-mail).
    $dadosObrigatoriosCompletos = $cliente !== null
        && trim((string)($cliente['nome_completo'] ?? '')) !== ''
        && ($telefoneConta !== '' || $emailConta !== '');

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
            'whatsapp_celular' => $telefoneConta,
            'email' => $emailConta,
            // Estado dos contatos para o perfil (verificar o segundo contato, último contato validado).
            'telefone_validado' => $telefoneValidado,
            'email_validado' => $emailValidado,
            'email_editavel' => !($emailValidado && !$telefoneValidado) || $cliente === null,
            'cadastrado' => $cliente !== null,
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
