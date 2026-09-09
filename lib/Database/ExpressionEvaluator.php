<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

/**
 * Sandboxed evaluator for the tiny expression DSL used by phpMyAdmin's
 * advisor rules.
 *
 * Grammar (PEG-style):
 *
 *     expr      ::= ternary
 *     ternary   ::= logicOr ( '?' ternary ':' ternary )?
 *     logicOr   ::= logicAnd ( ('||' | 'OR')  logicAnd )*
 *     logicAnd  ::= equality ( ('&&' | 'AND') equality )*
 *     equality  ::= relational ( ('==' | '!=') relational )*
 *     relational::= additive ( ('<' | '<=' | '>' | '>=') additive )*
 *     additive  ::= multiplicative ( ('+' | '-') multiplicative )*
 *     multiplicative ::= power ( ('*' | '/' | '%') power )*
 *     power     ::= unary ( '**' unary )*
 *     unary     ::= ('-' | '+' | '!') unary | primary
 *     primary   ::= number
 *                 | identifier
 *                 | identifier '(' arglist? ')'
 *                 | '(' expr ')'
 *     arglist   ::= expr ( ',' expr )*
 *
 * **There is no `eval()` anywhere in this class.**  The token list and
 * AST are produced by a recursive-descent parser we control end-to-end.
 *
 * Identifiers must resolve in the context map.  Function calls must
 * resolve to one of the whitelisted functions in {@see self::$functions}.
 * Anything else — string literals, function names not on the list,
 * unbalanced parentheses, dangling operators — throws an
 * {@see ExpressionException}.  Division by zero returns `INF` to match
 * phpMyAdmin's semantics (which exploits IEEE-754 behaviour for ratios).
 */
final class ExpressionEvaluator {
	/**
	 * Whitelisted functions.  Matches what phpMyAdmin's advisor uses
	 * (which is essentially `pow` and a few aliases).  Adding to this
	 * list is the only way new functions can be called from rules.
	 *
	 * @var array<string, callable(float...): float>
	 */
	private array $functions = [];

	public function __construct() {
		$this->functions = [
			'pow' => static fn (float $base, float $exp): float => $base ** $exp,
			'power' => static fn (float $base, float $exp): float => $base ** $exp,
			'min' => static fn (float ...$args): float => count($args) === 0 ? 0.0 : min($args),
			'max' => static fn (float ...$args): float => count($args) === 0 ? 0.0 : max($args),
			'abs' => static fn (float $x): float => abs($x),
			'ceil' => static fn (float $x): float => ceil($x),
			'floor' => static fn (float $x): float => floor($x),
			'round' => static fn (float $x): float => round($x),
		];
	}

	/**
	 * Evaluate `$expression` with identifiers resolved from `$context`.
	 *
	 * @param array<string, scalar|null> $context
	 * @throws ExpressionException on any parse / evaluation error.
	 */
	public function evaluate(string $expression, array $context): float|bool {
		$tokens = $this->tokenize($expression);
		$pos = 0;
		$result = $this->parseExpr($tokens, $pos, $context);
		if ($pos !== count($tokens)) {
			throw new ExpressionException(
				'Trailing tokens after expression at offset ' . $pos . ': ' . ($tokens[$pos][1] ?? '<eof>'),
			);
		}
		return $result;
	}

	// ── Tokenizer ─────────────────────────────────────────────────

	/**
	 * @return list<array{0:string,1:string}> Pairs of (type, lexeme).
	 */
	private function tokenize(string $src): array {
		$tokens = [];
		$len = strlen($src);
		$i = 0;

		while ($i < $len) {
			$c = $src[$i];

			// Whitespace
			if (ctype_space($c)) {
				$i++;
				continue;
			}

			// Numbers (integers and decimals; no scientific notation —
			// phpMyAdmin formulas don't use any).
			if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($src[$i + 1]))) {
				$start = $i;
				while ($i < $len && (ctype_digit($src[$i]) || $src[$i] === '.')) {
					$i++;
				}
				$tokens[] = ['NUMBER', substr($src, $start, $i - $start)];
				continue;
			}

			// Identifiers (letters, digits after first, underscore)
			if (ctype_alpha($c) || $c === '_') {
				$start = $i;
				while ($i < $len && (ctype_alnum($src[$i]) || $src[$i] === '_')) {
					$i++;
				}
				$ident = substr($src, $start, $i - $start);
				$upper = strtoupper($ident);
				if ($upper === 'AND') {
					$tokens[] = ['AND', $ident];
				} elseif ($upper === 'OR') {
					$tokens[] = ['OR', $ident];
				} elseif ($upper === 'TRUE') {
					$tokens[] = ['NUMBER', '1'];
				} elseif ($upper === 'FALSE') {
					$tokens[] = ['NUMBER', '0'];
				} else {
					$tokens[] = ['IDENT', $ident];
				}
				continue;
			}

			// Two-character operators
			$two = substr($src, $i, 2);
			if (in_array($two, ['==', '!=', '<=', '>=', '&&', '||', '**'], true)) {
				$tokens[] = [$two, $two];
				$i += 2;
				continue;
			}

			// Single-character operators
			if (strpbrk($c, '+-*/%<>=()!?,:') !== false) {
				$tokens[] = [$c, $c];
				$i++;
				continue;
			}

			throw new ExpressionException("Unexpected character '$c' at offset $i in: $src");
		}

		return $tokens;
	}

	// ── Parser / evaluator ────────────────────────────────────────
	//
	// We fold parse + evaluate into a single pass.  The expressions are
	// tiny (almost always under 60 tokens) so a separate AST step
	// would be over-engineering.

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseExpr(array $tokens, int &$pos, array $ctx): float|bool {
		return $this->parseTernary($tokens, $pos, $ctx);
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseTernary(array $tokens, int &$pos, array $ctx): float|bool {
		$cond = $this->parseOr($tokens, $pos, $ctx);
		if ($this->peek($tokens, $pos) === '?') {
			$pos++;
			$then = $this->parseTernary($tokens, $pos, $ctx);
			$this->expect($tokens, $pos, ':');
			$else = $this->parseTernary($tokens, $pos, $ctx);
			return $this->toBool($cond) ? $then : $else;
		}
		return $cond;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseOr(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseAnd($tokens, $pos, $ctx);
		while (in_array($this->peek($tokens, $pos), ['||', 'OR'], true)) {
			$pos++;
			$right = $this->parseAnd($tokens, $pos, $ctx);
			$left = $this->toBool($left) || $this->toBool($right);
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseAnd(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseEquality($tokens, $pos, $ctx);
		while (in_array($this->peek($tokens, $pos), ['&&', 'AND'], true)) {
			$pos++;
			$right = $this->parseEquality($tokens, $pos, $ctx);
			$left = $this->toBool($left) && $this->toBool($right);
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseEquality(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseRelational($tokens, $pos, $ctx);
		while (in_array($op = $this->peek($tokens, $pos), ['==', '!='], true)) {
			$pos++;
			$right = $this->parseRelational($tokens, $pos, $ctx);
			$lf = $this->toFloat($left);
			$rf = $this->toFloat($right);
			$left = match ($op) {
				'==' => $lf === $rf,
				'!=' => $lf !== $rf,
			};
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseRelational(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseAdditive($tokens, $pos, $ctx);
		while (in_array($op = $this->peek($tokens, $pos), ['<', '<=', '>', '>='], true)) {
			$pos++;
			$right = $this->parseAdditive($tokens, $pos, $ctx);
			$lf = $this->toFloat($left);
			$rf = $this->toFloat($right);
			$left = match ($op) {
				'<' => $lf < $rf,
				'<=' => $lf <= $rf,
				'>' => $lf > $rf,
				'>=' => $lf >= $rf,
			};
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseAdditive(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseMultiplicative($tokens, $pos, $ctx);
		while (in_array($op = $this->peek($tokens, $pos), ['+', '-'], true)) {
			$pos++;
			$right = $this->parseMultiplicative($tokens, $pos, $ctx);
			$lf = $this->toFloat($left);
			$rf = $this->toFloat($right);
			$left = $op === '+' ? $lf + $rf : $lf - $rf;
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseMultiplicative(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parsePower($tokens, $pos, $ctx);
		while (in_array($op = $this->peek($tokens, $pos), ['*', '/', '%'], true)) {
			$pos++;
			$right = $this->parsePower($tokens, $pos, $ctx);
			$lf = $this->toFloat($left);
			$rf = $this->toFloat($right);
			$left = match ($op) {
				'*' => $lf * $rf,
				// Match phpMyAdmin: divide-by-zero yields INF instead
				// of throwing, so ratio formulas (e.g. slow/total)
				// produce a sensible "very high" sentinel when the
				// denominator hasn't accumulated yet.
				'/' => $rf === 0.0 ? INF : $lf / $rf,
				'%' => $rf === 0.0 ? 0.0 : fmod($lf, $rf),
			};
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parsePower(array $tokens, int &$pos, array $ctx): float|bool {
		$left = $this->parseUnary($tokens, $pos, $ctx);
		if ($this->peek($tokens, $pos) === '**') {
			$pos++;
			// Right-associative
			$right = $this->parsePower($tokens, $pos, $ctx);
			return $this->toFloat($left) ** $this->toFloat($right);
		}
		return $left;
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parseUnary(array $tokens, int &$pos, array $ctx): float|bool {
		$op = $this->peek($tokens, $pos);
		if ($op === '-' || $op === '+' || $op === '!') {
			$pos++;
			$operand = $this->parseUnary($tokens, $pos, $ctx);
			return match ($op) {
				'-' => -$this->toFloat($operand),
				'+' => $this->toFloat($operand),
				'!' => !$this->toBool($operand),
			};
		}
		return $this->parsePrimary($tokens, $pos, $ctx);
	}

	/**
	 * @param list<array{0:string,1:string}> $tokens
	 * @param array<string, scalar|null> $ctx
	 */
	private function parsePrimary(array $tokens, int &$pos, array $ctx): float|bool {
		$type = $this->peek($tokens, $pos);
		if ($type === null) {
			throw new ExpressionException('Unexpected end of expression');
		}

		if ($type === '(') {
			$pos++;
			$inner = $this->parseExpr($tokens, $pos, $ctx);
			$this->expect($tokens, $pos, ')');
			return $inner;
		}

		if ($type === 'NUMBER') {
			$lex = $tokens[$pos][1];
			$pos++;
			return (float)$lex;
		}

		if ($type === 'IDENT') {
			$name = $tokens[$pos][1];
			$pos++;

			// Function call?
			if ($this->peek($tokens, $pos) === '(') {
				$pos++;
				$args = [];
				if ($this->peek($tokens, $pos) !== ')') {
					$args[] = $this->toFloat($this->parseExpr($tokens, $pos, $ctx));
					while ($this->peek($tokens, $pos) === ',') {
						$pos++;
						$args[] = $this->toFloat($this->parseExpr($tokens, $pos, $ctx));
					}
				}
				$this->expect($tokens, $pos, ')');
				if (!isset($this->functions[$name])) {
					throw new ExpressionException("Unknown function '$name'");
				}
				return ($this->functions[$name])(...$args);
			}

			// Identifier resolution.  Names are case-sensitive to
			// match what `SHOW STATUS` and `SHOW VARIABLES` return.
			if (!array_key_exists($name, $ctx)) {
				throw new ExpressionException("Unknown identifier '$name'");
			}
			$val = $ctx[$name];
			if ($val === null) {
				throw new ExpressionException("Identifier '$name' has null value");
			}
			if (is_bool($val)) {
				return $val ? 1.0 : 0.0;
			}
			if (is_numeric($val)) {
				return (float)$val;
			}
			// Remaining strings are accepted when they are one of MySQL's
			// boolean spellings.  MySQL/MariaDB return slow_query_log,
			// log_bin, etc. as 'ON'/'OFF' rather than 1/0; phpMyAdmin's
			// advisor rules treat them as numeric.
			$bool = match (strtoupper($val)) {
				'ON', 'YES', 'TRUE' => 1.0,
				'OFF', 'NO', 'FALSE', '' => 0.0,
				default => null,
			};
			if ($bool !== null) {
				return $bool;
			}
			throw new ExpressionException("Identifier '$name' is not numeric");
		}

		throw new ExpressionException("Unexpected token '$type' (lexeme: " . ($tokens[$pos][1] ?? '?') . ')');
	}

	private function peek(array $tokens, int $pos): ?string {
		return $tokens[$pos][0] ?? null;
	}

	private function expect(array $tokens, int &$pos, string $type): void {
		if ($this->peek($tokens, $pos) !== $type) {
			throw new ExpressionException(
				"Expected '$type' but got '" . ($this->peek($tokens, $pos) ?? '<eof>') . "'",
			);
		}
		$pos++;
	}

	private function toFloat(float|bool $v): float {
		return is_bool($v) ? ($v ? 1.0 : 0.0) : $v;
	}

	private function toBool(float|bool $v): bool {
		return is_bool($v) ? $v : $v !== 0.0;
	}
}
