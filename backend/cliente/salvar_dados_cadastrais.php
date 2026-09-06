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
    $cliente = buscarClienteDaSessao($conexao, $sessao);

    $nome = textoCliente($_POST['nome'] ?? '');
    $email = mb_strtolower(textoCliente($_POST['email'] ?? ''), 'UTF-8');
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
        $stmt = $conexao->prepare(
            "INSERT INTO cliente
                (id_empresa, nome_completo, whatsapp_celular, email, cpf, data_nascimento, cep, logradouro, numero, bairro, cidade, uf, complemento, cadastro_completo, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'ativo')"
        );
        if (!$stmt) {
            throw new RuntimeException('Não foi possível preparar o cadastro.');
        }
        $stmt->bind_param(
            'issssssssssss',
            $sessao['id_empresa'],
            $nome,
            $sessao['telefone'],
            $emailBanco,
            $cpfBanco,
            $nascimentoBanco,
            $cepBanco,
            $logradouroBanco,
            $numeroBanco,
            $bairroBanco,
            $cidadeBanco,
            $ufBanco,
            $complementoBanco
        );
    } else {
        // Atualização: reenvia todos os campos (o formulário sempre parte dos
        // valores já carregados do cadastro), então nenhum dado opcional
        // existente é apagado por uma alteração implícita.
        $stmt = $conexao->prepare(
            "UPDATE cliente
                SET nome_completo = ?, email = ?, cpf = ?, data_nascimento = ?, cep = ?,
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
            'sssssssssssii',
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
