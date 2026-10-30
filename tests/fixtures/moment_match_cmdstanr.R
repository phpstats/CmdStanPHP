# CmdStanMCMC$loo(moment_match = TRUE) reference for MomentMatchIntegrationTest.
# Args: stan_file options_json output_json. CmdStanR compiles its own copy, since model methods need its build.
args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages(library(cmdstanr))
set_cmdstan_path(Sys.getenv('CMDSTAN'))
options <- jsonlite::fromJSON(args[[2]], simplifyVector = TRUE)
model <- cmdstan_model(args[[1]], force_recompile = TRUE, quiet = TRUE)
fit <- do.call(model$sample, options)
fit$init_model_methods(quiet = TRUE)
result <- function(loo) list(pointwise = lapply(as.data.frame(loo$pointwise), as.numeric), pareto_k = loo$diagnostics$pareto_k, n_eff = loo$diagnostics$n_eff)
quiet <- function(expr) suppressWarnings(suppressMessages(expr))
# r_eff = TRUE explicitly: CmdStanR 1.0 defaults to FALSE, and the shift-only split this test relies on was established with it on.
out <- list(lp = as.numeric(fit$draws('lp__', format = 'draws_matrix')), unsplit = result(quiet(fit$loo(moment_match = TRUE, split = FALSE, r_eff = TRUE))), split = result(quiet(fit$loo(moment_match = TRUE, r_eff = TRUE))))
jsonlite::write_json(out, args[[3]], digits = NA, auto_unbox = TRUE)
