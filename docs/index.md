---
layout: page
title: CmdStanPHP
---

CmdStanPHP lets PHP applications compile Stan programs, run inference through CmdStan, and inspect the resulting posterior draws. PHP handles inputs, processes, and results; Stan handles the statistical computation.

```php
require 'vendor/autoload.php';
$model = CmdStan\cmdstan_model('examples/bernoulli.stan');
$fit = $model->sample(
    data: ['N' => 10, 'y' => [0,1,0,0,0,0,0,0,0,1]],
    chains: 4, parallel_chains: 2, seed: 123,
    iter_warmup: 500, iter_sampling: 500,
    refresh: 0, show_messages: false,
);
print_r($fit->summary('theta'));
```

For this model and data, the analytic posterior is Beta(3, 9), whose mean is 0.25. The integration tests compare the sampled mean with this value and check effective sample size and R-hat.

Start with [installation](installation.md), then follow the [inference guide](inference.md). The [API reference](api.md) lists supported methods and options. Read [compatibility](compatibility.md) before translating an R program: the CmdStan workflows and most CmdStanR features are supported, but R package types and a few CmdStanR features are not implemented. Common bayesplot diagnostics are available as ASCII text, Vega-Lite charts, and SVG/PNG images.

The package needs PHP 8.2 or newer and a working CmdStan toolchain. It has no runtime Composer dependencies beyond PHP and JSON. The FFI extension is needed only for in-process model methods and `expose_functions()`. Moment matching in `loo()` also needs the `phit` extension. PHP's `proc_open` must be enabled. A model run returns a typed fit object; nonzero process exits throw an exception containing the command, exit status, stdout, and stderr.

CmdStanPHP is part of the phpstats family with [phit](https://github.com/phpstats/phit), [phibble](https://github.com/phpstats/phibble), [phplyr](https://github.com/phpstats/phplyr), and [phiz](https://github.com/phpstats/phiz). Model data can be a phibble data frame, `StanData` builds regression data from columns or formulas, and fits return draws and summaries as columns or tables. The [API reference](api.md#data-frames-and-the-phpstats-family) describes the conventions. None of the family packages is required.
