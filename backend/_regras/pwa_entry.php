<?php
declare(strict_types=1);

const PWA_LOGIN_INTERNO = '/views/login-empresa.php?source=pwa';
const PWA_PAINEL_ADMINISTRATIVO = '/views/painel-administrativo/painel-administrativo.html';
const PWA_AGENDA = '/views/agenda.html';

/**
 * Decide somente o destino de uma sessão interna já consolidada.
 *
 * Não autentica, não consulta banco e não aceita contexto empresarial fora do
 * bloco auth. A página de destino continua responsável pela revalidação
 * autoritativa da sessão existente.
 */
function pwaDestinoSessao(array $sessao): string
{
    $auth = $sessao['auth'] ?? null;
    if (!is_array($auth)) {
        return PWA_LOGIN_INTERNO;
    }

    $idUsuario = (int)($auth['id_usuario'] ?? 0);
    $idEmpresa = (int)($auth['empresa_id'] ?? $auth['id_empresa'] ?? 0);
    $status = mb_strtolower(trim((string)($auth['status'] ?? '')), 'UTF-8');
    $tipoUsuario = mb_strtolower(trim((string)($auth['tipo_usuario'] ?? '')), 'UTF-8');
    $perfil = mb_strtolower(trim((string)($auth['perfil_nome'] ?? $auth['perfil'] ?? '')), 'UTF-8');

    if ($idUsuario <= 0 || $idEmpresa <= 0 || $status !== 'ativo' || $tipoUsuario === '') {
        return PWA_LOGIN_INTERNO;
    }

    if (in_array($tipoUsuario, ['cliente', 'super_admin'], true)) {
        return PWA_LOGIN_INTERNO;
    }

    $perfil = match ($perfil) {
        'proprietário' => 'proprietario',
        'recepção', 'recepcao' => 'recepcionista',
        default => $perfil,
    };

    if (!in_array($perfil, ['proprietario', 'profissional', 'recepcionista'], true)) {
        return PWA_LOGIN_INTERNO;
    }

    if (($auth['modo_regularizacao'] ?? false) === true || $perfil === 'proprietario') {
        return PWA_PAINEL_ADMINISTRATIVO;
    }

    return PWA_AGENDA;
}
