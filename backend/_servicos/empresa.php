<?php
declare(strict_types=1);

/**
 * Normaliza um texto para o identificador publico persistido da empresa.
 */
function empresaSlugNormalizar(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '' || !mb_check_encoding($texto, 'UTF-8')) {
        return '';
    }

    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    if ($ascii !== false) {
        $texto = $ascii;
    }

    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto) ?? '';

    return trim($texto, '-');
}

/**
 * Valida o formato canonico aceito pela rota publica.
 */
function empresaSlugEntradaValida(string $slug): bool
{
    $tamanho = strlen($slug);

    return $tamanho >= 1
        && $tamanho <= 160
        && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1;
}

/**
 * Monta candidatos deterministas: nome, nome-2, nome-3, etc.
 */
function empresaSlugCandidato(string $base, int $ordem = 1): string
{
    $base = empresaSlugNormalizar($base);
    if ($base === '' || $ordem <= 0) {
        throw new InvalidArgumentException('Não foi possível gerar um slug válido para a empresa.');
    }

    $sufixo = $ordem === 1 ? '' : '-' . $ordem;
    $limiteBase = 160 - strlen($sufixo);
    $base = rtrim(substr($base, 0, $limiteBase), '-');
    if ($base === '') {
        throw new InvalidArgumentException('Não foi possível gerar um slug válido para a empresa.');
    }

    return $base . $sufixo;
}

/**
 * Cria a empresa usando a conexao e a transacao mantidas pelo chamador.
 */
function empresaServicoCriar(mysqli $conexao, array $dados): array
{
    $nome = (string)($dados['nome'] ?? '');
    $cnpj = $dados['cnpj'] ?? null;
    $email = (string)($dados['email'] ?? '');
    $telefone = (string)($dados['telefone'] ?? '');
    $idPlano = (int)($dados['id_plano'] ?? 0);
    $status = (string)($dados['status'] ?? '');
    $endereco = (string)($dados['endereco'] ?? '');
    $observacao = (string)($dados['observacao'] ?? '');

    if ($nome === '' || $email === '' || $idPlano <= 0 || $status === '') {
        throw new InvalidArgumentException('Dados insuficientes para criar a empresa.');
    }

    $slugBase = empresaSlugNormalizar($nome);
    if ($slugBase === '') {
        throw new InvalidArgumentException('Não foi possível gerar um slug válido para a empresa.');
    }

    $stmt = $conexao->prepare(
        'INSERT INTO empresa (nome, slug, cnpj, email, telefone, plano_id, status, endereco, observacao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare insert empresa falhou.');
    }
    $slug = '';
    $stmt->bind_param('sssssisss', $nome, $slug, $cnpj, $email, $telefone, $idPlano, $status, $endereco, $observacao);

    for ($ordem = 1; $ordem <= 10000; $ordem++) {
        $slug = empresaSlugCandidato($slugBase, $ordem);
        if ($stmt->execute()) {
            $idEmpresa = (int)$stmt->insert_id;
            $stmt->close();

            return ['id_empresa' => $idEmpresa, 'slug' => $slug];
        }

        $errno = (int)$stmt->errno;
        $erro = (string)$stmt->error;
        if ($errno === 1062 && str_contains($erro, 'uq_empresa_slug')) {
            continue;
        }
        if ($errno === 1062) {
            $stmt->close();
            throw new RuntimeException('Registro duplicado ao inserir empresa.');
        }
        $stmt->close();
        throw new RuntimeException('Não foi possível inserir a empresa. Erro: ' . $erro);
    }

    $stmt->close();
    throw new RuntimeException('Não foi possível gerar um slug único para a empresa.');
}

/**
 * Cria as configuracoes iniciais da empresa sem confirmar ou reverter a transacao.
 */
function empresaServicoCriarConfiguracoesIniciais(mysqli $conexao, int $idEmpresa, array $dados): array
{
    if ($idEmpresa <= 0) {
        throw new InvalidArgumentException('Empresa inválida para criar as configurações iniciais.');
    }

    $observacaoPadrao = (string)($dados['observacao_padrao'] ?? '');
    $ddiPadrao = (string)($dados['ddi_padrao'] ?? '');
    $dddPadrao = (string)($dados['ddd_padrao'] ?? '');
    $mensagemPadrao = (string)($dados['mensagem_padrao'] ?? '');

    $stmt = $conexao->prepare(
        "INSERT INTO configuracao_geral_empresa
            (id_empresa, inicio_semana, intervalo_padrao_min, observacao_padrao, status)
         VALUES (?, 'segunda', 10, ?, 'ativo')"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare insert configuracao_geral_empresa falhou.');
    }
    $stmt->bind_param('is', $idEmpresa, $observacaoPadrao);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao criar configuração padrão da empresa. Erro: ' . $erro);
    }
    $stmt->close();

    $stmt = $conexao->prepare(
        "INSERT INTO horario_empresa
            (id_empresa, dia_semana, hora_inicio, hora_fim, almoco_inicio, almoco_fim, disponivel, status)
         VALUES
            (?, 'domingo', NULL, NULL, NULL, NULL, 0, 'ativo'),
            (?, 'segunda', '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
            (?, 'terca',   '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
            (?, 'quarta',  '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
            (?, 'quinta',  '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
            (?, 'sexta',   '08:00:00', '18:00:00', '12:00:00', '14:00:00', 1, 'ativo'),
            (?, 'sabado',  NULL, NULL, NULL, NULL, 0, 'ativo')"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare insert horario_empresa falhou.');
    }
    $stmt->bind_param('iiiiiii', $idEmpresa, $idEmpresa, $idEmpresa, $idEmpresa, $idEmpresa, $idEmpresa, $idEmpresa);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao criar horário padrão da empresa. Erro: ' . $erro);
    }
    $stmt->close();

    $stmt = $conexao->prepare(
        "INSERT INTO configuracao_whatsapp_empresa
            (id_empresa, ddi_padrao, ddd_padrao, mensagem_padrao, status)
         VALUES (?, ?, ?, ?, 'ativo')"
    );
    if (!$stmt) {
        throw new RuntimeException('Prepare insert configuracao_whatsapp_empresa falhou.');
    }
    $stmt->bind_param('isss', $idEmpresa, $ddiPadrao, $dddPadrao, $mensagemPadrao);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao criar configuração padrão do WhatsApp da empresa. Erro: ' . $erro);
    }
    $stmt->close();

    return [
        'configuracao_geral_empresa' => [
            'inicio_semana' => 'segunda',
            'intervalo_padrao_min' => 10,
            'observacao_padrao' => $observacaoPadrao,
            'status' => 'ativo',
        ],
        'configuracao_whatsapp_empresa' => [
            'ddi_padrao' => $ddiPadrao,
            'ddd_padrao' => $dddPadrao,
            'mensagem_padrao' => $mensagemPadrao,
            'status' => 'ativo',
        ],
    ];
}
