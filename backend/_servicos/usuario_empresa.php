<?php
declare(strict_types=1);

require_once __DIR__ . '/../_regras/limites_plano.php';

final class UsuarioEmpresaServicoErro extends RuntimeException
{
    public function __construct(
        private string $codigoServico,
        string $mensagem,
        private array $dadosServico = []
    ) {
        parent::__construct($mensagem);
    }

    public function codigo(): string
    {
        return $this->codigoServico;
    }

    public function dados(): array
    {
        return $this->dadosServico;
    }
}

/**
 * Cria um usuario global e seu vinculo, ou vincula um usuario ja existente.
 * A conexao deve estar na transacao controlada pelo chamador.
 */
function usuarioEmpresaServicoCriarOuVincular(mysqli $conexao, array $dados): array
{
    $idEmpresa = (int)($dados['id_empresa'] ?? 0);
    $idPerfil = (int)($dados['id_perfil'] ?? 0);
    $nome = trim((string)($dados['nome'] ?? ''));
    $email = mb_strtolower(trim((string)($dados['email'] ?? '')), 'UTF-8');
    $telefone = (string)($dados['telefone'] ?? '');
    $senha = (string)($dados['senha'] ?? '');
    $status = mb_strtolower(trim((string)($dados['status'] ?? '')), 'UTF-8');
    $autorizarVinculo = (bool)($dados['autorizar_vinculo_existente'] ?? false);
    $deveAlterarSenha = (bool)($dados['deve_alterar_senha'] ?? true);
    $perfilEsperado = (string)($dados['perfil_esperado_normalizado'] ?? '');

    if ($idEmpresa <= 0 || $idPerfil <= 0 || $nome === '' || $email === '' || $status === '') {
        throw new InvalidArgumentException('Dados insuficientes para criar ou vincular o usuario.');
    }

    $resultadoPlano = limitesPlanoBloquearEmpresa($conexao, $idEmpresa);
    if (($resultadoPlano['ok'] ?? false) !== true) {
        throw new UsuarioEmpresaServicoErro(
            (string)($resultadoPlano['code'] ?? 'COMPANY_PLAN_ERROR'),
            (string)($resultadoPlano['user_msg'] ?? 'Nao foi possivel validar o plano da empresa.'),
            $resultadoPlano
        );
    }

    $stmt = $conexao->prepare('SELECT id_perfil, nome, status FROM perfil WHERE id_perfil = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validacao do perfil: ' . $conexao->error);
    }
    $stmt->bind_param('i', $idPerfil);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao executar validacao do perfil: ' . $erro);
    }
    $perfil = $stmt->get_result()?->fetch_assoc() ?: null;
    $stmt->close();
    if ($perfil === null) {
        throw new UsuarioEmpresaServicoErro('PERFIL_NOT_FOUND', 'Perfil nao encontrado.');
    }
    if (mb_strtolower((string)$perfil['status'], 'UTF-8') !== 'ativo') {
        throw new UsuarioEmpresaServicoErro('PERFIL_INATIVO', 'O perfil selecionado esta inativo.');
    }
    $perfilNome = (string)$perfil['nome'];
    $perfilNormalizado = limitesPlanoNormalizarPerfil($perfilNome);
    if (in_array($perfilNormalizado, ['super admin', 'superadmin'], true)) {
        throw new UsuarioEmpresaServicoErro('INVALID_PROFILE_FOR_COMPANY_USER', 'Super Admin nao pode ser vinculado como usuario de empresa.');
    }
    if ($perfilEsperado !== '' && $perfilNormalizado !== $perfilEsperado) {
        throw new UsuarioEmpresaServicoErro(
            'INVALID_PROFILE_FOR_SUPERADMIN_COMPANY_USER',
            'O perfil informado nao e permitido neste fluxo.'
        );
    }

    $stmt = $conexao->prepare(
        'SELECT id_usuario, nome, email, telefone, status, tipo_usuario
           FROM usuario
          WHERE LOWER(email) = ?
          LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar validacao de e-mail: ' . $conexao->error);
    }
    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) {
        $erro = (string)$stmt->error;
        $stmt->close();
        throw new RuntimeException('Erro ao executar validacao de e-mail: ' . $erro);
    }
    $usuarioExistente = $stmt->get_result()?->fetch_assoc() ?: null;
    $stmt->close();

    $statusEfetivoPlano = $usuarioExistente === null
        || limitesPlanoStatusConta((string)$usuarioExistente['status'])
        ? $status
        : 'inativo';
    $resultadoLimites = limitesPlanoVerificarTransicaoPerfil(
        $conexao,
        $resultadoPlano['plano'],
        $idEmpresa,
        null,
        null,
        $perfilNome,
        $statusEfetivoPlano
    );
    if (($resultadoLimites['ok'] ?? false) !== true) {
        throw new UsuarioEmpresaServicoErro(
            (string)($resultadoLimites['code'] ?? 'PLAN_LIMIT_EXCEEDED'),
            (string)($resultadoLimites['user_msg'] ?? 'O limite do plano foi atingido.'),
            $resultadoLimites
        );
    }

    $usuarioNovo = $usuarioExistente === null;
    if ($usuarioNovo) {
        if ($senha === '') {
            throw new InvalidArgumentException('A senha e obrigatoria para criar um novo usuario.');
        }
        $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
        if ($senhaHash === false) {
            throw new RuntimeException('Nao foi possivel gerar o hash da senha.');
        }
        $tipoUsuario = 'usuario';
        $alterarSenha = $deveAlterarSenha ? 1 : 0;
        $stmt = $conexao->prepare(
            'INSERT INTO usuario
                (nome, email, telefone, senha_hash, status, tipo_usuario, deve_alterar_senha, data_senha_temporaria)
             VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 1, CURRENT_TIMESTAMP, NULL))'
        );
        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar cadastro do usuario: ' . $conexao->error);
        }
        $stmt->bind_param('ssssssii', $nome, $email, $telefone, $senhaHash, $status, $tipoUsuario, $alterarSenha, $alterarSenha);
        if (!$stmt->execute()) {
            $errno = (int)$stmt->errno;
            $erro = (string)$stmt->error;
            $stmt->close();
            if ($errno === 1062) {
                throw new UsuarioEmpresaServicoErro('EMAIL_EXISTS', 'Ja existe um usuario com este e-mail.');
            }
            throw new RuntimeException('Erro ao executar cadastro do usuario: ' . $erro);
        }
        $idUsuario = (int)$stmt->insert_id;
        $stmt->close();
        $nomeRetorno = $nome;
        $emailRetorno = $email;
        $telefoneRetorno = $telefone;
        $statusRetorno = $status;
    } else {
        $idUsuario = (int)$usuarioExistente['id_usuario'];
        $nomeRetorno = (string)$usuarioExistente['nome'];
        $emailRetorno = mb_strtolower((string)$usuarioExistente['email'], 'UTF-8');
        $telefoneRetorno = (string)($usuarioExistente['telefone'] ?? '');
        $statusRetorno = mb_strtolower((string)$usuarioExistente['status'], 'UTF-8');

        if (mb_strtolower((string)$usuarioExistente['tipo_usuario'], 'UTF-8') === 'super_admin') {
            throw new UsuarioEmpresaServicoErro('INVALID_LINK_SUPERADMIN', 'Super Admin nao pode ser vinculado como usuario de empresa.');
        }
        $stmt = $conexao->prepare(
            'SELECT id_empresa_usuario FROM empresa_usuario
              WHERE id_empresa = ? AND id_usuario = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Erro ao preparar validacao de vinculo: ' . $conexao->error);
        }
        $stmt->bind_param('ii', $idEmpresa, $idUsuario);
        if (!$stmt->execute()) {
            $erro = (string)$stmt->error;
            $stmt->close();
            throw new RuntimeException('Erro ao executar validacao de vinculo: ' . $erro);
        }
        $vinculoExistente = $stmt->get_result()?->fetch_assoc() ?: null;
        $stmt->close();
        if ($vinculoExistente !== null) {
            throw new UsuarioEmpresaServicoErro('USER_ALREADY_LINKED', 'Este usuario ja esta vinculado a esta empresa.');
        }
        if (!$autorizarVinculo) {
            return [
                'situacao' => 'USUARIO_EXISTENTE',
                'id_usuario' => $idUsuario,
                'id_empresa_usuario' => null,
                'usuario_novo' => false,
                'vinculo_criado' => false,
                'nome' => $nomeRetorno,
                'email' => $emailRetorno,
                'telefone' => $telefoneRetorno,
                'status_usuario' => $statusRetorno,
                'perfil_nome' => $perfilNome,
            ];
        }
    }

    $stmt = $conexao->prepare(
        'INSERT INTO empresa_usuario (id_empresa, id_usuario, id_perfil, status) VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Erro ao preparar vinculo empresa/usuario: ' . $conexao->error);
    }
    $stmt->bind_param('iiis', $idEmpresa, $idUsuario, $idPerfil, $status);
    if (!$stmt->execute()) {
        $errno = (int)$stmt->errno;
        $erro = (string)$stmt->error;
        $stmt->close();
        if ($errno === 1062) {
            throw new UsuarioEmpresaServicoErro('USER_ALREADY_LINKED', 'Este usuario ja esta vinculado a esta empresa.');
        }
        throw new RuntimeException('Erro ao executar vinculo empresa/usuario: ' . $erro);
    }
    $idEmpresaUsuario = (int)$stmt->insert_id;
    $stmt->close();

    return [
        'situacao' => $usuarioNovo ? 'USUARIO_NOVO' : 'USUARIO_EXISTENTE',
        'id_usuario' => $idUsuario,
        'id_empresa_usuario' => $idEmpresaUsuario,
        'usuario_novo' => $usuarioNovo,
        'vinculo_criado' => true,
        'nome' => $nomeRetorno,
        'email' => $emailRetorno,
        'telefone' => $telefoneRetorno,
        'status_usuario' => $statusRetorno,
        'status_vinculo' => $status,
        'perfil_nome' => $perfilNome,
    ];
}
