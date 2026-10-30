args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages(library(cmdstanr))
set_cmdstan_path(Sys.getenv('CMDSTAN'))
options <- jsonlite::fromJSON(args[[4]], simplifyVector = TRUE)
# CmdStanR 1.0 runs PHP's executable as it is, without its Stan file (args[[2]] is unused).
model <- cmdstan_model(exe_file = args[[3]])
fit <- do.call(model[[args[[1]]]], options)
draws <- as.data.frame(fit$draws(format = 'draws_df'))
summary <- as.data.frame(fit$summary())
diagnostics <- if (args[[1]] == 'sample') fit$diagnostic_summary() else NULL
approx_lp <- if (args[[1]] %in% c('variational', 'pathfinder', 'laplace')) fit$lp_approx() else NULL
jsonlite::write_json(list(draws = draws, summary = summary, diagnostics = diagnostics, approx_lp = approx_lp), args[[5]], digits = NA, auto_unbox = TRUE, na = 'null')
