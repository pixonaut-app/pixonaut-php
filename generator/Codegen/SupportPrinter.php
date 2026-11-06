<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use Pixonaut\Sdk\Credentials\Credential;
use Pixonaut\Sdk\Http\ArrayCatalogue;
use Pixonaut\Sdk\Http\Catalogue;
use Pixonaut\Sdk\Http\Operation;

/**
 * Prints `ErrorCode`, `Operations` and `ApiVersion`.
 */
final class SupportPrinter
{
    /** The constant listing every event type. */
    private const KNOWN_EVENT_TYPES = 'KNOWN';

    public function __construct(
        private readonly string $namespace,
    ) {}

    public function errorCode(Api $api): string
    {
        $statements = [];
        foreach ($api->errorCodes as $code => $statuses) {
            $constant = new Stmt\ClassConst([new Node\Const_(Naming::constant($code), Ast::string($code))], Modifiers::PUBLIC);
            $description = $api->errorDescriptions[$code] ?? '';
            $constant->setDocComment(self::doc([
                $description !== '' ? $description : 'Documented with status ' . implode(', ', $statuses) . '.',
            ]));
            $statements[] = $constant;
        }
        $byCode = new Stmt\ClassConst([new Node\Const_('STATUSES', Ast::value($api->errorCodes))], Modifiers::PUBLIC);
        $byCode->setDocComment(self::doc(['Each code, with the statuses the spec documents it with.', '', '@var array<string, list<int>>']));
        $statements[] = $byCode;

        $statusesConstant = Ast::constant('self', 'STATUSES');
        $isKnown = new Stmt\ClassMethod('isKnown', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'params' => [new Node\Param(Ast::var('code'), null, new Identifier('string'))],
            'returnType' => new Identifier('bool'),
            'stmts' => [new Stmt\Return_(new Expr\Isset_([new Expr\ArrayDimFetch($statusesConstant, Ast::var('code'))]))],
        ]);
        $isKnown->setDocComment(self::doc(['Whether the spec names the code. Pixonaut may answer others: the list is extensible.']));
        $statuses = new Stmt\ClassMethod('statuses', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'params' => [new Node\Param(Ast::var('code'), null, new Identifier('string'))],
            'returnType' => new Identifier('array'),
            'stmts' => [new Stmt\Return_(new Expr\BinaryOp\Coalesce(new Expr\ArrayDimFetch($statusesConstant, Ast::var('code')), new Expr\Array_([])))],
        ]);
        $statuses->setDocComment(self::doc(['The statuses the spec documents the code with; none for a code it does not name.', '', '@return list<int>']));
        $statements[] = $isKnown;
        $statements[] = $statuses;

        $class = new Stmt\Class_('ErrorCode', ['flags' => Modifiers::FINAL, 'stmts' => $statements]);
        $class->setDocComment(self::doc([
            'The error codes the API ' . $api->version . ' spec names: its refusals (`x-refusals`), and the `error`',
            'values of every error answer. The client\'s own codes are on `ApiException`.',
        ]));

        return Ast::file($this->namespace, [], $class);
    }

    public function operations(Api $api): string
    {
        $operations = [];
        foreach ($api->operations as $operation) {
            $credentials = [];
            foreach ($operation->credentials as $scheme) {
                $credential = Credential::tryFrom($scheme) ?? throw new GeneratorException(sprintf('%s accepts credential %s, which Credential does not have.', $operation->id, $scheme));
                $credentials[] = Ast::constant('Credential', $credential->name);
            }
            $operations[] = new Expr\New_(new Name('Operation'), [
                Ast::named('id', Ast::string($operation->id)),
                Ast::named('method', Ast::string($operation->method)),
                Ast::named('path', Ast::string($operation->path)),
                Ast::named('credentials', Ast::list($credentials)),
                Ast::named('anonymous', Ast::value($operation->anonymous)),
                Ast::named('idempotent', Ast::value($operation->idempotent)),
                Ast::named('accept', Ast::string($operation->accept)),
                Ast::named('sensitive', Ast::value($operation->sensitive)),
            ]);
        }
        $all = new Stmt\ClassMethod('all', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'returnType' => new Identifier('array'),
            'stmts' => [new Stmt\Return_(Ast::list($operations))],
        ]);
        $all->setDocComment(self::doc(['Every operation the SDK calls, in the spec\'s order.', '', '@return list<Operation>']));

        $statuses = new Stmt\ClassConst([new Node\Const_('ERROR_STATUSES', Ast::value($api->errorStatuses))], Modifiers::PUBLIC);
        $statuses->setDocComment(self::doc(['Every status the spec documents an unsuccessful answer with.', '', '@var list<int>']));

        $catalogue = new Stmt\ClassMethod('catalogue', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'returnType' => new Name('Catalogue'),
            'stmts' => [new Stmt\Return_(new Expr\New_(new Name('ArrayCatalogue'), [
                new Node\Arg(Ast::call('self', 'all', [])),
                new Node\Arg(Ast::func('array_keys', [Ast::constant('ErrorCode', 'STATUSES')])),
                new Node\Arg(Ast::constant('self', 'ERROR_STATUSES')),
            ]))],
        ]);
        $catalogue->setDocComment(self::doc(['The catalogue `Pixonaut` is constructed with: these operations, and the codes and statuses the spec documents.']));

        $class = new Stmt\Class_('Operations', ['flags' => Modifiers::FINAL, 'stmts' => [$statuses, $all, $catalogue]]);
        $class->setDocComment(self::doc(['The operations of the API ' . $api->version . ' spec, as the client sends them.']));

        return Ast::file($this->namespace, [ArrayCatalogue::class, Catalogue::class, Credential::class, Operation::class], $class);
    }

    public function eventType(Api $api): string
    {
        $statements = [];
        $names = [];
        foreach ($api->eventTypes as $type => $description) {
            $name = Naming::constant($type);
            if (isset($names[$name]) || $name === self::KNOWN_EVENT_TYPES) {
                throw new GeneratorException(sprintf('Event types "%s" and "%s" are both constant %s.', $names[$name] ?? self::KNOWN_EVENT_TYPES, $type, $name));
            }
            $names[$name] = $type;
            $constant = new Stmt\ClassConst([new Node\Const_($name, Ast::string($type))], Modifiers::PUBLIC);
            $constant->setDocComment(self::doc([$description !== '' ? $description : 'Delivered as `' . $type . '`.']));
            $statements[] = $constant;
        }
        $known = new Stmt\ClassConst([new Node\Const_(self::KNOWN_EVENT_TYPES, Ast::list(array_map(
            static fn(string $name): Expr => Ast::constant('self', $name),
            array_keys($names),
        )))], Modifiers::PUBLIC);
        $known->setDocComment(self::doc(['Every event type this release knows.', '', '@var list<string>']));
        $statements[] = $known;

        $class = new Stmt\Class_('EventType', ['flags' => Modifiers::FINAL, 'stmts' => $statements]);
        $class->setDocComment(self::doc([
            'The event types the API ' . $api->version . ' spec delivers to webhooks. The API may send a type',
            'added after this release: it decodes to `UnknownEvent`.',
        ]));

        return Ast::file($this->namespace, [], $class);
    }

    public function apiVersion(Api $api, string $sha256): string
    {
        $target = new Stmt\ClassConst([new Node\Const_('TARGET', Ast::string($api->version))], Modifiers::PUBLIC);
        $target->setDocComment(self::doc(['The API release this SDK release is generated from.']));
        $hash = new Stmt\ClassConst([new Node\Const_('SPEC_SHA256', Ast::string($sha256))], Modifiers::PUBLIC);
        $hash->setDocComment(self::doc(['The sha256 of that release\'s `openapi.json`.']));
        $class = new Stmt\Class_('ApiVersion', ['flags' => Modifiers::FINAL, 'stmts' => [$target, $hash]]);
        $class->setDocComment(self::doc(['The Pixonaut API release the SDK targets.']));

        return Ast::file($this->namespace, [], $class);
    }

    /**
     * @param list<string> $lines
     */
    private static function doc(array $lines): \PhpParser\Comment\Doc
    {
        return Ast::doc($lines) ?? throw new GeneratorException('Empty doc comment.');
    }
}
