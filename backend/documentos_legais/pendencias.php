<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';
documentosLegaisMetodo('GET');

$contexto = documentosLegaisContextoAutenticado($conexao);
try {
    $documentos = documentosLegaisPendencias($conexao, $contexto);
} catch (UnexpectedValueException) {
    out(['ok' => false, 'code' => 'DOCUMENT_INTEGRITY_ERROR', 'user_msg' => 'Não foi possível validar os documentos legais.'], 503);
}
$pendencias = array_values(array_filter($documentos, static fn(array $item): bool => $item['pendente']));

out([
    'ok' => true,
    'code' => 'LEGAL_DOCUMENTS_PENDING_LISTED',
    'data' => [
        'tipo_manifestante' => $contexto['tipo_manifestante'],
        'possui_pendencias' => $pendencias !== [],
        'quantidade' => count($pendencias),
        'pendencias' => $pendencias,
        'documentos_aplicaveis' => $documentos,
        'csrf_token' => csrfTokenSessao(),
    ],
]);
