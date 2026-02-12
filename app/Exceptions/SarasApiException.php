<?php

namespace App\Exceptions;

use Exception;

class SarasApiException extends Exception
{
    public const TYPE_UNAVAILABLE = 'saras_unavailable';

    public const TYPE_AUTH_FAILED = 'saras_auth_failed';

    public const TYPE_VALIDATION_ERROR = 'saras_validation_error';

    public const TYPE_TIMEOUT = 'saras_timeout';

    public const TYPE_UPLOAD_FAILED = 'upload_failed';

    public function __construct(
        string $message,
        public readonly string $type,
        public readonly ?string $endpoint = null,
        public readonly ?int $statusCode = null,
        public readonly ?array $context = null,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $endpoint, ?string $message = null, ?Exception $previous = null): self
    {
        return new self(
            message: $message ?? 'Saras API is unavailable',
            type: self::TYPE_UNAVAILABLE,
            endpoint: $endpoint,
            previous: $previous,
        );
    }

    public static function authFailed(?string $message = null): self
    {
        return new self(
            message: $message ?? 'Saras authentication failed',
            type: self::TYPE_AUTH_FAILED,
            endpoint: '/users/userLogin',
        );
    }

    public static function validationError(string $endpoint, string $message, ?array $errors = null): self
    {
        return new self(
            message: $message,
            type: self::TYPE_VALIDATION_ERROR,
            endpoint: $endpoint,
            context: $errors ? ['errors' => $errors] : null,
        );
    }

    public static function timeout(string $endpoint): self
    {
        return new self(
            message: 'Saras API request timed out',
            type: self::TYPE_TIMEOUT,
            endpoint: $endpoint,
        );
    }

    public static function uploadFailed(string $message, ?array $context = null): self
    {
        return new self(
            message: $message,
            type: self::TYPE_UPLOAD_FAILED,
            endpoint: '/process/knowledges/createStorage',
            context: $context,
        );
    }

    /**
     * Get an array representation for logging (without sensitive data).
     */
    public function toLogContext(): array
    {
        return [
            'type' => $this->type,
            'endpoint' => $this->endpoint,
            'status_code' => $this->statusCode,
            'message' => $this->getMessage(),
        ];
    }
}
