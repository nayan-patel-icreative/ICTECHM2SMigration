<?php

namespace App\Exceptions;

/**
 * Thrown by MagentoClient::request() when the Magento REST API returns
 * a non-2xx response or a structured error payload. Preserves the raw
 * response body so callers can still inspect the error after the underlying
 * Guzzle response body stream has been consumed.
 */
class MagentoApiException extends \RuntimeException
{
    private string $responseBody;
    private ?int $httpStatus;

    public function __construct(string $responseBody, ?int $httpStatus, \Throwable $previous)
    {
        $this->responseBody = $responseBody;
        $this->httpStatus   = $httpStatus;

        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
