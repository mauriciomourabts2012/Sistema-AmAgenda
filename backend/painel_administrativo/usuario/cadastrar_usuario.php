<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('s')) {
    function s(mixed $v): string
    {
        return trim((string)$v);
    }
}

if (!function_exists('lower')) {
    function lower(mixed $v): string
    {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }
}

if (!function_exists('soDigitos')) {
    function soDigitos(?string $v): string
    {
        return preg_replace('/\D+/', '', (string)$v) ?? '';
    }
}

if (!function_exists('readInput')) {
    function readInput(): array
    {
        $contentType = lower($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw ?: '', true);
            return is_array($data) ? $data : [];
        }

        return $_POST ?? [];
    }
}

try {
    /* ==========================================================
       MÉTODO
    ========================================================== */
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

    /* ==========================================================
       SESSÃO
    ========================================================== */
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;

    $idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
    $statusSessao    = lower((string)($auth['status'] ?? ''));

    if ($idUsuarioSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }

    if ($statusSessao !== '' && $statusSessao !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'SESSION_USER_INACTIVE',
            'user_msg' => 'Seu usuário não está ativo. Faça login novamente.'
        ], 403);
    }

    /* ==========================================================
       EMPRESA DA SESSÃO
    ========================================================== */
    $idEmpresaSessao = 0;

    if (isset($auth['id_empresa'])) {
        $idEmpresaSessao = (int)$auth['id_empresa'];
    } elseif (isset($_SESSION['empresa_id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa_id'];
    } elseif (isset($_SESSION['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id_empresa'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id_empresa'];
    } elseif (isset($_SESSION['empresa']['id'])) {
        $idEmpresaSessao = (int)$_SESSION['empresa']['id'];
    }

    if ($idEmpresaSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'SESSION_WITHOUT_COMPANY',
            'user_msg' => 'Não foi possível identificar a empresa da sessão.'
        ], 403);
    }

    /* ==========================================================
       CONEXÃO
    ========================================================== */
    require __DIR__ . '/../../_config/conexao.php';

    if (!isset($conexao) || !($conexao instanceof mysqli) || $conexao->connect_errno) {
        out([
            'ok' => false,
            'code' => 'DB_CONNECTION_ERROR',
            'user_msg' => 'Erro de conexão com banco de dados.'
        ], 500);
    }

    $conexao->set_charset('utf8mb4');

    /* ==========================================================
       INPUTS
    ========================================================== */
    $in = readInput();

    $nomeRaw          = s($in['nome'] ?? '');
    $emailRaw         = s($in['email'] ?? '');
    $telefoneRaw      = s($in['telefone'] ?? '');
    $senhaRaw         = (string)($in['senha'] ?? '');
    $senha2Raw        = (string)($in['senha2'] ?? '');
    $especialidadeRaw = s($in['especialidade'] ?? '');
    $idPerfil         = (int)($in['id_perfil'] ?? 0);

    $nome          = $nomeRaw;
    $email         = $emailRaw !== '' ? lower($emailRaw) : '';
    $telefone      = $telefoneRaw !== '' ? soDigitos($telefoneRaw) : '';
    $senha         = $senhaRaw;
    $senha2        = $senha2Raw;
    $especialidade = $especialidadeRaw;

    $statusNovoUsuario = 'ativo';
    $statusVinculo     = 'ativo';
    $tipoUsuario       = 'usuario';

    /* ==========================================================
       VALIDAÇÃO BÁSICA
    ========================================================== */
    $fields = [];

    if ($nome === '') {
        $fields['u_nome'] = 'Informe o nome do usuário.';
    } elseif (mb_strlen($nome) < 3) {
        $fields['u_nome'] = 'O nome deve ter no mínimo 3 caracteres.';
    } elseif (mb_strlen($nome) > 140) {
        $fields['u_nome'] = 'O nome deve ter no máximo 140 caracteres.';
    }

    if ($email === '') {
        $fields['u_email'] = 'Informe o e-mail.';
    } elseif (mb_strlen($email) > 160) {
        $fields['u_email'] = 'O e-mail deve ter no máximo 160 caracteres.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fields['u_email'] = 'E-mail inválido.';
    }

    if ($telefoneRaw !== '' && (strlen($telefone) < 10 || strlen($telefone) > 15)) {
        $fields['u_telefone'] = 'Telefone inválido. Informe DDD + número.';
    }

    if ($idPerfil <= 0) {
        $fields['u_perfil'] = 'Selecione um perfil válido.';
    }

    if (!empty($fields)) {
        out([
            'ok' => false,
            'code' => 'VALIDATION_ERROR',
            'user_msg' => 'Revise os campos destacados.',
            'fields' => $fields
        ], 422);
    }

    /* ==========================================================
       VALIDAR EMPRESA DA SESSÃO
    ========================================================== */
    $stmt = $conexao->prepare("
        SELECT id_empresa, nome, status
          FROM empresa
         WHERE id_empresa = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param("i", $idEmpresaSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmt->error);
    }

    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NOT_FOUND',
            'user_msg' => 'Empresa da sessão não encontrada.'
        ], 422);
    }

    if (lower((string)$empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INACTIVE',
            'user_msg' => 'A empresa vinculada à sessão está inativa.'
        ], 403);
    }

    /* ==========================================================
       VALIDAR PERFIL
    ========================================================== */
    $stmt = $conexao->prepare("
        SELECT id_perfil, nome, status
          FROM perfil
         WHERE id_perfil = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação do perfil: ' . $conexao->error);
    }

    $stmt->bind_param("i", $idPerfil);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação do perfil: ' . $stmt->error);
    }

    $stmt->bind_result($perfilIdDb, $perfilNomeDb, $perfilStatusDb);
    $perfilEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$perfilEncontrado) {
        out([
            'ok' => false,
            'code' => 'PERFIL_NOT_FOUND',
            'user_msg' => 'O perfil informado não foi encontrado.',
            'fields' => [
                'u_perfil' => 'Perfil inválido.'
            ]
        ], 422);
    }

    $perfilNome   = lower((string)$perfilNomeDb);
    $perfilStatus = lower((string)$perfilStatusDb);

    if ($perfilStatus !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PERFIL_INACTIVE',
            'user_msg' => 'O perfil informado está inativo.',
            'fields' => [
                'u_perfil' => 'Perfil inativo.'
            ]
        ], 422);
    }

    if (in_array($perfilNome, ['super admin', 'super_admin', 'superadmin', 'proprietario', 'proprietário'], true)) {
        out([
            'ok' => false,
            'code' => 'PERFIL_NOT_ALLOWED',
            'user_msg' => 'Esse perfil não pode ser usado neste cadastro.',
            'fields' => [
                'u_perfil' => 'Perfil não permitido.'
            ]
        ], 403);
    }

    $isProfissional = in_array($perfilNome, ['profissional'], true);

    if ($isProfissional) {
        if ($especialidade === '') {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => [
                    'u_especialidade' => 'Informe a especialidade do profissional.'
                ]
            ], 422);
        }

        if (mb_strlen($especialidade) > 120) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => [
                    'u_especialidade' => 'A especialidade deve ter no máximo 120 caracteres.'
                ]
            ], 422);
        }
    } else {
        $especialidade = '';
    }

    /* ==========================================================
       PROCURAR USUÁRIO GLOBAL PELO E-MAIL
    ========================================================== */
    $stmt = $conexao->prepare("
        SELECT id_usuario, nome, email, telefone, status, tipo_usuario
          FROM usuario
         WHERE LOWER(email) = ?
         LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar busca do usuário global: ' . $conexao->error);
    }

    $stmt->bind_param("s", $email);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar busca do usuário global: ' . $stmt->error);
    }

    $stmt->bind_result(
        $usuarioExistenteId,
        $usuarioExistenteNome,
        $usuarioExistenteEmail,
        $usuarioExistenteTelefone,
        $usuarioExistenteStatus,
        $usuarioExistenteTipo
    );

    $usuarioJaExiste = $stmt->fetch();
    $stmt->close();

    /* ==========================================================
       SE FOR USUÁRIO NOVO, VALIDAR SENHA
    ========================================================== */
    if (!$usuarioJaExiste) {
        $fieldsSenha = [];

        if ($senha === '') {
            $fieldsSenha['u_senha'] = 'Informe a senha.';
        } elseif (mb_strlen($senha) < 6 || mb_strlen($senha) > 60) {
            $fieldsSenha['u_senha'] = 'A senha deve ter entre 6 e 60 caracteres.';
        }

        if ($senha2 === '') {
            $fieldsSenha['u_senha2'] = 'Confirme a senha.';
        } elseif ($senha !== $senha2) {
            $fieldsSenha['u_senha2'] = 'A confirmação de senha não confere.';
        }

        if (!empty($fieldsSenha)) {
            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Revise os campos destacados.',
                'fields' => $fieldsSenha
            ], 422);
        }
    }

    $idUsuario = 0;
    $idEmpresaUsuario = 0;
    $usuarioFoiCriadoAgora = false;
    $profissionalFoiCriadoAgora = false;

    $nomeRetorno = $nome;
    $emailRetorno = $email;
    $telefoneRetorno = $telefone;

    /* ==========================================================
       TRANSAÇÃO
    ========================================================== */
    $conexao->begin_transaction();

    try {
        if ($usuarioJaExiste) {
            $idUsuario = (int)$usuarioExistenteId;
            $nomeRetorno = (string)$usuarioExistenteNome;
            $emailRetorno = lower((string)$usuarioExistenteEmail);
            $telefoneRetorno = (string)($usuarioExistenteTelefone ?? '');

            if (lower((string)$usuarioExistenteTipo) === 'super_admin') {
                $conexao->rollback();

                out([
                    'ok' => false,
                    'code' => 'INVALID_LINK_SUPERADMIN',
                    'user_msg' => 'Este e-mail pertence a um Super Admin e não pode ser vinculado como usuário de empresa.',
                    'fields' => [
                        'u_email' => 'Este e-mail pertence a um Super Admin.'
                    ]
                ], 422);
            }

            /* ==========================================================
               VERIFICAR SE JÁ ESTÁ VINCULADO À EMPRESA
            ========================================================== */
            $stmt = $conexao->prepare("
                SELECT id_empresa_usuario
                  FROM empresa_usuario
                 WHERE id_empresa = ?
                   AND id_usuario = ?
                 LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Erro ao preparar validação de vínculo: ' . $conexao->error);
            }

            $stmt->bind_param("ii", $idEmpresaSessao, $idUsuario);

            if (!$stmt->execute()) {
                throw new RuntimeException('Erro ao executar validação de vínculo: ' . $stmt->error);
            }

            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $stmt->close();
                $conexao->rollback();

                out([
                    'ok' => false,
                    'code' => 'USER_ALREADY_LINKED',
                    'user_msg' => 'Este usuário já está vinculado a esta empresa.',
                    'fields' => [
                        'u_email' => 'Usuário já vinculado a esta empresa.'
                    ]
                ], 409);
            }

            $stmt->close();
        } else {
            /* ==========================================================
               CRIAR USUÁRIO GLOBAL
            ========================================================== */
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

            if ($senhaHash === false) {
                throw new RuntimeException('Não foi possível gerar o hash da senha.');
            }

            $stmt = $conexao->prepare("
                INSERT INTO usuario
                    (nome, email, telefone, senha_hash, status, tipo_usuario)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('Erro ao preparar cadastro do usuário: ' . $conexao->error);
            }

            $stmt->bind_param(
                "ssssss",
                $nome,
                $email,
                $telefone,
                $senhaHash,
                $statusNovoUsuario,
                $tipoUsuario
            );

            if (!$stmt->execute()) {
                $errno = (int)$stmt->errno;
                $error = (string)$stmt->error;
                $stmt->close();

                if ($errno === 1062) {
                    $conexao->rollback();

                    out([
                        'ok' => false,
                        'code' => 'EMAIL_EXISTS',
                        'user_msg' => 'Já existe um usuário com este e-mail.',
                        'fields' => [
                            'u_email' => 'E-mail já cadastrado.'
                        ]
                    ], 409);
                }

                throw new RuntimeException('Erro ao executar cadastro do usuário: ' . $error);
            }

            $idUsuario = (int)$stmt->insert_id;
            $stmt->close();

            $usuarioFoiCriadoAgora = true;
        }

        /* ==========================================================
           CRIAR VÍNCULO EMPRESA_USUARIO
        ========================================================== */
        $stmt = $conexao->prepare("
            INSERT INTO empresa_usuario
                (id_empresa, id_usuario, id_perfil, status)
            VALUES
                (?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar vínculo empresa/usuário: ' . $conexao->error);
        }

        $stmt->bind_param(
            "iiis",
            $idEmpresaSessao,
            $idUsuario,
            $idPerfil,
            $statusVinculo
        );

        if (!$stmt->execute()) {
            $errno = (int)$stmt->errno;
            $error = (string)$stmt->error;
            $stmt->close();

            if ($errno === 1062) {
                $conexao->rollback();

                out([
                    'ok' => false,
                    'code' => 'USER_ALREADY_LINKED',
                    'user_msg' => 'Este usuário já está vinculado a esta empresa.',
                    'fields' => [
                        'u_email' => 'Usuário já vinculado a esta empresa.'
                    ]
                ], 409);
            }

            throw new RuntimeException('Erro ao executar vínculo empresa/usuário: ' . $error);
        }

        $idEmpresaUsuario = (int)$stmt->insert_id;
        $stmt->close();

        /* ==========================================================
           CADASTRO EM PROFISSIONAL
           OBS.: SUA TABELA profissional É GLOBAL POR id_usuario.
           ENTÃO UM USUÁRIO SÓ PODE TER 1 CADASTRO PROFISSIONAL.
        ========================================================== */
        if ($isProfissional) {
            $stmt = $conexao->prepare("
                SELECT id_profissional
                  FROM profissional
                 WHERE id_usuario = ?
                 LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Erro ao preparar verificação do profissional: ' . $conexao->error);
            }

            $stmt->bind_param("i", $idUsuario);

            if (!$stmt->execute()) {
                throw new RuntimeException('Erro ao executar verificação do profissional: ' . $stmt->error);
            }

            $stmt->store_result();
            $jaTemCadastroProfissional = $stmt->num_rows > 0;
            $stmt->close();

            if (!$jaTemCadastroProfissional) {
                $stmt = $conexao->prepare("
                    INSERT INTO profissional
                        (id_usuario, especialidade, descricao)
                    VALUES
                        (?, ?, NULL)
                ");

                if (!$stmt) {
                    throw new RuntimeException('Erro ao preparar cadastro do profissional: ' . $conexao->error);
                }

                $stmt->bind_param("is", $idUsuario, $especialidade);

                if (!$stmt->execute()) {
                    $errno = (int)$stmt->errno;
                    $error = (string)$stmt->error;
                    $stmt->close();

                    if ($errno === 1062) {
                        $conexao->rollback();

                        out([
                            'ok' => false,
                            'code' => 'PROFISSIONAL_ALREADY_EXISTS',
                            'user_msg' => 'Este usuário já possui cadastro profissional.'
                        ], 409);
                    }

                    throw new RuntimeException('Erro ao executar cadastro do profissional: ' . $error);
                }

                $stmt->close();
                $profissionalFoiCriadoAgora = true;
            }
        }

        $conexao->commit();
    } catch (Throwable $e) {
        $conexao->rollback();
        throw $e;
    }

    out([
        'ok' => true,
        'code' => $usuarioFoiCriadoAgora ? 'USER_CREATED_AND_LINKED' : 'USER_LINKED_TO_COMPANY',
        'user_msg' => $usuarioFoiCriadoAgora
            ? 'Usuário cadastrado e vinculado à empresa com sucesso.'
            : 'Usuário existente vinculado à empresa com sucesso.',
        'data' => [
            'id_usuario'            => $idUsuario,
            'id_empresa'            => $idEmpresaSessao,
            'empresa_nome'          => (string)$empresaNomeDb,
            'id_empresa_usuario'    => $idEmpresaUsuario,
            'id_perfil'             => $idPerfil,
            'perfil_nome'           => (string)$perfilNomeDb,
            'nome'                  => $nomeRetorno,
            'email'                 => $emailRetorno,
            'telefone'              => $telefoneRetorno,
            'status'                => $statusVinculo,
            'tipo_usuario'          => $tipoUsuario,
            'usuario_novo'          => $usuarioFoiCriadoAgora,
            'profissional'          => $isProfissional ? [
                'especialidade'      => $especialidade,
                'cadastro_criado'    => $profissionalFoiCriadoAgora
            ] : null
        ]
    ], 201);

} catch (Throwable $e) {
    error_log('[CadastrarUsuario] ' . $e->getMessage());

    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $ignore) {
        }
    }

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao cadastrar usuário.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}