# CmdStanR side of site/benchmarks/scripts/bench.php: times sample() and summary() on an already compiled model.
# Rscript bench.R <stan_file> <exe_file> <data.json> <reps> <seed> <output.json>
args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages(library(cmdstanr))
set_cmdstan_path(Sys.getenv("CMDSTAN"))
model <- cmdstan_model(stan_file = args[[1]], exe_file = args[[2]], compile = FALSE)
reps <- as.integer(args[[4]]); seed <- as.integer(args[[5]])
sample_ms <- numeric(reps); summary_ms <- numeric(reps)
for (r in seq_len(reps)) {
  dir <- tempfile("bench"); dir.create(dir)
  t0 <- Sys.time()
  fit <- model$sample(data = args[[3]], seed = seed, chains = 4, parallel_chains = 4, iter_warmup = 1000,
                      iter_sampling = 1000, refresh = 0, show_messages = FALSE, output_dir = dir)
  t1 <- Sys.time()
  invisible(fit$summary())
  t2 <- Sys.time()
  sample_ms[r] <- 1000 * as.numeric(difftime(t1, t0, units = "secs"))
  summary_ms[r] <- 1000 * as.numeric(difftime(t2, t1, units = "secs"))
  unlink(dir, recursive = TRUE)
}
jsonlite::write_json(list(sample = sample_ms, summary = summary_ms, r = paste(R.version$major, R.version$minor, sep = "."),
                          cmdstanr = as.character(packageVersion("cmdstanr")), posterior = as.character(packageVersion("posterior"))),
                     args[[6]], digits = NA, auto_unbox = TRUE)
