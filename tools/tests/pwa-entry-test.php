<?php
declare(strict_types=1);

$helper = __DIR__ . '/../../backend/_regras/pwa_entry.php';
if (!is_file($helper)) {
    fwrite(STDERR, "FAIL: helper de entrada PWA ainda não existe.\n");
    exit(1);
}

require_once $helper;

function assertDestino(string $nome, array $sessao, string $esperado): void
{
    $atual = pwaDestinoSessao($sessao);
    if ($atual !== $esperado) {
        fwrite(STDERR, "FAIL {$nome}: esperado {$esperado}, obtido {$atual}.\n");
        exit(1);
    }
}

$login = '/views/login-empresa.php?source=pwa';
$painel = '/views/painel-administrativo/painel-administrativo.html';
$agenda = '/views/agenda.html';
$authBase = [
    'id_usuario' => 41,
    'status' => 'ativo',
    'tipo_usuario' => 'usuario',
    'empresa_id' => 7,
    'perfil_nome' => 'proprietario',
    'modo_regularizacao' => false,
];

assertDestino('sessão vazia', [], $login);
assertDestino('auth malformado', ['auth' => 'invalido'], $login);
assertDestino('usuário sem id', ['auth' => array_replace($authBase, ['id_usuario' => 0])], $login);
assertDestino('usuário inativo', ['auth' => array_replace($authBase, ['status' => 'inativo'])], $login);
assertDestino('empresa ausente', ['auth' => array_replace($authBase, ['empresa_id' => 0])], $login);
assertDestino('cliente excluído', ['auth' => array_replace($authBase, ['tipo_usuario' => 'cliente'])], $login);
assertDestino('super admin excluído', ['auth' => array_replace($authBase, ['tipo_usuario' => 'super_admin'])], $login);
assertDestino('proprietário', ['auth' => $authBase], $painel);
assertDestino('profissional', ['auth' => array_replace($authBase, ['perfil_nome' => 'profissional'])], $agenda);
assertDestino('recepcionista', ['auth' => array_replace($authBase, ['perfil_nome' => 'recepcionista'])], $agenda);
assertDestino(
    'modo regularização',
    ['auth' => array_replace($authBase, ['perfil_nome' => 'profissional', 'modo_regularizacao' => true])],
    $painel
);
assertDestino('perfil desconhecido', ['auth' => array_replace($authBase, ['perfil_nome' => 'gerente'])], $login);
assertDestino(
    'empresa externa não fixa contexto',
    [
        'auth' => array_replace($authBase, ['empresa_id' => 0]),
        'id_empresa' => 999,
        'empresa_id' => 999,
        'empresa_slug' => 'empresa-externa',
        'empresa' => ['id_empresa' => 999],
    ],
    $login
);

fwrite(STDOUT, "PASS: 13 casos de roteamento PWA.\n");
