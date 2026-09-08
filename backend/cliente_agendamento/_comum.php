<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../cliente/_sessao_cliente.php';
require_once __DIR__ . '/../_config/conexao.php';

const CLIENTE_AGENDAMENTO_JANELA_DIAS = 90;
const CLIENTE_AGENDAMENTO_FOTO_PADRAO = '/public/imagens/avatar-default.png';

function clienteAgendamentoMetodo(string $esperado): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $esperado) {
        out(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'user_msg' => 'Método não permitido.'], 405);
    }
}

function clienteAgendamentoId(mixed $valor): int
{
    if (!is_scalar($valor) || preg_match('/^[1-9]\d*$/', trim((string)$valor)) !== 1) {
        return 0;
    }
    return (int)$valor;
}

function clienteAgendamentoData(string $valor): ?DateTimeImmutable
{
    $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $data && $data->format('Y-m-d') === $valor ? $data : null;
}

function clienteAgendamentoContexto(mysqli $conexao, bool $exigirCliente = false): array
{
    $sessao = exigirSessaoCliente();
    $idEmpresa = (int)$sessao['id_empresa'];

    $stmt = $conexao->prepare("SELECT id_empresa FROM empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a validação da empresa.');
    }
    $stmt->bind_param('i', $idEmpresa);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao validar a empresa.');
    }
    $stmt->store_result();
    $empresaAtiva = $stmt->num_rows === 1;
    $stmt->close();

    if (!$empresaAtiva) {
        out(['ok' => false, 'code' => 'CLIENT_COMPANY_ACCESS_DENIED', 'user_msg' => 'A empresa não está disponível para agendamentos.'], 403);
    }

    $cliente = null;
    if ($exigirCliente) {
        $cliente = buscarClienteDaSessao($conexao, $sessao);
        if ($cliente === null || (int)($cliente['cadastro_completo'] ?? 0) !== 1) {
            out(['ok' => false, 'code' => 'CLIENT_PROFILE_REQUIRED', 'user_msg' => 'Complete seus dados cadastrais antes de agendar.'], 422);
        }
    }

    return ['sessao' => $sessao, 'id_empresa' => $idEmpresa, 'cliente' => $cliente];
}

function clienteAgendamentoCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = (string)($_SESSION['cliente_agendamento_csrf'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['cliente_agendamento_csrf'] = $token;
    }
    return $token;
}

function clienteAgendamentoValidarCsrf(): void
{
    $recebido = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? ''));
    $esperado = (string)($_SESSION['cliente_agendamento_csrf'] ?? '');
    if ($esperado === '' || $recebido === '' || !hash_equals($esperado, $recebido)) {
        out(['ok' => false, 'code' => 'CSRF_INVALID', 'user_msg' => 'Sua sessão de agendamento expirou. Atualize a página e tente novamente.'], 403);
    }
}

function clienteAgendamentoFotoUrl(?string $foto): string
{
    $foto = trim((string)$foto);
    if ($foto === '' || preg_match('#^/public/imagens/usuarios/[A-Za-z0-9._-]+$#', $foto) !== 1) {
        return CLIENTE_AGENDAMENTO_FOTO_PADRAO;
    }

    $raiz = dirname(__DIR__, 2);
    $caminho = $raiz . str_replace('/', DIRECTORY_SEPARATOR, $foto);
    return is_file($caminho) ? $foto : CLIENTE_AGENDAMENTO_FOTO_PADRAO;
}

function clienteAgendamentoServico(mysqli $conexao, int $idEmpresa, int $idProfissional, int $idServico, bool $bloquear = false): ?array
{
    $sufixoBloqueio = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare(
        "SELECT s.id_servico, s.nome, s.descricao, s.duracao_min, s.valor,
                p.id_profissional, u.nome AS profissional_nome
           FROM servico s
           INNER JOIN profissional p ON p.id_profissional = s.id_profissional
           INNER JOIN usuario u ON u.id_usuario = p.id_usuario AND u.status = 'ativo'
           INNER JOIN empresa_usuario eu
                   ON eu.id_usuario = p.id_usuario
                  AND eu.id_empresa = s.id_empresa
                  AND eu.status = 'ativo'
                  AND eu.bloqueado_plano = 0
           INNER JOIN perfil pf
                   ON pf.id_perfil = eu.id_perfil
                  AND pf.status = 'ativo'
                  AND LOWER(TRIM(pf.nome)) IN ('profissional', 'profissionais')
          WHERE s.id_empresa = ?
            AND s.id_profissional = ?
            AND s.id_servico = ?
            AND s.status = 'ativo'
          LIMIT 1" . $sufixoBloqueio
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a validação do serviço.');
    }
    $stmt->bind_param('iii', $idEmpresa, $idProfissional, $idServico);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao validar o serviço.');
    }
    $resultado = $stmt->get_result();
    $servico = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    $stmt->close();
    return $servico;
}

function clienteAgendamentoMinutos(string $hora): int
{
    [$h, $m] = array_map('intval', explode(':', substr($hora, 0, 5)));
    return ($h * 60) + $m;
}

function clienteAgendamentoHora(int $minutos): string
{
    return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
}

function clienteAgendamentoIntervalo(mysqli $conexao, int $idEmpresa, int $idProfissional): int
{
    $intervalo = 10;
    $usaPadraoGeral = 1;
    $stmt = $conexao->prepare("SELECT intervalo_padrao_min, usa_padrao_empresa FROM configuracao_geral_profissional WHERE id_empresa = ? AND id_profissional = ? AND status = 'ativo' LIMIT 1");
    if (!$stmt) throw new RuntimeException('Falha ao preparar o intervalo do profissional.');
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $stmt->bind_result($intervaloProfissional, $usaPadraoGeralDb);
    $temConfig = $stmt->fetch();
    if ($temConfig) $usaPadraoGeral = (int)$usaPadraoGeralDb;
    $stmt->close();

    if ($temConfig && $usaPadraoGeral === 0) {
        $intervalo = (int)$intervaloProfissional;
    } else {
        $stmt = $conexao->prepare("SELECT intervalo_padrao_min FROM configuracao_geral_empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
        if (!$stmt) throw new RuntimeException('Falha ao preparar o intervalo da empresa.');
        $stmt->bind_param('i', $idEmpresa);
        $stmt->execute();
        $stmt->bind_result($intervaloEmpresa);
        if ($stmt->fetch()) $intervalo = (int)$intervaloEmpresa;
        $stmt->close();
    }
    return $intervalo > 0 && $intervalo <= 240 ? $intervalo : 10;
}

function clienteAgendamentoExcecoes(mysqli $conexao, int $idEmpresa, int $idProfissional, string $data, bool $bloquear = false): array
{
    $sufixoBloqueio = $bloquear ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare(
        "SELECT tipo, TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(hora_fim, '%H:%i') AS hora_fim
           FROM excecao_agenda
          WHERE id_empresa = ?
            AND data_excecao = ?
            AND status = 'ativo'
            AND (id_profissional IS NULL OR id_profissional = ?)
          ORDER BY id_profissional IS NULL DESC, id_excecao_agenda ASC" . $sufixoBloqueio
    );
    if (!$stmt) throw new RuntimeException('Falha ao preparar as exceções da agenda.');
    $stmt->bind_param('isi', $idEmpresa, $data, $idProfissional);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar as exceções da agenda.');
    }
    $resultado = $stmt->get_result();
    $excecoes = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) {
        $excecoes[] = [
            'tipo' => (string)$linha['tipo'],
            'hora_inicio' => $linha['hora_inicio'] === null ? null : (string)$linha['hora_inicio'],
            'hora_fim' => $linha['hora_fim'] === null ? null : (string)$linha['hora_fim'],
        ];
    }
    $stmt->close();
    return $excecoes;
}

function clienteAgendamentoGrade(mysqli $conexao, int $idEmpresa, int $idProfissional, DateTimeImmutable $data): ?array
{
    $dias = [1 => 'segunda', 2 => 'terca', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sabado', 7 => 'domingo'];
    $diaSemana = $dias[(int)$data->format('N')];

    $usaPadraoHorario = 1;
    $stmt = $conexao->prepare('SELECT usa_padrao_empresa FROM horario_profissional WHERE id_empresa = ? AND id_profissional = ? ORDER BY id_horario_profissional ASC LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a origem dos horários.');
    }
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $stmt->bind_result($usaPadraoHorarioDb);
    if ($stmt->fetch()) {
        $usaPadraoHorario = (int)$usaPadraoHorarioDb;
    }
    $stmt->close();

    if ($usaPadraoHorario === 0) {
        $stmt = $conexao->prepare("SELECT hora_inicio, hora_fim, almoco_inicio, almoco_fim, disponivel, status FROM horario_profissional WHERE id_empresa = ? AND id_profissional = ? AND dia_semana = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar o horário do profissional.');
        }
        $stmt->bind_param('iis', $idEmpresa, $idProfissional, $diaSemana);
        $origem = 'horario_profissional';
    } else {
        $stmt = $conexao->prepare("SELECT hora_inicio, hora_fim, almoco_inicio, almoco_fim, disponivel, status FROM horario_empresa WHERE id_empresa = ? AND dia_semana = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar o horário da empresa.');
        }
        $stmt->bind_param('is', $idEmpresa, $diaSemana);
        $origem = 'horario_empresa';
    }

    $stmt->execute();
    $stmt->bind_result($horaInicio, $horaFim, $almocoInicio, $almocoFim, $disponivel, $status);
    $encontrado = $stmt->fetch();
    $stmt->close();
    if (!$encontrado || (int)$disponivel !== 1 || $status !== 'ativo' || !$horaInicio || !$horaFim) {
        return null;
    }

    $intervalo = 10;
    $usaPadraoGeral = 1;
    $stmt = $conexao->prepare("SELECT intervalo_padrao_min, usa_padrao_empresa FROM configuracao_geral_profissional WHERE id_empresa = ? AND id_profissional = ? AND status = 'ativo' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar a configuração do profissional.');
    }
    $stmt->bind_param('ii', $idEmpresa, $idProfissional);
    $stmt->execute();
    $stmt->bind_result($intervaloProfissional, $usaPadraoGeralDb);
    $temConfigProfissional = $stmt->fetch();
    if ($temConfigProfissional) {
        $usaPadraoGeral = (int)$usaPadraoGeralDb;
    }
    $stmt->close();

    if ($temConfigProfissional && $usaPadraoGeral === 0) {
        $intervalo = (int)$intervaloProfissional;
    } else {
        $stmt = $conexao->prepare("SELECT intervalo_padrao_min FROM configuracao_geral_empresa WHERE id_empresa = ? AND status = 'ativo' LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar a configuração da empresa.');
        }
        $stmt->bind_param('i', $idEmpresa);
        $stmt->execute();
        $stmt->bind_result($intervaloEmpresa);
        if ($stmt->fetch()) {
            $intervalo = (int)$intervaloEmpresa;
        }
        $stmt->close();
    }
    if ($intervalo <= 0 || $intervalo > 240) {
        $intervalo = 10;
    }

    return [
        'dia_semana' => $diaSemana,
        'origem' => $origem,
        'hora_inicio' => (string)$horaInicio,
        'hora_fim' => (string)$horaFim,
        'almoco_inicio' => $almocoInicio ? (string)$almocoInicio : null,
        'almoco_fim' => $almocoFim ? (string)$almocoFim : null,
        'intervalo_min' => $intervalo,
    ];
}

function clienteAgendamentoHorarios(mysqli $conexao, int $idEmpresa, array $servico, DateTimeImmutable $data, bool $bloquearConflitos = false): array
{
    $hoje = new DateTimeImmutable('today');
    $maxima = $hoje->modify('+' . CLIENTE_AGENDAMENTO_JANELA_DIAS . ' days');
    if ($data < $hoje || $data > $maxima) {
        return [];
    }

    $idProfissional = (int)$servico['id_profissional'];
    $duracao = (int)$servico['duracao_min'];
    if ($duracao <= 0 || $duracao > 720) {
        return [];
    }

    $dataTexto = $data->format('Y-m-d');
    $excecoes = clienteAgendamentoExcecoes($conexao, $idEmpresa, $idProfissional, $dataTexto, $bloquearConflitos);
    $bloqueios = [];
    $janelasEspeciais = [];
    $temHorarioEspecial = false;
    foreach ($excecoes as $excecao) {
        $tipo = $excecao['tipo'];
        if (in_array($tipo, ['folga', 'feriado'], true)) return [];
        $temIntervaloValido = $excecao['hora_inicio'] !== null
            && $excecao['hora_fim'] !== null
            && clienteAgendamentoMinutos($excecao['hora_inicio']) < clienteAgendamentoMinutos($excecao['hora_fim']);
        if ($tipo === 'bloqueio') {
            if (!$temIntervaloValido) return [];
            $bloqueios[] = [
                'inicio' => clienteAgendamentoMinutos($excecao['hora_inicio']),
                'fim' => clienteAgendamentoMinutos($excecao['hora_fim']),
            ];
        } elseif ($tipo === 'horario_especial') {
            $temHorarioEspecial = true;
            if ($temIntervaloValido) {
                $janelasEspeciais[] = [
                    'inicio' => clienteAgendamentoMinutos($excecao['hora_inicio']),
                    'fim' => clienteAgendamentoMinutos($excecao['hora_fim']),
                ];
            }
        }
    }
    if ($temHorarioEspecial && $janelasEspeciais === []) return [];

    $grade = clienteAgendamentoGrade($conexao, $idEmpresa, $idProfissional, $data);
    if ($temHorarioEspecial) {
        $inicios = array_column($janelasEspeciais, 'inicio');
        $fins = array_column($janelasEspeciais, 'fim');
        $grade = [
            'dia_semana' => $data->format('N'),
            'origem' => 'excecao_agenda',
            'hora_inicio' => clienteAgendamentoHora(min($inicios)),
            'hora_fim' => clienteAgendamentoHora(max($fins)),
            'almoco_inicio' => null,
            'almoco_fim' => null,
            'intervalo_min' => clienteAgendamentoIntervalo($conexao, $idEmpresa, $idProfissional),
        ];
    }
    if ($grade === null) return [];

    $sufixoBloqueio = $bloquearConflitos ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare(
        "SELECT TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
                TIME_FORMAT(hora_fim, '%H:%i') AS hora_fim
           FROM agendamento
          WHERE id_empresa = ?
            AND id_profissional = ?
            AND data_agendamento = ?
            AND status IN ('pendente', 'confirmado')" . $sufixoBloqueio
    );
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar os conflitos da agenda.');
    }
    $stmt->bind_param('iis', $idEmpresa, $idProfissional, $dataTexto);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Falha ao consultar os conflitos da agenda.');
    }
    $resultado = $stmt->get_result();
    $ocupados = [];
    while ($resultado && ($linha = $resultado->fetch_assoc())) {
        $ocupados[] = [
            'inicio' => clienteAgendamentoMinutos((string)$linha['hora_inicio']),
            'fim' => clienteAgendamentoMinutos((string)$linha['hora_fim']),
        ];
    }
    $stmt->close();

    $inicioExpediente = clienteAgendamentoMinutos($grade['hora_inicio']);
    $fimExpediente = clienteAgendamentoMinutos($grade['hora_fim']);
    $inicioAlmoco = $grade['almoco_inicio'] ? clienteAgendamentoMinutos($grade['almoco_inicio']) : null;
    $fimAlmoco = $grade['almoco_fim'] ? clienteAgendamentoMinutos($grade['almoco_fim']) : null;
    $intervalo = (int)$grade['intervalo_min'];
    $agora = new DateTimeImmutable('now');
    $minutoAtual = ((int)$agora->format('H') * 60) + (int)$agora->format('i');
    $ehHoje = $dataTexto === $hoje->format('Y-m-d');
    $horarios = [];

    for ($inicio = $inicioExpediente; $inicio + $duracao + $intervalo <= $fimExpediente; $inicio += $intervalo) {
        if ($ehHoje && $inicio <= $minutoAtual) {
            continue;
        }
        $fimServico = $inicio + $duracao;
        $fimBloqueado = $fimServico + $intervalo;
        if ($janelasEspeciais !== []) {
            $dentroDeJanela = false;
            foreach ($janelasEspeciais as $janela) {
                if ($inicio >= $janela['inicio'] && $fimBloqueado <= $janela['fim']) {
                    $dentroDeJanela = true;
                    break;
                }
            }
            if (!$dentroDeJanela) continue;
        }
        if ($inicioAlmoco !== null && $fimAlmoco !== null && $inicio < $fimAlmoco && $fimBloqueado > $inicioAlmoco) {
            continue;
        }

        foreach ($bloqueios as $bloqueio) {
            if ($inicio < $bloqueio['fim'] && $fimBloqueado > $bloqueio['inicio']) {
                continue 2;
            }
        }

        $conflito = false;
        foreach ($ocupados as $ocupado) {
            $fimOcupadoComIntervalo = $ocupado['fim'] + $intervalo;
            if ($inicio < $fimOcupadoComIntervalo && $fimBloqueado > $ocupado['inicio']) {
                $conflito = true;
                break;
            }
        }
        if (!$conflito) {
            $horarios[] = [
                'hora_inicio' => clienteAgendamentoHora($inicio),
                'hora_fim' => clienteAgendamentoHora($fimServico),
            ];
        }
    }

    return $horarios;
}

function clienteAgendamentoLiberarLock(mysqli $conexao, ?string $nomeLock): void
{
    if ($nomeLock === null || $nomeLock === '') {
        return;
    }
    try {
        $stmt = $conexao->prepare('SELECT RELEASE_LOCK(?)');
        if ($stmt) {
            $stmt->bind_param('s', $nomeLock);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $ignorado) {
    }
}
