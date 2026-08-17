<?php
declare(strict_types=1);

// ✅ Se estiver usando api_central, não precisa header aqui
// header('Content-Type: application/json; charset=utf-8');
// header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.'
        ], 405);
    }

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
       HELPERS
    ========================================================== */
    function s(mixed $v): string {
        return trim((string)$v);
    }

    function lower(mixed $v): string {
        return mb_strtolower(trim((string)$v), 'UTF-8');
    }

    function soDigitos(?string $v): string {
        return preg_replace('/\D+/', '', (string)$v) ?? '';
    }

    function intPositivo(mixed $v): int|false {
        return filter_var($v, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
    }

    /* ==========================================================
       INPUTS
    ========================================================== */
    $idEmpresaRaw = $_POST['id_empresa'] ?? ($_POST['empresa_id'] ?? '');
    $nome         = s($_POST['nome'] ?? '');
    $email        = lower($_POST['email'] ?? '');
    $telefoneRaw  = s($_POST['telefone'] ?? '');
    $senha        = (string)($_POST['senha'] ?? '');
    $senha2       = (string)($_POST['senha2'] ?? '');
    $status       = lower($_POST['status'] ?? 'ativo');
    $perfilRaw    = $_POST['id_perfil'] ?? ($_POST['perfil'] ?? '');

    $idEmpresa = intPositivo($idEmpresaRaw);
    $idPerfil  = intPositivo($perfilRaw);
    $telefone  = $telefoneRaw !== '' ? soDigitos($telefoneRaw) : '';

    /* ==========================================================
       VALIDAÇÃO
    ========================================================== */
    $fields = [];

    if ($idEmpresaRaw === '' || $idEmpresa === false) {
        $fields['u_empresa'] = 'Selecione a empresa.';
    }

    if ($nome === '') {
        $fields['u_nome'] = 'Informe o nome completo.';
    }

    if ($email === '') {
        $fields['u_email'] = 'Informe o e-mail (login).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fields['u_email'] = 'E-mail inválido.';
    }

    if ($telefoneRaw !== '' && (strlen($telefone) < 10 || strlen($telefone) > 13)) {
        $fields['u_tel'] = 'Telefone inválido. Informe DDD + número.';
    }

    if ($senha === '') {
        $fields['u_senha'] = 'Informe a senha.';
    } elseif (mb_strlen($senha) < 6) {
        $fields['u_senha'] = 'Senha deve ter no mínimo 6 caracteres.';
    }

    if ($senha2 === '') {
        $fields['u_senha2'] = 'Confirme a senha.';
    } elseif ($senha !== '' && $senha !== $senha2) {
        $fields['u_senha2'] = 'As senhas não coincidem.';
    }

    if ($perfilRaw === '' || $idPerfil === false) {
        $fields['u_perfil_super_admin'] = 'Selecione o perfil.';
    }

    if ($status === '') {
        $fields['u_status'] = 'Selecione o status.';
    } elseif (!in_array($status, ['ativo', 'inativo', 'bloqueado'], true)) {
        $fields['u_status'] = 'Status inválido.';
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
       VALIDAR EMPRESA
    ========================================================== */
    $stmt = $conexao->prepare("
        SELECT id_empresa, nome, status, plano_id
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação da empresa: ' . $conexao->error);
    }

    $stmt->bind_param("i", $idEmpresa);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação da empresa: ' . $stmt->error);
    }

    $stmt->bind_result($empresaIdDb, $empresaNomeDb, $empresaStatusDb, $empresaPlanoIdDb);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();

    if (!$empresaEncontrada) {
        out([
            'ok' => false,
            'code' => 'EMPRESA_NOT_FOUND',
            'user_msg' => 'Empresa não encontrada.',
            'fields' => [
                'u_empresa' => 'Empresa não encontrada.'
            ]
        ], 422);
    }

    if (lower($empresaStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'EMPRESA_INATIVA',
            'user_msg' => 'A empresa selecionada está inativa.',
            'fields' => [
                'u_empresa' => 'Selecione uma empresa ativa.'
            ]
        ], 422);
    }

    /* ==========================================================
       VALIDAR PERFIL
       SUPER ADMIN NÃO DEVE ENTRAR AQUI
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
            'user_msg' => 'Perfil não encontrado.',
            'fields' => [
                'u_perfil_super_admin' => 'Perfil não encontrado.'
            ]
        ], 422);
    }

    if (lower($perfilStatusDb) !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'PERFIL_INATIVO',
            'user_msg' => 'O perfil selecionado está inativo.',
            'fields' => [
                'u_perfil_super_admin' => 'Selecione um perfil ativo.'
            ]
        ], 422);
    }

    $perfilNomeNormalizado = lower($perfilNomeDb);
    if ($perfilNomeNormalizado === 'super admin' || $perfilNomeNormalizado === 'superadmin') {
        out([
            'ok' => false,
            'code' => 'INVALID_PROFILE_FOR_COMPANY_USER',
            'user_msg' => 'Super Admin não pode ser vinculado como usuário de empresa.',
            'fields' => [
                'u_perfil_super_admin' => 'Selecione um perfil de empresa.'
            ]
        ], 422);
    }

    /* ==========================================================
       VALIDAR E-MAIL GLOBAL
       usuário é global no sistema
    ========================================================== */
    $stmt = $conexao->prepare("
        SELECT id_usuario, nome, email, telefone, status, tipo_usuario
        FROM usuario
        WHERE LOWER(email) = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação de e-mail: ' . $conexao->error);
    }

    $stmt->bind_param("s", $email);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação de e-mail: ' . $stmt->error);
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

    $idUsuario = 0;
    $idEmpresaUsuario = 0;
    $usuarioFoiCriadoAgora = false;
    $nomeRetorno = $nome;
    $emailRetorno = $email;
    $telefoneRetorno = $telefone;
    $statusRetorno = $status;

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
            $statusRetorno = lower((string)$usuarioExistenteStatus);

            if (lower((string)$usuarioExistenteTipo) === 'super_admin') {
                $conexao->rollback();

                out([
                    'ok' => false,
                    'code' => 'INVALID_LINK_SUPERADMIN',
                    'user_msg' => 'Super Admin não pode ser vinculado como usuário de empresa.',
                    'fields' => [
                        'u_email' => 'Este e-mail pertence a um Super Admin.'
                    ]
                ], 422);
            }

            /* ==========================================================
               SE JÁ EXISTE USUÁRIO, VALIDAR SE JÁ ESTÁ VINCULADO À EMPRESA
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

            $stmt->bind_param("ii", $idEmpresa, $idUsuario);

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
               INSERIR USUÁRIO GLOBAL
               tipo_usuario = usuario
            ========================================================== */
            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

            if ($senhaHash === false) {
                throw new RuntimeException('Não foi possível gerar o hash da senha.');
            }

            $tipoUsuario = 'usuario';

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
                $status,
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
                            'u_email' => 'E-mail (login) já cadastrado.'
                        ]
                    ], 409);
                }

                throw new RuntimeException('Erro ao executar cadastro do usuário: ' . $error);
            }

            $idUsuario = (int)$stmt->insert_id;
            $stmt->close();

            $usuarioFoiCriadoAgora = true;
            $nomeRetorno = $nome;
            $emailRetorno = $email;
            $telefoneRetorno = $telefone;
            $statusRetorno = $status;
        }

        /* ==========================================================
           INSERIR VÍNCULO EMPRESA_USUARIO
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
            $idEmpresa,
            $idUsuario,
            $idPerfil,
            $status
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
            'id_empresa_usuario' => $idEmpresaUsuario,
            'id_empresa'         => (int)$idEmpresa,
            'empresa_nome'       => (string)$empresaNomeDb,
            'id_usuario'         => (int)$idUsuario,
            'nome'               => $nomeRetorno,
            'email'              => $emailRetorno,
            'telefone'           => $telefoneRetorno,
            'id_perfil'          => (int)$idPerfil,
            'perfil_nome'        => (string)$perfilNomeDb,
            'status'             => $status,
            'usuario_novo'       => $usuarioFoiCriadoAgora
        ]
    ], 201);

} catch (Throwable $e) {
    error_log('[cadastrar_usuario_empresa] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao cadastrar usuário.',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ], 500);
}