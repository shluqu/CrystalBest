<?php

namespace app\controller\Auth;

class AuthException extends \RuntimeException
{
    private $httpStatus;
    private $errorCode;

    public function __construct(string $message, int $httpStatus = 400, string $errorCode = 'AUTH_ERROR')
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
