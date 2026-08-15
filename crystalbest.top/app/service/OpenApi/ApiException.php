<?php

namespace app\service\OpenApi;

final class ApiException extends \RuntimeException
{
    private int $httpStatus;
    private string $errorCode;

    public function __construct(string $message, int $httpStatus = 400, string $errorCode = 'OPENAPI_ERROR')
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
