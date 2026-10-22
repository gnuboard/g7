<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookEventType;
use Plugins\Sirsoft\SocialLogin\Enums\WebhookStatus;
use Plugins\Sirsoft\SocialLogin\Services\ProviderAdminService;

/**
 * 진단 화면 기록 목록(시도·웹훅 수신) 조회 요청.
 *
 * @since 1.0.0
 */
class AdminLogListRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어(`settings.view`)가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'regex:'.ProviderAdminService::KEY_PATTERN],
            'outcome' => ['nullable', Rule::in(AttemptOutcome::values())],
            'intent' => ['nullable', Rule::in(AttemptIntent::values())],
            'error_code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(WebhookStatus::values())],
            'event_type' => ['nullable', Rule::in(WebhookEventType::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-social_login::validation.attributes.log.';

        return [
            'provider' => __($p.'provider'),
            'outcome' => __($p.'outcome'),
            'intent' => __($p.'intent'),
            'error_code' => __($p.'error_code'),
            'status' => __($p.'status'),
            'event_type' => __($p.'event_type'),
            'page' => __($p.'page'),
            'per_page' => __($p.'per_page'),
        ];
    }
}
