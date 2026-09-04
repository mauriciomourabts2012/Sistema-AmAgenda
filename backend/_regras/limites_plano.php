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
              AND eu.bloqueado_plano = 0
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
              AND eu.bloqueado_plano = 0
              AND u.status IN ('ativo', 'bloqueado')
              AND LOWER(pf.nome) IN ('profissional', 'profissionais')
        ";
    } elseif ($recurso === 'administrativos') {
        $sql = "
            SELECT COUNT(DISTINCT eu.id_usuario)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND eu.bloqueado_plano = 0
              AND u.status IN ('ativo', 'bloqueado')
              AND u.tipo_usuario <> 'super_admin'
              AND LOWER(pf.nome) IN ('proprietário', 'proprietario', 'recepção', 'recepcao', 'recepcionista', 'recepcionistas')
        ";
    } elseif ($recurso === 'proprietarios') {
        $sql = "
            SELECT COUNT(DISTINCT eu.id_usuario)
            FROM empresa_usuario eu
            INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
            INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
            WHERE eu.id_empresa = ?
              AND eu.status IN ('ativo', 'bloqueado')
              AND eu.bloqueado_plano = 0
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
              AND eu.bloqueado_plano = 0
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

    if ($recurso === 'administrativos') {
        $limite = max(0, (int)($plano['limite_usuarios'] ?? 0) - (int)($plano['limite_profissionais'] ?? 0));
    } elseif (isset($campos[$recurso])) {
        $limite = (int)($plano[$campos[$recurso]] ?? 0);
    } else {
        throw new InvalidArgumentException('Limite não configurado para o recurso: ' . $recurso);
    }

    $consumo = limitesPlanoContarConsumo($conexao, $idEmpresa, $recurso);

    if (($consumo + $quantidadeSolicitada) <= $limite) {
        return ['ok' => true, 'consumo_atual' => $consumo, 'limite' => $limite];
    }

    $rotulos = [
        'usuarios' => 'usuários',
        'proprietarios' => 'proprietários',
        'profissionais' => 'profissionais',
        'recepcionistas' => 'recepcionistas',
        'administrativos' => 'usuários entre Proprietários e Recepção',
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

/**
 * Reativa um único vínculo bloqueado pelo plano. O chamador deve abrir a
 * transação; o bloqueio da empresa serializa todas as operações consumidoras
 * e as contagens são refeitas antes da alteração.
 */
function limitesPlanoReativarVinculo(
    mysqli $conexao,
    int $idEmpresa,
    int $idEmpresaUsuario
): array {
    $resultadoEmpresa = limitesPlanoBloquearEmpresa($conexao, $idEmpresa);
    if (($resultadoEmpresa['ok'] ?? false) !== true) {
        return $resultadoEmpresa;
    }

    $sql = "
        SELECT
            eu.id_empresa_usuario,
            eu.id_usuario,
            eu.status AS status_vinculo,
            eu.bloqueado_plano,
            u.nome,
            u.status AS status_usuario,
            pf.nome AS perfil_nome
        FROM empresa_usuario eu
        INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
        INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
        WHERE eu.id_empresa_usuario = ?
          AND eu.id_empresa = ?
        LIMIT 1
        FOR UPDATE
    ";
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar vínculo para reativação pelo plano.');
    }
    $stmt->bind_param('ii', $idEmpresaUsuario, $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao carregar vínculo para reativação: ' . $erro);
    }
    $res = $stmt->get_result();
    $vinculo = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();

    if (!$vinculo) {
        return [
            'ok' => false,
            'http_status' => 404,
            'code' => 'PLAN_LINK_NOT_FOUND',
            'user_msg' => 'Vínculo não encontrado para esta empresa.',
        ];
    }

    if ((int)($vinculo['bloqueado_plano'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_LINK_ALREADY_ACTIVE',
            'user_msg' => 'Este usuário não está bloqueado pelo plano.',
        ];
    }

    $statusVinculo = mb_strtolower(trim((string)($vinculo['status_vinculo'] ?? '')), 'UTF-8');
    $statusUsuario = mb_strtolower(trim((string)($vinculo['status_usuario'] ?? '')), 'UTF-8');
    if ($statusVinculo !== 'ativo' || $statusUsuario !== 'ativo') {
        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_LINK_MANUAL_STATUS_INACTIVE',
            'user_msg' => 'O usuário precisa estar com o status manual ativo para ser reativado pelo plano.',
        ];
    }

    $plano = $resultadoEmpresa['plano'];
    $resultadoTotal = limitesPlanoVerificarRecurso($conexao, $plano, $idEmpresa, 'usuarios', 1);
    if (($resultadoTotal['ok'] ?? false) !== true) {
        return $resultadoTotal;
    }

    $recursoPerfil = limitesPlanoNormalizarPerfil((string)($vinculo['perfil_nome'] ?? ''));
    if (in_array($recursoPerfil, ['proprietarios', 'recepcionistas'], true)) {
        $recursoPerfil = 'administrativos';
    }
    if (in_array($recursoPerfil, ['administrativos', 'profissionais'], true)) {
        $resultadoPerfil = limitesPlanoVerificarRecurso($conexao, $plano, $idEmpresa, $recursoPerfil, 1);
        if (($resultadoPerfil['ok'] ?? false) !== true) {
            return $resultadoPerfil;
        }
    }

    $stmt = $conexao->prepare("UPDATE empresa_usuario SET bloqueado_plano = 0 WHERE id_empresa_usuario = ? AND id_empresa = ? AND bloqueado_plano = 1 AND status = 'ativo' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar reativação do vínculo pelo plano.');
    }
    $stmt->bind_param('ii', $idEmpresaUsuario, $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao reativar vínculo pelo plano: ' . $erro);
    }
    $alterados = $stmt->affected_rows;
    $stmt->close();
    if ($alterados !== 1) {
        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_REACTIVATION_STALE',
            'user_msg' => 'O vínculo foi alterado. Atualize a lista e tente novamente.',
        ];
    }

    return [
        'ok' => true,
        'data' => [
            'id_empresa_usuario' => (int)$vinculo['id_empresa_usuario'],
            'id_usuario' => (int)$vinculo['id_usuario'],
            'nome' => (string)$vinculo['nome'],
            'perfil' => (string)$vinculo['perfil_nome'],
            'bloqueado_plano_anterior' => 1,
            'bloqueado_plano_novo' => 0,
        ],
    ];
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
 * Planeja o downgrade sem escolher automaticamente entre usuários quando o
 * novo limite continua maior que zero. A função não altera dados.
 */
function limitesPlanoPlanejarDowngradeUsuarios(array $usuarios, array $plano): array
{
    $limites = [
        'usuarios' => max(0, (int)($plano['limite_usuarios'] ?? 0)),
        'profissionais' => max(0, (int)($plano['limite_profissionais'] ?? 0)),
        'administrativos' => max(0, (int)($plano['limite_usuarios'] ?? 0) - (int)($plano['limite_profissionais'] ?? 0)),
    ];
    $porPerfil = [
        'profissionais' => [],
        'administrativos' => [],
    ];
    $usuariosNormalizados = [];

    foreach ($usuarios as $usuario) {
        $idVinculo = (int)($usuario['id_empresa_usuario'] ?? 0);
        if ($idVinculo <= 0) {
            continue;
        }

        $perfil = limitesPlanoNormalizarPerfil((string)($usuario['perfil_nome'] ?? ''));
        $recursoLimite = in_array($perfil, ['proprietarios', 'recepcionistas'], true)
            ? 'administrativos'
            : $perfil;
        $item = [
            'id_empresa_usuario' => $idVinculo,
            'id_usuario' => (int)($usuario['id_usuario'] ?? 0),
            'nome' => trim((string)($usuario['nome'] ?? '')),
            'perfil' => $perfil,
            'recurso_limite' => $recursoLimite,
        ];
        $usuariosNormalizados[] = $item;
        if (isset($porPerfil[$recursoLimite])) {
            $porPerfil[$recursoLimite][] = $item;
        }
    }

    $consumo = [
        'usuarios' => count($usuariosNormalizados),
        'profissionais' => count($porPerfil['profissionais']),
        'administrativos' => count($porPerfil['administrativos']),
    ];
    $bloquear = [];
    $excessosSelecao = [];

    foreach (['profissionais', 'administrativos'] as $recurso) {
        $limite = $limites[$recurso];
        $total = $consumo[$recurso];
        if ($total <= $limite) {
            continue;
        }

        if ($limite === 0) {
            foreach ($porPerfil[$recurso] as $usuario) {
                $bloquear[$usuario['id_empresa_usuario']] = true;
            }
            continue;
        }

        $excessosSelecao[$recurso] = [
            'recurso' => $recurso,
            'limite' => $limite,
            'consumo_atual' => $total,
            'excedente' => $total - $limite,
        ];
    }

    if ($limites['usuarios'] === 0 && $consumo['usuarios'] > 0) {
        foreach ($usuariosNormalizados as $usuario) {
            $bloquear[$usuario['id_empresa_usuario']] = true;
        }
        // Não existe escolha quando o plano não permite nenhum usuário.
        $excessosSelecao = [];
    } else {
        $totalAposBloqueiosDeterministicos = 0;
        foreach ($usuariosNormalizados as $usuario) {
            if (!isset($bloquear[$usuario['id_empresa_usuario']])) {
                $totalAposBloqueiosDeterministicos++;
            }
        }
        if ($totalAposBloqueiosDeterministicos > $limites['usuarios']) {
            $excessosSelecao['usuarios'] = [
                'recurso' => 'usuarios',
                'limite' => $limites['usuarios'],
                'consumo_atual' => $totalAposBloqueiosDeterministicos,
                'excedente' => $totalAposBloqueiosDeterministicos - $limites['usuarios'],
            ];
        }
    }

    if ($excessosSelecao !== []) {
        $candidatos = array_values(array_filter(
            $usuariosNormalizados,
            static function (array $usuario) use ($bloquear, $excessosSelecao): bool {
                if (isset($bloquear[$usuario['id_empresa_usuario']])) {
                    return false;
                }
                return isset($excessosSelecao['usuarios']) || isset($excessosSelecao[$usuario['recurso_limite']]);
            }
        ));
        if (isset($excessosSelecao['usuarios'])) {
            $capacidadePorPerfil = [
                'profissionais' => 0,
                'administrativos' => 0,
            ];
            $capacidadeSemLimiteEspecifico = 0;
            foreach ($candidatos as $usuario) {
                if (isset($capacidadePorPerfil[$usuario['recurso_limite']])) {
                    $capacidadePorPerfil[$usuario['recurso_limite']]++;
                } else {
                    $capacidadeSemLimiteEspecifico++;
                }
            }
            $capacidade = $capacidadeSemLimiteEspecifico;
            foreach ($capacidadePorPerfil as $recurso => $quantidade) {
                $capacidade += min($quantidade, $limites[$recurso]);
            }
            $quantidadeSelecao = min($limites['usuarios'], $capacidade);
        } else {
            $quantidadeSelecao = 0;
            foreach ($excessosSelecao as $recurso => $excesso) {
                $quantidadeSelecao += $limites[$recurso];
            }
        }

        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_USER_SELECTION_REQUIRED',
            'user_msg' => 'O novo plano exige selecionar quais usuários permanecerão com acesso.',
            'data' => [
                'limites' => $limites,
                'consumo_atual' => $consumo,
                'excessos' => array_values($excessosSelecao),
                'usuarios_para_selecao' => $candidatos,
                'quantidade_selecao_necessaria' => $quantidadeSelecao,
                'bloqueios_deterministicos' => array_map('intval', array_keys($bloquear)),
            ],
        ];
    }

    return [
        'ok' => true,
        'bloquear_ids' => array_map('intval', array_keys($bloquear)),
        'data' => [
            'limites' => $limites,
            'consumo_atual' => $consumo,
            'quantidade_bloqueada' => count($bloquear),
        ],
    ];
}

/** Valida a proposta do navegador contra o planejamento recalculado. */
function limitesPlanoValidarSelecaoDowngradeUsuarios(array $usuarios, array $plano, array $idsPermanentes): array
{
    $planejamento = limitesPlanoPlanejarDowngradeUsuarios($usuarios, $plano);
    if (($planejamento['code'] ?? '') !== 'PLAN_USER_SELECTION_REQUIRED') {
        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_USER_SELECTION_STALE',
            'user_msg' => 'Os limites mudaram. Revise novamente a troca de plano.',
        ];
    }

    $dados = $planejamento['data'];
    $candidatos = [];
    foreach ($dados['usuarios_para_selecao'] as $usuario) {
        $candidatos[(int)$usuario['id_empresa_usuario']] = $usuario;
    }

    $selecionados = [];
    foreach ($idsPermanentes as $idVinculo) {
        if (!(is_int($idVinculo) || (is_string($idVinculo) && preg_match('/^\d+$/', $idVinculo)))) {
            return [
                'ok' => false,
                'http_status' => 422,
                'code' => 'PLAN_USER_SELECTION_INVALID',
                'user_msg' => 'A seleção de usuários é inválida.',
                'data' => $dados,
            ];
        }
        $idVinculo = (int)$idVinculo;
        if ($idVinculo <= 0 || isset($selecionados[$idVinculo]) || !isset($candidatos[$idVinculo])) {
            return [
                'ok' => false,
                'http_status' => 422,
                'code' => 'PLAN_USER_SELECTION_INVALID',
                'user_msg' => 'A seleção contém um vínculo inválido ou não elegível.',
                'data' => $dados,
            ];
        }
        $selecionados[$idVinculo] = true;
    }

    $quantidadeNecessaria = (int)($dados['quantidade_selecao_necessaria'] ?? 0);
    if (count($selecionados) !== $quantidadeNecessaria) {
        return [
            'ok' => false,
            'http_status' => 422,
            'code' => 'PLAN_USER_SELECTION_INVALID',
            'user_msg' => "Selecione exatamente {$quantidadeNecessaria} usuários para permanecer com acesso.",
            'data' => $dados,
        ];
    }

    $deterministicos = array_fill_keys(array_map('intval', $dados['bloqueios_deterministicos'] ?? []), true);
    $contagemFinal = [
        'usuarios' => 0,
        'profissionais' => 0,
        'administrativos' => 0,
    ];
    foreach ($usuarios as $usuario) {
        $idVinculo = (int)($usuario['id_empresa_usuario'] ?? 0);
        if ($idVinculo <= 0 || isset($deterministicos[$idVinculo])) {
            continue;
        }
        if (isset($candidatos[$idVinculo]) && !isset($selecionados[$idVinculo])) {
            continue;
        }
        $contagemFinal['usuarios']++;
        $perfil = limitesPlanoNormalizarPerfil((string)($usuario['perfil_nome'] ?? ''));
        $recursoLimite = in_array($perfil, ['proprietarios', 'recepcionistas'], true)
            ? 'administrativos'
            : $perfil;
        if (isset($contagemFinal[$recursoLimite])) {
            $contagemFinal[$recursoLimite]++;
        }
    }

    foreach ($contagemFinal as $recurso => $quantidade) {
        if ($quantidade > (int)($dados['limites'][$recurso] ?? 0)) {
            return [
                'ok' => false,
                'http_status' => 422,
                'code' => 'PLAN_USER_SELECTION_INVALID',
                'user_msg' => 'A seleção não respeita todos os limites do novo plano.',
                'data' => $dados,
            ];
        }
    }

    $bloquear = $deterministicos;
    foreach ($candidatos as $idVinculo => $usuario) {
        if (!isset($selecionados[$idVinculo])) {
            $bloquear[$idVinculo] = true;
        }
    }

    return [
        'ok' => true,
        'bloquear_ids' => array_map('intval', array_keys($bloquear)),
        'data' => [
            'limites' => $dados['limites'],
            'consumo_atual' => $dados['consumo_atual'],
            'quantidade_bloqueada' => count($bloquear),
            'quantidade_permanente' => count($selecionados),
        ],
    ];
}

/**
 * Revalida e bloqueia empresa, plano e vínculos dentro da transação aberta
 * pelo chamador. Somente bloqueios determinísticos são persistidos.
 */
function limitesPlanoPrepararTrocaEmpresa(mysqli $conexao, int $idEmpresa, int $idPlanoNovo, ?array $idsPermanentes = null): array
{
    $stmt = $conexao->prepare('SELECT plano_id FROM empresa WHERE id_empresa = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar revalidação da empresa.');
    }
    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao revalidar empresa: ' . $erro);
    }
    $stmt->bind_result($idPlanoAtual);
    $empresaEncontrada = $stmt->fetch();
    $stmt->close();
    if (!$empresaEncontrada) {
        return [
            'ok' => false,
            'http_status' => 404,
            'code' => 'EMPRESA_NAO_ENCONTRADA',
            'user_msg' => 'Empresa não encontrada.',
        ];
    }

    $stmt = $conexao->prepare("SELECT id_plano, nome AS plano_nome, status AS plano_status, limite_usuarios, limite_proprietarios, limite_profissionais, limite_recepcionistas FROM plano WHERE id_plano = ? LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar revalidação do novo plano.');
    }
    $stmt->bind_param('i', $idPlanoNovo);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao revalidar novo plano: ' . $erro);
    }
    $res = $stmt->get_result();
    $plano = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();
    if (!$plano) {
        return [
            'ok' => false,
            'http_status' => 404,
            'code' => 'PLANO_NAO_ENCONTRADO',
            'user_msg' => 'Plano não encontrado.',
        ];
    }
    if (($plano['plano_status'] ?? '') !== 'ativo') {
        return [
            'ok' => false,
            'http_status' => 422,
            'code' => 'PLANO_INATIVO',
            'user_msg' => 'O plano informado não está ativo.',
        ];
    }

    if ((int)$idPlanoAtual === $idPlanoNovo) {
        return ['ok' => true, 'data' => ['quantidade_bloqueada' => 0]];
    }

    $sqlUsuarios = "
        SELECT eu.id_empresa_usuario, eu.id_usuario, u.nome, pf.nome AS perfil_nome
        FROM empresa_usuario eu
        INNER JOIN usuario u ON u.id_usuario = eu.id_usuario
        INNER JOIN perfil pf ON pf.id_perfil = eu.id_perfil
        WHERE eu.id_empresa = ?
          AND eu.status IN ('ativo', 'bloqueado')
          AND eu.bloqueado_plano = 0
          AND u.status IN ('ativo', 'bloqueado')
          AND u.tipo_usuario <> 'super_admin'
        FOR UPDATE
    ";
    $stmt = $conexao->prepare($sqlUsuarios);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar vínculos para o downgrade.');
    }
    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $erro = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Falha ao carregar vínculos para o downgrade: ' . $erro);
    }
    $res = $stmt->get_result();
    $usuarios = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    $resultado = limitesPlanoPlanejarDowngradeUsuarios($usuarios, $plano);
    if (($resultado['ok'] ?? false) !== true) {
        if ($idsPermanentes === null) {
            return $resultado;
        }
        $resultado = limitesPlanoValidarSelecaoDowngradeUsuarios($usuarios, $plano, $idsPermanentes);
        if (($resultado['ok'] ?? false) !== true) {
            return $resultado;
        }
    } elseif ($idsPermanentes !== null) {
        return [
            'ok' => false,
            'http_status' => 409,
            'code' => 'PLAN_USER_SELECTION_STALE',
            'user_msg' => 'Os limites mudaram. Revise novamente a troca de plano.',
        ];
    }

    $idsBloquear = $resultado['bloquear_ids'] ?? [];
    if ($idsBloquear !== []) {
        $stmt = $conexao->prepare('UPDATE empresa_usuario SET bloqueado_plano = 1 WHERE id_empresa = ? AND id_empresa_usuario = ? AND bloqueado_plano = 0');
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar bloqueio de vínculos excedentes.');
        }
        foreach ($idsBloquear as $idVinculo) {
            $idVinculo = (int)$idVinculo;
            $stmt->bind_param('ii', $idEmpresa, $idVinculo);
            if (!$stmt->execute()) {
                $erro = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Falha ao bloquear vínculo excedente: ' . $erro);
            }
        }
        $stmt->close();
    }

    return $resultado;
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
