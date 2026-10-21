<?php
declare(strict_types=1);

/**
 * Política centralizada de senhas DEFINITIVAS (usuários internos e Super Admin).
 *
 * Escopo:
 * - Valida apenas senhas definitivas. Não se aplica a senhas temporárias,
 *   ao login, à recuperação por SMS nem a clientes finais (OTP).
 * - Não gera nem verifica hash: o chamador continua usando
 *   password_hash(..., PASSWORD_DEFAULT) e password_verify() como hoje.
 *
 * Garantias:
 * - Funções puras: sem banco, sem sessão, sem saída, sem logs e sem debug.
 * - A senha nunca é transformada (sem trim/normalização). Formas normalizadas
 *   existem apenas em memória, para as comparações de bloqueio.
 * - Nenhuma mensagem ou retorno contém a senha ou partes dela.
 * - Parâmetros com senha usam #[\SensitiveParameter] (PHP 8.2+), que oculta
 *   o valor em stack traces; em versões anteriores o atributo é ignorado.
 *
 * Dependências: mbstring e PCRE com suporte a UTF-8 (já usados no projeto).
 */

const POLITICA_SENHA_MIN_CARACTERES = 8;
const POLITICA_SENHA_MAX_BYTES = 72; // limite efetivo do bcrypt (PASSWORD_DEFAULT)

/**
 * Caractere especial obrigatório: qualquer pontuação ou símbolo Unicode
 * (categorias \p{P} e \p{S}), como # @ ! $ % & * _ - . , ; : ? + = ( ) [ ] / € .
 * Letras, dígitos e espaços não contam. Mesma definição usada no JavaScript
 * (public/js/componentes/indicador-senha.js e validações dos formulários):
 * /[\p{P}\p{S}]/u. Maiúsculas, minúsculas e números NÃO são exigidos.
 */
const POLITICA_SENHA_REGEX_ESPECIAL = '/[\p{P}\p{S}]/u';

/**
 * Lista inicial enxuta de senhas e bases muito comuns.
 * Entradas em minúsculas, sem espaços. Termos curtos (ex.: "senha") servem
 * como base: "Senha2026!" também é bloqueada.
 */
const POLITICA_SENHA_COMUNS = [
    // Numéricas e teclado
    '12345678', '123456789', '1234567890', '12345678a', '123456789a',
    '87654321', '987654321', '11111111', '00000000', '12341234',
    '11223344', '12121212', '123123123', '147258369', '159753456',
    '741852963', '963852741', 'qwerty', 'qwerty123', 'qwertyuiop',
    'asdfghjk', 'asdfghjkl', 'zxcvbnm', '1q2w3e4r', '1q2w3e4r5t',
    'q1w2e3r4', '1qaz2wsx', 'qazwsxedc', 'abc123', 'abcd1234',
    'abc12345', 'abcdefgh',
    // Senha / acesso / padrões de sistema
    'senha', 'senha123', 'senha1234', 'minhasenha', 'novasenha',
    'mudar123', 'mudarsenha', 'trocar123', 'alterar123', 'password',
    'password1', 'admin', 'admin123', 'admin1234', 'administrador',
    'root', 'superadmin', 'usuario', 'user', 'teste', 'test',
    'login', 'acesso', 'entrar', 'segredo', 'secreto', 'changeme',
    'default', 'guest', 'visitante', 'welcome', 'bemvindo', 'letmein',
    'trustno1', 'master', 'sistema',
    // Domínio do produto e da empresa
    'amagenda', 'agenda', 'conex', 'conexinternet', 'internet',
    'empresa', 'suporte', 'suporteti', 'gerente', 'financeiro',
    'recepcao', 'clinica', 'salao', 'barbearia',
    // Palavras e nomes muito usados
    'iloveyou', 'euteamo', 'teamo', 'amor', 'meuamor', 'deus',
    'deusefiel', 'jesus', 'jesuscristo', 'familia', 'felicidade',
    'sucesso', 'liberdade', 'princesa', 'estrela', 'chocolate',
    'brasil', 'futebol', 'computador', 'celular', 'gabriel', 'lucas',
    'maria', 'joao', 'pedro', 'batman', 'superman', 'pokemon',
    'naruto', 'dragon', 'monkey', 'shadow', 'sunshine', 'princess',
    'football', 'starwars', 'google', 'facebook', 'instagram',
    'whatsapp',
    // Times de futebol
    'flamengo', 'corinthians', 'palmeiras', 'saopaulo', 'santos',
    'vasco', 'gremio', 'internacional', 'cruzeiro', 'atletico',
    'botafogo', 'fluminense', 'bahia', 'vitoria',
];

/**
 * Mensagens exibidas ao usuário, por código de retorno.
 * Padrão AmAgenda: linguagem simples, curta e orientativa, sem termos técnicos
 * e sem detalhes sensíveis. Os endpoints devem reutilizar estes textos.
 * PASSWORD_SAME_AS_CURRENT e PASSWORD_CHANGED são verificados/emitidos pelo
 * próprio endpoint (dependem da senha atual), mas o texto fica centralizado aqui.
 */
const POLITICA_SENHA_MENSAGENS = [
    'PASSWORD_REQUIRED'              => 'Informe a senha.',
    'PASSWORD_INVALID_CHARS'         => 'A senha contém caracteres que não podem ser usados. Digite a senha novamente, sem copiar e colar.',
    'PASSWORD_TOO_SHORT'             => 'A senha deve ter pelo menos ' . POLITICA_SENHA_MIN_CARACTERES . ' caracteres.',
    'PASSWORD_TOO_LONG'              => 'A senha está muito longa. Use até ' . POLITICA_SENHA_MAX_BYTES . ' caracteres, ou menos se usar acentos.',
    'PASSWORD_PERSONAL_DATA'         => 'A senha não pode ser igual ao seu nome, e-mail ou telefone.',
    'PASSWORD_TOO_COMMON'            => 'Esta senha é muito fácil de adivinhar. Escolha uma senha mais segura.',
    'PASSWORD_SPECIAL_REQUIRED'      => 'A senha deve conter pelo menos um caractere especial, como #, @ ou !.',
    'PASSWORD_CONFIRMATION_REQUIRED' => 'Repita a senha no campo de confirmação.',
    'PASSWORD_CONFIRMATION_MISMATCH' => 'As senhas informadas não coincidem.',
    'PASSWORD_SAME_AS_CURRENT'       => 'A nova senha deve ser diferente da senha atual.',
    'PASSWORD_CHANGED'               => 'Sua senha foi alterada com sucesso.',
];

/** Sequências de teclado e alfanuméricas (também verificadas ao contrário). */
const POLITICA_SENHA_SEQUENCIAS = [
    'abcdefghijklmnopqrstuvwxyz',
    '01234567890123456789',
    'qwertyuiopasdfghjklzxcvbnm',
    'qwertyuiop',
    'asdfghjkl',
    'zxcvbnm',
    '1qaz2wsx3edc4rfv5tgb6yhn7ujm8ik9ol0p',
    '1q2w3e4r5t6y7u8i9o0p',
    'q1w2e3r4t5y6u7i8o9p0',
    'qazwsxedcrfvtgbyhnujmikolp',
    '147258369',
    '159357',
];

/**
 * Valida uma senha definitiva e sua confirmação.
 *
 * @param array $contexto Opcional: ['nome' => ..., 'email' => ..., 'telefone' => ...]
 *                        usado para bloquear senhas idênticas a esses dados.
 * @return array|null null quando válida; caso contrário
 *                    ['code' => string, 'campo' => 'senha'|'confirmacao', 'user_msg' => string]
 */
function politicaSenhaValidarDefinitiva(
    #[\SensitiveParameter] string $senha,
    #[\SensitiveParameter] string $confirmacao,
    #[\SensitiveParameter] array $contexto = []
): ?array {
    $erro = politicaSenhaVerificarFormato($senha);
    if ($erro !== null) {
        return $erro;
    }

    if (politicaSenhaCorrespondeDadosPessoais($senha, $contexto)) {
        return politicaSenhaErro('PASSWORD_PERSONAL_DATA', 'senha');
    }

    if (politicaSenhaEhFraca($senha)) {
        return politicaSenhaErro('PASSWORD_TOO_COMMON', 'senha');
    }

    if (!politicaSenhaTemCaractereEspecial($senha)) {
        return politicaSenhaErro('PASSWORD_SPECIAL_REQUIRED', 'senha');
    }

    if ($confirmacao === '') {
        return politicaSenhaErro('PASSWORD_CONFIRMATION_REQUIRED', 'confirmacao');
    }

    if (!politicaSenhaConfirmacaoConfere($senha, $confirmacao)) {
        return politicaSenhaErro('PASSWORD_CONFIRMATION_MISMATCH', 'confirmacao');
    }

    return null;
}

/**
 * Regras estruturais: obrigatória, UTF-8 válido, sem caracteres de controle
 * ou invisíveis, mínimo de caracteres e máximo de bytes do bcrypt.
 * Espaços comuns e demais caracteres Unicode visíveis são permitidos.
 */
function politicaSenhaVerificarFormato(#[\SensitiveParameter] string $senha): ?array
{
    if ($senha === '') {
        return politicaSenhaErro('PASSWORD_REQUIRED', 'senha');
    }

    if (!mb_check_encoding($senha, 'UTF-8') || politicaSenhaTemCaracteresInvalidos($senha)) {
        return politicaSenhaErro('PASSWORD_INVALID_CHARS', 'senha');
    }

    if (mb_strlen($senha, 'UTF-8') < POLITICA_SENHA_MIN_CARACTERES) {
        return politicaSenhaErro('PASSWORD_TOO_SHORT', 'senha');
    }

    if (strlen($senha) > POLITICA_SENHA_MAX_BYTES) {
        return politicaSenhaErro('PASSWORD_TOO_LONG', 'senha');
    }

    return null;
}

/**
 * Detecta caracteres de controle (Cc), de formatação invisível (Cf: zero-width,
 * marcas bidi, BOM, hífen suave), surrogates (Cs) e separadores de linha ou
 * parágrafo (Zl, Zp). Retorna true também se a expressão não puder ser avaliada.
 */
function politicaSenhaTemCaracteresInvalidos(#[\SensitiveParameter] string $senha): bool
{
    $r = preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u', $senha);
    return $r !== 0;
}

/**
 * Indica se a senha tem ao menos um caractere especial (pontuação ou símbolo Unicode).
 * Exigência aplicada somente à criação ou alteração de senha definitiva;
 * senhas já existentes não são reavaliadas.
 */
function politicaSenhaTemCaractereEspecial(#[\SensitiveParameter] string $senha): bool
{
    return preg_match(POLITICA_SENHA_REGEX_ESPECIAL, $senha) === 1;
}

/** Comparação da confirmação em tempo constante, byte a byte, sem transformação. */
function politicaSenhaConfirmacaoConfere(
    #[\SensitiveParameter] string $senha,
    #[\SensitiveParameter] string $confirmacao
): bool {
    return hash_equals($senha, $confirmacao);
}

/**
 * Indica se a senha é comum, sequencial ou repetitiva.
 * Avalia a senha em minúsculas e sem espaços, a versão só com letras e dígitos
 * e uma versão com substituições simples (p@ssw0rd -> password).
 */
function politicaSenhaEhFraca(#[\SensitiveParameter] string $senha): bool
{
    $forma = politicaSenhaFormaComparacao($senha);
    if ($forma === '') {
        return true; // composta só de espaços
    }

    $alnum = preg_replace('/[^\p{L}\p{N}]+/u', '', $forma) ?? '';
    $letras = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $forma) ?? '';
    $substituida = strtr($forma, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
    $substituidaLetras = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $substituida) ?? '';

    foreach (array_unique([$forma, $alnum, $letras, $substituida, $substituidaLetras]) as $candidato) {
        if ($candidato !== '' && politicaSenhaEstaNaListaComum($candidato)) {
            return true;
        }
    }

    foreach (array_unique([$forma, $alnum]) as $candidato) {
        if ($candidato === '') {
            continue;
        }
        if (politicaSenhaEhRepetitiva($candidato) || politicaSenhaEhSequencial($candidato)) {
            return true;
        }
    }

    return false;
}

/** Verifica se o valor (já normalizado) está na lista de senhas comuns. */
function politicaSenhaEstaNaListaComum(#[\SensitiveParameter] string $valor): bool
{
    static $conjunto = null;
    if ($conjunto === null) {
        $conjunto = array_fill_keys(POLITICA_SENHA_COMUNS, true);
    }
    return isset($conjunto[$valor]);
}

/** Um bloco de 1 a 4 caracteres repetido em toda a senha (aaaaaaaa, 12121212, abcabcabc). */
function politicaSenhaEhRepetitiva(#[\SensitiveParameter] string $valor): bool
{
    return preg_match('/^(.{1,4}?)\1+$/us', $valor) === 1;
}

/** Trecho de sequência alfabética, numérica ou de teclado, em qualquer sentido. */
function politicaSenhaEhSequencial(#[\SensitiveParameter] string $valor): bool
{
    if (strlen($valor) < 4 || preg_match('/^[a-z0-9]+$/', $valor) !== 1) {
        return false;
    }

    foreach (POLITICA_SENHA_SEQUENCIAS as $sequencia) {
        if (str_contains($sequencia, $valor) || str_contains(strrev($sequencia), $valor)) {
            return true;
        }
    }

    return false;
}

/**
 * Bloqueia apenas correspondências exatas (após minúsculas e remoção de espaços)
 * com nome completo, e-mail, parte local do e-mail ou telefone.
 * Para telefone, compara os dígitos da senha com o número completo e com o
 * número sem DDD/DDI (8 ou 9 últimos dígitos), somente se a senha não tiver letras.
 */
function politicaSenhaCorrespondeDadosPessoais(
    #[\SensitiveParameter] string $senha,
    #[\SensitiveParameter] array $contexto
): bool {
    if ($contexto === []) {
        return false;
    }

    $forma = politicaSenhaFormaComparacao($senha);
    $candidatos = [];

    $nome = politicaSenhaValorContexto($contexto, 'nome');
    if ($nome !== '') {
        $candidatos[] = politicaSenhaFormaComparacao($nome);
    }

    $email = politicaSenhaValorContexto($contexto, 'email');
    if ($email !== '') {
        $emailForma = politicaSenhaFormaComparacao($email);
        $candidatos[] = $emailForma;
        $posArroba = strpos($emailForma, '@');
        if ($posArroba !== false && $posArroba > 0) {
            $candidatos[] = substr($emailForma, 0, $posArroba);
        }
    }

    foreach ($candidatos as $candidato) {
        if ($candidato !== '' && $candidato === $forma) {
            return true;
        }
    }

    $telefone = preg_replace('/\D+/', '', politicaSenhaValorContexto($contexto, 'telefone')) ?? '';
    if ($telefone !== '' && preg_match('/\p{L}/u', $senha) !== 1) {
        $digitosSenha = preg_replace('/\D+/', '', $senha) ?? '';
        if ($digitosSenha !== '') {
            $variantes = [$telefone];
            if (strlen($telefone) >= 10) {
                $variantes[] = substr($telefone, -9);
                $variantes[] = substr($telefone, -8);
            }
            if (in_array($digitosSenha, $variantes, true)) {
                return true;
            }
        }
    }

    return false;
}

/** Forma usada somente para comparações: minúsculas e sem espaços. Nunca é gravada. */
function politicaSenhaFormaComparacao(#[\SensitiveParameter] string $valor): string
{
    $valor = mb_strtolower($valor, 'UTF-8');
    return preg_replace('/[\s\p{Z}]+/u', '', $valor) ?? '';
}

/** Lê um campo textual do contexto, aceitando apenas string ou inteiro. */
function politicaSenhaValorContexto(array $contexto, string $chave): string
{
    $valor = $contexto[$chave] ?? '';
    if (is_int($valor)) {
        return (string)$valor;
    }
    return is_string($valor) ? trim($valor) : '';
}

/** Texto orientativo para o usuário a partir do código; nunca inclui a senha. */
function politicaSenhaMensagem(string $code): string
{
    return POLITICA_SENHA_MENSAGENS[$code] ?? 'Revise a senha informada.';
}

/** Monta o retorno padronizado de erro (nunca inclui a senha). */
function politicaSenhaErro(string $code, string $campo): array
{
    return [
        'code' => $code,
        'campo' => $campo,
        'user_msg' => politicaSenhaMensagem($code),
    ];
}
