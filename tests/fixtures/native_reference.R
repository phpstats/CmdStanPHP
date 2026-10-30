# Reference values for NativeCompatibilityTest: CmdStanR model methods and expose_functions().
# Args: stan_file data_file input_json output_json
args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages(library(cmdstanr))
set_cmdstan_path(Sys.getenv('CMDSTAN'))
input <- jsonlite::fromJSON(args[[3]], simplifyVector = TRUE)
model <- cmdstan_model(args[[1]], force_recompile = TRUE, quiet = TRUE)
model$expose_functions(quiet = TRUE)
# Full precision, so unconstraining the simplex draws stays within Stan's tolerance.
fit <- model$sample(data = args[[2]], chains = 1, iter_warmup = 100, iter_sampling = 5, seed = 1, sig_figs = 18, refresh = 0, show_messages = FALSE, show_exceptions = FALSE)
fit$init_model_methods(seed = input$seed, quiet = TRUE)
u <- input$unconstrained
grad <- function(jacobian) { g <- fit$grad_log_prob(u, jacobian = jacobian); list(log_prob = attr(g, 'log_prob'), gradient = as.numeric(g)) }
hess <- fit$hessian(u)
params <- c('mu', 'sigma', 'a', 'm', 's', 'S')
draws <- as.data.frame(posterior::subset_draws(fit$draws(format = 'draws_df'), variable = params))
unconstrained_draws <- fit$unconstrain_draws(format = 'draws_matrix')
f <- model$functions
cm <- f$cmul(complex(real = 1, imaginary = 2), complex(real = 3, imaginary = -1))
out <- list(
  log_prob = fit$log_prob(u), log_prob_no_jacobian = fit$log_prob(u, jacobian = FALSE),
  grad_log_prob = grad(TRUE), grad_log_prob_no_jacobian = grad(FALSE),
  hessian = list(log_prob = hess$log_prob, grad_log_prob = as.numeric(hess$grad_log_prob), hessian = unname(hess$hessian)),
  unconstrain_variables = as.numeric(fit$unconstrain_variables(input$variables)),
  constrain_variables = lapply(fit$constrain_variables(u, generated_quantities = FALSE), function(x) if (length(dim(x)) == 2) unname(x) else as.numeric(x)),
  skeleton = lapply(fit$metadata()$stan_variable_sizes, as.integer),
  draws = draws[, setdiff(names(draws), c('.chain', '.iteration', '.draw'))],
  unconstrained_draws = unname(matrix(as.numeric(unconstrained_draws), nrow = nrow(unconstrained_draws))),
  unconstrained_names = colnames(unconstrained_draws),
  functions = list(
    add = f$add(1.5, 2L), scale = as.numeric(f$scale(c(1, 2, 3), 2)), outer = unname(f$outer(c(1, 2), c(3, 4, 5))),
    twice = f$twice(c(1.5, -2)), pairs = f$pairs(3L), cmul = c(Re(cm), Im(cm)),
    draw_rng = f$draw_rng(0.5, seed = 42), shifted_lpdf = f$shifted_lpdf(0.3, 1), counts_lpmf = f$counts_lpmf(2L, 1.5)
  )
)
jsonlite::write_json(out, args[[4]], digits = NA, auto_unbox = TRUE, matrix = 'rowmajor')
