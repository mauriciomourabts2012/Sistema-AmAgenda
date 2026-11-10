<?php
declare(strict_types=1);

// Responsabilidade exclusiva: persistir os dados cadastrais do próprio
// cliente autenticado. Nome e Telefone são os únicos campos obrigatórios;
// nenhum campo opcional pode impedir o salvamento dos obrigatórios.

require_once __DIR__ . '/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

function textoCliente(mixed $valor): string
{
    return trim((string)$valor);
}

function somenteDigitosCliente(mixed $valor): string
{
    return preg_replace('/\D+/', '', (string)$valor) ?? '';
}

try {
    // Cliente e empresa vêm sempre da sessão validada (isolamento multiempresa).
    $sessao = exigirSessaoCliente();
    require_once __DIR__ . '/../_auth/csrf.php';
    csrfValidarSessao();
    $cliente = buscarClienteDaSessao($conexao, $sessao);

    $nome = textoCliente($_POST['nome'] ?? '');
    $email = mb_strtolower(textoCliente($_POST['email'] ?? ''), 'UTF-8');

    // C3.1: conta nova criada por e-mail: o e-mail é o comprovado no login (não editável aqui) e o
    // celular só entra depois, confirmado por SMS no perfil (contatos_cliente.php). Nunca há telefone
    // fictício: sem celular comprovado, whatsapp_celular fica NULL.
    $telefoneConta = $sessao['telefone'] !== '' ? $sessao['telefone'] : null;
    if ($cliente === null && $sessao['email_comprovado'] === true) {
        $email = (string)$sessao['email'];
    }
    $cpf = somenteDigitosCliente($_POST['cpf'] ?? '');
    $nascimento = textoCliente($_POST['nascimento'] ?? '');
    $cep = somenteDigitosCliente($_POST['cep'] ?? '');
    $logradouro = textoCliente($_POST['logradouro'] ?? '');
    $numero = textoCliente($_POST['numero'] ?? '');
    $bairro = textoCliente($_POST['bairro'] ?? '');
    $cidade = textoCliente($_POST['cidade'] ?? '');
    $uf = mb_strtoupper(textoCliente($_POST['uf'] ?? ''), 'UTF-8');
    $complemento = textoCliente($_POST['complemento'] ?? '');
    $campos = [];

    // Único campo obrigatório validado aqui: Nome (Telefone vem da sessão
    // já verificada). Os campos abaixo são todos opcionais — só geram erro
    // quando preenchidos com um valor inválido, nunca por estarem vazios.
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 140) {
        $campos['cli_nome'] = 'Informe o nome completo (de 3 a 140 caracteres).';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160)) {
        $campos['cli_email'] = 'Informe um e-mail válido.';
    }
    if ($cpf !== '' && strlen($cpf) !== 11) {
        $campos['cli_cpf'] = 'Informe um CPF com 11 dígitos ou deixe o campo vazio.';
    }
    if ($nascimento !== '') {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $nascimento);
        if (!$data || $data->format('Y-m-d') !== $nascimento || $data > new DateTimeImmutable('today')) {
            $campos['cli_nasc'] = 'Informe uma data de nascimento válida ou deixe o campo vazio.';
        }
    }
    if ($cep !== '' && strlen($cep) !== 8) {
        $campos['end_cep'] = 'Informe um CEP com 8 dígitos ou deixe o campo vazio.';
    }
    foreach ([
        'end_logradouro' => [$logradouro, 160, 'A rua ou avenida deve ter no máximo 160 caracteres.'],
        'end_numero' => [$numero, 20, 'O número deve ter no máximo 20 caracteres.'],
        'end_bairro' => [$bairro, 100, 'O bairro deve ter no máximo 100 caracteres.'],
        'end_cidade' => [$cidade, 100, 'A cidade deve ter no máximo 100 caracteres.'],
    ] as $idCampo => [$valor, $limite, $mensagem]) {
        if (mb_strlen($valor) > $limite) {
            $campos[$idCampo] = $mensagem;
        }
    }
    if ($uf !== '' && !preg_match('/^[A-Z]{2}$/', $uf)) {
        $campos['end_uf'] = 'Informe a UF com 2 letras ou deixe o campo vazio.';
    }
    if (mb_strlen($complemento) > 160) {
        $campos['end_complemento'] = 'O complemento deve ter no máximo 160 caracteres.';
    }

    if ($campos) {
        out([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Preencha os campos obrigatórios destacados.',
            'fields' => $campos,
        ], 422);
    }

    $idClienteAtual = $cliente === null ? 0 : (int)$cliente['id_cliente'];

    // Regras dos contatos (C3.1): a conta mantém ao menos um contato e nunca perde o último contato
    // validado por edição. Alterar o e-mail invalida a verificação anterior (SQL abaixo e trigger).
    if ($cliente === null) {
        if ($telefoneConta === null && $email === '') {
            out([
                'ok' => false,
                'code' => 'CLIENT_CONTACT_REQUIRED',
                'user_msg' => 'Informe ao menos um contato (celular ou e-mail).',
                'fields' => ['cli_email' => 'Informe o e-mail.'],
            ], 422);
        }
    } else {
        $emailBancoAtual = mb_strtolower(trim((string)($cliente['email'] ?? '')), 'UTF-8');
        $emailVerificadoAtual = ($cliente['email_verificado_em'] ?? null) !== null;
        $telefoneVerificadoAtual = ($cliente['telefone_verificado_em'] ?? null) !== null
            && trim((string)($cliente['whatsapp_celular'] ?? '')) !== '';
        $temTelefoneAtual = trim((string)($cliente['whatsapp_celular'] ?? '')) !== '';

        if ($email !== $emailBancoAtual) {
            if ($emailVerificadoAtual && !$telefoneVerificadoAtual) {
                out([
                    'ok' => false,
                    'code' => 'CLIENT_LAST_VERIFIED_CONTACT',
                    'user_msg' => 'Este é seu único contato validado. Valide o celular antes de alterar ou remover o e-mail.',
                    'fields' => ['cli_email' => 'Último contato validado.'],
                ], 409);
            }
            if ($email === '' && !$temTelefoneAtual) {
                out([
                    'ok' => false,
                    'code' => 'CLIENT_CONTACT_REQUIRED',
                    'user_msg' => 'A conta precisa de ao menos um contato (celular ou e-mail).',
                    'fields' => ['cli_email' => 'Informe o e-mail.'],
                ], 422);
            }
        }
    }

    if ($email !== '') {
        $stmtEmail = $conexao->prepare(
            'SELECT id_cliente FROM cliente WHERE id_empresa = ? AND LOWER(email) = ? AND id_cliente <> ? LIMIT 1'
        );
        if (!$stmtEmail) {
            throw new RuntimeException('Não foi possível validar o e-mail.');
        }
        $stmtEmail->bind_param('isi', $sessao['id_empresa'], $email, $idClienteAtual);
        $stmtEmail->execute();
        $stmtEmail->store_result();
        $emailEmUso = $stmtEmail->num_rows > 0;
        $stmtEmail->close();
        if ($emailEmUso) {
            out([
                'ok' => false,
                'code' => 'CLIENT_EMAIL_ALREADY_EXISTS',
                'user_msg' => 'Este e-mail já está cadastrado nesta empresa.',
                'fields' => ['cli_email' => 'E-mail já cadastrado.'],
            ], 409);
        }
    }

    $emailBanco = $email === '' ? null : $email;
    $cpfBanco = $cpf === '' ? null : $cpf;
    $nascimentoBanco = $nascimento === '' ? null : $nascimento;
    $cepBanco = $cep === '' ? null : $cep;
    $logradouroBanco = $logradouro === '' ? null : $logradouro;
    $numeroBanco = $numero === '' ? null : $numero;
    $bairroBanco = $bairro === '' ? null : $bairro;
    $cidadeBanco = $cidade === '' ? null : $cidade;
    $ufBanco = $uf === '' ? null : $uf;
    $complementoBanco = $complemento === '' ? null : $complemento;

    if ($cliente === null) {
        // O contato comprovado neste login nasce validado; o outro (se houver) entra sem validação.
        $telefoneConfirmado = $telefoneConta !== null && $sessao['telefone_comprovado'] === true ? 1 : 0;
        $emailConfirmado = $emailBanco !== null && $sessao['email_comprovado'] === true
            && $emailBanco === (string)$sessao['email'] ? 1 : 0;

        $stmt = $conexao->prepare(
            "INSERT INTO cliente
                (id_empresa, nome_completo, whatsapp_celular, email, cpf, data_nascimento, cep, logradouro, numero, bairro, cidade, uf, complemento, cadastro_completo, status,
                 telefone_verificado_em, email_verificado_em, origem_cadastro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'ativo', IF(? = 1, NOW(), NULL), IF(? = 1, NOW(), NULL), 'agenda_online')"
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar o cadastro.');
        }
        $stmt->bind_param(
            'issssssssssssii',
            $sessao['id_empresa'],
            $nome,
            $telefoneConta,
            $emailBanco,
            $cpfBanco,
            $nascimentoBanco,
            $cepBanco,
            $logradouroBanco,
            $numeroBanco,
            $bairroBanco,
            $cidadeBanco,
            $ufBanco,
            $complementoBanco,
            $telefoneConfirmado,
            $emailConfirmado
        );
    } else {
        // Atualização: reenvia todos os campos (o formulário sempre parte dos
        // valores já carregados do cadastro), então nenhum dado opcional
        // existente é apagado por uma alteração implícita.
        // Alterar (ou apagar) o e-mail invalida a verificação anterior (email_verificado_em).
        // A atribuição de email_verificado_em vem ANTES de "email = ?": o MySQL avalia as
        // atribuições da esquerda para a direita, então "LOWER(email)" ainda é o e-mail antigo.
        $stmt = $conexao->prepare(
            "UPDATE cliente
                SET email_verificado_em = IF(LOWER(email) <=> ?, email_verificado_em, NULL),
                    nome_completo = ?, email = ?, cpf = ?, data_nascimento = ?, cep = ?,
                    logradouro = ?, numero = ?, bairro = ?, cidade = ?, uf = ?, complemento = ?,
                    cadastro_completo = 1
              WHERE id_cliente = ? AND id_empresa = ? AND status = 'ativo'
              LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar a atualização.');
        }
        $idCliente = (int)$cliente['id_cliente'];
        $stmt->bind_param(
            'ssssssssssssii',
            $emailBanco,
            $nome,
            $emailBanco,
            $cpfBanco,
            $nascimentoBanco,
            $cepBanco,
            $logradouroBanco,
            $numeroBanco,
            $bairroBanco,
            $cidadeBanco,
            $ufBanco,
            $complementoBanco,
            $idCliente,
            $sessao['id_empresa']
        );
    }

    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $errno = $stmt->errno;
        $stmt->close();
        if ($errno === 1062) {
            out([
                'ok' => false,
                'code' => 'DUPLICATE_CLIENT',
                'user_msg' => 'Já existe um cadastro com estes dados.',
            ], 409);
        }
        throw new RuntimeException('Falha ao salvar o cadastro: ' . $erro);
    }

    $idCliente = $cliente === null ? (int)$stmt->insert_id : (int)$cliente['id_cliente'];
    $stmt->close();

    $_SESSION['cliente_auth']['id_cliente'] = $idCliente;
    $_SESSION['cliente_auth']['nome_completo'] = $nome;
    $_SESSION['cliente_auth']['cadastro_completo'] = true;

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFILE_SAVED',
        'user_msg' => 'Dados salvos com sucesso.',
        'data' => [
            'id_cliente' => $idCliente,
            'cadastro_completo' => true,
            'nome_completo' => $nome,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[cliente_salvar_dados_cadastrais] ' . $e->getMessage());
    out([
        'ok' => false,
        'code' => 'CLIENT_PROFILE_SAVE_ERROR',
        'user_msg' => 'Não foi possível salvar seus dados agora.',
    ], 500);
}
