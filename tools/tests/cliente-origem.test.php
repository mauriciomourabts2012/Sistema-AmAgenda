<?php
declare(strict_types=1);

/*
 * Testes da origem do cadastro do cliente (C4.1): gravação nos dois pontos de criação e filtro na
 * lista de clientes existente. Verificações sobre o código-fonte; não acessam o banco nem enviam nada.
 * Uso: php tools/tests/cliente-origem.test.php
 */

$total = 0;
$falhas = [];
require_once __DIR__ . '/sms-banco-falso.php';

$raiz = realpath(__DIR__ . '/../..');
$ler = static fn (string $caminho): string => str_replace("\r\n", "\n", (string)file_get_contents($raiz . '/' . $caminho));

$cadastrar = $ler('backend/painel_administrativo/cliente/cadastrar_cliente.php');
$salvar = $ler('backend/cliente/salvar_dados_cadastrais.php');
$editar = $ler('backend/painel_administrativo/cliente/editar_cliente.php');
$lista = $ler('backend/painel_administrativo/cliente/lista_cliente.php');
$html = $ler('public/views/painel-administrativo/painel-administrativo.html');
$js = $ler('public/js/painel-administrativo/cliente/lista-cliente.js');

// ---- Gravação: Painel Administrativo ---------------------------------------------------------------
falsoVerificar(
    preg_match("/INSERT INTO cliente\s*\(id_empresa, nome_completo, whatsapp_celular, email, observacao, cadastro_completo, status, origem_cadastro\)\s*VALUES\s*\(\?, \?, \?, \?, \?, \?, \?, 'painel'\)/", $cadastrar) === 1,
    'painel: o INSERT grava origem_cadastro = \'painel\' (literal, sem parâmetro vindo do navegador)'
);
falsoVerificar(str_contains($cadastrar, '"issssis"'), 'painel: tipos do bind_param inalterados (cadastro rápido não muda)');
falsoVerificar(substr_count($cadastrar, 'origem_cadastro') === 1, 'painel: a origem aparece uma única vez (no INSERT)');
falsoVerificar(!str_contains($cadastrar, "\$_POST['origem") && !str_contains($cadastrar, "\$entrada['origem"), 'painel: a origem nunca é lida da requisição');

// ---- Gravação: Agenda Online ------------------------------------------------------------------------
falsoVerificar(
    str_contains($salvar, "telefone_verificado_em, email_verificado_em, origem_cadastro)")
    && str_contains($salvar, "IF(? = 1, NOW(), NULL), IF(? = 1, NOW(), NULL), 'agenda_online')"),
    'agenda online: o INSERT grava origem_cadastro = \'agenda_online\' (literal)'
);
falsoVerificar(str_contains($salvar, "'issssssssssssii'"), 'agenda online: tipos do bind_param inalterados');
falsoVerificar(substr_count($salvar, 'origem_cadastro') === 1, 'agenda online: a origem aparece uma única vez (somente no INSERT)');
$posUpdate = strpos($salvar, 'UPDATE cliente');
$posInsert = strpos($salvar, 'INSERT INTO cliente');
falsoVerificar(
    $posUpdate !== false && $posInsert !== false
    && !str_contains(substr($salvar, $posUpdate, 700), 'origem_cadastro'),
    'agenda online: o UPDATE (cliente existente) não altera a origem'
);
falsoVerificar(!str_contains($salvar, "\$_POST['origem"), 'agenda online: a origem nunca é lida da requisição');

// ---- Edição não altera a origem ---------------------------------------------------------------------
falsoVerificar(!str_contains($editar, 'origem_cadastro'), 'editar cliente (painel) não toca a origem');
foreach (['backend/painel_administrativo/cliente/alterar_status_cliente.php', 'backend/cliente_perfil/alterar_senha_perfil.php', 'backend/cliente/contatos_cliente.php'] as $arq) {
    falsoVerificar(!str_contains($ler($arq), 'origem_cadastro'), basename($arq) . ' não toca a origem');
}

// ---- Lista: retorno e filtro ------------------------------------------------------------------------
falsoVerificar(str_contains($lista, "\$origem = lower(\$_GET['origem'] ?? '');"), 'lista: parâmetro origem lido do GET');
falsoVerificar(
    str_contains($lista, "if (!in_array(\$origem, ['painel', 'agenda_online', 'nao_informado'], true)) {\n        \$origem = '';\n    }"),
    'lista: valor de origem desconhecido vira "todas" (nunca entra no SQL)'
);
falsoVerificar(
    str_contains($lista, "\$where[] = 'c.origem_cadastro IS NULL';")
    && str_contains($lista, "\$where[] = 'c.origem_cadastro = ?';"),
    'lista: filtra por origem_cadastro = ? (parametrizado) ou IS NULL (não informada)'
);
falsoVerificar(
    strpos($lista, "\$where[] = 'c.id_empresa = ?';") < strpos($lista, "\$where[] = 'c.origem_cadastro = ?';")
    && str_contains($lista, "\$where[] = 'c.id_empresa = ?';"),
    'lista: o isolamento por empresa continua a primeira condição e a origem só acrescenta'
);
// A ordem dos tipos/parâmetros precisa acompanhar a ordem das condições (status, origem, datas, busca).
$posTipoStatus = strpos($lista, "\$params[] = \$status;");
$posTipoOrigem = strpos($lista, "\$params[] = \$origem;");
$posTipoInicio = strpos($lista, "\$params[] = \$inicio . ' 00:00:00';");
falsoVerificar($posTipoStatus < $posTipoOrigem && $posTipoOrigem < $posTipoInicio, 'lista: parâmetros da origem entram entre status e período, na mesma ordem das condições');
falsoVerificar(
    str_contains($lista, '            c.origem_cadastro,') && str_contains($lista, "'origem_cadastro'        => \$row['origem_cadastro'] !== null ? (string)\$row['origem_cadastro'] : null,"),
    'lista: devolve origem_cadastro em cada cliente (null quando não informada)'
);
falsoVerificar(str_contains($lista, "'origem' => \$origem,"), 'lista: devolve a origem aplicada em filtros');
falsoVerificar(substr_count($lista, 'FROM cliente c') === 2, 'lista: continua sendo uma única lista (total e itens)');
falsoVerificar(str_contains($lista, '$pagina') && str_contains($lista, 'LIMIT ? OFFSET ?') && str_contains($lista, "c.status = ?"), 'lista: paginação e status preservados');

// Comportamento do filtro isolado do banco: mesma regra de normalização usada pelo handler.
$normalizar = static function (string $origem): string {
    $origem = mb_strtolower(trim($origem), 'UTF-8');

    return in_array($origem, ['painel', 'agenda_online', 'nao_informado'], true) ? $origem : '';
};
foreach (['painel' => 'painel', 'PAINEL' => 'painel', ' agenda_online ' => 'agenda_online', 'nao_informado' => 'nao_informado', '' => '', 'todos' => '', "x' OR 1=1 --" => '', 'null' => ''] as $entrada => $esperado) {
    falsoVerificar($normalizar($entrada) === $esperado, 'normalização da origem: ' . json_encode($entrada));
}

// ---- Tela: HTML e JS ----------------------------------------------------------------------------------
falsoVerificar(
    str_contains($html, '<select class="input-filtro" id="origem_clientes" name="origem"')
    && str_contains($html, '<option value="">Todas</option>')
    && str_contains($html, '<option value="painel">Painel Administrativo</option>')
    && str_contains($html, '<option value="agenda_online">Agenda Online</option>')
    && str_contains($html, '<option value="nao_informado">Não informada</option>'),
    'html: opção Origem (Todas, Painel Administrativo, Agenda Online, Não informada) no filtro existente'
);
falsoVerificar(substr_count($html, 'id="origem_clientes"') === 1 && substr_count($html, 'id="formPeriodo_clientes"') === 1, 'html: um único filtro de clientes (sem segunda lista)');
falsoVerificar(str_contains($js, 'ORIGEM: "origem_clientes"') && str_contains($js, 'const selOrigem = document.getElementById(CFG.ORIGEM);'), 'js: seletor de origem ligado ao filtro');
falsoVerificar(str_contains($js, 'origem: ""') && str_contains($js, 'FILTRO.origem = selOrigem ? String(selOrigem.value || "").trim() : "";'), 'js: aplicar guarda a origem no estado do filtro');
falsoVerificar(str_contains($js, "if (selOrigem) selOrigem.value = \"\";") && str_contains($js, 'FILTRO.origem = "";'), 'js: limpar zera a origem');
falsoVerificar(str_contains($js, 'url.searchParams.set("origem", FILTRO.origem);') && str_contains($js, 'if (FILTRO.origem) {'), 'js: a origem vai na requisição só quando escolhida');
falsoVerificar(str_contains($js, 'origem_cadastro: item?.origem_cadastro ?? null,'), 'js: a origem do servidor chega ao item da lista');
falsoVerificar(str_contains($js, '<span><strong>Origem:</strong> ${C.escapeHtml(rotuloOrigem(cli.origem_cadastro))}</span>'), 'js: o card exibe a origem (com escape de HTML)');
falsoVerificar(str_contains($js, 'agenda_online: "Agenda Online"') && str_contains($js, 'nao_informado: "Não informada"') && str_contains($js, 'painel: "Painel Administrativo"'), 'js: rótulos das origens');
falsoVerificar(str_contains($js, 'return ROTULO_ORIGEM[String(valor || "")] || "Não informada";'), 'js: origem nula exibe "Não informada"');
falsoVerificar(str_contains($js, 'function setLabelFiltro(iniISO, fimISO, statusVal, origemVal = FILTRO.origem) {'), 'js: rótulo do filtro mostra a origem sem alterar as chamadas existentes');

// O cadastro rápido e seus campos não foram alterados.
foreach (['public/js/painel-administrativo/cliente/cadastrar-cliente.js', 'public/js/agenda/cadastrar-cliente-modal-novo-agendamento.js'] as $arq) {
    falsoVerificar(!str_contains($ler($arq), 'origem'), basename($arq) . ': formulário do cadastro rápido sem campo de origem');
}

falsoRelatorio();
