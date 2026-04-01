<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 사용자 알림 설정 훅 리스너
 * 사용자 생성/수정/조회 시 알림 설정을 처리합니다.
 */
class UserNotificationSettingsListener implements HookListenerInterface
{
    /**
     * 알림 설정 필드 목록
     */
    private const NOTIFICATION_FIELDS = [
        'notify_post_complete',
        'notify_post_reply',
        'notify_comment',
        'notify_reply_comment',
    ];

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
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // Filter 훅: FormRequest validation rules 확장
            'core.user.create_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.update_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 프로필 업데이트용 (마이페이지)
            'core.user.update_profile_validation_rules' => [
                'method' => 'addValidationRules',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Filter 훅: 요청 데이터에서 알림 설정 분리
            'core.user.filter_create_data' => [
                'method' => 'filterCreateData',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.user.filter_update_data' => [
                'method' => 'filterUpdateData',
                'priority' => 10,
                'type' => 'filter',
            ],

            // Action 훅: 생성 후 알림 설정 저장
            'core.user.after_create' => [
                'method' => 'afterCreate',
                'priority' => 10,
            ],

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
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * FormRequest validation rules에 알림 설정 필드 추가
     *
     * @param  array  $rules  기존 validation rules
     * @return array 알림 설정 필드가 추가된 rules
     */
    public function addValidationRules(array $rules): array
    {
        return array_merge($rules, [
            'notify_post_complete' => 'nullable|boolean',
            'notify_post_reply' => 'nullable|boolean',
            'notify_comment' => 'nullable|boolean',
            'notify_reply_comment' => 'nullable|boolean',
        ]);
    }

    /**
     * 생성 데이터 필터: 알림 설정 필드 추출 후 세션에 임시 저장
     *
     * @param  array  $data  요청 데이터
     * @return array 알림 필드가 제거된 데이터
     */
    public function filterCreateData(array $data): array
    {
        $notificationData = $this->extractNotificationData($data);
        if (! empty($notificationData)) {
            session(['board_notification_data' => $notificationData]);
        }

        return $this->removeNotificationFields($data);
    }

    /**
     * 수정 데이터 필터: 알림 설정 필드 추출 후 즉시 저장
     *
     * @param  array  $data  요청 데이터
     * @param  User  $user  수정 대상 사용자
     * @return array 알림 필드가 제거된 데이터
     */
    public function filterUpdateData(array $data, User $user): array
    {
        $notificationData = $this->extractNotificationData($data);
        if (! empty($notificationData)) {
            $this->service->createOrUpdate($user->id, $notificationData);
        }

        return $this->removeNotificationFields($data);
    }

    /**
     * 생성 후 액션: 세션에서 데이터 가져와 저장
     *
     * @param  User  $user  생성된 사용자
     * @param  array  $originalData  원본 요청 데이터
     */
    public function afterCreate(User $user, array $originalData): void
    {
        $notificationData = session('board_notification_data', []);
        session()->forget('board_notification_data');

        if (! empty($notificationData)) {
            $this->service->createOrUpdate($user->id, $notificationData);
        }
    }

    /**
     * 리소스 데이터 필터: User API 응답에 알림 설정 병합
     *
     * @param  array  $data  API 응답 데이터
     * @param  User  $user  조회 대상 사용자
     * @return array 알림 설정이 병합된 데이터
     */
    public function filterResourceData(array $data, User $user): array
    {
        try {
            $settings = $this->service->getByUserId($user->id);

            if ($settings) {
                $data['notify_post_complete'] = $settings->notify_post_complete;
                $data['notify_post_reply'] = $settings->notify_post_reply;
                $data['notify_comment'] = $settings->notify_comment;
                $data['notify_reply_comment'] = $settings->notify_reply_comment;
            } else {
                // 설정이 없으면 기본값 반환
                $data['notify_post_complete'] = false;
                $data['notify_post_reply'] = false;
                $data['notify_comment'] = false;
                $data['notify_reply_comment'] = false;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // 테이블이 존재하지 않는 경우 (모듈 미설치 등) 기본값 반환
            $data['notify_post_complete'] = false;
            $data['notify_post_reply'] = false;
            $data['notify_comment'] = false;
            $data['notify_reply_comment'] = false;
        }

        return $data;
    }

    /**
     * 요청 데이터에서 알림 설정 필드만 추출합니다.
     *
     * @param  array  $data  요청 데이터
     * @return array 알림 설정 데이터
     */
    private function extractNotificationData(array $data): array
    {
        return array_filter(
            array_intersect_key($data, array_flip(self::NOTIFICATION_FIELDS)),
            fn ($v) => $v !== null
        );
    }

    /**
     * 요청 데이터에서 알림 설정 필드를 제거합니다.
     *
     * @param  array  $data  요청 데이터
     * @return array 알림 필드가 제거된 데이터
     */
    private function removeNotificationFields(array $data): array
    {
        return array_diff_key($data, array_flip(self::NOTIFICATION_FIELDS));
    }
}
