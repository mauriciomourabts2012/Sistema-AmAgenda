<?php
declare(strict_types=1);

/**
 * AmAgenda — Versionamento oficial (Windows e Ubuntu).
 *
 * Uso:
 *   php tools/release-version.php check
 *   php tools/release-version.php patch|minor|major [--dry-run]
 *
 * Fonte única da versão: backend/_config/app-version.json
 * Exit code: 0 = sucesso; diferente de 0 = erro.
 * Detalhes: tools/README-versionamento.md
 */

const RV_EXIT_OK = 0;
const RV_EXIT_FALHA = 1;      // validação reprovada
const RV_EXIT_USO = 2;        // comando inválido
const RV_EXIT_GRAVACAO = 3;   // falha de gravação (rollback executado)

const RV_ARQUIVO_VERSAO = 'backend/_config/app-version.json';
const RV_VERSAO_REGEX = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D';
const RV_BOM = "\xEF\xBB\xBF";

/**
 * Arquivos controlados (caminhos relativos à raiz do projeto, com "/").
 *  - "todos": todas as referências locais .js/.css de <script>/<link>
 *    (mesmo escopo de tools/aplicar-versao-assets.ps1);
 *  - "pwa": somente assets em /PWA/ (páginas de cliente e Agenda Online, cujos
 *    demais assets usam versões próprias por data e não são controlados aqui).
 */
function rvArquivosPadrao(): array
{
    return [
        'public/views/login-empresa.php' => 'todos',
        'public/views/agenda.html' => 'todos',
        'public/views/painel-administrativo/painel-administrativo.html' => 'todos',
        'public/views/login-cliente.php' => 'pwa',
        'public/views/cliente-agendamento.html' => 'pwa',
        'public/views/cliente-perfil.html' => 'pwa',
    ];
}

final class RvErro extends RuntimeException
{
}

function rvCaminho(string $raiz, string $relativo): string
{
    return rtrim($raiz, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
}

function rvVersaoValida(string $versao): bool
{
    return preg_match(RV_VERSAO_REGEX, $versao) === 1;
}

function rvProximaVersao(string $versao, string $tipo): string
{
    if (!rvVersaoValida($versao)) {
        throw new RvErro("Versão inválida: '{$versao}'. Formato obrigatório: MAJOR.MINOR.PATCH (ex.: 1.0.10).");
    }
    [$major, $minor, $patch] = array_map('intval', explode('.', $versao));
    switch ($tipo) {
        case 'major': return ($major + 1) . '.0.0';
        case 'minor': return "{$major}." . ($minor + 1) . '.0';
        case 'patch': return "{$major}.{$minor}." . ($patch + 1);
    }
    throw new RvErro("Tipo de incremento inválido: '{$tipo}'.");
}

/** Lê o arquivo como texto UTF-8. Retorna [texto sem BOM, tinha BOM]. */
function rvLerUtf8(string $caminho, string $rotulo): array
{
    if (!is_file($caminho)) {
        throw new RvErro("Arquivo não encontrado: {$rotulo}");
    }
    $bytes = file_get_contents($caminho);
    if ($bytes === false) {
        throw new RvErro("Não foi possível ler: {$rotulo}");
    }
    $temBom = strncmp($bytes, RV_BOM, 3) === 0;
    $texto = $temBom ? substr($bytes, 3) : $bytes;
    if (preg_match('//u', $texto) !== 1) {
        throw new RvErro("Conteúdo não é UTF-8 válido: {$rotulo}");
    }
    return [$texto, $temBom];
}

function rvLerVersaoCentral(string $raiz): array
{
    [$texto, $temBom] = rvLerUtf8(rvCaminho($raiz, RV_ARQUIVO_VERSAO), RV_ARQUIVO_VERSAO);
    $dados = json_decode($texto, true);
    if (!is_array($dados) || !array_key_exists('version', $dados) || !is_string($dados['version'])) {
        throw new RvErro(RV_ARQUIVO_VERSAO . ' não contém um campo "version" válido.');
    }
    if (!rvVersaoValida($dados['version'])) {
        throw new RvErro("Versão central inválida em " . RV_ARQUIVO_VERSAO . ": '{$dados['version']}'. Formato obrigatório: MAJOR.MINOR.PATCH.");
    }
    return [$dados['version'], $texto, $temBom];
}

function rvPadraoAssets(): string
{
    // Porta direta do padrão de tools/aplicar-versao-assets.ps1.
    return '/(?P<prefix><(?:script|link)\b[^>]*?\b(?:src|href)\s*=\s*(?P<quote>["\']))'
        . '(?P<url>[^"\']+?\.(?:js|css))(?P<query>\?[^"\']*)?(?P=quote)/i';
}

function rvReferenciaControlada(string $url, string $escopo): bool
{
    if (preg_match('#^(?:https?:)?//#i', $url) === 1 || stripos($url, 'data:') === 0) {
        return false;
    }
    return $escopo !== 'pwa' || strpos($url, '/PWA/') !== false;
}

/** Lista as referências controladas: cada item = ['url' => ..., 'versao' => string|null]. */
function rvListarReferencias(string $texto, string $escopo): array
{
    $referencias = [];
    preg_match_all(rvPadraoAssets(), $texto, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        if (!rvReferenciaControlada($m['url'], $escopo)) {
            continue;
        }
        $query = $m['query'] ?? '';
        $versao = preg_match('/[?&]v=([^&]*)/i', $query, $v) === 1 ? $v[1] : null;
        $referencias[] = ['url' => $m['url'], 'versao' => $versao];
    }
    return $referencias;
}

/** Aplica a versão às referências controladas. Retorna [novo texto, total alterado]. */
function rvAplicarVersao(string $texto, string $escopo, string $versao): array
{
    $alteradas = 0;
    $novo = preg_replace_callback(rvPadraoAssets(), function (array $m) use ($escopo, $versao, &$alteradas): string {
        if (!rvReferenciaControlada($m['url'], $escopo)) {
            return $m[0];
        }
        $query = $m['query'] ?? '';
        if ($query === '') {
            $novaQuery = "?v={$versao}";
        } elseif (preg_match('/([?&])v=[^&]*/i', $query) === 1) {
            $novaQuery = preg_replace('/([?&])v=[^&]*/i', '${1}v=' . $versao, $query, 1);
        } else {
            $novaQuery = "{$query}&v={$versao}";
        }
        $resultado = $m['prefix'] . $m['url'] . $novaQuery . $m['quote'];
        if ($resultado !== $m[0]) {
            $alteradas++;
        }
        return $resultado;
    }, $texto);
    if ($novo === null) {
        throw new RvErro('Falha ao processar as referências de assets (PCRE).');
    }
    return [$novo, $alteradas];
}

/** Valida tudo sem alterar nada. Retorna relatório; lança RvErro na primeira falha estrutural. */
function rvVerificar(string $raiz, array $arquivos): array
{
    [$versao, , $bomVersao] = rvLerVersaoCentral($raiz);
    $erros = [];
    if ($bomVersao) {
        $erros[] = RV_ARQUIVO_VERSAO . ': possui BOM UTF-8.';
    }
    $totalRefs = 0;
    foreach ($arquivos as $relativo => $escopo) {
        [$texto, $temBom] = rvLerUtf8(rvCaminho($raiz, $relativo), $relativo);
        if ($temBom) {
            $erros[] = "{$relativo}: possui BOM UTF-8.";
        }
        $refs = rvListarReferencias($texto, $escopo);
        if ($refs === []) {
            $erros[] = "{$relativo}: nenhuma referência controlada encontrada.";
        }
        foreach ($refs as $ref) {
            $totalRefs++;
            if ($ref['versao'] !== $versao) {
                $atual = $ref['versao'] === null ? 'sem versão' : "v={$ref['versao']}";
                $erros[] = "{$relativo}: {$ref['url']} ({$atual}, esperado v={$versao}).";
            }
        }
    }
    return ['versao' => $versao, 'arquivos' => count($arquivos), 'referencias' => $totalRefs, 'erros' => $erros];
}

/** Grava bytes de forma atômica (arquivo temporário + rename), preservando permissões. */
function rvGravarAtomico(string $caminho, string $bytes): void
{
    $temporario = $caminho . '.release-tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporario, $bytes, LOCK_EX) !== strlen($bytes)) {
        @unlink($temporario);
        throw new RvErro("Falha ao gravar arquivo temporário para: {$caminho}");
    }
    $permissoes = @fileperms($caminho);
    if ($permissoes !== false) {
        @chmod($temporario, $permissoes & 0777);
    }
    if (!@rename($temporario, $caminho)) {
        @unlink($temporario);
        throw new RvErro("Falha ao substituir: {$caminho}");
    }
}

/**
 * Prepara e (se não for dry-run) aplica a nova versão de forma transacional.
 * $gravar permite injetar o gravador nos testes (simulação de falha).
 */
function rvLiberar(string $raiz, array $arquivos, string $tipo, bool $dryRun, ?callable $gravar = null): array
{
    $gravar = $gravar ?? 'rvGravarAtomico';

    // 1) Leitura e validação completas ANTES de qualquer gravação.
    [$versaoAtual, $textoVersao, $bomVersao] = rvLerVersaoCentral($raiz);
    $novaVersao = rvProximaVersao($versaoAtual, $tipo);
    $plano = [];
    $totalRefs = 0;
    $totalAlteradas = 0;
    foreach ($arquivos as $relativo => $escopo) {
        $caminho = rvCaminho($raiz, $relativo);
        [$texto, $temBom] = rvLerUtf8($caminho, $relativo);
        $refs = rvListarReferencias($texto, $escopo);
        if ($refs === []) {
            throw new RvErro("{$relativo}: nenhuma referência controlada encontrada.");
        }
        [$novoTexto, $alteradas] = rvAplicarVersao($texto, $escopo, $novaVersao);
        $totalRefs += count($refs);
        $totalAlteradas += $alteradas;
        $plano[] = [
            'relativo' => $relativo,
            'caminho' => $caminho,
            'original' => file_get_contents($caminho),
            'novo' => $novoTexto, // sempre sem BOM
            'muda' => $temBom || $novoTexto !== $texto,
        ];
    }

    // 2) app-version.json: troca só o valor, preservando a formatação do arquivo.
    $padraoVersao = '/("version"\s*:\s*")' . preg_quote($versaoAtual, '/') . '(")/';
    if (preg_match_all($padraoVersao, $textoVersao) !== 1) {
        throw new RvErro(RV_ARQUIVO_VERSAO . ': não foi possível localizar o campo "version" de forma única.');
    }
    $novoTextoVersao = preg_replace($padraoVersao, '${1}' . $novaVersao . '${2}', $textoVersao, 1);
    $dadosNovos = json_decode((string)$novoTextoVersao, true);
    if (($dadosNovos['version'] ?? null) !== $novaVersao) {
        throw new RvErro(RV_ARQUIVO_VERSAO . ': JSON resultante inválido.');
    }
    $caminhoVersao = rvCaminho($raiz, RV_ARQUIVO_VERSAO);
    // A versão central é gravada por último.
    $plano[] = [
        'relativo' => RV_ARQUIVO_VERSAO,
        'caminho' => $caminhoVersao,
        'original' => file_get_contents($caminhoVersao),
        'novo' => $novoTextoVersao,
        'muda' => true,
    ];

    $resumo = [
        'versaoAtual' => $versaoAtual,
        'novaVersao' => $novaVersao,
        'arquivos' => count($arquivos),
        'referencias' => $totalRefs,
        'alteradas' => $totalAlteradas,
        'bomRemovido' => $bomVersao,
    ];
    if ($dryRun) {
        return $resumo;
    }

    // 3) Gravação com rollback.
    $gravados = [];
    try {
        foreach ($plano as $item) {
            if (!$item['muda']) {
                continue;
            }
            $gravar($item['caminho'], $item['novo']);
            $gravados[] = $item;
        }
        // 4) Conferência final no disco.
        $verificacao = rvVerificar($raiz, $arquivos);
        if ($verificacao['versao'] !== $novaVersao || $verificacao['erros'] !== []) {
            throw new RvErro('Conferência pós-gravação reprovada: ' . implode(' | ', $verificacao['erros']));
        }
    } catch (Throwable $erro) {
        $falhasRollback = [];
        foreach (array_reverse($gravados) as $item) {
            try {
                rvGravarAtomico($item['caminho'], $item['original']);
            } catch (Throwable $e) {
                $falhasRollback[] = $item['relativo'];
            }
        }
        $mensagem = 'Falha na gravação: ' . $erro->getMessage() . ' Rollback executado';
        $mensagem .= $falhasRollback === []
            ? '; nenhum arquivo ficou alterado.'
            : '; ATENÇÃO, não foi possível restaurar: ' . implode(', ', $falhasRollback) . '.';
        throw new RvErro($mensagem, RV_EXIT_GRAVACAO);
    }
    return $resumo;
}

function rvMain(array $argv, string $raiz, array $arquivos, $saidaErro = STDERR): int
{
    $args = array_slice($argv, 1);
    $dryRun = in_array('--dry-run', $args, true);
    $args = array_values(array_filter($args, static fn($a) => $a !== '--dry-run'));
    $comando = $args[0] ?? '';

    echo "AmAgenda — Versionamento\n\n";
    if (count($args) !== 1 || !in_array($comando, ['check', 'patch', 'minor', 'major'], true) || ($comando === 'check' && $dryRun)) {
        fwrite($saidaErro, "Uso: php tools/release-version.php check\n");
        fwrite($saidaErro, "     php tools/release-version.php patch|minor|major [--dry-run]\n");
        return RV_EXIT_USO;
    }

    try {
        if ($comando === 'check') {
            $r = rvVerificar($raiz, $arquivos);
            echo "Versão central: {$r['versao']}\n";
            echo "Arquivos verificados: {$r['arquivos']}\n";
            echo "Referências verificadas: {$r['referencias']}\n";
            $semBom = array_filter($r['erros'], static fn($e) => strpos($e, 'BOM') !== false) === [];
            echo 'UTF-8 sem BOM: ' . ($semBom ? 'OK' : 'FALHA') . "\n";
            echo 'Consistência: ' . ($r['erros'] === [] ? 'OK' : 'FALHA') . "\n";
            foreach ($r['erros'] as $erro) {
                echo "  - {$erro}\n";
            }
            echo "\nResultado: " . ($r['erros'] === [] ? 'APROVADO' : 'REPROVADO') . "\n";
            return $r['erros'] === [] ? RV_EXIT_OK : RV_EXIT_FALHA;
        }

        $r = rvLiberar($raiz, $arquivos, $comando, $dryRun);
        echo "Versão atual: {$r['versaoAtual']}\n";
        echo "Nova versão: {$r['novaVersao']}\n\n";
        if ($dryRun) {
            echo "Arquivos que seriam atualizados: {$r['arquivos']}\n";
            echo "Referências que seriam alteradas: {$r['alteradas']}\n\n";
            echo "Nenhum arquivo foi modificado.\n";
            return RV_EXIT_OK;
        }
        echo "Arquivos atualizados: {$r['arquivos']} + " . RV_ARQUIVO_VERSAO . "\n";
        echo "Referências alteradas: {$r['alteradas']}\n";
        echo "Conferência pós-gravação: OK\n\n";
        echo "Resultado: APROVADO\n";
        return RV_EXIT_OK;
    } catch (RvErro $erro) {
        fwrite($saidaErro, "ERRO: {$erro->getMessage()}\n");
        echo "\nResultado: REPROVADO\n";
        return $erro->getCode() === RV_EXIT_GRAVACAO ? RV_EXIT_GRAVACAO : RV_EXIT_FALHA;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(rvMain($_SERVER['argv'], dirname(__DIR__), rvArquivosPadrao()));
}
