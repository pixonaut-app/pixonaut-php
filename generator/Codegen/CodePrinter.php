<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;

/**
 * PHP-Parser's printer, breaking an argument, parameter or array list over
 * several lines when one of its items is more than a plain value or a call on
 * plain values, so that nested decoders stay readable.
 */
final class CodePrinter extends Standard
{
    /** The PHP version the package supports at least. */
    private const MINIMUM_PHP = [8, 1];

    /** A list of plain items longer than this goes over several lines. */
    private const MAX_INLINE_LIST = 100;

    public function __construct()
    {
        parent::__construct(['phpVersion' => PhpVersion::fromComponents(...self::MINIMUM_PHP), 'shortArraySyntax' => true]);
    }

    /**
     * @param array<Node> $nodes
     */
    protected function pMaybeMultiline(array $nodes, bool $trailingComma = false): string
    {
        if ($this->breaks($nodes)) {
            return $this->pCommaSeparatedMultiline($nodes, true) . $this->nl;
        }
        $inline = parent::pMaybeMultiline($nodes, $trailingComma);
        if (count($nodes) > 1 && strlen($inline) > self::MAX_INLINE_LIST) {
            return $this->pCommaSeparatedMultiline($nodes, true) . $this->nl;
        }

        return $inline;
    }

    protected function pStmt_Declare(Stmt\Declare_ $node): string
    {
        return 'declare(' . $this->pCommaSeparated($node->declares) . ');';
    }

    protected function pStmt_ClassMethod(Stmt\ClassMethod $node): string
    {
        $method = parent::pStmt_ClassMethod($node);
        if ($node->stmts !== []) {
            return $method;
        }

        // An empty body, e.g. a constructor promoting its parameters: `) {}`.
        return preg_replace('/\)\s*\{\s*\}$/', ') {}', $method) ?? $method;
    }

    /**
     * @param Node\Param[] $params
     */
    protected function pParams(array $params): string
    {
        foreach ($params as $param) {
            if ($param->flags !== 0) {
                // Promoted properties: one per line.
                return $this->pCommaSeparatedMultiline($params, true) . $this->nl;
            }
        }

        return parent::pParams($params);
    }

    /**
     * @param array<Node> $nodes
     */
    private function breaks(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->getComments() !== [] || !self::plain($node)) {
                return true;
            }
        }

        return false;
    }

    private static function plain(Node $node): bool
    {
        return match (true) {
            $node instanceof Node\Arg => self::plain($node->value),
            $node instanceof Node\ArrayItem => $node->key === null ? self::plain($node->value) : self::plain($node->key) && self::plain($node->value),
            $node instanceof Scalar, $node instanceof Expr\Variable, $node instanceof Expr\ConstFetch,
            $node instanceof Expr\ClassConstFetch => true,
            $node instanceof Expr\PropertyFetch => self::plain($node->var),
            $node instanceof Expr\UnaryMinus => self::plain($node->expr),
            $node instanceof Expr\BinaryOp\Concat => self::plain($node->left) && self::plain($node->right),
            $node instanceof Expr\ArrayDimFetch => self::plain($node->var) && ($node->dim === null || self::plain($node->dim)),
            $node instanceof Expr\StaticCall, $node instanceof Expr\FuncCall => self::plainArgs($node->args),
            default => false,
        };
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
     */
    private static function plainArgs(array $args): bool
    {
        foreach ($args as $arg) {
            if (!self::plain($arg)) {
                return false;
            }
        }

        return true;
    }
}
