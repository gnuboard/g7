<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Database\QueryException;
use Modules\Sirsoft\Board\Fields\UserNotificationFieldHandler;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 회원 응답에 게시판 알림 수신 설정을 싣는 리스너
 *
 * 알림 수신 설정 4칸의 검증과 저장은 회원 항목 선언(`module.php` 의 `getFields()`)과
 * 값 핸들러(`UserNotificationFieldHandler`)가 맡는다. 이 리스너는 회원 응답의 최상위 키(`notify_*`)만 채운다 —
 * 회원 폼이 회원 응답의 최상위 키로 칸의 초기값을 받기 때문이다.
 *
 * 검증 규칙 필터, 저장 데이터 필터, 생성 후 액션은 구독하지 않는다. 구독하면 항목 엔진과 겹쳐
 * 같은 칸을 두 번 검증하고 두 번 저장한다.
 */
class UserNotificationSettingsListener implements HookListenerInterface
{
    /**
     * UserNotificationSettingsListener 생성자
     *
     * @param  UserNotificationSettingService  $service  알림 설정 서비스
     */
    public function __construct(
        private UserNotificationSettingService $service
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 → 메서드·우선순위·유형
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: API 응답에 알림 설정 병합
            'core.user.filter_resource_data' => [
                'method' => 'filterResourceData',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * getSubscribedHooks()에서 개별 메서드를 지정하므로,
     * 이 메서드는 호출되지 않지만 인터페이스 준수를 위해 구현
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 리소스 데이터 필터: User API 응답에 알림 설정 병합
     *
     * 설정 행이 없거나 설정 테이블이 아직 없으면 네 칸 모두 false 로 싣는다.
     *
     * @param  array  $data  API 응답 데이터
     * @param  User  $user  조회 대상 사용자
     * @return array 알림 설정이 병합된 데이터
     */
    public function filterResourceData(array $data, User $user): array
    {
        try {
            $settings = $this->service->getByUserId($user->id);
        } catch (QueryException $e) {
            // 테이블이 존재하지 않는 경우 (모듈 미설치 등) 기본값 반환
            $settings = null;
        }

        foreach (UserNotificationFieldHandler::FIELD_KEYS as $field) {
            $data[$field] = $settings !== null && (bool) $settings->{$field};
        }

        return $data;
    }
}
