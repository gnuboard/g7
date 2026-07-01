<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 낙관적 잠금 충돌 예외 — 동일 행에 대해 두 클라이언트가 동시에 저장을 시도해
 * `lock_version` 이 기대값과 일치하지 않을 때 발생합니다. 컨트롤러는 이 예외를
 * 잡아 409 Conflict 응답으로 변환하고, 프론트엔드는 "다른 사용자가 먼저
 * 저장했습니다" 안내 모달을 표시합니다.
 */
class ConcurrentModificationException extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        public readonly int $expectedVersion,
        public readonly string $resource,
    ) {
        parent::__construct(__('exceptions.concurrent_modification', [
            'resource' => $resource,
            'current' => $currentVersion,
            'expected' => $expectedVersion,
        ]));
    }
}
