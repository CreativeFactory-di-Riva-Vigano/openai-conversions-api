<?php

declare(strict_types=1);

namespace CreativeFactoryRV\OpenAIConversions\Exception;

final class ApiException extends \RuntimeException
{
    /**
     * Creates an exception for a non-successful API response.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $responseBody Raw response body
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $responseBody = '',
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Returns the HTTP status code from the API response.
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the raw body of the API response.
     *
     * @return string Response body
     */
    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
