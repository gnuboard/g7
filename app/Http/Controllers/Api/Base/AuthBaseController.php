<?php

namespace App\Http\Controllers\Api\Base;

use Illuminate\Support\Facades\Log;

/**
 * 인증된 사용자용 베이스 컨트롤러
 *
 * 인증이 필요한 API 컨트롤러가 상속받아야 하는 기본 클래스입니다.
 * 사용자 인증, 권한 체크, 리소스 소유권 확인 등의 기능을 제공합니다.
 */
abstract class AuthBaseController extends BaseApiController
{
    public function __construct()
    {
        // 사용자 인증 미들웨어 적용
        $this->middleware('auth:sanctum');
    }

    /**
     * 사용자 소유권을 확인합니다.
     *
     * @param int $userId 확인할 사용자 ID
     * @return bool
     */
    protected function isOwner(int $userId): bool
    {
        return $this->getCurrentUser()?->id === $userId;
    }

    /**
     * 사용자가 특정 리소스에 접근할 수 있는지 확인합니다.
     *
     * @param mixed $resource 접근하려는 리소스
     * @return bool
     */
    protected function canAccessResource($resource): bool
    {
        // 기본적으로 자신의 리소스만 접근 가능
        if (method_exists($resource, 'user_id')) {
            return $this->getCurrentUser()?->id === $resource->user_id;
        }

        return true;
    }

    /**
     * 사용자 활동을 기록합니다.
     *
     * @param string $action 수행한 작업
     * @param array $data 관련 데이터
     * @return void
     */
    protected function logUserActivity(string $action, array $data = []): void
    {
        // TODO: 사용자 활동 로그 시스템 구현
        Log::info("User Activity: {$action}", [
            'user_id' => $this->getCurrentUser()?->uuid,
            'data' => $data,
            'timestamp' => now()
        ]);
    }

    /**
     * 리소스 소유권을 확인하고, 소유자가 아니면 Forbidden 응답을 반환합니다.
     *
     * @param mixed $resource 확인할 리소스
     * @param string $messageKey 오류 메시지 키
     * @return \Illuminate\Http\JsonResponse|null 소유자이면 null, 아니면 Forbidden 응답
     */
    protected function checkOwnership($resource, string $messageKey = 'common.forbidden')
    {
        if (!$this->canAccessResource($resource)) {
            return $this->forbidden($messageKey);
        }

        return null;
    }

    /**
     * API 사용량을 기록합니다.
     *
     * @param string $endpoint 엔드포인트
     * @param array $data 관련 데이터
     * @return void
     */
    protected function logApiUsage(string $endpoint, array $data = []): void
    {
        // TODO: API 사용량 통계 시스템 구현
        Log::info("Auth API Usage: {$endpoint}", [
            'user_id' => $this->getCurrentUser()?->uuid,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data,
            'timestamp' => now(),
        ]);
    }
}
