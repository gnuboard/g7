<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 「서비스 추가」 창의 예시 주소 확인 요청 (#883 5단계)
 *
 * 저장 전 입력값(주소 형식·플레이어 주소·id 글자 규칙)과 예시 주소를 받아, 저장 때와 같은 컴파일 규칙으로 꺼낸 id 와 만들어질
 * 플레이어 주소를 돌려준다. 서버에는 아무것도 저장하지 않는다.
 */
class EmbedProviderPreviewRequest extends FormRequest
{
    /**
     * 요청 권한 확인
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'provider' => ['required', 'array'],
            'provider.url_patterns' => ['required', 'array', 'min:1', 'max:5'],
            'provider.url_patterns.*' => ['string', 'max:255'],
            'provider.player_url' => ['required', 'string', 'max:255'],
            'provider.id_rule' => ['nullable', 'string', 'max:20'],
            'sample_url' => ['required', 'string', 'max:500'],
        ];

        return HookManager::applyFilters('core.settings.embed_preview_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성 표시명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'provider' => __('validation.attributes.embed_provider'),
            'provider.url_patterns' => __('validation.attributes.embed_provider_url_patterns'),
            'provider.url_patterns.*' => __('validation.attributes.embed_provider_url_patterns'),
            'provider.player_url' => __('validation.attributes.embed_provider_player_url'),
            'provider.id_rule' => __('validation.attributes.embed_provider_id_rule'),
            'sample_url' => __('validation.attributes.embed_provider_sample_url'),
        ];
    }
}
