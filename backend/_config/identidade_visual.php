<?php
declare(strict_types=1);

/**
 * Configuração central da Identidade Visual da Empresa.
 * NULL no banco representa os recursos oficiais do AmAgenda.
 */
const AMAGENDA_NOME_PADRAO = 'AmAgenda';
const AMAGENDA_LOGO_PADRAO_URL = '/public/imagens/logo-menu.png';
const AMAGENDA_LOGIN_PADRAO_URL = '/public/imagens/logo.png';
const AMAGENDA_UPLOAD_EMPRESAS_URL = '/uploads/empresas';
const AMAGENDA_IDENTIDADE_MAX_BYTES = 5242880; // 5 MB
const AMAGENDA_LOGIN_LADO_MENOR_MIN = 400;
const AMAGENDA_LOGIN_LADO_MAIOR_MIN = 800;

function identidadeBaseProjeto(): string
{
    return dirname(__DIR__, 2);
}

function identidadeUploadEmpresasDir(): string
{
    return identidadeBaseProjeto() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'empresas';
}

function identidadeEmpresaIdSessao(): int
{
    $auth = $_SESSION['auth'] ?? [];
    return (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $_SESSION['empresa_id'] ?? 0);
}

function identidadeExigirProprietario(mysqli $conexao): array
{
    require_once __DIR__ . '/../_auth/require_auth.php';

    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $idEmpresa = identidadeEmpresaIdSessao();
    $idUsuario = (int)($auth['id_usuario'] ?? $_SESSION['usuario_id'] ?? 0);
    $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? $_SESSION['usuario_tipo'] ?? '')), 'UTF-8');
    $modoSuporte = (bool)($auth['modo_suporte'] ?? $_SESSION['modo_suporte'] ?? false);

    if ($idEmpresa <= 0) {
        out(['ok' => false, 'code' => 'EMPRESA_SESSION_REQUIRED', 'user_msg' => 'Empresa da sessão não identificada.'], 403);
    }

    // Em modo de suporte, o Super Admin administra a empresa ativa da própria sessão.
    if ($tipoUsuario === 'super_admin' && $modoSuporte && $idUsuario > 0) {
        $stmt = $conexao->prepare("SELECT 1 FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
        if (!$stmt) {
            out(['ok' => false, 'code' => 'IDENTIDADE_PERMISSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar a empresa.'], 500);
        }
        $stmt->bind_param('i', $idEmpresa);
        $stmt->execute();
        $empresaAtiva = (bool)$stmt->get_result()?->fetch_row();
        $stmt->close();

        if (!$empresaAtiva) {
            out(['ok' => false, 'code' => 'IDENTIDADE_COMPANY_INACTIVE', 'user_msg' => 'A empresa selecionada não está ativa.'], 403);
        }

        return ['id_empresa' => $idEmpresa, 'perfil_id' => 0, 'perfil_nome' => 'super_admin', 'tipo_usuario' => $tipoUsuario];
    }

    // A autorização é confirmada no banco para não depender de IDs fixos ou de uma sessão antiga.
    $stmt = $conexao->prepare("SELECT eu.id_perfil, p.nome FROM empresa_usuario eu INNER JOIN perfil p ON p.id_perfil = eu.id_perfil WHERE eu.id_empresa = ? AND eu.id_usuario = ? AND eu.status = 'ativo' AND p.status = 'ativo' LIMIT 1");
    if (!$stmt) {
        out(['ok' => false, 'code' => 'IDENTIDADE_PERMISSION_CHECK_ERROR', 'user_msg' => 'Não foi possível validar a permissão do usuário.'], 500);
    }
    $stmt->bind_param('ii', $idEmpresa, $idUsuario);
    $stmt->execute();
    $res = $stmt->get_result();
    $vinculo = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();

    $perfilId = (int)($vinculo['id_perfil'] ?? 0);
    $perfilNome = mb_strtolower(trim((string)($vinculo['nome'] ?? '')), 'UTF-8');
    $ehProprietario = in_array($perfilNome, ['proprietario', 'proprietário'], true);

    if ($idUsuario <= 0 || !$ehProprietario) {
        out(['ok' => false, 'code' => 'IDENTIDADE_PERMISSION_DENIED', 'user_msg' => 'Somente o proprietário pode alterar a identidade visual.'], 403);
    }

    return ['id_empresa' => $idEmpresa, 'perfil_id' => $perfilId, 'perfil_nome' => $perfilNome, 'tipo_usuario' => $tipoUsuario];
}

function identidadeFallback(array $row = []): array
{
    $nome = trim((string)($row['nome_exibicao'] ?? ''));
    $logo = trim((string)($row['logo_empresa'] ?? ''));
    $login = trim((string)($row['imagem_login'] ?? ''));
    $escala = max(60, min(150, (int)($row['imagem_login_escala'] ?? 100)));
    $posX = max(-30, min(30, (int)($row['imagem_login_pos_x'] ?? 0)));
    $posY = max(-30, min(30, (int)($row['imagem_login_pos_y'] ?? 0)));

    return [
        'nome_exibicao' => $nome !== '' ? $nome : AMAGENDA_NOME_PADRAO,
        'logo_url' => $logo !== '' ? $logo : AMAGENDA_LOGO_PADRAO_URL,
        'imagem_login_url' => $login !== '' ? $login : AMAGENDA_LOGIN_PADRAO_URL,
        'imagem_login_escala' => $escala,
        'imagem_login_pos_x' => $posX,
        'imagem_login_pos_y' => $posY,
        'personalizada' => $nome !== '' || $logo !== '' || $login !== '',
    ];
}

function identidadeBuscar(mysqli $conexao, int $idEmpresa): array
{
    $stmt = $conexao->prepare('SELECT nome_exibicao, logo_empresa, imagem_login, imagem_login_escala, imagem_login_pos_x, imagem_login_pos_y FROM configuracao_geral_empresa WHERE id_empresa = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta da identidade visual.');
    }
    $stmt->bind_param('i', $idEmpresa);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    return $row;
}

function identidadeDiretorioEmpresa(int $idEmpresa): string
{
    return identidadeUploadEmpresasDir() . DIRECTORY_SEPARATOR . $idEmpresa . DIRECTORY_SEPARATOR . 'identidade';
}

function identidadeValidarESalvarUpload(string $campo, string $prefixo, int $idEmpresa): ?array
{
    if (!isset($_FILES[$campo]) || (int)($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $arquivo = $_FILES[$campo];
    $erro = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Não foi possível receber a imagem enviada.');
    }

    $tmp = (string)($arquivo['tmp_name'] ?? '');
    $tamanho = (int)($arquivo['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $tamanho <= 0) {
        throw new InvalidArgumentException('O arquivo enviado é inválido.');
    }
    if ($tamanho > AMAGENDA_IDENTIDADE_MAX_BYTES) {
        throw new InvalidArgumentException('Cada imagem deve ter no máximo 5 MB.');
    }

    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($permitidos[$mime])) {
        throw new InvalidArgumentException('Formato inválido. Envie uma imagem JPG, PNG ou WebP.');
    }

    $dimensoes = @getimagesize($tmp);
    if (!$dimensoes || (int)$dimensoes[0] <= 0 || (int)$dimensoes[1] <= 0 || (int)$dimensoes[0] > 8000 || (int)$dimensoes[1] > 8000) {
        throw new InvalidArgumentException('A imagem é inválida ou possui dimensões muito grandes.');
    }

    $largura = (int)$dimensoes[0];
    $altura = (int)$dimensoes[1];
    if ($prefixo === 'login') {
        if (min($largura, $altura) < AMAGENDA_LOGIN_LADO_MENOR_MIN || max($largura, $altura) < AMAGENDA_LOGIN_LADO_MAIOR_MIN) {
            throw new InvalidArgumentException('A imagem do login deve ter o lado menor com pelo menos 400 px e o lado maior com pelo menos 800 px.');
        }
    }

    $diretorio = identidadeDiretorioEmpresa($idEmpresa);
    if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
        throw new RuntimeException('Não foi possível preparar a pasta da empresa.');
    }

    $nome = $prefixo . '_' . bin2hex(random_bytes(8)) . '.' . $permitidos[$mime];
    $fisico = $diretorio . DIRECTORY_SEPARATOR . $nome;
    if (!move_uploaded_file($tmp, $fisico)) {
        throw new RuntimeException('Não foi possível salvar a imagem enviada.');
    }
    @chmod($fisico, 0644);

    return [
        'fisico' => $fisico,
        'url' => AMAGENDA_UPLOAD_EMPRESAS_URL . '/' . $idEmpresa . '/identidade/' . $nome,
    ];
}

function identidadeRemoverArquivoSeguro(?string $url, int $idEmpresa): void
{
    $url = trim((string)$url);
    $prefixo = AMAGENDA_UPLOAD_EMPRESAS_URL . '/' . $idEmpresa . '/identidade/';
    if ($url === '' || !str_starts_with($url, $prefixo)) {
        return;
    }

    $nome = basename(parse_url($url, PHP_URL_PATH) ?: '');
    if ($nome === '' || !preg_match('/^(logo|login)_[a-f0-9]{16}\.(jpg|png|webp)$/', $nome)) {
        return;
    }

    $arquivo = identidadeDiretorioEmpresa($idEmpresa) . DIRECTORY_SEPARATOR . $nome;
    $diretorioReal = realpath(identidadeDiretorioEmpresa($idEmpresa));
    $arquivoReal = realpath($arquivo);
    if ($diretorioReal && $arquivoReal && str_starts_with($arquivoReal, $diretorioReal . DIRECTORY_SEPARATOR) && is_file($arquivoReal)) {
        @unlink($arquivoReal);
    }
}
