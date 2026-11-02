# Third-party notices

CmdStanPHP is released under the GNU General Public License, version 3 or later, in [LICENSE](LICENSE). Parts of it follow the source code of the projects below, which keep their own licenses.

## posterior

- Version: 1.7.1
- URL: <https://mc-stan.org/posterior/>, <https://github.com/stan-dev/posterior>
- License: BSD 3-Clause
- Copyright, from its LICENSE file: `YEAR: 2021` and `COPYRIGHT HOLDER: posterior package authors; Stan Developers and their Assignees; Trustees of Columbia University`
- Files: `src/Summary.php`, `src/Diagnostics.php`, `src/Rvars.php`, and the Pareto smoothing, Pareto k, generalized Pareto fit and quantile, and tail-length functions in `src/Psis.php`.

```text
Copyright (c) 2021, posterior package authors; Stan Developers and their Assignees;
Trustees of Columbia University

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its
   contributors may be used to endorse or promote products derived from
   this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

## CmdStanR

- Version: 1.0 release candidate (0.99.0, commit `0a54123` of its v1.0 branch)
- URL: <https://mc-stan.org/cmdstanr/>, <https://github.com/stan-dev/cmdstanr>
- License: BSD 3-Clause
- Copyright, from its LICENSE file: `YEAR: 2019` and `COPYRIGHT HOLDER: Stan Developers and their Assignees`
- Files: `src/Inits.php`, and the CSV metadata reading, option handling, and output file naming in `src/Csv.php`, `src/CmdStanFit.php`, `src/CmdStanModel.php`, `src/Options.php`, and `src/functions.php`. The example programs and data in `resources/examples/` are copied from CmdStanR, with its notice in `resources/examples/NOTICE`.

```text
Copyright (c) 2019, Stan Developers and their Assignees

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its
   contributors may be used to endorse or promote products derived from
   this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

## matrixStats

- Version: 1.5.0
- URL: <https://github.com/HenrikBengtsson/matrixStats>
- License: Artistic License 2.0, in [LICENSES/Artistic-2.0.txt](LICENSES/Artistic-2.0.txt)
- Copyright holder, from its DESCRIPTION file: Henrik Bengtsson [aut, cre, cph]
- Files: `Special::logSumExp()` in `src/Special.php`.

## loo

- Version: 2.10.1.9000
- URL: <https://mc-stan.org/loo/>, <https://github.com/stan-dev/loo>
- License: GNU GPL, version 3 or later, the text in [LICENSE](LICENSE)
- Authors, from its DESCRIPTION file: Aki Vehtari, Jonah Gabry, Måns Magnusson, Yuling Yao, Paul-Christian Bürkner, Topi Paananen, and Andrew Gelman
- Files: `src/Loo.php`, `src/MomentMatch.php`, and the `psis()`, `psisColumns()`, and `prepareReff()` functions in `src/Psis.php`.

## bayesplot

- Version: 1.16.0.9000
- URL: <https://mc-stan.org/bayesplot/>, <https://github.com/stan-dev/bayesplot>
- License: GNU GPL, version 3 or later, the text in [LICENSE](LICENSE)
- Authors, from its DESCRIPTION file: Jonah Gabry and Tristan Mahr
- Files: the figure computations in `src/Plot.php`, which reproduce bayesplot's values, such as `mcmc_intervals_data()`.

## R

- Version: 4.6.1
- URL: <https://www.r-project.org>
- License: GNU GPL, version 2 or later, used here under version 3, the text in [LICENSE](LICENSE)
- Copyright, from its COPYRIGHTS file: "The bulk of this code is copyright by members of or all of the R Core Team."
- Files: `lbeta()` and the Stirling series in `src/Special.php`, which follow R's `lbeta.c`, and `bandwidth()`, `density()`, and `autocorrelation()` in `src/Plot.php`, which reproduce `bw.nrd0()`, `density()`, and `acf()`.
