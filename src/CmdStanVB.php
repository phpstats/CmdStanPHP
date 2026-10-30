<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanVB extends CmdStanFit
{
    /** CmdStanR 1.0's lp_approx(): the approximation's log density at each draw, as one list in draw order. */
    public function lpApprox(): array { return $this->column('lp_approx__'); }
    public function variationalMean(): array { return $this->parsed()[0]['estimate'] ?? []; }
}
