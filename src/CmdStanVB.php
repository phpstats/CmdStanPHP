<?php
declare(strict_types=1);
namespace CmdStan;
final class CmdStanVB extends CmdStanFit
{
    public function lpApprox(): array { return $this->approximateLp('lp_approx__'); }
    public function variationalMean(): array { return $this->parsed()[0]['estimate'] ?? []; }
}
