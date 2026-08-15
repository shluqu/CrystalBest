<?php

namespace app\service\C2c;

final class C2cException extends \RuntimeException
{
    private $httpStatus;
    private $errorCode;

    public function __construct(string $message, int $httpStatus = 422, string $errorCode = 'C2C_ERROR')
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
