# One timed run: model from the precompiled executable, 4 parallel chains, summary. Prints the elapsed milliseconds.
suppressMessages(library(cmdstanr))
args <- commandArgs(trailingOnly = TRUE)
exe <- args[1]
out <- args[2]
data <- list(J = 8, y = c(28, 8, -3, 7, -1, 1, 18, 12), sigma = c(15, 10, 16, 11, 9, 11, 10, 18))

start <- Sys.time()
model <- cmdstan_model(exe_file = exe)
fit <- model$sample(
  data = data,
  seed = 20261101,
  chains = 4,
  parallel_chains = 4,
  iter_warmup = 1000,
  iter_sampling = 1000,
  output_dir = out,
  show_messages = FALSE,
  refresh = 0
)
summary <- fit$summary(
  c("mu", "tau", "theta"),
  "mean",
  "sd",
  ~quantile(.x, probs = c(0.05, 0.95)),
  "rhat",
  "ess_bulk"
)
elapsed <- as.numeric(difftime(Sys.time(), start, units = "secs")) * 1000

cat(jsonlite::toJSON(list(ms = elapsed, csv = fit$output_files(), mu = summary$mean[1]),
                     auto_unbox = TRUE, digits = NA), "\n")
