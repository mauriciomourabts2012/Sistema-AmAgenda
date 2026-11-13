<?php
declare(strict_types=1);

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

    /* ==========================================================
       SESSÃO
    ========================================================== */
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $auth = $_SESSION['auth'] ?? null;

    $idUsuarioSessao = (int)($auth['id_usuario'] ?? 0);
    $statusSessao    = (string)($auth['status'] ?? '');

    if ($idUsuarioSessao <= 0) {
        out([
            'ok' => false,
            'code' => 'NOT_AUTHENTICATED',
            'user_msg' => 'Sessão expirada. Faça login novamente.'
        ], 401);
    }
    require_once __DIR__ . '/../../_auth/csrf.php';
    csrfValidarSessao();

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

    require __DIR__ . '/../../_config/conexao.php';
    require_once __DIR__ . '/../../_servicos/auditoria.php';
    // Normalização de contatos compartilhada com os fluxos de contatos/unificação do cliente.
    require_once __DIR__ . '/../../_regras/cliente_unificacao.php';

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

    /* ==========================================================
       INPUTS
    ========================================================== */
    $idClienteRaw   = $_POST['id_cliente'] ?? 0;
    $nomeRaw        = s($_POST['nome'] ?? '');
    $telefoneRaw    = s($_POST['telefone'] ?? '');
    $emailRaw       = s($_POST['email'] ?? '');
    $observacaoRaw  = s($_POST['observacao'] ?? '');
    $statusRaw      = lower($_POST['status'] ?? 'ativo');

    $idCliente   = (int)$idClienteRaw;
    $nome        = preg_replace('/\s+/', ' ', $nomeRaw) ?: '';
    // Chave nacional comparável (sem máscara e sem +55), a mesma usada em contatos/unificação.
    $telefone    = clienteUnifChaveTelefone($telefoneRaw);
    $email       = $emailRaw !== '' ? lower($emailRaw) : null;
    $observacao  = $observacaoRaw !== '' ? $observacaoRaw : null;
    $status      = $statusRaw !== '' ? $statusRaw : 'ativo';

    /* ==========================================================
       VALIDAÇÃO
    ========================================================== */
    $fields = [];

    if ($idCliente <= 0) {
        $fields['e_cli_id'] = 'Cliente inválido.';
    }

    if ($nome === '') {
        $fields['e_cli_nome'] = 'Informe o nome do cliente.';
    } elseif (mb_strlen($nome) < 3) {
        $fields['e_cli_nome'] = 'O nome deve ter no mínimo 3 caracteres.';
    } elseif (mb_strlen($nome) > 140) {
        $fields['e_cli_nome'] = 'O nome deve ter no máximo 140 caracteres.';
    }

    // Regra do banco (ck_cliente_contato_obrigatorio): ao menos um contato, telefone OU e-mail.
    if ($telefone === null && $email === null) {
        $fields['e_cli_telefone'] = 'Informe o telefone (WhatsApp) ou o e-mail.';
        $fields['e_cli_email'] = 'Informe o e-mail ou o telefone (WhatsApp).';
    } elseif ($telefone !== null && (strlen($telefone) < 10 || strlen($telefone) > 11)) {
        $fields['e_cli_telefone'] = 'Telefone inválido. Informe DDD + número.';
    }

    if ($email !== null) {
        if (mb_strlen($email) > 160) {
            $fields['e_cli_email'] = 'O e-mail deve ter no máximo 160 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['e_cli_email'] = 'E-mail inválido.';
        }
    }

    if ($observacao !== null && mb_strlen($observacao) > 220) {
        $fields['e_cli_obs'] = 'A observação deve ter no máximo 220 caracteres.';
    }

    if (!in_array($status, ['ativo', 'bloqueado', 'inativo'], true)) {
        $fields['e_cli_status'] = 'Status inválido.';
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
       VALIDAR CLIENTE DA EMPRESA
    ========================================================== */
    // Transação desde a leitura: a linha fica bloqueada (a unificação também a bloqueia),
    // então o estado conferido abaixo é o mesmo que será gravado.
    $conexao->begin_transaction();

    $stmt = $conexao->prepare("
        SELECT id_cliente, id_empresa, nome_completo, whatsapp_celular, email, observacao, status,
               id_cliente_unificado, telefone_verificado_em, email_verificado_em,
               (senha_hash IS NOT NULL AND senha_hash <> '') AS tem_senha
        FROM cliente
        WHERE id_cliente = ?
          AND id_empresa = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validação do cliente: ' . $conexao->error);
    }

    $stmt->bind_param("ii", $idCliente, $idEmpresaSessao);

    if (!$stmt->execute()) {
        throw new RuntimeException('Erro ao executar validação do cliente: ' . $stmt->error);
    }

    $stmt->bind_result(
        $clienteIdDb,
        $clienteEmpresaDb,
        $clienteNomeDb,
        $clienteTelefoneDb,
        $clienteEmailDb,
        $clienteObservacaoDb,
        $clienteStatusDb,
        $clienteUnificadoDb,
        $clienteTelVerificadoDb,
        $clienteEmailVerificadoDb,
        $clienteTemSenhaDb
    );

    $clienteEncontrado = $stmt->fetch();
    $stmt->close();

    if (!$clienteEncontrado) {
        $conexao->rollback();
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado para esta empresa.'
        ], 404);
    }

    /* ==========================================================
       CONTA SECUNDÁRIA UNIFICADA (C4.2): SOMENTE LEITURA
       A conta definitiva concentra o cadastro; a secundária é preservada inativa
       e não pode ser editada nem reativada pelo painel.
    ========================================================== */
    if ($clienteUnificadoDb !== null) {
        $conexao->rollback();
        out([
            'ok' => false,
            'code' => 'CLIENT_UNIFIED_READ_ONLY',
            'user_msg' => 'Esta conta foi unificada a outra conta do cliente e não pode ser editada. Edite a conta principal.'
        ], 409);
    }

    /* ==========================================================
       ALTERAÇÃO DE CONTATO x CONTA JÁ REIVINDICADA PELO CLIENTE
       Telefone e e-mail são credenciais (código OTP e recuperação de senha). Se a equipe
       trocasse o contato de uma conta que o cliente já usa (senha ou contato verificado),
       receberia o código OTP e assumiria a conta. Nesse caso a alteração de contato fica
       restrita ao próprio cliente (perfil, com prova por código).
    ========================================================== */
    $telefoneDbChave = clienteUnifChaveTelefone((string)$clienteTelefoneDb);
    $emailDbChave    = clienteUnifChaveEmail((string)$clienteEmailDb);
    $telefoneMudou   = $telefone !== $telefoneDbChave;
    $emailMudou      = $email !== $emailDbChave;
    $contaReivindicada = (int)$clienteTemSenhaDb === 1
        || $clienteTelVerificadoDb !== null
        || $clienteEmailVerificadoDb !== null;

    if ($contaReivindicada && ($telefoneMudou || $emailMudou)) {
        $conexao->rollback();
        $fieldsContato = [];
        if ($telefoneMudou) {
            $fieldsContato['e_cli_telefone'] = 'Contato protegido: somente o cliente pode alterá-lo.';
        }
        if ($emailMudou) {
            $fieldsContato['e_cli_email'] = 'Contato protegido: somente o cliente pode alterá-lo.';
        }
        out([
            'ok' => false,
            'code' => 'CLIENT_CONTACT_PROTECTED',
            'user_msg' => 'Este cliente já acessa a própria conta. Telefone e e-mail só podem ser alterados por ele, no perfil.',
            'fields' => $fieldsContato
        ], 409);
    }

    // Contato inalterado preserva exatamente o valor armazenado (inclusive formato legado, ex.: +55).
    $telefoneGravar = $telefoneMudou ? $telefone : ($clienteTelefoneDb !== null && $clienteTelefoneDb !== '' ? (string)$clienteTelefoneDb : null);
    $emailGravar    = $emailMudou ? $email : ($clienteEmailDb !== null && $clienteEmailDb !== '' ? (string)$clienteEmailDb : null);

    /* ==========================================================
       VALIDAR TELEFONE EM OUTRO CLIENTE DA MESMA EMPRESA
    ========================================================== */
    // Formatos equivalentes (máscara, +55) são o mesmo telefone: compara a chave nacional.
    // Só verifica quando o telefone foi efetivamente alterado (dados legados não são reprocessados).
    $telefoneDuplicado = false;
    if ($telefoneMudou && $telefone !== null) {
        $telefoneComPais = '55' . $telefone;
        $stmt = $conexao->prepare("
            SELECT id_cliente
            FROM cliente
            WHERE id_empresa = ?
              AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp_celular, '+', ''), '(', ''), ')', ''), '-', ''), ' ', '') IN (?, ?)
              AND id_cliente <> ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar validação de telefone: ' . $conexao->error);
        }

        $stmt->bind_param("issi", $idEmpresaSessao, $telefone, $telefoneComPais, $idCliente);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar validação de telefone: ' . $stmt->error);
        }

        $stmt->store_result();
        $telefoneDuplicado = $stmt->num_rows > 0;
        $stmt->close();
    }

    if ($telefoneDuplicado) {
        $conexao->rollback();

        out([
            'ok' => false,
            'code' => 'CLIENT_PHONE_ALREADY_EXISTS',
            'user_msg' => 'Já existe outro cliente com este telefone nesta empresa.',
            'fields' => [
                'e_cli_telefone' => 'Telefone já cadastrado para outro cliente.'
            ]
        ], 409);
    }

    /* ==========================================================
       VALIDAR E-MAIL EM OUTRO CLIENTE DA MESMA EMPRESA
    ========================================================== */
    if ($emailMudou && $email !== null) {
        $stmt = $conexao->prepare("
            SELECT id_cliente
            FROM cliente
            WHERE id_empresa = ?
              AND LOWER(email) = ?
              AND id_cliente <> ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar validação de e-mail: ' . $conexao->error);
        }

        $stmt->bind_param("isi", $idEmpresaSessao, $email, $idCliente);

        if (!$stmt->execute()) {
            throw new RuntimeException('Erro ao executar validação de e-mail: ' . $stmt->error);
        }

        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->close();
            $conexao->rollback();

            out([
                'ok' => false,
                'code' => 'CLIENT_EMAIL_ALREADY_EXISTS',
                'user_msg' => 'Já existe outro cliente com este e-mail nesta empresa.',
                'fields' => [
                    'e_cli_email' => 'E-mail já cadastrado para outro cliente.'
                ]
            ], 409);
        }

        $stmt->close();
    }

    /* ==========================================================
       UPDATE CLIENTE
    ========================================================== */
    // A transação e o bloqueio da linha foram abertos na leitura (limitada à empresa da sessão).
    // Apenas a verificação do contato alterado é invalidada; a do contato inalterado é preservada.
    // Não toca origem_cadastro, senha_hash nem cadastro_completo. Sem id_cliente_unificado
    // (conta unificada já rejeitada acima), reforçado também no WHERE.
    $telefoneMudouInt = $telefoneMudou ? 1 : 0;
    $emailMudouInt = $emailMudou ? 1 : 0;
    $stmt = $conexao->prepare("
        UPDATE cliente
        SET
            nome_completo = ?,
            whatsapp_celular = ?,
            email = ?,
            telefone_verificado_em = IF(? = 1, NULL, telefone_verificado_em),
            email_verificado_em = IF(? = 1, NULL, email_verificado_em),
            observacao = ?,
            status = ?
        WHERE id_cliente = ?
          AND id_empresa = ?
          AND id_cliente_unificado IS NULL
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar atualização do cliente: ' . $conexao->error);
    }

    $stmt->bind_param(
        "sssiissii",
        $nome,
        $telefoneGravar,
        $emailGravar,
        $telefoneMudouInt,
        $emailMudouInt,
        $observacao,
        $status,
        $idCliente,
        $idEmpresaSessao
    );

    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $error = (string)$stmt->error;
        $stmt->close();

        if ($errno === 1062) {
            $conexao->rollback();
            out([
                'ok' => false,
                'code' => 'DUPLICATE_CLIENT',
                'user_msg' => 'Já existe um cliente com os dados informados.'
            ], 409);
        }

        throw new RuntimeException('Erro ao executar atualização do cliente: ' . $error);
    }

    $stmt->close();

    $campos=['nome'=>[$clienteNomeDb,$nome],'telefone'=>[$clienteTelefoneDb,$telefoneGravar],'email'=>[$clienteEmailDb,$emailGravar],'observacao'=>[$clienteObservacaoDb,$observacao],'status'=>[$clienteStatusDb,$status]];
    $diferencas=[];foreach($campos as $campo=>[$antes,$depois])if(!auditoriaValoresIguais($antes,$depois))$diferencas[$campo]=['antes'=>$antes,'depois'=>$depois];
    if($diferencas!==[])auditoriaRegistrar($conexao,'cliente.editado',['entidade_id'=>$idCliente,'entidade_rotulo'=>$nome,'descricao'=>'Alterou o cliente '.$nome.'.','alteracoes'=>$diferencas,'contexto'=>['origem'=>'painel_administrativo']]);
    $conexao->commit();

    out([
        'ok' => true,
        'code' => 'CLIENT_UPDATED',
        'user_msg' => 'Cliente atualizado com sucesso.',
        'data' => [
            'id_cliente'       => $idCliente,
            'id_empresa'       => $idEmpresaSessao,
            'empresa_nome'     => (string)$empresaNomeDb,
            'nome_completo'    => $nome,
            'whatsapp_celular' => $telefoneGravar,
            'email'            => $emailGravar,
            'observacao'       => $observacao,
            'status'           => $status
        ]
    ], 200);

} catch (Throwable $e) {
    try { $conexao->rollback(); } catch (Throwable $ignorado) {}
    error_log('[editar_cliente] ' . $e->getMessage());

    out([
        'ok' => false,
        'code' => 'INTERNAL_ERROR',
        'user_msg' => 'Erro interno ao editar cliente.'
    ], 500);
}
