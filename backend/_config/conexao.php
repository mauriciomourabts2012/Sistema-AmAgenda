<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

mysqli_report(MYSQLI_REPORT_OFF);

$dbServidor = "10.0.0.54";
$dbPorta    = 3306;
$dbUsuario  = "AmAgenda";
$dbSenha    = "#UvWQL4dx4#";
$dbBanco    = "amagenda";

$conexao = new mysqli($dbServidor, $dbUsuario, $dbSenha, $dbBanco, $dbPorta);

if ($conexao->connect_errno) {
    http_response_code(500);
    exit('Erro interno de conexão.');
}

$conexao->set_charset("utf8mb4");