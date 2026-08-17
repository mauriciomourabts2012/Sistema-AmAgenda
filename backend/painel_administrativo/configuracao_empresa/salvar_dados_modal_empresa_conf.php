<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÃO DE RETORNO JSON
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/*
|--------------------------------------------------------------------------
| LOG DE ERROS
|--------------------------------------------------------------------------
| Em produção, não exibe erro na tela.
| Salva os erros no arquivo php_errors.log.
*/
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

/*
|--------------------------------------------------------------------------
| FUNÇÃO PADRÃO DE SAÍDA JSON
|--------------------------------------------------------------------------
*/
if (!function_exists('out')) {
    function out(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| BUSCA ID DA EMPRESA NA SESSÃO
|--------------------------------------------------------------------------
*/
if (!function_exists('getIdEmpresaFromSession')) {
    function getIdEmpresaFromSession(): int
    {
        $candidatos = [
            $_SESSION['auth']['id_empresa'] ?? null,
            $_SESSION['empresa_id'] ?? null,
            $_SESSION['id_empresa'] ?? null,
            $_SESSION['empresa']['id_empresa'] ?? null,
            $_SESSION['empresa']['id'] ?? null,
        ];

        foreach ($candidatos as $valor) {
            if (filter_var($valor, FILTER_VALIDATE_INT) !== false && (int)$valor > 0) {
                return (int)$valor;
            }
        }

        return 0;
    }
}

/*
|--------------------------------------------------------------------------
| LÊ DADOS ENVIADOS PELO JS
|--------------------------------------------------------------------------
| Aceita JSON e POST normal.
*/
if (!function_exists('readInputData')) {
    function readInputData(): array
    {
        $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));

        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');

            if (!is_string($raw) || trim($raw) === '') {
                return [];
            }

            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST ?? [];
    }
}

/*
|--------------------------------------------------------------------------
| PEGA VALOR TEXTO COM SEGURANÇA
|--------------------------------------------------------------------------
*/
if (!function_exists('valorTexto')) {
    function valorTexto(array $data, string $chave, string $default = ''): string
    {
        $valor = $data[$chave] ?? $default;

        if (is_array($valor)) {
            return $default;
        }

        return trim((string)$valor);
    }
}

/*
|--------------------------------------------------------------------------
| NORMALIZA HORA PARA SALVAR NO BANCO
|--------------------------------------------------------------------------
| HTML input time envia HH:MM.
| MySQL TIME aceita HH:MM:SS.
*/
if (!function_exists('normalizarHoraBanco')) {
    function normalizarHoraBanco(?string $valor): ?string
    {
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor . ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return null;
    }
}

/*
|--------------------------------------------------------------------------
| RETORNA HORA CURTA PARA O JS
|--------------------------------------------------------------------------
*/
if (!function_exists('horaCurta')) {
    function horaCurta(?string $valor): string
    {
        $valor = trim((string)($valor ?? ''));

        if ($valor === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $valor)) {
            return substr($valor, 0, 5);
        }

        if (preg_match('/^\d{2}:\d{2}$/', $valor)) {
            return $valor;
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| DIAS OFICIAIS DO BANCO
|--------------------------------------------------------------------------
*/
if (!function_exists('diasSemanaBanco')) {
    function diasSemanaBanco(): array
    {
        return [
            'segunda',
            'terca',
            'quarta',
            'quinta',
            'sexta',
            'sabado',
            'domingo',
        ];
    }
}

/*
|--------------------------------------------------------------------------
| MAPEAR INÍCIO DA SEMANA
|--------------------------------------------------------------------------
*/
if (!function_exists('mapearInicioSemana')) {
    function mapearInicioSemana(string $valor): string
    {
        $valor = strtolower(trim($valor));

        $permitidos = diasSemanaBanco();

        if (in_array($valor, $permitidos, true)) {
            return $valor;
        }

        if ($valor === '0') {
            return 'domingo';
        }

        return 'segunda';
    }
}

/*
|--------------------------------------------------------------------------
| COMPARAÇÃO DE HORA
|--------------------------------------------------------------------------
*/
if (!function_exists('horaTimestamp')) {
    function horaTimestamp(string $hora): int|false
    {
        return strtotime('1970-01-01 ' . $hora);
    }
}

/*
|--------------------------------------------------------------------------
| PERMITE SOMENTE POST
|--------------------------------------------------------------------------
*/
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    out([
        'ok' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'user_msg' => 'Método não permitido.',
    ], 405);
}

/*
|--------------------------------------------------------------------------
| INICIA SESSÃO
|--------------------------------------------------------------------------
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| CONEXÃO COM BANCO
|--------------------------------------------------------------------------
| Ajuste o caminho se o seu arquivo de conexão estiver em outro local.
*/
require_once __DIR__ . '/../../_config/conexao.php';

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_MISSING',
        'user_msg' => 'Conexão com banco não encontrada.',
    ], 500);
}

if ($conexao->connect_errno) {
    out([
        'ok' => false,
        'code' => 'DB_CONN_ERROR',
        'user_msg' => 'Falha ao conectar no banco.',
    ], 500);
}

$conexao->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| VALIDA EMPRESA DA SESSÃO
|--------------------------------------------------------------------------
*/
$idEmpresa = getIdEmpresaFromSession();

if ($idEmpresa <= 0) {
    out([
        'ok' => false,
        'code' => 'INVALID_COMPANY',
        'user_msg' => 'Empresa da sessão não encontrada.',
    ], 401);
}

/*
|--------------------------------------------------------------------------
| LÊ DADOS DO REQUEST
|--------------------------------------------------------------------------
*/
$data = readInputData();
$aba = valorTexto($data, 'aba');

$abasPermitidas = [
    'cfg-geral',
    'cfg-horarios',
    'cfg-whatsapp',
];

if ($aba === '' || !in_array($aba, $abasPermitidas, true)) {
    out([
        'ok' => false,
        'code' => 'INVALID_TAB',
        'user_msg' => 'Aba de configuração inválida.',
    ], 422);
}

$fieldErrors = [];

try {
    /*
    |--------------------------------------------------------------------------
    | VALIDA SE EMPRESA EXISTE E ESTÁ ATIVA
    |--------------------------------------------------------------------------
    */
    $sqlEmpresa = "
        SELECT id_empresa, nome, status
        FROM empresa
        WHERE id_empresa = ?
        LIMIT 1
    ";

    $stmtEmpresa = $conexao->prepare($sqlEmpresa);

    if (!$stmtEmpresa) {
        throw new Exception('Falha ao preparar consulta da empresa.');
    }

    $stmtEmpresa->bind_param('i', $idEmpresa);
    $stmtEmpresa->execute();

    $resultEmpresa = $stmtEmpresa->get_result();
    $empresa = $resultEmpresa ? $resultEmpresa->fetch_assoc() : null;

    $stmtEmpresa->close();

    if (!$empresa) {
        out([
            'ok' => false,
            'code' => 'COMPANY_NOT_FOUND',
            'user_msg' => 'Empresa não encontrada.',
        ], 404);
    }

    if (($empresa['status'] ?? '') !== 'ativo') {
        out([
            'ok' => false,
            'code' => 'COMPANY_INACTIVE',
            'user_msg' => 'A empresa está inativa.',
        ], 403);
    }

    /*
    |--------------------------------------------------------------------------
    | INICIA TRANSAÇÃO
    |--------------------------------------------------------------------------
    */
    $conexao->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | ABA GERAL
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-geral') {
        $semanaInicioRaw = valorTexto($data, 'semana_inicio', 'segunda');
        $inicioSemana = mapearInicioSemana($semanaInicioRaw);

        $intervaloPadrao = valorTexto($data, 'intervalo_padrao', '10');
        $intervalosPermitidos = ['10', '15', '20', '30', '45', '60'];

        if (!in_array($intervaloPadrao, $intervalosPermitidos, true)) {
            $fieldErrors['cfg_intervalo_padrao'] = 'Informe um intervalo padrão válido.';
        }

        $intervaloPadraoMin = (int)$intervaloPadrao;
        $observacaoPadrao = valorTexto($data, 'observacao_padrao', '');

        if (mb_strlen($observacaoPadrao) > 3000) {
            $fieldErrors['cfg_obs_geral'] = 'A observação deve ter no máximo 3000 caracteres.';
        }

        if (!empty($fieldErrors)) {
            $conexao->rollback();

            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Verifique os campos destacados.',
                'field_errors' => $fieldErrors,
            ], 422);
        }

        $sqlExiste = "
            SELECT id_config
            FROM configuracao_geral_empresa
            WHERE id_empresa = ?
            LIMIT 1
        ";

        $stmtExiste = $conexao->prepare($sqlExiste);

        if (!$stmtExiste) {
            throw new Exception('Falha ao preparar verificação da configuração geral.');
        }

        $stmtExiste->bind_param('i', $idEmpresa);
        $stmtExiste->execute();

        $resultExiste = $stmtExiste->get_result();
        $registroExiste = $resultExiste ? $resultExiste->fetch_assoc() : null;

        $stmtExiste->close();

        if ($registroExiste) {
            $sqlUpdate = "
                UPDATE configuracao_geral_empresa
                SET
                    inicio_semana = ?,
                    intervalo_padrao_min = ?,
                    observacao_padrao = ?,
                    status = 'ativo'
                WHERE id_empresa = ?
                LIMIT 1
            ";

            $stmtUpdate = $conexao->prepare($sqlUpdate);

            if (!$stmtUpdate) {
                throw new Exception('Falha ao preparar atualização da configuração geral.');
            }

            $stmtUpdate->bind_param(
                'sisi',
                $inicioSemana,
                $intervaloPadraoMin,
                $observacaoPadrao,
                $idEmpresa
            );

            if (!$stmtUpdate->execute()) {
                throw new Exception('Falha ao atualizar a configuração geral.');
            }

            $stmtUpdate->close();
        } else {
            $sqlInsert = "
                INSERT INTO configuracao_geral_empresa
                (
                    id_empresa,
                    inicio_semana,
                    intervalo_padrao_min,
                    observacao_padrao,
                    status
                )
                VALUES (?, ?, ?, ?, 'ativo')
            ";

            $stmtInsert = $conexao->prepare($sqlInsert);

            if (!$stmtInsert) {
                throw new Exception('Falha ao preparar inserção da configuração geral.');
            }

            $stmtInsert->bind_param(
                'isis',
                $idEmpresa,
                $inicioSemana,
                $intervaloPadraoMin,
                $observacaoPadrao
            );

            if (!$stmtInsert->execute()) {
                throw new Exception('Falha ao inserir a configuração geral.');
            }

            $stmtInsert->close();
        }

        $conexao->commit();

        out([
            'ok' => true,
            'code' => 'CONFIG_GERAL_SAVED',
            'user_msg' => 'Configurações gerais salvas com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresa,
                'inicio_semana' => $inicioSemana,
                'semana_inicio' => $inicioSemana,
                'intervalo_padrao_min' => $intervaloPadraoMin,
                'observacao_padrao' => $observacaoPadrao,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | ABA HORÁRIOS
    |--------------------------------------------------------------------------
    | Nova estrutura esperada:
    |
    | horarios[segunda][ativo]
    | horarios[segunda][hora_inicio]
    | horarios[segunda][hora_fim]
    | horarios[segunda][almoco_inicio]
    | horarios[segunda][almoco_fim]
    |
    | Cada dia vira um registro na tabela horario_empresa.
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-horarios') {
        $horarios = $data['horarios'] ?? [];

        if (!is_array($horarios)) {
            $horarios = [];
        }

        $diasBanco = diasSemanaBanco();
        $horariosNormalizados = [];
        $algumDiaAtivo = false;

        foreach ($diasBanco as $diaSemana) {
            $linha = $horarios[$diaSemana] ?? [];

            if (!is_array($linha)) {
                $linha = [];
            }

            $ativo = isset($linha['ativo']) && (string)$linha['ativo'] === '1';

            $horaInicioRaw = trim((string)($linha['hora_inicio'] ?? ''));
            $horaFimRaw = trim((string)($linha['hora_fim'] ?? ''));
            $almocoInicioRaw = trim((string)($linha['almoco_inicio'] ?? ''));
            $almocoFimRaw = trim((string)($linha['almoco_fim'] ?? ''));

            $horaInicioDb = normalizarHoraBanco($horaInicioRaw);
            $horaFimDb = normalizarHoraBanco($horaFimRaw);
            $almocoInicioDb = normalizarHoraBanco($almocoInicioRaw);
            $almocoFimDb = normalizarHoraBanco($almocoFimRaw);

            /*
            |--------------------------------------------------------------------------
            | SE O DIA ESTIVER ATIVO, HORÁRIO INÍCIO/FIM É OBRIGATÓRIO
            |--------------------------------------------------------------------------
            */
            if ($ativo) {
                $algumDiaAtivo = true;

                if ($horaInicioRaw === '' || $horaInicioDb === null) {
                    $fieldErrors['horarios_empresa'] = 'Informe o horário inicial dos dias ativos.';
                }

                if ($horaFimRaw === '' || $horaFimDb === null) {
                    $fieldErrors['horarios_empresa'] = 'Informe o horário final dos dias ativos.';
                }

                if ($horaInicioDb !== null && $horaFimDb !== null) {
                    if (horaTimestamp($horaInicioDb) >= horaTimestamp($horaFimDb)) {
                        $fieldErrors['horarios_empresa'] = 'O horário final deve ser maior que o horário inicial.';
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | ALMOÇO É OPCIONAL, MAS SE INFORMAR UM, PRECISA INFORMAR O OUTRO
                |--------------------------------------------------------------------------
                */
                $temAlmocoInicio = $almocoInicioRaw !== '';
                $temAlmocoFim = $almocoFimRaw !== '';

                if ($temAlmocoInicio xor $temAlmocoFim) {
                    $fieldErrors['horarios_empresa'] = 'Informe início e fim do almoço nos dias ativos.';
                }

                if ($temAlmocoInicio && $almocoInicioDb === null) {
                    $fieldErrors['horarios_empresa'] = 'Informe um horário válido para início do almoço.';
                }

                if ($temAlmocoFim && $almocoFimDb === null) {
                    $fieldErrors['horarios_empresa'] = 'Informe um horário válido para fim do almoço.';
                }

                if ($almocoInicioDb !== null && $almocoFimDb !== null) {
                    if (horaTimestamp($almocoInicioDb) >= horaTimestamp($almocoFimDb)) {
                        $fieldErrors['horarios_empresa'] = 'O fim do almoço deve ser maior que o início.';
                    }

                    if ($horaInicioDb !== null && $horaFimDb !== null) {
                        if (
                            horaTimestamp($almocoInicioDb) <= horaTimestamp($horaInicioDb)
                            || horaTimestamp($almocoFimDb) >= horaTimestamp($horaFimDb)
                        ) {
                            $fieldErrors['horarios_empresa'] = 'O almoço deve ficar dentro do horário de funcionamento.';
                        }
                    }
                }
            } else {
                /*
                |--------------------------------------------------------------------------
                | SE O DIA ESTIVER INATIVO, LIMPA OS HORÁRIOS
                |--------------------------------------------------------------------------
                */
                $horaInicioDb = null;
                $horaFimDb = null;
                $almocoInicioDb = null;
                $almocoFimDb = null;
            }

            $horariosNormalizados[$diaSemana] = [
                'dia_semana' => $diaSemana,
                'disponivel' => $ativo ? 1 : 0,
                'hora_inicio' => $horaInicioDb,
                'hora_fim' => $horaFimDb,
                'almoco_inicio' => $almocoInicioDb,
                'almoco_fim' => $almocoFimDb,
            ];
        }

        if (!$algumDiaAtivo) {
            $fieldErrors['horarios_empresa'] = 'Selecione pelo menos um dia ativo.';
        }

        if (!empty($fieldErrors)) {
            $conexao->rollback();

            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Verifique os campos destacados.',
                'field_errors' => $fieldErrors,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | BUSCA HORÁRIOS JÁ EXISTENTES
        |--------------------------------------------------------------------------
        */
        $sqlExistentes = "
            SELECT id_horario_empresa, dia_semana
            FROM horario_empresa
            WHERE id_empresa = ?
            ORDER BY id_horario_empresa ASC
        ";

        $stmtExistentes = $conexao->prepare($sqlExistentes);

        if (!$stmtExistentes) {
            throw new Exception('Falha ao preparar verificação dos horários existentes.');
        }

        $stmtExistentes->bind_param('i', $idEmpresa);
        $stmtExistentes->execute();

        $resultExistentes = $stmtExistentes->get_result();
        $existentes = [];

        if ($resultExistentes) {
            while ($row = $resultExistentes->fetch_assoc()) {
                $dia = (string)$row['dia_semana'];

                if (!isset($existentes[$dia])) {
                    $existentes[$dia] = (int)$row['id_horario_empresa'];
                }
            }
        }

        $stmtExistentes->close();

        /*
        |--------------------------------------------------------------------------
        | SALVA CADA DIA NO BANCO
        |--------------------------------------------------------------------------
        */
        foreach ($horariosNormalizados as $diaSemana => $item) {
            $horaInicioDb = $item['hora_inicio'];
            $horaFimDb = $item['hora_fim'];
            $almocoInicioDb = $item['almoco_inicio'];
            $almocoFimDb = $item['almoco_fim'];
            $disponivel = (int)$item['disponivel'];

            if (isset($existentes[$diaSemana])) {
                /*
                |--------------------------------------------------------------------------
                | ATUALIZA DIA EXISTENTE
                |--------------------------------------------------------------------------
                */
                $idHorarioEmpresa = (int)$existentes[$diaSemana];

                $sqlUpdateHorario = "
                    UPDATE horario_empresa
                    SET
                        hora_inicio = ?,
                        hora_fim = ?,
                        almoco_inicio = ?,
                        almoco_fim = ?,
                        disponivel = ?,
                        status = 'ativo'
                    WHERE id_horario_empresa = ?
                    LIMIT 1
                ";

                $stmtUpdateHorario = $conexao->prepare($sqlUpdateHorario);

                if (!$stmtUpdateHorario) {
                    throw new Exception('Falha ao preparar atualização do horário da empresa.');
                }

                $stmtUpdateHorario->bind_param(
                    'ssssii',
                    $horaInicioDb,
                    $horaFimDb,
                    $almocoInicioDb,
                    $almocoFimDb,
                    $disponivel,
                    $idHorarioEmpresa
                );

                if (!$stmtUpdateHorario->execute()) {
                    throw new Exception('Falha ao atualizar horário da empresa.');
                }

                $stmtUpdateHorario->close();
            } else {
                /*
                |--------------------------------------------------------------------------
                | INSERE DIA NOVO
                |--------------------------------------------------------------------------
                */
                $sqlInsertHorario = "
                    INSERT INTO horario_empresa
                    (
                        id_empresa,
                        dia_semana,
                        hora_inicio,
                        hora_fim,
                        almoco_inicio,
                        almoco_fim,
                        disponivel,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'ativo')
                ";

                $stmtInsertHorario = $conexao->prepare($sqlInsertHorario);

                if (!$stmtInsertHorario) {
                    throw new Exception('Falha ao preparar inserção do horário da empresa.');
                }

                $stmtInsertHorario->bind_param(
                    'isssssi',
                    $idEmpresa,
                    $diaSemana,
                    $horaInicioDb,
                    $horaFimDb,
                    $almocoInicioDb,
                    $almocoFimDb,
                    $disponivel
                );

                if (!$stmtInsertHorario->execute()) {
                    throw new Exception('Falha ao inserir horário da empresa.');
                }

                $stmtInsertHorario->close();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DEFINE INÍCIO DA SEMANA AUTOMATICAMENTE
        |--------------------------------------------------------------------------
        | Será o primeiro dia ativo seguindo a ordem:
        | segunda, terça, quarta, quinta, sexta, sábado, domingo.
        |--------------------------------------------------------------------------
        */
        $inicioSemanaAutomatico = 'segunda';

        foreach ($diasBanco as $diaSemana) {
            if (($horariosNormalizados[$diaSemana]['disponivel'] ?? 0) === 1) {
                $inicioSemanaAutomatico = $diaSemana;
                break;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ATUALIZA OU CRIA CONFIGURAÇÃO GERAL COM O INÍCIO DA SEMANA
        |--------------------------------------------------------------------------
        */
        $sqlExisteConfigGeral = "
            SELECT id_config
            FROM configuracao_geral_empresa
            WHERE id_empresa = ?
            LIMIT 1
        ";

        $stmtExisteConfigGeral = $conexao->prepare($sqlExisteConfigGeral);

        if (!$stmtExisteConfigGeral) {
            throw new Exception('Falha ao preparar verificação da configuração geral.');
        }

        $stmtExisteConfigGeral->bind_param('i', $idEmpresa);
        $stmtExisteConfigGeral->execute();

        $resultExisteConfigGeral = $stmtExisteConfigGeral->get_result();
        $configGeralExiste = $resultExisteConfigGeral ? $resultExisteConfigGeral->fetch_assoc() : null;

        $stmtExisteConfigGeral->close();

        if ($configGeralExiste) {
            $sqlUpdateInicioSemana = "
                UPDATE configuracao_geral_empresa
                SET
                    inicio_semana = ?,
                    status = 'ativo'
                WHERE id_empresa = ?
                LIMIT 1
            ";

            $stmtUpdateInicioSemana = $conexao->prepare($sqlUpdateInicioSemana);

            if (!$stmtUpdateInicioSemana) {
                throw new Exception('Falha ao preparar atualização do início da semana.');
            }

            $stmtUpdateInicioSemana->bind_param(
                'si',
                $inicioSemanaAutomatico,
                $idEmpresa
            );

            if (!$stmtUpdateInicioSemana->execute()) {
                throw new Exception('Falha ao atualizar início da semana.');
            }

            $stmtUpdateInicioSemana->close();
        } else {
            $intervaloPadraoDefault = 10;
            $observacaoPadraoDefault = '';

            $sqlInsertConfigGeral = "
                INSERT INTO configuracao_geral_empresa
                (
                    id_empresa,
                    inicio_semana,
                    intervalo_padrao_min,
                    observacao_padrao,
                    status
                )
                VALUES (?, ?, ?, ?, 'ativo')
            ";

            $stmtInsertConfigGeral = $conexao->prepare($sqlInsertConfigGeral);

            if (!$stmtInsertConfigGeral) {
                throw new Exception('Falha ao preparar criação da configuração geral.');
            }

            $stmtInsertConfigGeral->bind_param(
                'isis',
                $idEmpresa,
                $inicioSemanaAutomatico,
                $intervaloPadraoDefault,
                $observacaoPadraoDefault
            );

            if (!$stmtInsertConfigGeral->execute()) {
                throw new Exception('Falha ao criar configuração geral.');
            }

            $stmtInsertConfigGeral->close();
        }

        $conexao->commit();

        /*
        |--------------------------------------------------------------------------
        | MONTA RETORNO PARA O JS
        |--------------------------------------------------------------------------
        */
        $retornoHorarios = [];

        foreach ($horariosNormalizados as $diaSemana => $item) {
            $retornoHorarios[$diaSemana] = [
                'ativo' => (int)$item['disponivel'],
                'hora_inicio' => horaCurta($item['hora_inicio']),
                'hora_fim' => horaCurta($item['hora_fim']),
                'almoco_inicio' => horaCurta($item['almoco_inicio']),
                'almoco_fim' => horaCurta($item['almoco_fim']),
            ];
        }

        out([
            'ok' => true,
            'code' => 'CONFIG_HORARIOS_SAVED',
            'user_msg' => 'Horários da agenda salvos com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresa,
                'inicio_semana' => $inicioSemanaAutomatico,
                'semana_inicio' => $inicioSemanaAutomatico,
                'horarios' => $retornoHorarios,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | ABA WHATSAPP
    |--------------------------------------------------------------------------
    */
    if ($aba === 'cfg-whatsapp') {
        $dddPadrao = preg_replace('/\D+/', '', valorTexto($data, 'ddd_padrao'));
        $ddiPadrao = preg_replace('/\D+/', '', valorTexto($data, 'ddi_padrao', '55'));
        $mensagemPadrao = valorTexto($data, 'msg_whats', '');

        if ($ddiPadrao === '') {
            $fieldErrors['cfg_ddi_padrao'] = 'Informe o DDI.';
        } elseif (mb_strlen($ddiPadrao) < 1 || mb_strlen($ddiPadrao) > 5) {
            $fieldErrors['cfg_ddi_padrao'] = 'O DDI deve ter entre 1 e 5 dígitos.';
        }

        if ($dddPadrao !== '' && mb_strlen($dddPadrao) !== 2) {
            $fieldErrors['cfg_ddd_padrao'] = 'O DDD deve ter 2 dígitos.';
        }

        if (mb_strlen($mensagemPadrao) > 5000) {
            $fieldErrors['cfg_msg_whats'] = 'A mensagem padrão deve ter no máximo 5000 caracteres.';
        }

        if (!empty($fieldErrors)) {
            $conexao->rollback();

            out([
                'ok' => false,
                'code' => 'VALIDATION_ERROR',
                'user_msg' => 'Verifique os campos destacados.',
                'field_errors' => $fieldErrors,
            ], 422);
        }

        $sqlExisteWhatsapp = "
            SELECT id_config_whatsapp
            FROM configuracao_whatsapp_empresa
            WHERE id_empresa = ?
            LIMIT 1
        ";

        $stmtExisteWhatsapp = $conexao->prepare($sqlExisteWhatsapp);

        if (!$stmtExisteWhatsapp) {
            throw new Exception('Falha ao preparar verificação da configuração de WhatsApp.');
        }

        $stmtExisteWhatsapp->bind_param('i', $idEmpresa);
        $stmtExisteWhatsapp->execute();

        $resultExisteWhatsapp = $stmtExisteWhatsapp->get_result();
        $registroWhatsapp = $resultExisteWhatsapp ? $resultExisteWhatsapp->fetch_assoc() : null;

        $stmtExisteWhatsapp->close();

        if ($registroWhatsapp) {
            $sqlUpdateWhatsapp = "
                UPDATE configuracao_whatsapp_empresa
                SET
                    ddi_padrao = ?,
                    ddd_padrao = ?,
                    mensagem_padrao = ?,
                    status = 'ativo'
                WHERE id_empresa = ?
                LIMIT 1
            ";

            $stmtUpdateWhatsapp = $conexao->prepare($sqlUpdateWhatsapp);

            if (!$stmtUpdateWhatsapp) {
                throw new Exception('Falha ao preparar atualização da configuração de WhatsApp.');
            }

            $stmtUpdateWhatsapp->bind_param(
                'sssi',
                $ddiPadrao,
                $dddPadrao,
                $mensagemPadrao,
                $idEmpresa
            );

            if (!$stmtUpdateWhatsapp->execute()) {
                throw new Exception('Falha ao atualizar a configuração de WhatsApp.');
            }

            $stmtUpdateWhatsapp->close();
        } else {
            $sqlInsertWhatsapp = "
                INSERT INTO configuracao_whatsapp_empresa
                (
                    id_empresa,
                    ddi_padrao,
                    ddd_padrao,
                    mensagem_padrao,
                    status
                )
                VALUES (?, ?, ?, ?, 'ativo')
            ";

            $stmtInsertWhatsapp = $conexao->prepare($sqlInsertWhatsapp);

            if (!$stmtInsertWhatsapp) {
                throw new Exception('Falha ao preparar inserção da configuração de WhatsApp.');
            }

            $stmtInsertWhatsapp->bind_param(
                'isss',
                $idEmpresa,
                $ddiPadrao,
                $dddPadrao,
                $mensagemPadrao
            );

            if (!$stmtInsertWhatsapp->execute()) {
                throw new Exception('Falha ao inserir a configuração de WhatsApp.');
            }

            $stmtInsertWhatsapp->close();
        }

        $conexao->commit();

        out([
            'ok' => true,
            'code' => 'CONFIG_WHATSAPP_SAVED',
            'user_msg' => 'Configurações do WhatsApp salvas com sucesso.',
            'aba' => $aba,
            'data' => [
                'id_empresa' => $idEmpresa,
                'ddi_padrao' => $ddiPadrao,
                'ddd_padrao' => $dddPadrao,
                'mensagem_padrao' => $mensagemPadrao,
            ],
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | FLUXO INVÁLIDO
    |--------------------------------------------------------------------------
    */
    $conexao->rollback();

    out([
        'ok' => false,
        'code' => 'INVALID_FLOW',
        'user_msg' => 'Fluxo de salvamento inválido.',
    ], 422);

} catch (Throwable $e) {
    /*
    |--------------------------------------------------------------------------
    | TRATA ERROS INTERNOS
    |--------------------------------------------------------------------------
    */
    if (isset($conexao) && $conexao instanceof mysqli) {
        try {
            $conexao->rollback();
        } catch (Throwable $rollbackError) {
        }
    }

    out([
        'ok' => false,
        'code' => 'SERVER_ERROR',
        'user_msg' => 'Erro interno ao salvar as configurações da agenda.',
        'debug' => $e->getMessage(),
    ], 500);
}