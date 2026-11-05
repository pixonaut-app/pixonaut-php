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

    /**
     * Functions whose class-name argument must be visible to a scoper: the
     * argument's position and its parameter name, for named arguments.
     */
    private const CLASS_NAME_ARGUMENTS = [
        'class_exists' => [0, 'class'],
        'interface_exists' => [0, 'interface'],
        'trait_exists' => [0, 'trait'],
        'enum_exists' => [0, 'enum'],
        'is_a' => [1, 'class'],
        'is_subclass_of' => [1, 'class'],
    ];

    /** Functions that take a callable, with its position and parameter name. */
    private const CALLABLE_ARGUMENTS = [
        'call_user_func' => [0, 'callback'],
        'call_user_func_array' => [0, 'callback'],
    ];

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
            $node instanceof Expr\StaticPropertyFetch,
            $node instanceof Expr\Instanceof_ => $this->checkClassReference($node->class, $node),
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
            $argument = $this->argument($node, ...self::CLASS_NAME_ARGUMENTS[$function]);
            if ($argument !== null && !$this->isClassLiteral($argument)) {
                $this->report($node, "{$function}() with a class name that is not a ::class literal");
            }
        }

        if (array_key_exists($function, self::CALLABLE_ARGUMENTS)) {
            $callable = $this->argument($node, ...self::CALLABLE_ARGUMENTS[$function]);
            if ($callable !== null && !$this->isStaticCallable($callable)) {
                $this->report($node, "{$function}() with a callable that does not name its class statically");
            }
        }
    }

    private function checkString(string $value, Node $node): void
    {
        if (preg_match(self::PACKAGE_CLASS_PATTERN, $value) === 1) {
            $this->report($node, "class name in a string: {$value}");
        }
    }

    /**
     * The argument passed for a parameter, by name when the call names it,
     * else by position. Null when the call leaves it out or unpacks a list.
     */
    private function argument(Expr\FuncCall $node, int $position, string $name): ?Expr
    {
        $positional = 0;
        foreach ($node->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }
            if ($argument->name !== null) {
                if ($argument->name->toString() === $name) {
                    return $argument->value;
                }
                continue;
            }
            if ($positional === $position) {
                return $argument->value;
            }
            ++$positional;
        }

        return null;
    }

    private function isClassLiteral(Expr $expression): bool
    {
        return $expression instanceof Expr\ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Node\Identifier
            && $expression->name->toLowerString() === 'class';
    }

    /**
     * Whether a callable's class, if it has one, is visible in the source: a
     * closure, a first-class callable (`$this->run(...)`), or an array whose
     * first element is `$this` or a `::class` literal. Anything else may hold
     * a class name in a string.
     */
    private function isStaticCallable(Expr $expression): bool
    {
        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            return true;
        }
        if ($expression instanceof Expr\CallLike && $expression->isFirstClassCallable()) {
            return true;
        }
        if (!$expression instanceof Expr\Array_) {
            return false;
        }
        $target = $expression->items[0]->value ?? null;

        return ($target instanceof Expr\Variable && $target->name === 'this')
            || ($target instanceof Expr && $this->isClassLiteral($target));
    }

    private function report(Node $node, string $problem): void
    {
        $this->violations[] = "{$this->file}:{$node->getStartLine()}: {$problem}";
    }
}
