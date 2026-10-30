// A small hierarchical model with an indexed parameter, shaped like the
// Better Shoes latent-quality models: shoe_latent[1..L] around a shared mean.
data {
  int<lower=1> N;
  int<lower=1> L;
  array[N] int<lower=1, upper=L> shoe;
  vector[N] y;
}
parameters {
  real mu;
  real<lower=0> tau;
  real<lower=0> sigma;
  vector[L] shoe_latent;
}
model {
  mu ~ normal(0, 2);
  tau ~ normal(0, 1);
  sigma ~ normal(0, 1);
  shoe_latent ~ normal(mu, tau);
  y ~ normal(shoe_latent[shoe], sigma);
}
