<?php

namespace App\Http\Requests\Admin\Thumbnail;

use App\Extension\HookManager;
use App\Services\ThumbnailAdminService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 기존 이미지 썸네일 만들기(한 번분) 요청
 *
 * `cursor` 는 직전 응답의 `next_cursor` 를 그대로 돌려받는다(`{제공자 순번}:{제공자 커서}`).
 * `sources`·`presets` 를 비우면 전부다 — 모르는 키가 「전부」로 해석되지 않게 목록 밖 값은 거부한다.
 */
class ThumbnailPregenerateRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $service = app(ThumbnailAdminService::class);

        $rules = [
            'cursor' => ['nullable', 'string', 'max:200', 'regex:/^\d+:[^\s]*$/'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', Rule::in($service->sourceKeys())],
            'presets' => ['nullable', 'array'],
            'presets.*' => ['string', Rule::in($service->presetNames())],
        ];

        return HookManager::applyFilters('core.thumbnail.pregenerate_validation_rules', $rules, $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'cursor' => __('thumbnail.attributes.cursor'),
            'sources' => __('thumbnail.attributes.sources'),
            'sources.*' => __('thumbnail.attributes.sources'),
            'presets' => __('thumbnail.attributes.presets'),
            'presets.*' => __('thumbnail.attributes.presets'),
        ];
    }
}
