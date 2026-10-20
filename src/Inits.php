<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Initial values from fits and draws, ported from CmdStanR's process_init methods.
 *
 * $parameters is the stanc --info parameter map (CmdStanModel::variables()['parameters']):
 * ['theta' => ['type' => 'real', 'dimensions' => 0], ...]. When null, every non-reserved variable (no '__' suffix)
 * is used and dimensions are inferred from the column names.
 * Results are ['inits' => list of per-chain arrays ready for StanJson / sample(init: ...), 'warnings' => list of
 * ['chain' => int, 'type' => 'missing_parameters'|'unknown_parameters'|'pareto_smooth', 'parameters' => list<string>, 'message' => string]].
 * Resampling uses \Random\Engine\Mt19937($seed); it is reproducible in PHP but does not reproduce R's RNG streams.
 */
final class Inits
{
    public static function fromFit(CmdStanFit $fit, int $numProcs, ?array $parameters = null, ?int $seed = null, bool $warnPartial = true): array
    {
        if ($numProcs < 1) { throw new \InvalidArgumentException('At least one chain is required.'); }
        self::validateFit($fit, $parameters);
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        if ($fit instanceof CmdStanMLE) { $rows = array_fill(0, $numProcs, $fit->mle()); $warnings = []; }
        elseif ($fit instanceof CmdStanMCMC) { $rows = $fit->draws(format: 'draws_df'); $rows = self::pick($rows, self::sampleNoReplace(array_fill(0, count($rows), 1.0), $numProcs, $rng)); $warnings = []; }
        elseif ($fit instanceof CmdStanPathfinder && !self::truthy(self::metadata($fit)['calculate_lp'] ?? true)) { $rows = $fit->draws(format: 'draws_df'); $rows = self::pick($rows, self::sampleNoReplace(array_fill(0, count($rows), 1.0), $numProcs, $rng)); $warnings = []; }
        elseif ($fit instanceof CmdStanVB || $fit instanceof CmdStanLaplace || $fit instanceof CmdStanPathfinder) { [$rows, $warnings] = self::approximate($fit, $numProcs, $rng); }
        else { throw new \InvalidArgumentException('Initial values can only be created from MCMC, optimization, variational, Laplace or Pathfinder fits.'); }
        $result = self::fromDraws($rows, $numProcs, $parameters, $seed, $warnPartial);
        $result['warnings'] = array_merge($warnings, $result['warnings']);
        return $result;
    }
    /**
     * cmdstanr:::process_init.draws for draws_df/draws_matrix rows (list of [variable => value]) or a draws_array
     * [iteration][chain][variable => value]. Fewer rows than chains are recycled; more are resampled without replacement.
     */
    public static function fromDraws(array $draws, int $numProcs, ?array $parameters = null, ?int $seed = null, bool $warnPartial = true): array
    {
        if ($numProcs < 1) { throw new \InvalidArgumentException('At least one chain is required.'); }
        $rows = self::rows($draws); $n = count($rows);
        if ($n === 0) { throw new \InvalidArgumentException('Draws must not be empty.'); }
        if ($n < $numProcs) { $rows = array_map(fn($i) => $rows[$i % $n], range(0, $numProcs - 1)); }
        elseif ($n > $numProcs) { $rows = self::pick($rows, self::sampleNoReplace(array_fill(0, $n, 1.0), $numProcs, new \Random\Randomizer(new \Random\Engine\Mt19937($seed)))); }
        $inits = [];
        foreach ($rows as $row) { $inits[] = self::nest($row, $parameters); }
        return self::validate($inits, $numProcs, $parameters, $warnPartial);
    }
    /**
     * Automatic parameter-dimension matching for one draw: flat columns ('x[1,2]') become zero-based nested arrays sized
     * by the maximum index; only model parameters are kept; dimensions == 0 gives a float. Throws when values are NA/Inf.
     */
    public static function nest(array $row, ?array $parameters = null): array
    {
        $columns = [];
        foreach ($row as $name => $value) {
            if (str_starts_with((string)$name, '.')) { continue; }
            [$base] = Rvars::parseName((string)$name);
            if ($parameters === null ? str_contains((string)$name, '__') : !isset($parameters[$base])) { continue; }
            $columns[$name] = (float)$value;
        }
        $rvars = Rvars::fromDrawsDf([$columns]); $names = $parameters === null ? array_keys($rvars) : array_values(array_filter(array_keys($parameters), fn($p) => isset($rvars[$p])));
        $init = []; $bad = [];
        foreach ($names as $name) {
            $value = Rvars::draw($rvars[$name], 0); $flat = is_array($value) ? self::flatten($value) : [$value];
            if (count(array_filter($flat, 'is_finite')) !== count($flat)) { $bad[] = $name; }
            $dimensions = $parameters === null ? count($rvars[$name]['dims']) : (int)($parameters[$name]['dimensions'] ?? 0);
            if ($dimensions === 0 && is_array($value)) { $value = count($flat) === 1 ? $flat[0] : $flat; }
            $init[$name] = $value;
        }
        if ($bad !== []) { throw new \InvalidArgumentException((count($bad) > 1 ? 'Variables: ' : 'Variable: ').implode(', ', $bad).' contains NA or Inf values!'); }
        return $init;
    }
    /**
     * cmdstanr:::process_init.list: checks a list of per-chain init arrays. Wrong length, empty entries or bracketed names
     * throw; missing (and unknown) parameters are reported as warnings; length-1 values of one-dimensional parameters
     * are wrapped in a list.
     */
    public static function validate(array $inits, int $numProcs, ?array $parameters = null, bool $warnPartial = true): array
    {
        if (!array_is_list($inits) || count(array_filter($inits, fn($x) => is_array($x) && ($x === [] || !array_is_list($x)))) !== count($inits)) { throw new \InvalidArgumentException("If 'init' is a list it must be a list of arrays keyed by parameter name."); }
        if (count($inits) !== $numProcs) { throw new \InvalidArgumentException("'init' has the wrong length. See documentation of 'init' argument."); }
        if (in_array([], $inits, true)) { throw new \InvalidArgumentException("'init' contains empty lists."); }
        foreach ($inits as $init) { foreach (array_keys($init) as $name) { if (str_contains((string)$name, '[')) { throw new \InvalidArgumentException("'init' contains entries with parameter names that include square-brackets, which is not permitted. To supply inits for a vector, matrix or array of parameters, create a single entry with the parameter's name in the 'init' list and specify initial values for the entire parameter container."); } } }
        $warnings = [];
        if ($parameters !== null) {
            foreach ($inits as $i => &$init) {
                $missing = array_values(array_diff(array_map('strval', array_keys($parameters)), array_map('strval', array_keys($init))));
                $unknown = array_values(array_diff(array_map('strval', array_keys($init)), array_map('strval', array_keys($parameters))));
                foreach ($parameters as $name => $info) { if (array_key_exists($name, $init) && (int)($info['dimensions'] ?? 0) === 1 && !is_array($init[$name])) { $init[$name] = [$init[$name]]; } }
                $prefix = count($inits) > 1 ? 'chain '.($i + 1).': ' : '';
                if ($missing !== [] && $warnPartial) { $warnings[] = ['chain' => $i + 1, 'type' => 'missing_parameters', 'parameters' => $missing, 'message' => 'Init values were only set for a subset of parameters. Missing init values for the following parameters: '.$prefix.implode(', ', $missing)]; }
                if ($unknown !== [] && $warnPartial) { $warnings[] = ['chain' => $i + 1, 'type' => 'unknown_parameters', 'parameters' => $unknown, 'message' => 'Init values were supplied for names that are not model parameters and will be ignored: '.$prefix.implode(', ', $unknown)]; }
            }
            unset($init);
        }
        return ['inits' => $inits, 'warnings' => $warnings];
    }
    /**
     * Weighted sampling without replacement (R's sample(replace = FALSE, prob = weights)): returns zero-based indices
     * in selection order. Weights need not be normalized.
     */
    public static function sampleNoReplace(array $weights, int $size, \Random\Randomizer $rng): array
    {
        $weights = array_map('floatval', array_values($weights));
        foreach ($weights as $w) { if (!is_finite($w) || $w < 0) { throw new \InvalidArgumentException('Resampling weights must be finite and non-negative.'); } }
        if ($size > count($weights)) { throw new \InvalidArgumentException("Argument 'ndraws' must be smaller than the total number of draws in method 'simple_no_replace'."); }
        if ($size > count(array_filter($weights, fn($w) => $w > 0))) { throw new \InvalidArgumentException('Too few positive probabilities.'); }
        $out = []; $total = array_sum($weights);
        for ($k = 0; $k < $size; ++$k) {
            $u = $rng->getInt(0, (1 << 53) - 1) / 9007199254740992 * $total; $chosen = null; $cumulative = 0.0;
            foreach ($weights as $i => $w) { if ($w <= 0) { continue; } $chosen = $i; $cumulative += $w; if ($u < $cumulative) { break; } }
            $out[] = $chosen; $total -= $weights[$chosen]; $weights[$chosen] = 0.0;
            if ($total <= 0) { $total = array_sum($weights); }
        }
        return $out;
    }
    /** cmdstanr:::process_init_approx: importance resampling with lw = lp__ - lp_approx__. Returns [rows, warnings]. */
    private static function approximate(CmdStanFit $fit, int $numProcs, \Random\Randomizer $rng): array
    {
        $rows = $fit->draws(format: 'draws_df'); $approx = $fit->lpApprox(); $lw = []; $warnings = [];
        foreach ($rows as $i => $row) {
            $value = ($row['lp__'] ?? NAN) - ($approx[$row['.iteration'] - 1][$row['.chain'] - 1] ?? NAN);
            $lw[$i] = is_finite($value) ? $value : -INF;
        }
        $first = [];
        foreach ($lw as $i => $value) { $first[pack('E', $value == 0.0 ? 0.0 : $value)] ??= $i; }
        if ($numProcs > count($first)) {
            [$name, $extra] = match (true) { $fit instanceof CmdStanPathfinder => [' Pathfinder ', ' Try running Pathfinder with psis_resample=FALSE.'], $fit instanceof CmdStanVB => [' CmdStanVB ', ''], default => [' CmdStanLaplace ', ''] };
            throw new \RuntimeException('Not enough distinct draws ('.$numProcs.') in'.$name.'fit to create inits.'.$extra);
        }
        $max = max($lw);
        if (count($first) < 0.95 * count($rows)) {
            // CmdStanR intends to keep one draw per distinct lw (its merge() keeps duplicates); PHP removes them.
            $keep = array_values($first); sort($keep); $rows = self::pick($rows, $keep);
            $weights = array_map(fn($i) => exp($lw[$i] - $max), $keep);
        } else {
            $smoothed = Psis::paretoSmooth(array_map(fn($v) => exp($v - $max), $lw), 'right', 1.0);
            $weights = $smoothed['x'];
            foreach ($smoothed['warnings'] as $message) { $warnings[] = ['chain' => null, 'type' => 'pareto_smooth', 'parameters' => [], 'message' => $message]; }
        }
        return [self::pick($rows, self::sampleNoReplace($weights, $numProcs, $rng)), $warnings];
    }
    private static function validateFit(CmdStanFit $fit, ?array $parameters): void
    {
        $codes = $fit->returnCodes();
        if ($codes !== [] && count(array_filter($codes, fn($c) => $c !== 0)) === count($codes)) { throw new \RuntimeException('We are unable to create initial values from a model with no samples. Please check the results of the model used for inits before continuing.'); }
        if ($parameters !== null) {
            $available = array_map(fn($name) => Rvars::parseName($name)[0], $fit->variables());
            if (array_intersect(array_map('strval', array_keys($parameters)), $available) === []) { throw new \InvalidArgumentException('None of the names of the parameters for the model used for initial values match the names of parameters from the model currently running.'); }
        }
    }
    private static function rows(array $draws): array
    {
        $draws = array_values($draws);
        if ($draws === [] || !is_array(reset($draws[0]))) { return $draws; }
        $rows = []; $chains = count($draws[0]);
        for ($c = 0; $c < $chains; ++$c) { foreach ($draws as $iteration) { $rows[] = $iteration[$c]; } }
        return $rows;
    }
    private static function pick(array $rows, array $indices): array { $rows = array_values($rows); return array_map(fn($i) => $rows[$i], $indices); }
    private static function flatten(array $value): array { $out = []; array_walk_recursive($value, function ($v) use (&$out) { $out[] = $v; }); return $out; }
    /** First-chain metadata, whether metadata() returns per-chain rows or CmdStanR's merged array. */
    private static function metadata(CmdStanFit $fit): array { $meta = $fit->metadata(); return array_is_list($meta) ? ($meta[0] ?? []) : $meta; }
    private static function truthy(mixed $value): bool { return !in_array($value, [false, 0, '0', 'false', 0.0], true); }
}
