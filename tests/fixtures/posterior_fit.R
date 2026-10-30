# posterior's summaries and CmdStanR's sampler diagnostics for the draws PHP read.
# Usage: Rscript posterior_fit.R input.json output.json. input.json names the CSV files and a binary file with PHP's
# draws as big-endian doubles, iterations fastest, then chains, then variables. The summaries use those doubles rather
# than CmdStanR's own CSV reading, which is not always correctly rounded: an ulp can reorder two near-tied folded draws
# and move rhat's tail half by 1e-6.
args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages({ library(posterior); library(cmdstanr) })
input <- jsonlite::fromJSON(args[[1]], simplifyVector = TRUE)
n <- input$iterations * input$chains * length(input$variables)
values <- readBin(input$binary, what = "double", n = n, size = 8, endian = "big")
draws <- as_draws_array(array(values, dim = c(input$iterations, input$chains, length(input$variables)), dimnames = list(NULL, NULL, input$variables)))
probs <- input$probs
summary <- as.data.frame(summarise_draws(draws, "mean", "median", "sd", "mad", ~quantile2(.x, probs = probs), default_convergence_measures()))
fit <- as_cmdstan_fit(input$files, check_diagnostics = FALSE)
csv <- unclass(as_draws_matrix(fit$draws(variables = input$variables)))
attr(csv, "nchains") <- NULL
jsonlite::write_json(list(summary = summary, columns = colnames(csv), matrix = unname(csv), diagnostics = fit$diagnostic_summary()), args[[2]], digits = NA, auto_unbox = TRUE, na = 'null', matrix = 'rowmajor')
