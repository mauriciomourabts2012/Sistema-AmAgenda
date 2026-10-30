<?php
declare(strict_types=1);

/*
 * Apoio dos testes de SMS/OTP: banco falso em memória por regras (sem MySQL, sem escrita real).
 * Não é executado sozinho. Fidelidade limitada: valida a sequência, os parâmetros e as guardas
 * das consultas; semântica real de SQL, locks e concorrência exige um MySQL descartável.
 */

require_once __DIR__ . '/../../backend/_servicos/sms.php';

function falsoVerificar(bool $condicao, string $descricao): void
{
    global $total, $falhas;
    $total++;
    if (!$condicao) {
        $falhas[] = $descricao;
    }
}

function falsoRelatorio(): never
{
    global $total, $falhas;
    echo $total . ' verificações, ' . count($falhas) . " falha(s)\n";
    foreach ($falhas as $falha) {
        echo "FALHA: " . $falha . "\n";
    }
    exit($falhas === [] ? 0 : 1);
}

final class BancoFalso extends mysqli implements SmsBancoSubstituto
{
    /** @var list<array{sql: string, valores: array}> */
    public array $log = [];
    /** @var list<array{0: string, 1: mixed, 2: bool}> */
    private array $regras = [];
    public int $inicios = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    /** @var list<string> sequência de eventos de transação e escrita */
    public array $eventos = [];

    public function __construct()
    {
    }

    /** $resposta: array de linhas (consulta), int (escrita) ou callable(array $valores). */
    public function regra(string $regex, mixed $resposta, bool $umaVez = false): void
    {
        array_unshift($this->regras, [$regex, $resposta, $umaVez]);
    }

    private function resolver(string $sql, array $valores, mixed $padrao): mixed
    {
        $normal = trim((string)preg_replace('/\s+/', ' ', $sql));
        $this->log[] = ['sql' => $normal, 'valores' => $valores];
        $this->eventos[] = $normal;
        foreach ($this->regras as $i => [$regex, $resposta, $umaVez]) {
            if (preg_match($regex, $normal) === 1) {
                if ($umaVez) {
                    array_splice($this->regras, $i, 1);
                }

                return is_callable($resposta) ? $resposta($valores) : $resposta;
            }
        }

        return $padrao;
    }

    public function consultarSubstituto(string $sql, array $valores): array
    {
        $r = $this->resolver($sql, $valores, []);
        if ($r instanceof Throwable) {
            throw $r;
        }

        return is_array($r) ? $r : [];
    }

    public function executarSubstituto(string $sql, array $valores): int
    {
        $r = $this->resolver($sql, $valores, 1);
        if ($r instanceof Throwable) {
            throw $r;
        }

        return is_int($r) ? $r : 1;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->inicios++;
        $this->eventos[] = 'BEGIN';

        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->commits++;
        $this->eventos[] = 'COMMIT';

        return true;
    }

    public function rollback(int $flags = 0, ?string $name = null, ): bool
    {
        $this->rollbacks++;
        $this->eventos[] = 'ROLLBACK';

        return true;
    }

    /** @return list<array{sql: string, valores: array}> */
    public function chamadas(string $regex): array
    {
        return array_values(array_filter($this->log, static fn (array $c): bool => preg_match($regex, $c['sql']) === 1));
    }

    public function todosOsValores(): string
    {
        return json_encode(array_map(static fn (array $c): array => $c['valores'], $this->log), JSON_UNESCAPED_UNICODE);
    }
}
