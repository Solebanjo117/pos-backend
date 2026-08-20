<?php

namespace App\Services;

/**
 * Safe formula parser for Excel-like pricing formulas.
 *
 * Supported functions: ROUND, ROUNDUP, ROUNDDOWN, CEIL, FLOOR
 * Supported variables: costo, ITBM, base_price
 * Supported operators: +, -, *, /
 *
 * Examples:
 *   costo * 2
 *   ROUND(costo * 1.5)
 *   ROUNDUP(costo * 2)
 *   ROUNDUP(costo * 2) + 0.88
 *   FLOOR(costo * 2) + 0.88
 *   costo + costo * ITBM / 100
 */
class PricingFormulaService
{
    /**
     * Evaluate a pricing formula with the given context values.
     *
     * @param  string  $formula  The formula string (e.g. "ROUND(costo * 2)")
     * @param  float  $cost  The product cost (maps to 'costo')
     * @param  float  $taxRate  The tax rate in percent (maps to 'ITBM', e.g. 7 for 7%)
     * @return float The calculated price
     *
     * @throws \InvalidArgumentException if the formula is invalid
     */
    public function evaluate(string $formula, float $cost, float $taxRate = 7.0): float
    {
        $formula = trim($formula);

        if (empty($formula)) {
            return $cost;
        }

        // Step 1: Replace variable names with numeric values
        $expr = str_ireplace(
            ['costo',  'base_price', 'ITBM'],
            [$cost,    $cost,        $taxRate / 100],
            $formula
        );

        // Step 2: Resolve nested function calls from innermost to outermost
        $expr = $this->resolveFunctions($expr);

        // Step 3: Evaluate the remaining arithmetic expression
        return $this->evaluateArithmetic($expr);
    }

    /**
     * Validate that a formula string is safe and parseable.
     */
    public function validate(string $formula): bool
    {
        if (empty(trim($formula))) {
            return true; // Empty formula is valid (means no formula)
        }

        try {
            // Test with a sample cost of 10 and tax rate of 7
            $result = $this->evaluate($formula, 10.0, 7.0);

            return is_numeric($result) && $result >= 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get a human-readable preview of what a formula produces.
     */
    public function preview(string $formula, float $cost, float $taxRate = 7.0): array
    {
        try {
            $result = $this->evaluate($formula, $cost, $taxRate);

            return [
                'success' => true,
                'result' => round($result, 2),
                'formula' => $formula,
                'cost' => $cost,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'formula' => $formula,
                'cost' => $cost,
            ];
        }
    }

    /**
     * Aplica ITBM sobre el precio si la categoría lo tiene activo.
     * El ITBM se aplica SOBRE el resultado de la fórmula, no sobre el costo.
     *
     * @param  float  $price  El precio base
     * @param  bool  $appliesItbm  Si true, aplica ITBM
     * @param  float  $itbmPercent  Porcentaje de ITBM (default 7%)
     */
    public function applyItbm(float $price, bool $appliesItbm, float $itbmPercent = 7.0): float
    {
        if (! $appliesItbm) {
            return $price;
        }

        return round($price * (1 + $itbmPercent / 100), 2);
    }

    /**
     * Precio final para una categoría: evalúa fórmula → aplica ITBM si corresponde.
     * Retorna null si la fórmula es vacía o inválida.
     *
     * @param  string  $formula  Fórmula de la categoría (ej: "costo * 1.3 + 5")
     * @param  float  $costo  Costo del producto
     * @param  bool  $appliesItbm  Si true, aplica ITBM sobre el precio resultante
     * @param  float  $itbmPercent  Porcentaje de ITBM (default 7%)
     * @return float|null Precio calculado o null si la fórmula es inválida
     */
    public function calculate(string $formula, float $costo, bool $appliesItbm, float $itbmPercent = 7.0): ?float
    {
        if (empty(trim($formula))) {
            return null;
        }

        // Check if formula already handles ITBM to prevent double application
        $formulaUsesItbm = (bool) preg_match('/\bITBM\b/i', $formula);

        try {
            // Pass itbmPercent so evaluate() uses the correct value
            $base = $this->evaluate($formula, $costo, $itbmPercent);
        } catch (\Throwable $e) {
            return null; // Fórmula inválida → falla silenciosamente
        }

        // If formula already handles ITBM, don't apply it again
        if ($formulaUsesItbm) {
            return round($base, 2);
        }

        return $this->applyItbm($base, $appliesItbm, $itbmPercent);
    }

    /**
     * List of built-in preset formulas.
     */
    public static function presets(): array
    {
        return [
            ['label' => 'Costo × 2',                   'formula' => 'costo * 2'],
            ['label' => 'Costo × 1.5 (redondeado)',    'formula' => 'ROUND(costo * 1.5)'],
            ['label' => 'Costo × 2 (redondear arriba)', 'formula' => 'ROUNDUP(costo * 2)'],
            ['label' => 'Costo × 2 (termina en .88)',  'formula' => 'FLOOR(costo * 2) + 0.88'],
            ['label' => 'Costo × 2 (termina en .99)',  'formula' => 'FLOOR(costo * 2) + 0.99'],
            ['label' => 'Costo + ITBM',                'formula' => 'costo + costo * ITBM'],
            ['label' => 'Costo × 1.5 + ITBM',         'formula' => 'ROUND(costo * 1.5 + costo * 1.5 * ITBM)'],
        ];
    }

    // ==================== PRIVATE HELPERS ====================

    /**
     * Recursively resolve function calls (ROUND, ROUNDUP, ROUNDDOWN, CEIL, FLOOR).
     * Uses stack-based matching to handle deeply nested parentheses.
     * Example: ROUND((costo + 5) * (2 + 0.5)) → numeric value.
     */
    private function resolveFunctions(string $expr): string
    {
        $functions = ['ROUNDUP', 'ROUNDDOWN', 'ROUND', 'CEIL', 'FLOOR'];
        $maxIterations = 10;
        $iteration = 0;

        while ($iteration++ < $maxIterations) {
            $found = false;
            foreach ($functions as $fn) {
                $fnLower = strtolower($fn);
                $exprLower = strtolower($expr);
                $pos = 0;

                while (($pos = strpos($exprLower, $fnLower, $pos)) !== false) {
                    // Skip if function name is part of another word
                    if ($pos > 0 && ctype_alpha($expr[$pos - 1])) {
                        $pos++;

                        continue;
                    }

                    $afterFn = $pos + strlen($fn);

                    // Skip whitespace after function name
                    while ($afterFn < strlen($expr) && $expr[$afterFn] === ' ') {
                        $afterFn++;
                    }

                    // Must be followed by opening parenthesis
                    if ($afterFn >= strlen($expr) || $expr[$afterFn] !== '(') {
                        $pos++;

                        continue;
                    }

                    // Stack-based balanced parentheses matching
                    $depth = 0;
                    $end = $afterFn;
                    for ($i = $afterFn; $i < strlen($expr); $i++) {
                        if ($expr[$i] === '(') {
                            $depth++;
                        }
                        if ($expr[$i] === ')') {
                            $depth--;
                        }
                        if ($depth === 0) {
                            $end = $i;
                            break;
                        }
                    }

                    if ($depth !== 0) {
                        throw new \InvalidArgumentException("Paréntesis sin cerrar en función {$fn}");
                    }

                    // Extract and evaluate inner expression
                    $innerExpr = substr($expr, $afterFn + 1, $end - $afterFn - 1);
                    // Resolve nested functions inside the argument before arithmetic evaluation.
                    $resolvedInnerExpr = $this->resolveFunctions($innerExpr);
                    $innerValue = $this->evaluateArithmetic($resolvedInnerExpr);
                    $result = $this->applyFunction($fn, (float) $innerValue);

                    // Replace function call with result
                    $expr = substr($expr, 0, $pos).(string) $result.substr($expr, $end + 1);

                    $found = true;
                    break 2; // Break both foreach and while
                }
            }
            if (! $found) {
                break;
            }
        }

        return $expr;
    }

    /**
     * Apply a math function to a numeric value.
     */
    private function applyFunction(string $fn, float $value): float
    {
        return match (strtoupper($fn)) {
            'ROUND' => round($value),
            'ROUNDUP' => ceil($value),
            'ROUNDDOWN' => floor($value),
            'CEIL' => ceil($value),
            'FLOOR' => floor($value),
            default => $value,
        };
    }

    /**
     * Safely evaluate a purely arithmetic expression (no functions, no variables).
     * Uses a recursive descent parser — NO eval().
     *
     * Supports: +, -, *, /, unary minus, parentheses, floats.
     */
    private function evaluateArithmetic(string $expr): float
    {
        $expr = trim($expr);

        // Safety: only allow digits, operators, dots, spaces, and parentheses
        if (! preg_match('/^[\d\s\+\-\*\/\.\(\)]+$/', $expr)) {
            throw new \InvalidArgumentException("Expresión aritmética inválida: {$expr}");
        }

        // Use a simple token-based evaluator
        $tokens = $this->tokenize($expr);
        $pos = 0;
        $result = $this->parseExpression($tokens, $pos);

        if ($pos < count($tokens)) {
            throw new \InvalidArgumentException("Token inesperado en la fórmula: {$tokens[$pos]}");
        }

        return $result;
    }

    private function tokenize(string $expr): array
    {
        $tokens = [];
        $i = 0;
        $len = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];

            if ($ch === ' ') {
                $i++;

                continue;
            }

            if (in_array($ch, ['+', '-', '*', '/', '(', ')'])) {
                $tokens[] = $ch;
                $i++;

                continue;
            }

            if (is_numeric($ch) || $ch === '.') {
                $num = '';
                while ($i < $len && (is_numeric($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = $num;

                continue;
            }

            throw new \InvalidArgumentException("Carácter inválido en fórmula: '{$ch}'");
        }

        return $tokens;
    }

    // Recursive descent parser: expression → term ((+|-) term)*
    private function parseExpression(array &$tokens, int &$pos): float
    {
        $result = $this->parseTerm($tokens, $pos);

        while ($pos < count($tokens) && in_array($tokens[$pos], ['+', '-'])) {
            $op = $tokens[$pos++];
            $right = $this->parseTerm($tokens, $pos);
            $result = $op === '+' ? $result + $right : $result - $right;
        }

        return $result;
    }

    // term → factor ((*|/) factor)*
    private function parseTerm(array &$tokens, int &$pos): float
    {
        $result = $this->parseFactor($tokens, $pos);

        while ($pos < count($tokens) && in_array($tokens[$pos], ['*', '/'])) {
            $op = $tokens[$pos++];
            $right = $this->parseFactor($tokens, $pos);
            if ($op === '/' && abs($right) <= 1e-10) {
                throw new \InvalidArgumentException('División por cero en la fórmula');
            }
            $result = $op === '*' ? $result * $right : $result / $right;
        }

        return $result;
    }

    // factor → number | '(' expression ')' | '-' factor
    private function parseFactor(array &$tokens, int &$pos): float
    {
        if ($pos >= count($tokens)) {
            throw new \InvalidArgumentException('Fórmula incompleta');
        }

        $token = $tokens[$pos];

        if ($token === '(') {
            $pos++; // consume '('
            $result = $this->parseExpression($tokens, $pos);
            if ($pos >= count($tokens) || $tokens[$pos] !== ')') {
                throw new \InvalidArgumentException('Paréntesis sin cerrar en la fórmula');
            }
            $pos++; // consume ')'

            return $result;
        }

        if ($token === '-') {
            $pos++;

            return -$this->parseFactor($tokens, $pos);
        }

        if (is_numeric($token)) {
            $pos++;

            return floatval($token);
        }

        throw new \InvalidArgumentException("Token inesperado: '{$token}'");
    }
}
