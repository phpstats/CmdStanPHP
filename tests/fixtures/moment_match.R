args <- commandArgs(trailingOnly = TRUE)
suppressPackageStartupMessages(library(loo))
input <- jsonlite::fromJSON(args[[1]], simplifyVector = TRUE)
quiet <- function(expr) suppressWarnings(suppressMessages(expr))

# Conjugate regression y ~ normal(a + b x, 1), a, b ~ normal(0, 10): analytic densities stand in for a Stan model.
# Draws and log-likelihoods come from PHP, so both sides see identical inputs. Callbacks mirror CmdStanMCMC$loo().
model <- function(case) {
  pointwise <- function(theta, i) -0.5 * log(2 * pi) - 0.5 * (case$y[i] - theta[1] - theta[2] * case$x[i])^2
  list(upars = case$upars, ll = case$log_lik, pointwise = pointwise,
       log_prob = function(theta) sum(vapply(seq_along(case$x), function(i) pointwise(theta, i), numeric(1))) - 0.5 * case$prior * sum(theta^2))
}
moment_match <- function(m, split) {
  ll <- m$ll
  r_eff <- loo::relative_eff(exp(sweep(ll, 3, apply(ll, 3, max), FUN = "-")))
  psis_loo <- quiet(loo::loo.array(ll, r_eff = r_eff))
  out <- quiet(loo::loo_moment_match.default(
    x = m, loo = psis_loo, split = split, cores = 1,
    post_draws = function(x, ...) x$upars,
    log_lik_i = function(x, i, ...) x$ll[, , i],
    unconstrain_pars = function(x, pars, ...) pars,
    log_prob_upars = function(x, upars, ...) apply(upars, 1, x$log_prob),
    log_lik_i_upars = function(x, upars, i, ...) apply(upars, 1, function(theta) x$pointwise(theta, i))))
  list(pointwise = lapply(as.data.frame(out$pointwise), as.numeric), pareto_k = out$diagnostics$pareto_k,
       n_eff = out$diagnostics$n_eff, estimates = lapply(as.data.frame(out$estimates), as.numeric))
}

# loo:::loo_moment_match_split with two changes, used as the reference for CmdStan\MomentMatch: the exact inverse
# T^-1(y) = diag(1/c) M^-1 (y - m - t) + m (loo subtracts t after the linear map), and r_eff from both halves
# (loo takes the second half twice).
exact_split <- function(x, upars, cov, total_shift, total_scaling, total_mapping, i, log_prob_upars, log_lik_i_upars, r_eff_i, cores, is_method, ...) {
  S <- dim(upars)[1]
  S_half <- as.integer(0.5 * S)
  mean_original <- colMeans(upars)
  upars_trans <- sweep(upars, 2, mean_original, "-")
  upars_trans <- sweep(upars_trans, 2, total_scaling, "*")
  if (cov) upars_trans <- tcrossprod(upars_trans, total_mapping)
  upars_trans <- sweep(upars_trans, 2, total_shift + mean_original, "+")
  attributes(upars_trans) <- attributes(upars)
  upars_trans_inv <- sweep(upars, 2, mean_original + total_shift, "-")
  if (cov) upars_trans_inv <- tcrossprod(upars_trans_inv, solve(total_mapping))
  upars_trans_inv <- sweep(upars_trans_inv, 2, total_scaling, "/")
  upars_trans_inv <- sweep(upars_trans_inv, 2, mean_original, "+")
  attributes(upars_trans_inv) <- attributes(upars)
  upars_trans_half <- upars
  take <- seq_len(S_half)
  upars_trans_half[take, ] <- upars_trans[take, , drop = FALSE]
  upars_trans_half_inv <- upars
  take <- seq_len(S)[-seq_len(S_half)]
  upars_trans_half_inv[take, ] <- upars_trans_inv[take, , drop = FALSE]
  log_prob_half_trans <- log_prob_upars(x, upars = upars_trans_half, ...)
  log_prob_half_trans_inv <- log_prob_upars(x, upars = upars_trans_half_inv, ...)
  log_liki_half <- log_lik_i_upars(x, upars = upars_trans_half, i = i, ...)
  log_prob_half_trans_inv <- log_prob_half_trans_inv - log(prod(total_scaling)) - log(det(total_mapping))
  stable_S <- log_prob_half_trans > log_prob_half_trans_inv
  lwi_half <- -log_liki_half + log_prob_half_trans
  lwi_half[stable_S] <- lwi_half[stable_S] - (log_prob_half_trans[stable_S] + log1p(exp(log_prob_half_trans_inv[stable_S] - log_prob_half_trans[stable_S])))
  lwi_half[!stable_S] <- lwi_half[!stable_S] - (log_prob_half_trans_inv[!stable_S] + log1p(exp(log_prob_half_trans[!stable_S] - log_prob_half_trans_inv[!stable_S])))
  lr <- lwi_half
  lr[is.na(lr)] <- -Inf
  is_obj_half <- suppressWarnings(loo:::importance_sampling.default(lr, method = is_method, r_eff = r_eff_i, cores = cores))
  lwi_half <- as.vector(weights(is_obj_half))
  lr <- lwi_half + log_liki_half
  lr[is.na(lr)] <- -Inf
  is_obj_f_half <- suppressWarnings(loo:::importance_sampling.default(lr, method = is_method, r_eff = r_eff_i, cores = cores))
  lwfi_half <- as.vector(weights(is_obj_f_half))
  half_1 <- log_liki_half[seq_len(S_half)]
  half_2 <- log_liki_half[seq_len(S)[-seq_len(S_half)]]
  dim(half_1) <- c(length(half_1), 1, 1)
  dim(half_2) <- c(length(half_2), 1, 1)
  r_eff_i <- min(loo::relative_eff(exp(half_1), cores = cores), loo::relative_eff(exp(half_2), cores = cores))
  list(lwi = lwi_half, lwfi = lwfi_half, log_liki = log_liki_half, r_eff_i = r_eff_i)
}

out <- list()
for (name in names(input)) {
  m <- model(input[[name]])
  out[[name]] <- list(unsplit = moment_match(m, FALSE), loo_split = moment_match(m, TRUE))
}
environment(exact_split) <- asNamespace("loo")
assignInNamespace("loo_moment_match_split", exact_split, ns = "loo")
for (name in names(input)) out[[name]]$exact_split <- moment_match(model(input[[name]]), TRUE)
out$versions <- list(loo = as.character(packageVersion("loo")), loo_sha = packageDescription("loo")$RemoteSha)
jsonlite::write_json(out, args[[2]], digits = NA, auto_unbox = TRUE)
