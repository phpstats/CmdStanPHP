<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanLaplace extends CmdStanFit
{
    public function lpApprox(): array { return $this->approximateLp('lp_approx__'); }
    public function mode(): array
    {
        $mode = $this->context['mode'] ?? null;
        if ($mode instanceof CmdStanMLE) { return $mode->mle(); }
        if (is_array($mode)) { return $mode; }
        if (is_string($mode) && is_file($mode)) {
            if (str_ends_with($mode, '.csv')) { return Csv::read($mode)['estimate'] ?? []; }
            return json_decode(file_get_contents($mode), true, flags: JSON_THROW_ON_ERROR);
        }
        return [];
    }
}
