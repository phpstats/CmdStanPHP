# Reference side of site/verifications/scripts/verify.php: samples with CmdStanR at the given seed and settings, then
# summarises with posterior and computes PSIS-LOO and WAIC with loo.
# Rscript verify.R <stan_file> <exe_file> <data.json> <seed> <iter_sampling> <output.json>
args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages({ library(cmdstanr); library(posterior); library(loo) })
set_cmdstan_path(Sys.getenv("CMDSTAN"))
model <- cmdstan_model(stan_file = args[[1]], exe_file = args[[2]], compile = FALSE)
fit <- model$sample(data = args[[3]], seed = as.integer(args[[4]]), chains = 4, parallel_chains = 4, iter_warmup = 1000,
                    iter_sampling = as.integer(args[[5]]), refresh = 0, show_messages = FALSE, output_dir = tempdir())
draws <- fit$draws()
summary <- as.data.frame(summarise_draws(draws, default_summary_measures(), default_convergence_measures()))
log_lik <- fit$draws("log_lik")
loo_fit <- loo(log_lik, r_eff = relative_eff(exp(log_lik)))
waic_fit <- suppressWarnings(waic(log_lik))
jsonlite::write_json(list(
  variables = variables(draws),
  draws = lapply(seq_len(dim(draws)[3]), function(v) as.vector(draws[, , v])),  # iteration-major within chain
  summary = summary,
  loo = list(estimates = unname(loo_fit$estimates), pointwise = unname(loo_fit$pointwise[, "elpd_loo"]),
             pareto_k = unname(loo_fit$diagnostics$pareto_k), n_eff = unname(loo_fit$diagnostics$n_eff)),
  waic = list(estimates = unname(waic_fit$estimates), pointwise = unname(waic_fit$pointwise[, "elpd_waic"])),
  versions = list(cmdstanr = as.character(packageVersion("cmdstanr")), posterior = as.character(packageVersion("posterior")),
                  loo = as.character(packageVersion("loo")))
), args[[6]], digits = NA, auto_unbox = TRUE, na = "string")
