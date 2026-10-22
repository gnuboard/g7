<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * discovery 문서 조회 요청 — https 주소만.
 *
 * @since 1.0.0
 */
class DiscoverProviderRequest extends FormRequest
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
            'discovery_url' => ['required', 'url:https', 'max:500'],
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
            'discovery_url' => __('sirsoft-social_login::validation.attributes.provider.discovery_url'),
        ];
    }
}
