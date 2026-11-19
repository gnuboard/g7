<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;

/**
 * IdP 추가 요청 — 수정 요청의 칸에 키와 프로토콜을 더한다.
 *
 * @since 1.0.0
 */
class IdpStoreRequest extends IdpUpdateRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'protocol' => ['required', 'string', 'max:10', 'regex:/^[a-z][a-z0-9_]{1,9}$/'],
            'label' => ['required', 'array', new LocaleRequiredTranslatable(100)],
        ] + parent::rules();
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'key' => __('sirsoft-sso::admin.attributes.key'),
            'protocol' => __('sirsoft-sso::admin.attributes.protocol'),
            'label' => __('sirsoft-sso::admin.attributes.label'),
        ] + parent::attributes();
    }
}
