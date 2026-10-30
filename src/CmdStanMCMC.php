<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanMCMC extends CmdStanFit
{
    /**
     * PSIS-LOO as in CmdStanR's loo() (see Loo::loo() for the result shape). momentMatch: true needs the phit
     * posterior-draw kernels and model methods; maxIters, kThreshold, split and cov are loo_moment_match()'s options
     * (see MomentMatch::loo() for the added moment_match key and the differences from loo).
     */
    public function loo(string $variables = 'log_lik', array|float|bool|null $rEff = false, bool $momentMatch = false, int $maxIters = 30, ?float $kThreshold = null, bool $split = true, bool $cov = true): array
    {
        if ($momentMatch) { return MomentMatch::fromFit($this, $variables, $rEff, $maxIters, $kThreshold, $split, $cov); }
        return Loo::fromFit($this, $variables, $rEff);
    }
    public function waic(string $variables = 'log_lik'): array { return Loo::waicFromFit($this, $variables); }
}
