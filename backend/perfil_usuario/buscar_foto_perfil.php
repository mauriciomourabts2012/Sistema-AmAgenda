<?php
declare(strict_types=1);

/**
 * HANDLER: perfil/buscar-foto
 * - Busca a foto do usuário logado
 * - Lê da tabela usuario.foto_perfil
 * - Devolve também as "Informações pessoais" do modal Meu Perfil e o perfil de acesso
 *   (Proprietário, Profissional, Recepção ou Super Admin), sempre do usuário da sessão
 *   autenticada: nenhum identificador do frontend é aceito; sem senha, hash ou token.
 * - Retorna JSON padrão
 */

date_default_timezone_set('America/Sao_Paulo');

if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

/* ==========================================================
   AUTH
========================================================== */
require_once __DIR__ . '/../_auth/require_auth.php';
require_once __DIR__ . '/../_regras/permissoes_usuario.php';

$auth = $_SESSION['auth'] ?? null;
$idUsuario = (int)($auth['id_usuario'] ?? 0);

if ($idUsuario <= 0) {
    out([
        'ok' => false,
        'code' => 'NOT_AUTHENTICATED',
        'user_msg' => 'Sessão expirada. Faça login novamente.',
    ], 401);
}

/** Telefone no formato brasileiro de exibição; formatos desconhecidos voltam como estão. */
if (!function_exists('perfilTelefoneExibicao')) {
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
}

/* ==========================================================
   BANCO
========================================================== */
require_once __DIR__ . '/../_config/conexao.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

try {
    $sql = "SELECT id_usuario, nome, email, telefone, tipo_usuario, foto_perfil
            FROM usuario
            WHERE id_usuario = ? 
            LIMIT 1";

    $stmt = $conexao->prepare($sql);

    if (!$stmt) {
        throw new Exception('Prepare select foto_perfil falhou.');
    }

    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();

    $result = $stmt->get_result();
    $usuario = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    if (!$usuario) {
        out([
            'ok' => false,
            'code' => 'USER_NOT_FOUND',
            'user_msg' => 'Usuário não encontrado.',
        ], 404);
    }

    $fotoPerfil = trim((string)($usuario['foto_perfil'] ?? ''));
    $fotoDefault = '/public/imagens/avatar-default.png';

    $fotoUrl = $fotoDefault;
    $temFoto = false;

    if ($fotoPerfil !== '') {
        $baseDir = dirname(__DIR__, 2);
        $caminhoFisico = $baseDir . $fotoPerfil;

        if (is_file($caminhoFisico)) {
            $fotoUrl = $fotoPerfil . '?v=' . time();
            $temFoto = true;
        }
    }

    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        $_SESSION['auth'] = [];
    }

    $_SESSION['auth']['foto_perfil'] = $temFoto ? $fotoPerfil : null;

    // Perfil de acesso (não confundir com especialidade/cargo): Super Admin pela identidade global; os
    // demais pelo vínculo da empresa da sessão, resolvido pelo mesmo contexto de permissões do sistema.
    $rotulosPerfil = ['proprietario' => 'Proprietário', 'profissional' => 'Profissional', 'recepcionista' => 'Recepção'];
    $perfilAcesso = '';
    $empresaNome = '';
    if ((string)$usuario['tipo_usuario'] === 'super_admin') {
        $perfilAcesso = 'Super Admin';
    }
    $contextoPerfil = permissoesContexto($conexao);
    if (($contextoPerfil['valido'] ?? false) === true) {
        if ($perfilAcesso === '') {
            $perfilAcesso = $rotulosPerfil[(string)($contextoPerfil['perfil'] ?? '')] ?? '';
        }
        $stmtEmpresa = $conexao->prepare('SELECT nome FROM empresa WHERE id_empresa = ? LIMIT 1');
        if ($stmtEmpresa) {
            $idEmpresaPerfil = (int)$contextoPerfil['id_empresa'];
            $stmtEmpresa->bind_param('i', $idEmpresaPerfil);
            $stmtEmpresa->execute();
            $stmtEmpresa->bind_result($nomeEmpresa);
            $empresaNome = $stmtEmpresa->fetch() ? trim((string)$nomeEmpresa) : '';
            $stmtEmpresa->close();
        }
    }

    out([
        'ok' => true,
        'code' => 'PHOTO_FOUND',
        'user_msg' => $temFoto
            ? 'Foto de perfil carregada com sucesso.'
            : 'Usuário sem foto cadastrada. Exibindo imagem padrão.',
        'data' => [
            'id_usuario'   => (int)$usuario['id_usuario'],
            'nome'         => (string)$usuario['nome'],
            'tem_foto'     => $temFoto,
            'foto_perfil'  => $temFoto ? $fotoPerfil : null,
            'foto_url'     => $fotoUrl,
            'foto_default' => $fotoDefault,
            // Informações pessoais (somente leitura); vazio = a interface mostra "Não informado".
            'informacoes' => [
                'perfil' => $perfilAcesso,
                'nome' => trim((string)$usuario['nome']),
                'email' => trim((string)($usuario['email'] ?? '')),
                'telefone' => perfilTelefoneExibicao((string)($usuario['telefone'] ?? '')),
                'empresa' => $empresaNome,
            ],
        ],
    ], 200);

} catch (Throwable $e) {
    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao buscar a foto do perfil.',
    ], 500);
}