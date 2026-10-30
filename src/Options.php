<?php
declare(strict_types=1);
namespace CmdStan;
/** Maps CmdStanR spelling to CmdStan's hierarchical argument tree. */
final class Options
{
    public const COMMON = ['data', 'seed', 'refresh', 'init', 'output_dir', 'output_basename', 'sig_figs', 'opencl_ids', 'show_messages', 'show_exceptions', 'save_cmdstan_config', 'timeout'];
    public const METHODS = [
        'sample' => ['threads_per_chain', 'save_latent_dynamics', 'chains', 'parallel_chains', 'chain_ids', 'iter_warmup', 'iter_sampling', 'save_warmup', 'thin', 'max_treedepth', 'adapt_engaged', 'adapt_delta', 'step_size', 'metric', 'metric_file', 'inv_metric', 'init_buffer', 'term_buffer', 'window', 'fixed_param', 'save_metric', 'diagnostics'],
        'optimize' => ['threads', 'algorithm', 'jacobian', 'init_alpha', 'iter', 'tol_obj', 'tol_rel_obj', 'tol_grad', 'tol_rel_grad', 'tol_param', 'history_size', 'save_iterations'],
        'variational' => ['threads', 'save_latent_dynamics', 'algorithm', 'iter', 'grad_samples', 'elbo_samples', 'eta', 'adapt_engaged', 'adapt_iter', 'tol_rel_obj', 'eval_elbo', 'draws'],
        'pathfinder' => ['threads', 'init_alpha', 'tol_obj', 'tol_rel_obj', 'tol_grad', 'tol_rel_grad', 'tol_param', 'history_size', 'single_path_draws', 'draws', 'num_paths', 'max_lbfgs_iters', 'num_elbo_draws', 'save_single_paths', 'psis_resample', 'calculate_lp'],
        'laplace' => ['threads', 'mode', 'opt_args', 'jacobian', 'draws', 'calculate_lp'],
        'generate_quantities' => ['threads_per_chain', 'fitted_params', 'parallel_chains'],
        'diagnose' => ['epsilon', 'error'],
        'log_prob' => ['unconstrained_params', 'constrained_params', 'jacobian'],
    ];
    private const INTEGERS = ['chains', 'parallel_chains', 'threads', 'threads_per_chain', 'thin', 'max_treedepth', 'iter', 'grad_samples', 'elbo_samples', 'adapt_iter', 'eval_elbo', 'draws', 'history_size', 'num_paths', 'max_lbfgs_iters', 'num_elbo_draws', 'single_path_draws'];
    private const NONNEGATIVE = ['iter_warmup', 'iter_sampling', 'refresh', 'init_buffer', 'term_buffer', 'window'];
    private const BOOLEANS = ['save_warmup', 'adapt_engaged', 'fixed_param', 'save_metric', 'save_cmdstan_config', 'save_latent_dynamics', 'show_messages', 'show_exceptions', 'jacobian', 'save_iterations', 'save_single_paths', 'psis_resample', 'calculate_lp'];
    public static function validate(string $method, array $options): void
    {
        foreach ($options as $key => $value) {
            if (!in_array($key, array_merge(self::COMMON, self::METHODS[$method]), true)) { throw new \InvalidArgumentException('Unknown '.$method.' option: '.$key); }
            if ($value === null) { continue; }
            if (in_array($key, self::INTEGERS, true) && (!is_int($value) || $value < 1)) { throw new \InvalidArgumentException($key.' must be a positive integer.'); }
            if (in_array($key, self::NONNEGATIVE, true) && (!is_int($value) || $value < 0)) { throw new \InvalidArgumentException($key.' must be a nonnegative integer.'); }
            if (in_array($key, self::BOOLEANS, true) && !is_bool($value)) { throw new \InvalidArgumentException($key.' must be boolean.'); }
            if (in_array($key, ['step_size', 'init_alpha', 'tol_obj', 'tol_rel_obj', 'tol_grad', 'tol_rel_grad', 'tol_param', 'eta', 'epsilon', 'error', 'timeout'], true) && (!is_numeric($value) || !is_finite((float)$value) || $value <= 0)) { throw new \InvalidArgumentException($key.' must be finite and positive.'); }
        }
        if (isset($options['adapt_delta']) && (!is_numeric($options['adapt_delta']) || !is_finite((float)$options['adapt_delta']) || $options['adapt_delta'] <= 0 || $options['adapt_delta'] >= 1)) { throw new \InvalidArgumentException('adapt_delta must be in (0, 1).'); }
        if (isset($options['metric']) && !in_array($options['metric'], ['diag_e', 'dense_e', 'unit_e'], true)) { throw new \InvalidArgumentException('Invalid metric.'); }
        if (isset($options['algorithm'])) {
            $allowed = $method === 'optimize' ? ['lbfgs', 'bfgs', 'newton'] : ['meanfield', 'fullrank'];
            if (!in_array($options['algorithm'], $allowed, true)) { throw new \InvalidArgumentException('Invalid algorithm for '.$method); }
        }
        if (isset($options['sig_figs']) && (!is_int($options['sig_figs']) || $options['sig_figs'] < 1 || $options['sig_figs'] > 18)) { throw new \InvalidArgumentException('sig_figs must be between 1 and 18.'); }
        if (isset($options['seed'])) {
            foreach ((array)$options['seed'] as $seed) {
                if (!is_int($seed) || $seed < 0 || $seed > 4294967295) { throw new \InvalidArgumentException('Seed must be a uint32 integer.'); }
            }
        }
        if (isset($options['opencl_ids']) && (!is_array($options['opencl_ids']) || count($options['opencl_ids']) !== 2 || !array_is_list($options['opencl_ids']) || count(array_filter($options['opencl_ids'], fn($x) => is_int($x) && $x >= 0)) !== 2)) { throw new \InvalidArgumentException('opencl_ids must contain platform and device integers.'); }
        if (isset($options['diagnostics']) && (!is_array($options['diagnostics']) || array_diff($options['diagnostics'], ['divergences', 'treedepth', 'ebfmi']) !== [])) { throw new \InvalidArgumentException('Invalid diagnostics selection.'); }
        if (isset($options['inv_metric'], $options['metric_file'])) { throw new \InvalidArgumentException('Specify inv_metric or metric_file, not both.'); }
    }
    public static function methodArgs(string $method, array $o): array
    {
        $args = [$method];
        $add = static function (array $map) use (&$args, $o): void {
            foreach ($map as $key => $cli) { if (isset($o[$key])) { $args[] = $cli.'='.self::scalar($o[$key]); } }
        };
        if ($method === 'sample') {
            $add(['iter_sampling' => 'num_samples', 'iter_warmup' => 'num_warmup', 'save_warmup' => 'save_warmup', 'thin' => 'thin']);
            if ($o['fixed_param'] ?? false) { $args[] = 'algorithm=fixed_param'; }
            else {
                $args[] = 'algorithm=hmc';
                $add(['metric' => 'metric', 'metric_file' => 'metric_file', 'step_size' => 'stepsize']);
                $args[] = 'engine=nuts'; $add(['max_treedepth' => 'max_depth']);
                $args[] = 'adapt';
                $add(['adapt_engaged' => 'engaged', 'adapt_delta' => 'delta', 'init_buffer' => 'init_buffer', 'term_buffer' => 'term_buffer', 'window' => 'window', 'save_metric' => 'save_metric']);
            }
        } elseif ($method === 'optimize') {
            $add(['iter' => 'iter', 'jacobian' => 'jacobian', 'save_iterations' => 'save_iterations']);
            $args[] = 'algorithm='.($o['algorithm'] ?? 'lbfgs');
            $add(array_combine(['init_alpha', 'tol_obj', 'tol_rel_obj', 'tol_grad', 'tol_rel_grad', 'tol_param', 'history_size'], ['init_alpha', 'tol_obj', 'tol_rel_obj', 'tol_grad', 'tol_rel_grad', 'tol_param', 'history_size']));
        } elseif ($method === 'variational') {
            $add(['algorithm' => 'algorithm', 'iter' => 'iter', 'grad_samples' => 'grad_samples', 'elbo_samples' => 'elbo_samples', 'eta' => 'eta', 'tol_rel_obj' => 'tol_rel_obj', 'eval_elbo' => 'eval_elbo', 'draws' => 'output_samples']);
            $args[] = 'adapt'; $add(['adapt_engaged' => 'engaged', 'adapt_iter' => 'iter']);
        } elseif ($method === 'pathfinder') {
            $add(['init_alpha' => 'init_alpha', 'tol_obj' => 'tol_obj', 'tol_rel_obj' => 'tol_rel_obj', 'tol_grad' => 'tol_grad', 'tol_rel_grad' => 'tol_rel_grad', 'tol_param' => 'tol_param', 'history_size' => 'history_size', 'single_path_draws' => 'num_draws', 'draws' => 'num_psis_draws', 'num_paths' => 'num_paths', 'max_lbfgs_iters' => 'max_lbfgs_iters', 'num_elbo_draws' => 'num_elbo_draws', 'save_single_paths' => 'save_single_paths', 'psis_resample' => 'psis_resample', 'calculate_lp' => 'calculate_lp']);
        } elseif ($method === 'laplace') {
            $add(['mode' => 'mode', 'jacobian' => 'jacobian', 'draws' => 'draws', 'calculate_lp' => 'calculate_lp']);
        } elseif ($method === 'generate_quantities') { $add(['fitted_params' => 'fitted_params']); }
        elseif ($method === 'log_prob') { $add(['unconstrained_params' => 'unconstrained_params', 'constrained_params' => 'constrained_params', 'jacobian' => 'jacobian']); }
        else { $args[] = 'test=gradient'; $add(['epsilon' => 'epsilon', 'error' => 'error']); }
        return $args;
    }
    public static function scalar(mixed $value): string
    {
        if (is_bool($value)) { return $value ? '1' : '0'; }
        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) { throw new \InvalidArgumentException('CLI values must be finite scalars.'); }
        return (string)$value;
    }
}
