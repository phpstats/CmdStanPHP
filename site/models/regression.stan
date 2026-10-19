// Varying-intercept linear regression on the data block from StanData::fromFormula(drop_intercept: true).
data {
  int<lower=1> N;
  int<lower=1> K;
  matrix[N, K] X;
  vector[N] y;
  int<lower=1> J;
  array[N] int<lower=1, upper=J> school_id;
}
parameters {
  real alpha;
  vector[K] beta;
  real<lower=0> sigma;
  real<lower=0> tau;
  vector[J] z;
}
transformed parameters {
  vector[J] a = tau * z;
}
model {
  alpha ~ normal(70, 20);
  beta ~ normal(0, 10);
  sigma ~ exponential(0.1);
  tau ~ exponential(0.2);
  z ~ std_normal();
  y ~ normal(alpha + X * beta + a[school_id], sigma);
}
generated quantities {
  vector[N] log_lik;
  {
    vector[N] mu = alpha + X * beta + a[school_id];
    for (n in 1:N) log_lik[n] = normal_lpdf(y[n] | mu[n], sigma);
  }
}
