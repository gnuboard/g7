<?php

namespace Plugins\Sirsoft\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\SocialLogin\Enums\EmailConflictPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\ExtraInfoMode;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookUnlinkPolicy;

/**
 * 플러그인 설정 저장 검증 규칙.
 *
 * 코어 설정 저장 요청이 모든 플러그인에 같은 필터를 발행하므로 식별자가 이 플러그인일 때만 규칙을 더한다.
 *
 * @since 1.0.0
 */
class ValidateSettingsListener implements HookListenerInterface
{
    /** 플러그인 식별자 */
    private const IDENTIFIER = 'sirsoft-social_login';

    /** 정수 설정 범위 [최소, 최대] */
    public const INTEGER_RANGES = [
        'profile_raw_retention_days' => [0, 90],
        'attempt_log_retention_days' => [7, 365],
        'webhook_event_retention_days' => [7, 365],
        'signup_surge_threshold_per_hour' => [0, 10000],
        'provider_failure_streak_threshold' => [0, 1000],
        'pending_signup_ttl_minutes' => [5, 120],
        'state_ttl_minutes' => [3, 30],
        'custom_provider_limit' => [0, 10],
    ];

    /** 불리언 설정 */
    public const BOOLEANS = ['two_factor_enabled', 'allow_admin_social_login', 'allow_unlink_last_method', 'revoke_on_unlink'];

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_validation_rules' => ['method' => 'rules', 'type' => 'filter', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인의 설정 규칙을 더합니다.
     *
     * @param  array<string, mixed>  $rules  기존 규칙
     * @param  string  $identifier  저장 대상 플러그인 식별자
     * @param  array<string, mixed>  $input  이번 요청 입력
     * @return array<string, mixed> 규칙
     */
    public function rules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        $rules['email_conflict_policy'] = ['sometimes', 'required', Rule::enum(EmailConflictPolicy::class)];
        $rules['extra_info_mode'] = ['sometimes', 'required', Rule::enum(ExtraInfoMode::class)];
        $rules['webhook_unlink_policy'] = ['sometimes', 'required', Rule::enum(WebhookUnlinkPolicy::class)];

        foreach (self::BOOLEANS as $field) {
            $rules[$field] = ['sometimes', 'required', 'boolean'];
        }

        foreach (self::INTEGER_RANGES as $field => [$min, $max]) {
            $rules[$field] = ['sometimes', 'required', 'integer', 'between:'.$min.','.$max];
        }

        return $rules;
    }
}
