# CmdStanPHP

Compile Stan models, run Bayesian inference, and inspect posterior draws from PHP.

CmdStanPHP runs [Stan](https://mc-stan.org) programs through [CmdStan](https://mc-stan.org/docs/cmdstan-guide/), Stan's command-line interface. Its API is modeled on [CmdStanR](https://mc-stan.org/cmdstanr/) 1.0 and uses CmdStanR's function and option names.

Documentation: <https://cmdstanphp.phpstats.org/docs>

## Installation

```sh
composer require phpstats/cmdstanphp
```

CmdStanPHP needs PHP 8.2 to 8.5, CmdStan 2.37 or later, and a C++ toolchain, and does not need R. [Installation](https://cmdstanphp.phpstats.org/docs/installation) covers the requirements, CmdStan, and the optional extensions.

## Example

This Stan program estimates the probability `theta` of a success from `N` yes-or-no outcomes `y`. Save it as `bernoulli.stan`:

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

Compile it, sample four chains, and summarize `theta`. The data are CmdStan's own Bernoulli example data, `examples/bernoulli/bernoulli.data.json` in CmdStan:

```php
use function CmdStan\cmdstan_model;

$model = cmdstan_model(__DIR__.'/bernoulli.stan');

$data = [
    'N' => 10,
    'y' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 1],
];

$fit = $model->sample(
    data: $data,
    seed: 123,
    chains: 4,
    parallel_chains: 4,
    show_messages: false,
);

echo "variable   mean    sd    q5   q95  rhat ess_bulk\n";
foreach ($fit->summary('theta') as $r) {
    printf(
        "%-8s %6.3f %5.3f %5.3f %5.3f %5.3f %8.0f\n",
        $r['variable'],
        $r['mean'],
        $r['sd'],
        $r['q5'],
        $r['q95'],
        $r['rhat'],
        $r['ess_bulk'],
    );
}
```

```text
variable   mean    sd    q5   q95  rhat ess_bulk
theta     0.257 0.124 0.080 0.485 1.001     1232
```

[Getting started](https://cmdstanphp.phpstats.org/docs/getting-started) explains the output and the convergence checks.

## Documentation

The [documentation](https://cmdstanphp.phpstats.org/docs) has guides, worked examples, and [Functions and methods](https://cmdstanphp.phpstats.org/docs/api), an index of every function and every model and fit method.

## Compatibility

CmdStanPHP does not implement every CmdStanR feature. The [compatibility page](https://cmdstanphp.phpstats.org/docs/compatibility) maps CmdStanR's names to CmdStanPHP's and lists the differences and the missing features. [Verification](https://cmdstanphp.phpstats.org/verification) reports the comparisons with CmdStanR, posterior, loo, and bayesplot.

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

CmdStanPHP is released under the GNU General Public License, version 3 or later. See [LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md). The example models in `resources/examples/` come from CmdStanR under its BSD 3-Clause license (see the `NOTICE` there). CmdStan and CmdStanR are separate Stan projects, and CmdStanPHP is not an official Stan interface.
