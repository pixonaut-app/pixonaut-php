<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Generator\Codegen;

/**
 * One operation, as the client's `Operation` descriptor states it.
 */
final class OperationDef
{
    /**
     * @param list<string> $credentials Security scheme names, in the spec's order.
     * @param array<int, Type> $responses JSON bodies of successful answers, by status.
     * @param string             $tag            The spec's first tag for it.
     * @param list<ParameterDef> $parameters     Path, query and header parameters, in the spec's order, `idempotency-key` left out.
     * @param bool               $requestRequired Whether the body must be sent.
     * @param list<int>          $binaryStatuses Successful statuses that answer a file rather than JSON.
     * @param list<int>          $emptyStatuses  Successful statuses that answer nothing.
     * @param list<string>       $errorCodes     The error codes its unsuccessful answers document, sorted.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
        public readonly array $credentials,
        public readonly bool $anonymous,
        public readonly bool $idempotent,
        public readonly string $accept,
        public readonly bool $sensitive,
        public readonly string $summary,
        public readonly ?Type $request,
        public readonly array $responses,
        public readonly string $tag = '',
        public readonly array $parameters = [],
        public readonly bool $requestRequired = false,
        public readonly array $binaryStatuses = [],
        public readonly array $emptyStatuses = [],
        public readonly array $errorCodes = [],
    ) {}

    /**
     * The request headers it declares, lower case, other than `idempotency-key`.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->parameters as $parameter) {
            if ($parameter->in === ParameterDef::HEADER) {
                $headers[] = strtolower($parameter->name);
            }
        }

        return $headers;
    }
}
