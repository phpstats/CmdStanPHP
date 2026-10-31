# CmdStanPHP

CmdStanPHP fits [Stan](https://mc-stan.org) models from PHP through [CmdStan](https://mc-stan.org/users/interfaces/cmdstan). It is a port of [CmdStanR](https://mc-stan.org/cmdstanr/) 1.0 and keeps CmdStanR's function and option names. It compiles Stan programs, runs MCMC, optimization, Laplace, ADVI, Pathfinder, and standalone generated quantities, and returns draws, summaries, diagnostics, PSIS-LOO, and WAIC as PHP arrays. No R installation is needed.

Documentation: <https://cmdstanphp.phpstats.org/docs>

## Installation

```sh
composer require phpstats/cmdstanphp
```

Requirements:

- PHP 8.2, 8.3, 8.4, or 8.5
- The JSON extension
- PHP's `proc_open()` function
- CmdStan 2.37 or later
- make and a C++ compiler that CmdStan supports

CmdStanPHP finds CmdStan through the `CMDSTAN` environment variable or under `~/.cmdstan`. `CmdStan\install_cmdstan('2.40.0', cores: 2)` downloads and builds an official release. The [installation guide](https://cmdstanphp.phpstats.org/docs/installation) covers the toolchain, the supported platforms, and the optional FFI extension, phit extension, phibble package, and vl-convert binary.

## Example

`bernoulli.stan`:

```stan
data {
  int<lower=0> N;
  array[N] int<lower=0, upper=1> y;
}
parameters {
  real<lower=0, upper=1> theta;
}
model {
  theta ~ beta(1, 1);
  y ~ bernoulli(theta);
}
```

`bernoulli.php`, in the same directory:

```php
<?php
require __DIR__.'/vendor/autoload.php';

use function CmdStan\cmdstan_model;

$model = cmdstan_model(__DIR__.'/bernoulli.stan');

$fit = $model->sample(
    data: ['N' => 10, 'y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1]],
    seed: 123,
    chains: 4,
    parallel_chains: 4,
    show_messages: false,
);

print_r($fit->summary('theta'));
print_r($fit->diagnosticSummary());
```

[Getting started](https://cmdstanphp.phpstats.org/docs/getting-started) shows and explains the output.

## Compatibility

CmdStanPHP does not implement every CmdStanR feature. The [compatibility page](https://cmdstanphp.phpstats.org/docs/compatibility) maps CmdStanR's names to CmdStanPHP's and lists the differences and the missing features. [Verification](https://cmdstanphp.phpstats.org/docs/verification) describes the tests, including the comparisons with CmdStanR, posterior, loo, and bayesplot.

## Development

```sh
composer install
composer test                 # unit tests, no CmdStan needed
composer test:integration     # compiles and runs real models
composer test:compatibility   # compares with R; needs CmdStanR and R packages
composer lint
```

See [Contributing](https://cmdstanphp.phpstats.org/docs/contributing).

## License

MIT. The example models in `resources/examples/` come from CmdStanR under its BSD 3-Clause license (see the `NOTICE` there). CmdStan and CmdStanR are separate Stan projects. CmdStanPHP is an independent implementation and not an official Stan interface.
