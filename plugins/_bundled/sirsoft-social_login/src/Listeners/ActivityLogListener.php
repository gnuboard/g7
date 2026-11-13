<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameResolver;
use App\Models\User;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 이 플러그인의 연결·가입 훅을 활동 로그로 남깁니다.
 *
 * 발행 지점(서비스)은 훅만 쏘고 기록은 이 리스너가 맡는다. 활동 로그에는 제공자 키와 연결 경로만 남기고 제공자 사용자
 * ID·토큰은 싣지 않는다.
 *
 * @since 1.0.0
 */
class ActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-social_login.account.after_link' => ['method' => 'onLinked', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.account.after_unlink' => ['method' => 'onUnlinked', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.auth.after_social_signup' => ['method' => 'onSignup', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.provider.after_create' => ['method' => 'onProviderCreated', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.provider.after_update' => ['method' => 'onProviderUpdated', 'priority' => 50, 'sync' => true],
            'sirsoft-social_login.provider.after_delete' => ['method' => 'onProviderDeleted', 'priority' => 50, 'sync' => true],
            'core.plugin_settings.after_save' => ['method' => 'onSettingsSaved', 'priority' => 50, 'sync' => true],
        ];
    }

    /** 사이트 정책 중 위험도가 있어 값까지 남기는 설정 */
    private const AUDITED_SETTINGS = [
        'email_conflict_policy', 'two_factor_enabled', 'allow_admin_social_login', 'allow_unlink_last_method',
        'webhook_unlink_policy', 'revoke_on_unlink',
    ];

    /**
     * 제공자 추가를 기록합니다 (관리자 행위).
     *
     * @param  array<string, mixed>  $summary  key·driver·type·enabled·label (비밀값 없음)
     * @return void 반환값 없음
     */
    public function onProviderCreated(array $summary): void
    {
        $this->writeAdmin('provider_created', $summary, []);
    }

    /**
     * 제공자 수정을 기록합니다 — 바뀐 칸 이름만 남기고 값(특히 비밀값)은 남기지 않는다.
     *
     * @param  array<string, mixed>  $summary  요약
     * @param  array<int, string>  $changed  바뀐 칸 이름
     * @return void 반환값 없음
     */
    public function onProviderUpdated(array $summary, array $changed = []): void
    {
        $this->writeAdmin('provider_updated', $summary, ['changed' => array_values($changed)]);
    }

    /**
     * 제공자 삭제를 기록합니다.
     *
     * @param  array<string, mixed>  $summary  요약
     * @return void 반환값 없음
     */
    public function onProviderDeleted(array $summary): void
    {
        $this->writeAdmin('provider_deleted', $summary, []);
    }

    /**
     * 이 플러그인 설정 저장을 기록합니다 — 위험 정책은 저장된 값까지 남긴다(코어 기록은 키 목록만).
     *
     * @param  string  $identifier  저장한 플러그인
     * @param  array<string, mixed>  $settings  저장된 설정 전체
     * @param  bool  $result  저장 성공 여부
     * @return void 반환값 없음
     */
    public function onSettingsSaved(string $identifier, array $settings = [], bool $result = true): void
    {
        if ($identifier !== 'sirsoft-social_login' || ! $result) {
            return;
        }

        $this->logActivity('settings_updated', [
            'description_key' => 'sirsoft-social_login::activity_log.description.settings_updated',
            'description_params' => [],
            'properties' => ['policies' => array_intersect_key($settings, array_flip(self::AUDITED_SETTINGS))],
        ]);
    }

    /**
     * 관리자 제공자 행위 1건을 씁니다.
     *
     * @param  string  $type  유형
     * @param  array<string, mixed>  $summary  제공자 요약
     * @param  array<string, mixed>  $properties  추가 속성
     * @return void 반환값 없음
     */
    private function writeAdmin(string $type, array $summary, array $properties): void
    {
        $this->logActivity($type, [
            'description_key' => 'sirsoft-social_login::activity_log.description.'.$type,
            'description_params' => ['provider' => (string) ($summary['label'] ?? $summary['key'] ?? '')],
            'properties' => ['provider_key' => (string) ($summary['key'] ?? ''), 'provider_type' => (string) ($summary['type'] ?? '')] + $properties,
        ]);
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 연결을 기록합니다 — 자동 연결은 따로 남긴다(운영자가 위험을 알고 켠 정책의 실행 기록).
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @param  array<string, mixed>  $context  `method` (link·existing·auto·signup)
     * @return void 반환값 없음
     */
    public function onLinked(SocialAccount $account, User $user, array $context = []): void
    {
        $method = (string) ($context['method'] ?? 'link');
        if ($method === 'signup') {
            // 가입과 함께 생긴 연결은 가입 기록 하나로 남긴다.
            return;
        }

        $type = $method === 'auto' ? 'account_auto_linked' : 'account_linked';

        $this->write($type, $user, $account->provider_key, ['method' => $method]);
    }

    /**
     * 해제를 기록합니다 (사유별 유형).
     *
     * @param  SocialAccount  $account  연결
     * @param  User  $user  회원
     * @param  string  $reason  사유 (user·admin·webhook·withdraw·delete)
     * @return void 반환값 없음
     */
    public function onUnlinked(SocialAccount $account, User $user, string $reason = 'user'): void
    {
        $type = match ($reason) {
            'admin' => 'account_unlinked_by_admin',
            'webhook' => 'account_unlinked_by_webhook',
            default => 'account_unlinked',
        };

        $this->write($type, $user, $account->provider_key, ['reason' => $reason]);
    }

    /**
     * 소셜 가입을 기록합니다.
     *
     * @param  User  $user  회원
     * @param  SocialAccount  $account  연결
     * @return void 반환값 없음
     */
    public function onSignup(User $user, SocialAccount $account): void
    {
        $this->write('signup', $user, $account->provider_key, []);
    }

    /**
     * 활동 로그 1건을 씁니다.
     *
     * @param  string  $type  유형 (activity_log.description 키)
     * @param  User  $user  대상 회원
     * @param  string  $providerKey  제공자 키
     * @param  array<string, mixed>  $properties  추가 속성
     * @return void 반환값 없음
     */
    private function write(string $type, User $user, string $providerKey, array $properties): void
    {
        $this->logActivity($type, [
            'loggable' => $user,
            'description_key' => 'sirsoft-social_login::activity_log.description.'.$type,
            'description_params' => [
                // 활동 로그는 관리자 화면의 기록물이다. 그 시점의 관리자 영역 표시 이름을 남기고, 이미 남은 기록은 바꾸지 않는다.
                'user_name' => (string) (app(DisplayNameResolver::class)->resolve($user, DisplayNameAreaRegistry::AREA_ADMIN) ?? ''),
                'provider' => $providerKey,
            ],
            'properties' => ['target_user_id' => (int) $user->getKey(), 'provider_key' => $providerKey] + $properties,
        ]);
    }
}
