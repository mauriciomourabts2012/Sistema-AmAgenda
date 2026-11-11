<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PERFIL DO CLIENTE - BUSCAR FOTO
|--------------------------------------------------------------------------
|
| Retorna somente os dados necessários ao modal "Meu Perfil" do cliente (foto, nome,
| estado da senha e as "Informações pessoais" de leitura). Cliente e empresa são obtidos
| exclusivamente da sessão autenticada; nenhum identificador do frontend é aceito.
| Nunca devolve senha, hash, tokens nem o CPF completo (somente mascarado).
|
*/

require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

/** CPF mascarado: só os dois últimos dígitos. Valor vazio ou inválido não é exibido. */
function perfilCpfMascarado(string $cpf): string
{
    $digitos = preg_replace('/\D+/', '', $cpf) ?? '';

    return strlen($digitos) === 11 ? '***.***.***-' . substr($digitos, -2) : '';
}

/** Celular no formato brasileiro de exibição; formatos desconhecidos são devolvidos como estão. */
function perfilTelefoneExibicao(string $telefone): string
{
    $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
    if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
        $digitos = substr($digitos, 2);
    }
    if (strlen($digitos) === 11) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }
    if (strlen($digitos) === 10) {
        return sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }

    return trim($telefone);
}

try {

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        out([
            'ok' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'user_msg' => 'Método não permitido.',
        ], 405);
    }

    $sessao = exigirSessaoCliente();
    $clienteSessao = buscarClienteDaSessao($conexao, $sessao);

    if ($clienteSessao === null) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $idCliente = (int)$clienteSessao['id_cliente'];
    $idEmpresa = (int)$sessao['id_empresa'];

    $recuperacao = $_SESSION['cliente_recuperacao_senha'] ?? null;
    $recuperacaoAutorizada = is_array($recuperacao)
        && (int)($recuperacao['id_empresa'] ?? 0) === $idEmpresa
        && (int)($recuperacao['id_cliente'] ?? 0) === $idCliente
        // C3.1: a autorização vale para o mesmo identificador de login (celular ou "email:<endereço>").
        && hash_equals(
            (string)($sessao['identificador'] ?? ''),
            (string)($recuperacao['identificador'] ?? $recuperacao['telefone'] ?? '')
        )
        && (int)($recuperacao['expira_em'] ?? 0) > time();

    if (!$recuperacaoAutorizada && is_array($recuperacao)) {
        unset($_SESSION['cliente_recuperacao_senha']);
    }

    $stmt = $conexao->prepare(
        "SELECT
            nome_completo,
            foto_perfil,
            senha_hash,
            email,
            whatsapp_celular,
            cpf,
            data_nascimento,
            cidade,
            uf
         FROM cliente
         WHERE id_cliente = ?
           AND id_empresa = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Não foi possível preparar a consulta do perfil.'
        );
    }

    $stmt->bind_param(
        'ii',
        $idCliente,
        $idEmpresa
    );

    if (!$stmt->execute()) {
        $stmt->close();

        throw new RuntimeException(
            'Não foi possível consultar o perfil.'
        );
    }

    $resultado = $stmt->get_result();
    $cliente = $resultado ? $resultado->fetch_assoc() : null;

    $stmt->close();

    if (!$cliente) {
        out([
            'ok' => false,
            'code' => 'CLIENT_NOT_FOUND',
            'user_msg' => 'Cliente não encontrado.',
        ], 404);
    }

    $nome = trim((string)($cliente['nome_completo'] ?? ''));
    $foto = trim((string)($cliente['foto_perfil'] ?? ''));
    $senhaHash = trim((string)($cliente['senha_hash'] ?? ''));

    if ($nome === '') {
        $nome = 'Cliente';
    }

    if ($foto === '') {
        $foto = '/public/imagens/avatar-default.png';
    }

    // Empresa da sessão do cliente (nome apenas, para "Conta vinculada à empresa").
    $empresaNome = '';
    $stmtEmpresa = $conexao->prepare('SELECT nome FROM empresa WHERE id_empresa = ? LIMIT 1');
    if ($stmtEmpresa) {
        $stmtEmpresa->bind_param('i', $idEmpresa);
        $stmtEmpresa->execute();
        $stmtEmpresa->bind_result($nomeEmpresa);
        $empresaNome = $stmtEmpresa->fetch() ? trim((string)$nomeEmpresa) : '';
        $stmtEmpresa->close();
    }

    // Informações pessoais (somente leitura). Campo vazio vai como string vazia: a interface exibe "Não informado".
    $nascimento = trim((string)($cliente['data_nascimento'] ?? ''));
    $dataNascimento = DateTimeImmutable::createFromFormat('!Y-m-d', $nascimento);
    $cidade = trim((string)($cliente['cidade'] ?? ''));
    $uf = trim((string)($cliente['uf'] ?? ''));
    $localizacao = $cidade !== '' && $uf !== '' ? $cidade . ' / ' . $uf : ($cidade !== '' ? $cidade : $uf);

    out([
        'ok' => true,
        'code' => 'CLIENT_PROFILE_LOADED',
        'data' => [
            'nome' => $nome,
            'foto_url' => $foto,
            'tem_senha' => $senhaHash !== '',
            'recuperacao_senha_autorizada' => $recuperacaoAutorizada,
            'informacoes' => [
                // Perfil de acesso da sessão do cliente (não é cargo nem especialidade).
                'perfil' => 'Cliente',
                'empresa' => $empresaNome,
                'nome' => trim((string)($cliente['nome_completo'] ?? '')),
                'email' => trim((string)($cliente['email'] ?? '')),
                'telefone' => perfilTelefoneExibicao((string)($cliente['whatsapp_celular'] ?? '')),
                'cpf' => perfilCpfMascarado((string)($cliente['cpf'] ?? '')),
                'data_nascimento' => $dataNascimento !== false && $dataNascimento->format('Y-m-d') === $nascimento
                    ? $dataNascimento->format('d/m/Y')
                    : '',
                'localizacao' => $localizacao,
            ],
        ],
    ]);

} catch (Throwable $e) {

    error_log(
        '[cliente_perfil_buscar_foto] ' . $e->getMessage()
    );

    out([
        'ok' => false,
        'code' => 'CLIENT_PROFILE_LOAD_ERROR',
        'user_msg' => 'Não foi possível carregar seu perfil agora.',
    ], 500);
}
