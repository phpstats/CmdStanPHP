<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanMCMC extends CmdStanFit
{
    /**
     * PSIS-LOO as in CmdStanR's loo() (see Loo::loo() for the result shape). moment_match: true needs the phit
     * posterior-draw kernels and model methods; max_iters, k_threshold, split and cov are loo_moment_match()'s options
     * (see MomentMatch::loo() for the added moment_match key and the differences from loo).
     */
    public function loo(string $variables = 'log_lik', array|float|bool|null $r_eff = true, bool $moment_match = false, int $max_iters = 30, ?float $k_threshold = null, bool $split = true, bool $cov = true): array
    {
        if ($moment_match) { return MomentMatch::fromFit($this, $variables, $r_eff, $max_iters, $k_threshold, $split, $cov); }
        return Loo::fromFit($this, $variables, $r_eff);
    }
    public function waic(string $variables = 'log_lik'): array { return Loo::waicFromFit($this, $variables); }
}
