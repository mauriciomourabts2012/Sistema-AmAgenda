<?php
declare(strict_types=1);

/**
 * Regras autoritativas de consumo dos planos.
 *
 * Todas as funções reutilizam a conexão e a transação do endpoint chamador.
 * Nenhuma conexão ou transação paralela é criada neste arquivo.
 */

function limitesPlanoNormalizarPerfil(?string $perfil): string
{
    $valor = mb_strtolower(trim((string)$perfil), 'UTF-8');
    $valor = strtr($valor, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
    ]);

    return match ($valor) {
        'proprietario' => 'proprietarios',
        'profissional', 'profissionais' => 'profissionais',
        'recepcao', 'recepcionista', 'recepcionistas' => 'recepcionistas',
        default => $valor,
    };
}

function limitesPlanoStatusConta(?string $status): bool
{
    return in_array(mb_strtolower(trim((string)$status), 'UTF-8'), ['ativo', 'bloqueado'], true);
}

/**
 * Bloqueia a empresa antes de qualquer contagem. Esse bloqueio serializa as
 * operações consumidoras e impede que requisições concorrentes furem o plano.
 */
function limitesPlanoBloquearEmpresa(mysqli $conexao, int $idEmpresa): array
{
    $sql = "SELECT id_empresa, plano_id, status FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar bloqueio da empresa para validação do plano.');
    }

    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao bloquear empresa para validação do plano: ' . $erro);
    }

    $res = $stmt->get_result();
    $empresa = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();

    if (!$empresa) {
        return [
            'ok' => false,
            'http_status' => 422,
            'code' => 'COMPANY_PLAN_NOT_FOUND',
            'user_msg' => 'Empresa não encontrada para validação do plano.',
            'data' => ['id_empresa' => $idEmpresa],
        ];
    }

    if (($empresa['status'] ?? '') !== 'ativo') {
        return [
            'ok' => false,
            'http_status' => 403,
            'code' => 'COMPANY_INACTIVE',
            'user_msg' => 'A empresa não está ativa.',
            'data' => ['id_empresa' => $idEmpresa],
        ];
    }

    $idPlano = (int)($empresa['plano_id'] ?? 0);
    if ($idPlano <= 0) {
        return [
            'ok' => false,
            'http_status' => 422,
            'code' => 'COMPANY_PLAN_NOT_FOUND',
            'user_msg' => 'A empresa não possui um plano válido vinculado.',
            'data' => ['id_empresa' => $idEmpresa],
        ];
    }

    $sqlPlano = "
        SELECT
            id_plano,
            nome AS plano_nome,
            status AS plano_status,
            limite_usuarios,
            limite_proprietarios,
            limite_profissionais,
            limite_recepcionistas,
            limite_servicos,
            limite_agendamentos
        FROM plano
        WHERE id_plano = ?
        LIMIT 1
    ";
    $stmt = $conexao->prepare($sqlPlano);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar leitura do plano da empresa.');
    }
    $stmt->bind_param('i', $idPlano);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao carregar plano da empresa: ' . $erro);
    }
    $res = $stmt->get_result();
    $plano = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();

    if (!$plano) {
        return [
            'ok' => false,
            'http_status' => 422,
            'code' => 'COMPANY_PLAN_NOT_FOUND',
            'user_msg' => 'O plano vinculado à empresa não foi encontrado.',
            'data' => ['id_empresa' => $idEmpresa, 'id_plano' => $idPlano],
        ];
    }

    if (($plano['plano_status'] ?? '') !== 'ativo') {
        return [
            'ok' => false,
            'http_status' => 403,
            'code' => 'COMPANY_PLAN_INACTIVE',
            'user_msg' => 'O plano vinculado à empresa não está ativo.',
            'data' => ['id_empresa' => $idEmpresa, 'plano' => (string)$plano['plano_nome']],
        ];
    }

    foreach ([
        'limite_usuarios', 'limite_proprietarios', 'limite_profissionais',
        'limite_recepcionistas', 'limite_servicos', 'limite_agendamentos',
    ] as $campo) {
        $plano[$campo] = (int)$plano[$campo];
    }

    return ['ok' => true, 'plano' => $plano];
}

function limitesPlanoContarConsumo(mysqli $conexao, int $idEmpresa, string $recurso): int
{
    $recurso = limitesPlanoNormalizarPerfil($recurso);

    if ($recurso === 'usuarios') {
        $sql = "
            SELECT COUNT(DISTINCT eu.id_usuario)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND u.status IN ('ativo', 'bloqueado')
              AND u.tipo_usuario <> 'super_admin'
        ";
    } elseif ($recurso === 'profissionais') {
        $sql = "
            SELECT COUNT(DISTINCT pr.id_profissional)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            INNER JOIN profissional pr ON pr.id_usuario = eu.id_usuario
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND u.status IN ('ativo', 'bloqueado')
              AND LOWER(pf.nome) IN ('profissional', 'profissionais')
        ";
    } elseif ($recurso === 'proprietarios') {
        $sql = "
            SELECT COUNT(DISTINCT eu.id_usuario)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND u.status IN ('ativo', 'bloqueado')
              AND LOWER(pf.nome) IN ('proprietário', 'proprietario')
        ";
    } elseif ($recurso === 'recepcionistas') {
        $sql = "
            SELECT COUNT(DISTINCT eu.id_usuario)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND u.status IN ('ativo', 'bloqueado')
              AND LOWER(pf.nome) IN ('recepção', 'recepcao', 'recepcionista', 'recepcionistas')
        ";
    } elseif ($recurso === 'servicos') {
        $sql = "SELECT COUNT(*) FROM servico WHERE id_empresa = ? AND status = 'ativo'";
    } else {
        throw new InvalidArgumentException('Recurso de plano não suportado: ' . $recurso);
    }

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar contagem do recurso ' . $recurso . '.');
    }

    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao contar consumo do plano: ' . $erro);
    }

    $stmt->bind_result($total);
    $stmt->fetch();
    $stmt->close();

    return (int)$total;
}

function limitesPlanoVerificarRecurso(
    mysqli $conexao,
    array $plano,
    int $idEmpresa,
    string $recurso,
    int $quantidadeSolicitada = 1
): array {
    if ($quantidadeSolicitada <= 0) {
        return ['ok' => true];
    }

    $recurso = limitesPlanoNormalizarPerfil($recurso);
    $campos = [
        'usuarios' => 'limite_usuarios',
        'proprietarios' => 'limite_proprietarios',
        'profissionais' => 'limite_profissionais',
        'recepcionistas' => 'limite_recepcionistas',
        'servicos' => 'limite_servicos',
    ];

    if (!isset($campos[$recurso])) {
        throw new InvalidArgumentException('Limite não configurado para o recurso: ' . $recurso);
    }

    $limite = (int)($plano[$campos[$recurso]] ?? 0);
    $consumo = limitesPlanoContarConsumo($conexao, $idEmpresa, $recurso);

    if (($consumo + $quantidadeSolicitada) <= $limite) {
        return ['ok' => true, 'consumo_atual' => $consumo, 'limite' => $limite];
    }

    $rotulos = [
        'usuarios' => 'usuários',
        'proprietarios' => 'proprietários',
        'profissionais' => 'profissionais',
        'recepcionistas' => 'recepcionistas',
        'servicos' => 'serviços',
    ];
    $rotulo = $rotulos[$recurso];
    $planoNome = (string)($plano['plano_nome'] ?? 'contratado');

    return [
        'ok' => false,
        'http_status' => 409,
        'code' => 'PLAN_LIMIT_REACHED',
        'user_msg' => "Limite do plano atingido. Seu plano {$planoNome} permite até {$limite} {$rotulo}. Faça upgrade do plano para adicionar novos {$rotulo}.",
        'data' => [
            'recurso' => $recurso,
            'plano' => $planoNome,
            'limite' => $limite,
            'consumo_atual' => $consumo,
            'quantidade_solicitada' => $quantidadeSolicitada,
        ],
    ];
}

/**
 * Compara a situação anterior e a nova. Apenas aumentos de consumo são
 * validados; mudanças que mantêm ou reduzem consumo nunca são bloqueadas.
 */
function limitesPlanoVerificarTransicaoPerfil(
    mysqli $conexao,
    array $plano,
    int $idEmpresa,
    ?string $perfilAnterior,
    ?string $statusAnterior,
    ?string $perfilNovo,
    string $statusNovo
): array {
    $contavaAntes = limitesPlanoStatusConta($statusAnterior);
    $contaAgora = limitesPlanoStatusConta($statusNovo);
    $perfilAntes = limitesPlanoNormalizarPerfil($perfilAnterior);
    $perfilAgora = limitesPlanoNormalizarPerfil($perfilNovo);

    $incrementos = [];
    if (!$contavaAntes && $contaAgora) {
        $incrementos['usuarios'] = 1;
    }

    foreach (['proprietarios', 'profissionais', 'recepcionistas'] as $recurso) {
        $ocupavaAntes = $contavaAntes && $perfilAntes === $recurso;
        $ocupaAgora = $contaAgora && $perfilAgora === $recurso;
        if (!$ocupavaAntes && $ocupaAgora) {
            $incrementos[$recurso] = 1;
        }
    }

    foreach ($incrementos as $recurso => $quantidade) {
        $resultado = limitesPlanoVerificarRecurso($conexao, $plano, $idEmpresa, $recurso, $quantidade);
        if (($resultado['ok'] ?? false) !== true) {
            return $resultado;
        }
    }

    return ['ok' => true];
}

function limitesPlanoVerificarServico(
    mysqli $conexao,
    array $plano,
    int $idEmpresa,
    string $statusNovo
): array {
    if (!limitesPlanoStatusConta($statusNovo)) {
        return ['ok' => true];
    }

    return limitesPlanoVerificarRecurso($conexao, $plano, $idEmpresa, 'servicos', 1);
}

/**
 * Cada ocorrência criada permanece contabilizada no mês de sua data, mesmo
 * que seja cancelada posteriormente. Recorrências são verificadas mês a mês.
 */
function limitesPlanoVerificarAgendamentosPorMes(
    mysqli $conexao,
    array $plano,
    int $idEmpresa,
    array $datas
): array {
    $porMes = [];
    foreach ($datas as $data) {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$data);
        if (!$dt || $dt->format('Y-m-d') !== (string)$data) {
            throw new InvalidArgumentException('Data inválida na validação mensal do plano.');
        }
        $mes = $dt->format('Y-m');
        $porMes[$mes] = ($porMes[$mes] ?? 0) + 1;
    }

    $limite = (int)($plano['limite_agendamentos'] ?? 0);
    $stmt = $conexao->prepare("SELECT COUNT(*) FROM agendamento WHERE id_empresa = ? AND data_agendamento >= ? AND data_agendamento < ?");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar contagem mensal de agendamentos.');
    }

    $avisos = [];
    ksort($porMes);
    foreach ($porMes as $mes => $novas) {
        $inicio = $mes . '-01';
        $fim = (new DateTimeImmutable($inicio))->modify('+1 month')->format('Y-m-d');
        $stmt->bind_param('iss', $idEmpresa, $inicio, $fim);
        if (!$stmt->execute()) {
            $erro = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Falha ao contar agendamentos do mês: ' . $erro);
        }
        $stmt->bind_result($consumo);
        $stmt->fetch();
        $stmt->free_result();
        $consumo = (int)$consumo;

        $consumoProjetado = $consumo + $novas;
        if ($consumoProjetado > $limite) {
            $stmt->close();
            $planoNome = trim((string)($plano['plano_nome'] ?? ''));
            $planoNome = $planoNome !== '' ? $planoNome : 'atual';
            [$ano, $numeroMes] = explode('-', $mes);
            $mesReferencia = $numeroMes . '/' . $ano;
            $quantidadeTexto = $novas === 1
                ? 'o novo agendamento solicitado'
                : "os {$novas} novos agendamentos solicitados";
            $mensagemLimite = $consumo >= $limite
                ? "Limite mensal atingido. Sua empresa utilizou os {$limite} agendamentos disponíveis no plano em {$mesReferencia}. Para continuar criando agendamentos, será necessário alterar o plano."
                : "Não foi possível concluir porque {$quantidadeTexto} ultrapassaria o limite mensal do plano {$planoNome}. Em {$mesReferencia}, já existem {$consumo} agendamentos registrados, e o plano permite até {$limite} por mês. Para continuar agendando neste período, é necessário alterar o plano.";

            return [
                'ok' => false,
                'http_status' => 409,
                'code' => 'PLAN_MONTHLY_APPOINTMENT_LIMIT_REACHED',
                'user_msg' => $mensagemLimite,
                'data' => [
                    'recurso' => 'agendamentos',
                    'plano' => (string)($plano['plano_nome'] ?? ''),
                    'mes' => $mes,
                    'limite' => $limite,
                    'consumo_atual' => $consumo,
                    'quantidade_solicitada' => $novas,
                ],
            ];
        }
        // ceil evita antecipar o aviso quando 80% resulta em fração.
        $inicioAviso = (int)ceil($limite * 0.80);
        if ($limite > 0 && $consumoProjetado >= $inicioAviso && $consumoProjetado < $limite) {
            [$ano, $numeroMes] = explode('-', $mes);
            $restantes = max(0, $limite - $consumoProjetado);
            $rotuloRestante = $restantes === 1 ? 'agendamento' : 'agendamentos';
            $avisos[] = [
                'mes' => $mes,
                'mes_referencia' => $numeroMes . '/' . $ano,
                'consumo' => $consumoProjetado,
                'limite' => $limite,
                'restantes' => $restantes,
                'inicio_aviso' => $inicioAviso,
                'mensagem' => "Atenção: sua empresa já utilizou {$consumoProjetado} dos {$limite} agendamentos mensais disponíveis no plano em {$numeroMes}/{$ano}. Restam {$restantes} {$rotuloRestante}.",
            ];
        }
    }

    $stmt->close();
    return ['ok' => true, 'avisos' => $avisos];
}

/**
 * Encerra o endpoint preservando o formato JSON já utilizado pelo projeto.
 * O rollback é feito antes da resposta para manter atomicidade.
 */
function limitesPlanoAbortarSeNegado(mysqli $conexao, array $resultado): void
{
    if (($resultado['ok'] ?? false) === true) {
        return;
    }

    try {
        $conexao->rollback();
    } catch (Throwable $ignorado) {
    }

    $status = (int)($resultado['http_status'] ?? 409);
    unset($resultado['http_status']);
    out($resultado, $status);
}
