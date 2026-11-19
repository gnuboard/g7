<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\SocialLogin\Services\ProviderAdminService;

/**
 * 제공자 표시 순서 변경 요청 — 새 순서의 키 목록.
 *
 * @since 1.0.0
 */
class ReorderProvidersRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어(`settings.update`)가 본다.
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
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['string', 'distinct', 'regex:'.ProviderAdminService::KEY_PATTERN],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'keys' => __('sirsoft-social_login::validation.attributes.provider.keys'),
            'keys.*' => __('sirsoft-social_login::validation.attributes.provider.keys'),
        ];
    }
}
