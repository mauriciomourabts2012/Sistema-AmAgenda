<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/_config/sessao.php';
amagendaConfigurarSessaoSegura();
session_start();

header('Location: /public/views/link-empresa-invalido.html');
exit;