<?php

namespace App\Http\Requests\Admin\Thumbnail;

use App\Extension\HookManager;
use App\Services\ThumbnailAdminService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 썸네일 비우기 요청
 *
 * `location` 은 공개·권한·둘 다, `preset` 을 주면 그 규격 파일만 지운다. 규격 이름은 현재 정의된 이름만 받는다
 * (꺼 둔 규격 포함 — 화면 선택지와 같은 목록). 정의에서 빠진 규격의 남은 파일은 정리 명령이 지운다.
 */
class ThumbnailPurgeRequest extends FormRequest
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
        $rules = [
            'location' => [
                'required',
                'string',
                Rule::in([...ThumbnailAdminService::LOCATIONS, ThumbnailAdminService::LOCATION_ALL]),
            ],
            'preset' => [
                'nullable',
                'string',
                'max:100',
                Rule::in(app(ThumbnailAdminService::class)->presetNames()),
            ],
            // 직전 응답의 next_cursor — `{위치 순번 0·1}:{base64url}`. 경로 비교에만 쓰여 파일을 직접 가리키지 않는다
            'cursor' => [
                'nullable',
                'string',
                'max:2048',
                'regex:/^[01]:[A-Za-z0-9_-]*$/',
            ],
        ];

        return HookManager::applyFilters('core.thumbnail.purge_validation_rules', $rules, $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'location' => __('thumbnail.attributes.location'),
            'preset' => __('thumbnail.attributes.preset'),
            'cursor' => __('thumbnail.attributes.cursor'),
        ];
    }
}
