<?php
declare(strict_types=1);
namespace CmdStan;
/**
 * Builds Vega-Lite 5 specs from Figure data. The statistics are inlined as computed by Plot; the specs use no
 * Vega-Lite density, binning, or quantile transforms, so rendered values agree with the ASCII output and with R.
 */
final class VegaLite
{
    private const DIVERGENT = '#d62728';
    public static function spec(Figure $figure, int $width = 480, int $height = 240, ?int $maxRows = null): array
    {
        if ($width < 50 || $height < 30) { throw new \InvalidArgumentException('Vega-Lite figures need width >= 50 and height >= 30 pixels.'); }
        if ($maxRows !== null && $maxRows < 1) { throw new \InvalidArgumentException('maxRows must be positive.'); }
        $rows = self::thin($figure, $maxRows); $m = $figure->meta;
        $spec = match ($figure->kind) {
            'trace' => ['mark' => ['type' => 'line', 'strokeWidth' => 0.8, 'opacity' => 0.8], 'encoding' => ['x' => self::q('iteration', 'iteration'), 'y' => self::q('value', $m['variable'], false), 'color' => self::chain()]],
            'hist' => ['mark' => ['type' => 'bar', 'binSpacing' => 1], 'encoding' => ['x' => self::q('x0', $m['variable']) + ['bin' => ['binned' => true]], 'x2' => ['field' => 'x1'], 'y' => self::q('count', 'count')]],
            'dens' => ['mark' => ['type' => 'line', 'strokeWidth' => 1.5], 'encoding' => ['x' => self::q('x', $m['variable'], false), 'y' => self::q('density', 'density')] + ($m['by_chain'] ? ['color' => self::chain()] : [])],
            'intervals' => self::intervals($m, $height),
            'rank_hist' => self::facet([['mark' => ['type' => 'bar', 'binSpacing' => 1], 'encoding' => ['x' => self::q('x0', 'rank') + ['bin' => ['binned' => true]], 'x2' => ['field' => 'x1'], 'y' => self::q('count', 'count')]], ['mark' => ['type' => 'rule', 'strokeDash' => [4, 3]], 'encoding' => ['y' => ['field' => 'expected', 'aggregate' => 'mean', 'type' => 'quantitative']]]], $width, $height),
            'scatter' => self::scatter($m),
            'acf' => self::facet([['mark' => 'bar', 'encoding' => ['x' => ['field' => 'lag', 'type' => 'ordinal', 'title' => 'lag', 'axis' => ['labelAngle' => 0]], 'y' => self::q('acf', 'autocorrelation')]], ['mark' => 'rule', 'encoding' => ['y' => ['datum' => 0]]]], $width, $height),
            'pareto_k' => ['transform' => [['calculate' => 'datum.k > '.self::number($m['threshold']), 'as' => 'above threshold']], 'layer' => [
                ['mark' => ['type' => 'rule', 'strokeDash' => [4, 3], 'color' => 'gray'], 'encoding' => ['y' => ['datum' => $m['threshold']]]],
                ['mark' => ['type' => 'rule', 'color' => self::DIVERGENT], 'encoding' => ['y' => ['datum' => 1]]],
                ['mark' => ['type' => 'point', 'filled' => true], 'encoding' => ['x' => self::q('observation', 'observation'), 'y' => self::q('k', 'Pareto k'), 'color' => ['field' => 'above threshold', 'type' => 'nominal', 'scale' => ['domain' => [false, true], 'range' => ['#4c78a8', self::DIVERGENT]]]]],
            ]],
            default => throw new \InvalidArgumentException('Unknown figure kind: '.$figure->kind),
        };
        $size = in_array($figure->kind, ['rank_hist', 'acf'], true) ? [] : ['width' => $width, 'height' => $height];
        $divergent = $figure->kind === 'scatter' && $m['divergences'] ? count(array_filter($figure->data, fn($row) => $row['divergent'])) : null;
        $title = $divergent === null ? $figure->title : ['text' => $figure->title, 'subtitle' => 'red: '.$divergent.' divergent transition'.($divergent === 1 ? '' : 's')];
        return ['$schema' => Figure::VEGA_LITE_SCHEMA, 'title' => $title, 'data' => ['values' => $rows]] + $size + $spec;
    }
    /** Keeps every k-th trace iteration or scatter draw (and every divergent draw) so at most about $maxRows remain. */
    private static function thin(Figure $figure, ?int $maxRows): array
    {
        $n = count($figure->data);
        if ($maxRows === null || $n <= $maxRows || !in_array($figure->kind, ['trace', 'scatter'], true)) { return $figure->data; }
        $step = (int)ceil($n / $maxRows);
        if ($figure->kind === 'trace') { return array_values(array_filter($figure->data, fn($row) => ($row['iteration'] - 1) % $step === 0)); }
        return array_values(array_filter($figure->data, fn($row, $i) => $i % $step === 0 || $row['divergent'], ARRAY_FILTER_USE_BOTH));
    }
    private static function intervals(array $m, int $height): array
    {
        $y = ['field' => 'variable', 'type' => 'nominal', 'sort' => null, 'title' => null];
        $layers = [
            ['mark' => ['type' => 'rule', 'strokeWidth' => 1.5], 'encoding' => ['x' => self::q('ll', 'value', false), 'x2' => ['field' => 'hh'], 'y' => $y]],
            ['mark' => ['type' => 'rule', 'strokeWidth' => 5], 'encoding' => ['x' => self::q('l', 'value', false), 'x2' => ['field' => 'h'], 'y' => $y]],
        ];
        if ($m['point_est'] !== 'none') { $layers[] = ['mark' => ['type' => 'point', 'filled' => true, 'size' => 70, 'color' => 'white', 'stroke' => 'black'], 'encoding' => ['x' => self::q('m', 'value', false), 'y' => $y]]; }
        return ['layer' => $layers];
    }
    private static function scatter(array $m): array
    {
        $encoding = ['x' => self::q('x', $m['x_variable'], false), 'y' => self::q('y', $m['y_variable'], false)];
        $layers = [['transform' => [['filter' => '!datum.divergent']], 'mark' => ['type' => 'point', 'filled' => true, 'size' => 12, 'opacity' => 0.4], 'encoding' => $encoding]];
        if ($m['divergences']) { $layers[] = ['transform' => [['filter' => 'datum.divergent']], 'mark' => ['type' => 'point', 'filled' => true, 'size' => 30, 'color' => self::DIVERGENT], 'encoding' => $encoding]; }
        return ['layer' => $layers];
    }
    /** One row per chain; sizes apply to each facet. */
    private static function facet(array $layers, int $width, int $height): array
    {
        return ['facet' => ['row' => ['field' => 'chain', 'type' => 'ordinal', 'title' => null, 'header' => ['labelAngle' => 0, 'labelAlign' => 'left', 'labelExpr' => "'chain ' + datum.value"]]], 'spec' => ['width' => $width, 'height' => $height, 'layer' => $layers], 'resolve' => ['scale' => ['y' => 'shared']]];
    }
    private static function q(string $field, string $title, bool $zero = true): array
    {
        return ['field' => $field, 'type' => 'quantitative', 'title' => $title] + ($zero ? [] : ['scale' => ['zero' => false]]);
    }
    private static function chain(): array { return ['field' => 'chain', 'type' => 'nominal', 'title' => 'chain']; }
    private static function number(float $x): string { return sprintf('%.17g', $x); }
}
