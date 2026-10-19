<?php
/**
 * Runs a vignette the way the site does: php site/scripts/run-vignette.php <slug> [name=value ...]. Prints the block
 * types and the run time, and with --json the blocks as JSON. Unset inputs take their defaults.
 */
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';

$args = array_slice($argv, 1); $json = in_array('--json', $args, true); $args = array_values(array_diff($args, ['--json']));
$vignette = require __DIR__.'/../vignettes/'.($args[0] ?? throw new InvalidArgumentException('Usage: run-vignette.php <slug> [name=value ...] [--json]')).'.php';
$input = array_map(fn($spec) => $spec['default'], $vignette['inputs']);
foreach (array_slice($args, 1) as $pair) { [$name, $value] = explode('=', $pair, 2); $input[$name] = $value; }

$start = hrtime(true);
$blocks = $vignette['run']($input, realpath(__DIR__.'/../data'));
$ms = (hrtime(true) - $start) / 1e6;
$encoded = json_encode($blocks, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
if ($json) { echo $encoded, "\n"; exit; }
printf("%s: %d blocks (%s) in %.0f ms, %d bytes of JSON\n", $args[0], count($blocks), implode(', ', array_column($blocks, 'type')), $ms, strlen($encoded));
