<?php

namespace Plugins\Sirsoft\Totp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\PermissionType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;

/**
 * 관리자 회원 상세 응답에 인증 앱 등록 여부를 싣는 리스너 (filter 훅).
 *
 * 회원 상세 「인증 앱」 카드가 등록 여부를 보이지 않아 미등록 회원에게도 [강제 해제] 가 보였다
 * (7.1.0 관측 R-26·B O-B5). 카드는 이 값(`totp_enrollment.enrolled`)으로 「등록됨/미등록」을 보이고,
 * 등록된 회원에게만 [강제 해제] 를 낸다.
 *
 * **해제 권한(`sirsoft-totp.credentials.delete`)을 가진 관리자의 관리자 응답에만** 싣는다 — 회원 정보를
 * 볼 수 있다는 것과 남의 2단계 인증 상태를 아는 것은 다른 신뢰 수준이라, 해제 라우트와 같은 경계를 쓴다.
 * 본인 프로필(`/api/me`)·목록에는 싣지 않는다(목록은 이 훅이 발화하지 않는다).
 *
 * 「등록됨」은 확인 코드까지 마친 등록(`confirmed_at` 있음)이다 — 발급만 하고 확인하지 않은 행은
 * 로그인에 쓰이지 않으므로 미등록으로 본다.
 *
 * @since 1.0.0
 */
class UserTotpEnrollmentInfoListener implements HookListenerInterface
{
    /** 해제 권한 — 해제 라우트 미들웨어와 같은 식별자 */
    private const RESET_PERMISSION = 'sirsoft-totp.credentials.delete';

    /**
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     */
    public function __construct(
        private readonly TotpCredentialRepositoryInterface $credentials,
    ) {}

    /**
     * 구독할 훅 목록 반환.
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => [
                'method' => 'injectEnrollmentStatus',
                'type' => 'filter',
                'priority' => 40,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑을 쓰므로 직접 호출되지 않음).
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // method 매핑(injectEnrollmentStatus)을 사용한다.
    }

    /**
     * 해제 권한을 가진 관리자의 관리자 응답에 등록 여부를 병합합니다.
     *
     * @param  array<string, mixed>  $data  코어가 직렬화한 회원 응답 데이터
     * @param  User  $user  대상 회원
     * @return array<string, mixed> 병합 결과
     */
    public function injectEnrollmentStatus(array $data, User $user): array
    {
        $request = request();

        if (! $this->isAdminRequest($request) || ! $this->viewerMayReset($request)) {
            return $data;
        }

        try {
            $credential = $this->credentials->findConfirmedByUserId((int) $user->id);
        } catch (QueryException) {
            // 테이블이 아직 없거나 조회가 실패해도 회원 상세 화면 자체는 떠야 한다 — 상태를 모르면 싣지 않는다.
            return $data;
        }

        $data['totp_enrollment'] = [
            'enrolled' => $credential !== null,
            'enrolled_at' => $credential?->confirmed_at?->toIso8601String(),
        ];

        return $data;
    }

    /**
     * 관리자 컨트롤러가 처리하는 요청인지 판정합니다(형제 확장 board 의 판정과 같은 기준).
     *
     * @param  Request|null  $request  HTTP 요청
     * @return bool 관리자 요청이면 true
     */
    private function isAdminRequest(?Request $request): bool
    {
        $controller = $request?->route()?->getController();

        return $controller !== null && str_contains(get_class($controller), '\\Admin\\');
    }

    /**
     * 요청한 관리자가 인증 앱 해제 권한을 가졌는지 판정합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return bool 권한이 있으면 true
     */
    private function viewerMayReset(?Request $request): bool
    {
        $viewer = $request?->user();

        return $viewer instanceof User && $viewer->hasPermission(self::RESET_PERMISSION, PermissionType::Admin);
    }
}
