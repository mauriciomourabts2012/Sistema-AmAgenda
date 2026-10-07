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
// Cor oficial do AmAgenda: usada quando configuracao_geral_empresa.cor_primaria é NULL.
const AMAGENDA_COR_PRIMARIA_PADRAO = '#1163DD';
// Variações oficiais já usadas pelo Design System (mantêm a aparência atual sem cor personalizada).
const AMAGENDA_COR_HOVER_PADRAO = '#0F57C5';
const AMAGENDA_COR_SUAVE_PADRAO = '#EEF5FF';
// Texto escuro oficial do projeto (navy), preferido ao preto puro.
const AMAGENDA_COR_TEXTO_ESCURO = '#0F172A';
const AMAGENDA_CONTRASTE_TEXTO_MIN = 4.5;
const AMAGENDA_CONTRASTE_GRAFICO_MIN = 3.0;

function identidadeBaseProjeto(): string
{
    return dirname(__DIR__, 2);
}

function identidadeUploadEmpresasDir(): string
{
    return identidadeBaseProjeto() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'empresas';
}

/**
 * Empresa cuja identidade vale para a requisição, sempre pelo contexto da sessão
 * (nunca por parâmetro do navegador). 0 = sem contexto empresarial (identidade oficial).
 * - Usuário interno: empresa da sessão autenticada (mesma precedência de require_auth.php);
 *   se divergir da empresa do contexto da sessão, falha de forma segura.
 * - Super Admin: somente em Modo Suporte (empresa validada ao entrar no suporte).
 * - Cliente: cliente_auth.id_empresa precisa coincidir com a empresa do link (empresa_id);
 *   divergência falha de forma segura (identidade oficial).
 * - Sem login: empresa do link público já resolvida na sessão.
 */
function identidadeEmpresaIdSessao(): int
{
    $auth = is_array($_SESSION['auth'] ?? null) ? $_SESSION['auth'] : [];
    $empresaContexto = (int)($_SESSION['empresa_id'] ?? 0);

    if ((int)($auth['id_usuario'] ?? 0) > 0) {
        $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
        if ($tipoUsuario === 'super_admin' && !(bool)($auth['modo_suporte'] ?? false)) {
            return 0;
        }
        $empresaAuth = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? $empresaContexto);
        // Divergência entre a empresa autenticada e a do contexto (ex.: link de outra empresa).
        if ($empresaAuth <= 0 || ($empresaContexto > 0 && $empresaContexto !== $empresaAuth)) return 0;
        return $empresaAuth;
    }

    $clienteAuth = is_array($_SESSION['cliente_auth'] ?? null) ? $_SESSION['cliente_auth'] : [];
    if ($clienteAuth !== []) {
        $empresaCliente = (int)($clienteAuth['id_empresa'] ?? 0);
        return $empresaCliente > 0 && $empresaCliente === $empresaContexto ? $empresaCliente : 0;
    }

    return max(0, $empresaContexto);
}

/** Normaliza #RRGGBB (maiúsculas) ou retorna null quando o valor não está no formato. */
function identidadeCorNormalizar(mixed $valor): ?string
{
    if (!is_string($valor)) return null;
    $valor = trim($valor);
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $valor) === 1 ? strtoupper($valor) : null;
}

/** @return array{0:int,1:int,2:int} */
function identidadeCorRgb(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

function identidadeCorHex(array $rgb): string
{
    return sprintf('#%02X%02X%02X', ...array_map(static fn($c) => max(0, min(255, (int)round($c))), $rgb));
}

/** Mistura determinística: $peso de $cor sobre a cor base (0..1). */
function identidadeCorMisturar(string $cor, string $base, float $peso): string
{
    $a = identidadeCorRgb($cor);
    $b = identidadeCorRgb($base);
    return identidadeCorHex([
        $a[0] * $peso + $b[0] * (1 - $peso),
        $a[1] * $peso + $b[1] * (1 - $peso),
        $a[2] * $peso + $b[2] * (1 - $peso),
    ]);
}

function identidadeCorLuminancia(string $hex): float
{
    $canais = array_map(static function (int $c): float {
        $v = $c / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, identidadeCorRgb($hex));
    return 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
}

/** Razão de contraste WCAG 2.x entre duas cores. */
function identidadeCorContraste(string $a, string $b): float
{
    $la = identidadeCorLuminancia($a);
    $lb = identidadeCorLuminancia($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Escurece em passos fixos de 5% até atingir o contraste mínimo contra todos os fundos.
 * Determinístico; no limite chega ao preto, que atende a qualquer fundo claro.
 */
function identidadeCorEscurecerAte(string $cor, array $fundos, float $minimo): string
{
    for ($passo = 0; $passo <= 20; $passo++) {
        $candidata = identidadeCorMisturar('#000000', $cor, $passo * 0.05);
        $ok = true;
        foreach ($fundos as $fundo) {
            if (identidadeCorContraste($candidata, $fundo) < $minimo) { $ok = false; break; }
        }
        if ($ok) return $candidata;
    }
    return '#000000';
}

/** Texto sobre a cor principal: branco, depois o navy oficial; preto só se necessário. */
function identidadeCorContrastePara(string $fundo): string
{
    foreach (['#FFFFFF', AMAGENDA_COR_TEXTO_ESCURO] as $opcao) {
        if (identidadeCorContraste($opcao, $fundo) >= AMAGENDA_CONTRASTE_TEXTO_MIN) return $opcao;
    }
    $opcoes = ['#FFFFFF', AMAGENDA_COR_TEXTO_ESCURO, '#000000'];
    usort($opcoes, static fn($x, $y) => identidadeCorContraste($y, $fundo) <=> identidadeCorContraste($x, $fundo));
    return $opcoes[0];
}

/**
 * Tokens de cor derivados no backend (sem color-mix/filter no navegador).
 * A cor principal nunca é alterada; quem compensa o contraste são as derivações.
 */
function identidadeCorTokens(?string $corPersistida): array
{
    $cor = identidadeCorNormalizar($corPersistida);
    $personalizada = $cor !== null && $cor !== AMAGENDA_COR_PRIMARIA_PADRAO;
    $cor ??= AMAGENDA_COR_PRIMARIA_PADRAO;

    $contraste = identidadeCorContrastePara($cor);
    if ($personalizada) {
        $suave = identidadeCorMisturar($cor, '#FFFFFF', 0.10);
        // Hover um pouco mais escuro; se o texto sobre a cor for escuro e perder contraste, clareia.
        $hover = identidadeCorMisturar('#000000', $cor, 0.12);
        if (identidadeCorContraste($contraste, $hover) < AMAGENDA_CONTRASTE_TEXTO_MIN) {
            $hover = identidadeCorMisturar($cor, '#FFFFFF', 0.85);
            if (identidadeCorContraste($contraste, $hover) < AMAGENDA_CONTRASTE_TEXTO_MIN) $hover = $cor;
        }
    } else {
        $suave = AMAGENDA_COR_SUAVE_PADRAO;
        $hover = AMAGENDA_COR_HOVER_PADRAO;
    }

    [$r, $g, $b] = identidadeCorRgb($cor);
    return [
        'cor_primaria' => $cor,
        'cor_personalizada' => $personalizada,
        'cor_hover' => $hover,
        'cor_suave' => $suave,
        // Elementos gráficos (borda/foco): mínimo 3:1.
        // Borda: tom mais leve (60% da cor sobre branco), escurecido só o necessário.
        'cor_borda' => identidadeCorEscurecerAte(identidadeCorMisturar($cor, '#FFFFFF', 0.60), ['#FFFFFF'], AMAGENDA_CONTRASTE_GRAFICO_MIN),
        'cor_foco' => identidadeCorEscurecerAte($cor, ['#FFFFFF', $suave], AMAGENDA_CONTRASTE_GRAFICO_MIN),
        'cor_contraste' => $contraste,
        // Texto/link: mínimo 4,5:1 contra o branco e o fundo suave.
        'cor_texto' => identidadeCorEscurecerAte($cor, ['#FFFFFF', $suave], AMAGENDA_CONTRASTE_TEXTO_MIN),
        // Componentes RGB para transparências no CSS (ex.: rgba(var(--x), .24)).
        'cor_rgb' => $r . ', ' . $g . ', ' . $b,
    ];
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
        // Mantém o significado atual (nome/logo/imagem); a cor tem indicador próprio.
        'personalizada' => $nome !== '' || $logo !== '' || $login !== '',
    ] + identidadeCorTokens(isset($row['cor_primaria']) ? (string)$row['cor_primaria'] : null);
}

function identidadeBuscar(mysqli $conexao, int $idEmpresa): array
{
    $stmt = $conexao->prepare('SELECT nome_exibicao, logo_empresa, imagem_login, imagem_login_escala, imagem_login_pos_x, imagem_login_pos_y, cor_primaria FROM configuracao_geral_empresa WHERE id_empresa = ? LIMIT 1');
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
