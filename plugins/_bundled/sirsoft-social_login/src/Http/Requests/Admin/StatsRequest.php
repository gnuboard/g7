<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\SocialLogin\Services\StatsService;

/**
 * 통계 조회 요청 — 기간 7·30·90일(기본 30).
 *
 * @since 1.0.0
 */
class StatsRequest extends FormRequest
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
            'days' => ['nullable', 'integer', Rule::in(StatsService::PERIODS)],
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
            'days' => __('sirsoft-social_login::validation.attributes.log.days'),
        ];
    }
}
