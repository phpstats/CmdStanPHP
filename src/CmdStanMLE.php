<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanMLE extends CmdStanFit
{
    public function mle(): array { return $this->parsed()[0]['estimate'] ?? []; }
    public function summary(array|string|null $variables = null, array $probs = [0.05, 0.95], array $measures = []): array
    {
        if ($measures !== []) { throw new \InvalidArgumentException('Optimization summaries report point estimates only.'); }
        $values = $this->draws($variables, 'draws_matrix'); $last = $values === [] ? [] : end($values);
        return array_map(fn($name, $value) => ['variable' => $name, 'estimate' => $value], array_keys($last), array_values($last));
    }
    public function optimizedParams(): array { return $this->mle(); }
    public function lp(): float { return $this->mle()['lp__'] ?? NAN; }
}
