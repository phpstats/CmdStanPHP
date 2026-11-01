# Fits the model with cmdstanr and writes its summary to output/cmdstanr/summary.json.
library(cmdstanr)
library(jsonlite)

here <- dirname(normalizePath(sub("^--file=", "", grep("^--file=", commandArgs(), value = TRUE))))
out <- file.path(here, "output", "cmdstanr")
dir.create(out, recursive = TRUE, showWarnings = FALSE)
file.copy(file.path(here, "schools.stan"), file.path(out, "schools.stan"), overwrite = TRUE)

model <- cmdstan_model(file.path(out, "schools.stan"))
fit <- model$sample(
  data = file.path(here, "data.json"),
  seed = 20261101,
  chains = 4,
  parallel_chains = 4,
  chain_ids = 1:4,
  iter_warmup = 1000,
  iter_sampling = 1000,
  init = 2,
  output_dir = out,
  show_messages = FALSE
)

variables <- c("mu", "tau", paste0("theta[", 1:8, "]"))
s <- fit$summary(variables, "mean", "sd", ~quantile(.x, probs = c(0.05, 0.95)), "rhat", "ess_bulk")
names(s) <- c("variable", "mean", "sd", "q5", "q95", "rhat", "ess_bulk")
write_json(list(
  versions = list(cmdstanr = as.character(packageVersion("cmdstanr")),
                  posterior = as.character(packageVersion("posterior")),
                  CmdStan = cmdstan_version(), R = R.version.string),
  csv = fit$output_files(),
  summary = s
), file.path(out, "summary.json"), auto_unbox = TRUE, digits = NA, pretty = TRUE)
