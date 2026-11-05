<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Guard;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Finds code that names a class at run time from a string or a variable.
 *
 * Strauss and PHP-Scoper rewrite class names they can see in the source.
 * A name assembled at run time, or written inside a string, is left as it
 * was and points at a class that no longer exists once the package is
 * prefixed.
 */
final class DynamicClassNameFinder extends NodeVisitorAbstract
{
    /** The oldest PHP the package supports; newer syntax is a parse error. */
    private const MINIMUM_PHP = '8.1';

    /** Matches a fully qualified name in the package's namespace. */
    private const PACKAGE_CLASS_PATTERN = '/^\\\\?Pixonaut\\\\Sdk(\\\\|$)/';

    /** Functions whose class-name argument must be visible to a scoper, by argument position. */
    private const CLASS_NAME_ARGUMENTS = [
        'class_exists' => 0,
        'interface_exists' => 0,
        'trait_exists' => 0,
        'enum_exists' => 0,
        'is_a' => 1,
        'is_subclass_of' => 1,
    ];

    /** Functions that take a callable as their first argument. */
    private const CALLABLE_FUNCTIONS = ['call_user_func', 'call_user_func_array'];

    private readonly Parser $parser;

    /** @var list<string> */
    private array $violations = [];

    private string $file = '';

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString(self::MINIMUM_PHP));
    }

    /**
     * @return list<string> one "file:line: problem" entry per finding
     */
    public function findInDirectory(string $directory): array
    {
        $violations = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $violations = [...$violations, ...$this->findInFile($file->getPathname())];
            }
        }
        sort($violations);

        return $violations;
    }

    /**
     * @return list<string> one "file:line: problem" entry per finding
     */
    public function findInFile(string $path): array
    {
        $code = file_get_contents($path);
        if ($code === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }
        $statements = $this->parser->parse($code) ?? [];

        $this->file = $path;
        $this->violations = [];
        $traverser = new NodeTraverser($this);
        $traverser->traverse($statements);

        return $this->violations;
    }

    public function enterNode(Node $node): ?int
    {
        match (true) {
            $node instanceof Expr\New_ => $this->checkNew($node),
            $node instanceof Expr\StaticCall,
            $node instanceof Expr\StaticPropertyFetch => $this->checkClassReference($node->class, $node),
            $node instanceof Expr\ClassConstFetch => $this->checkClassConstant($node),
            $node instanceof Expr\FuncCall => $this->checkFunctionCall($node),
            $node instanceof Scalar\String_ => $this->checkString($node->value, $node),
            $node instanceof Node\InterpolatedStringPart => $this->checkString($node->value, $node),
            default => null,
        };

        return null;
    }

    private function checkNew(Expr\New_ $node): void
    {
        // An anonymous class is declared in place, so it has a static name.
        if ($node->class instanceof Stmt\Class_) {
            return;
        }
        $this->checkClassReference($node->class, $node);
    }

    private function checkClassConstant(Expr\ClassConstFetch $node): void
    {
        // `$object::class` reads the name of an existing object's class.
        if ($node->name instanceof Node\Identifier && $node->name->toLowerString() === 'class') {
            return;
        }
        $this->checkClassReference($node->class, $node);
    }

    private function checkClassReference(Name|Expr|Stmt\Class_ $class, Node $node): void
    {
        if (!$class instanceof Name) {
            $this->report($node, 'class named by an expression');
        }
    }

    private function checkFunctionCall(Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Name) {
            $this->report($node, 'function named by an expression');

            return;
        }
        $function = strtolower($node->name->toString());

        if (array_key_exists($function, self::CLASS_NAME_ARGUMENTS)) {
            $argument = $this->argument($node, self::CLASS_NAME_ARGUMENTS[$function]);
            if ($argument !== null && !$this->isClassLiteral($argument)) {
                $this->report($node, "{$function}() with a class name that is not a ::class literal");
            }
        }

        if (in_array($function, self::CALLABLE_FUNCTIONS, true)) {
            $callable = $this->argument($node, 0);
            if ($callable !== null && $this->isStringCallable($callable)) {
                $this->report($node, "{$function}() with a string callable");
            }
        }
    }

    private function checkString(string $value, Node $node): void
    {
        if (preg_match(self::PACKAGE_CLASS_PATTERN, $value) === 1) {
            $this->report($node, "class name in a string: {$value}");
        }
    }

    private function argument(Expr\FuncCall $node, int $position): ?Expr
    {
        $argument = $node->args[$position] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private function isClassLiteral(Expr $expression): bool
    {
        return $expression instanceof Expr\ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Node\Identifier
            && $expression->name->toLowerString() === 'class';
    }

    private function isStringCallable(Expr $expression): bool
    {
        if ($this->isString($expression)) {
            return true;
        }
        if (!$expression instanceof Expr\Array_) {
            return false;
        }
        // `['Some\Class', 'method']` names the class in a string.
        $first = $expression->items[0] ?? null;

        return $first !== null && $this->isString($first->value);
    }

    private function isString(Expr $expression): bool
    {
        return $expression instanceof Scalar\String_
            || $expression instanceof Scalar\InterpolatedString
            || $expression instanceof Expr\BinaryOp\Concat;
    }

    private function report(Node $node, string $problem): void
    {
        $this->violations[] = "{$this->file}:{$node->getStartLine()}: {$problem}";
    }
}
