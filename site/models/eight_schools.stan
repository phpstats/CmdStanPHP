// Eight schools, non-centered (Rubin 1981; Gelman et al., BDA3 section 5.5).
data {
  int<lower=1> J;
  vector[J] y;
  vector<lower=0>[J] sigma;
}
parameters {
  real mu;
  real<lower=0> tau;
  vector[J] theta_raw;
}
transformed parameters {
  vector[J] theta = mu + tau * theta_raw;
}
model {
  mu ~ normal(0, 5);
  tau ~ cauchy(0, 5);
  theta_raw ~ std_normal();
  y ~ normal(theta, sigma);
}
generated quantities {
  vector[J] log_lik;
  for (j in 1:J) log_lik[j] = normal_lpdf(y[j] | theta[j], sigma[j]);
}
