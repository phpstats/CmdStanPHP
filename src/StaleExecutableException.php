<?php
declare(strict_types=1);
namespace CmdStan;
/** The model's executable no longer matches its Stan program or build (CmdStanR's cmdstanr_stale_executable condition). */
final class StaleExecutableException extends \RuntimeException {}
