# The timed region of cmdstanr.R, split into phases. Prints milliseconds per phase.
suppressMessages(library(cmdstanr))
args <- commandArgs(trailingOnly = TRUE)
exe <- args[1]
out <- args[2]
data <- list(J = 8, y = c(28, 8, -3, 7, -1, 1, 18, 12), sigma = c(15, 10, 16, 11, 9, 11, 10, 18))

t0 <- Sys.time()
model <- cmdstan_model(exe_file = exe)
t1 <- Sys.time()
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
t2 <- Sys.time()
summary <- fit$summary(
  c("mu", "tau", "theta"),
  "mean",
  "sd",
  ~quantile(.x, probs = c(0.05, 0.95)),
  "rhat",
  "ess_bulk"
)
t3 <- Sys.time()

ms <- function(a, b) as.numeric(difftime(b, a, units = "secs")) * 1000
cat(jsonlite::toJSON(list(load = ms(t0, t1), sample = ms(t1, t2), summary = ms(t2, t3), ms = ms(t0, t3),
                          csv = fit$output_files(), mu = summary$mean[1]),
                     auto_unbox = TRUE, digits = NA), "\n")
