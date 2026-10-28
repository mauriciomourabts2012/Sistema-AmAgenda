<?php
declare(strict_types=1);

/*
 * Teste estático e simulado: não carrega autenticação, conexão ou sessão real.
 * Confere a superfície exata liberada durante a regularização e o contrato do
 * nome devolvido pela sessão interna.
 */

function perfilRegularizacaoFalhar(string $mensagem): never
{
    fwrite(STDERR, "FAIL: {$mensagem}\n");
    exit(1);
}

function perfilRegularizacaoAssert(bool $condicao, string $mensagem): void
{
    if (!$condicao) perfilRegularizacaoFalhar($mensagem);
}

$raiz = dirname(__DIR__, 2);
$requireAuth = file_get_contents($raiz . '/backend/_auth/require_auth.php');
$sessao = file_get_contents($raiz . '/backend/_auth/sessao.php');
$perfil = file_get_contents($raiz . '/backend/perfil_usuario/buscar_foto_perfil.php');

if (!is_string($requireAuth) || !is_string($sessao) || !is_string($perfil)) {
    perfilRegularizacaoFalhar('não foi possível ler os arquivos da correção A.');
}

preg_match_all("/'perfil\\/buscar-foto'/", $requireAuth, $rotas);
perfilRegularizacaoAssert(
    count($rotas[0]) === 2,
    'perfil/buscar-foto deve estar nas duas listas exatas de regularização.'
);
perfilRegularizacaoAssert(
    !preg_match("/'perfil\\/'/", $requireAuth),
    'nenhum prefixo amplo de perfil pode ser autorizado.'
);
perfilRegularizacaoAssert(
    !preg_match("/'perfil\\/(?:alterar-foto|salvar|editar)'/", $requireAuth),
    'rotas de alteração de perfil não podem ser liberadas pela correção.'
);

perfilRegularizacaoAssert(
    str_contains($perfil, "strtoupper(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'"),
    'a consulta de perfil deve continuar limitada a GET.'
);
perfilRegularizacaoAssert(
    str_contains($perfil, "\$idUsuario = (int)(\$auth['id_usuario'] ?? 0)")
        && !preg_match('/\\$_(?:GET|POST|REQUEST)\\s*\\[[^]]*id_usuario/', $perfil),
    'o usuário consultado deve vir exclusivamente da sessão.'
);

perfilRegularizacaoAssert(
    str_contains(
        $sessao,
        <<<'PHP'
'nome_completo' => (string)($auth['nome'] ?? $auth['nome_completo'] ?? '')
PHP
    ),
    'a sessão interna deve priorizar auth.nome e preservar o fallback legado nome_completo.'
);

fwrite(STDOUT, "PASS: perfil próprio liberado de forma exata e nome da sessão compatível.\n");

