<?php

namespace App\Http\Requests\Admin\Thumbnail;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 썸네일 저장 위치별 사용량 조회 요청
 *
 * 받는 파라미터는 없다. 확장이 조회 조건을 덧붙일 수 있게 검증 규칙 필터만 연다.
 */
class ThumbnailStatsRequest extends FormRequest
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
        return HookManager::applyFilters('core.thumbnail.stats_validation_rules', [], $this);
    }
}
