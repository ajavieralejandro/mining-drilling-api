<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Uniform error carrier for anything that goes wrong on the
 * UndSurf API -> Data Gateway -> client DB path, so tenant-scoped
 * controllers all render the same error shape the demo endpoint already
 * established (request_id/correlation_id/error.code/error.message).
 * Never hides a Gateway-side failure behind a 200.
 */
class GatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->httpStatus);
    }
}
