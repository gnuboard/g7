<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 버전 목록 조회 요청
 *
 * 버전 행은 저장할 때마다 쌓이고 정리(pruning)되지 않습니다. 상한이 없으면 오래 편집한
 * 레이아웃 하나가 수백~수천 건의 버전을 한 응답에 담게 되므로 조회 건수를 제한합니다.
 * 더 오래된 이력이 필요하면 `limit` 을 명시해 넓힐 수 있습니다.
 */
class LayoutVersionListRequest extends FormRequest
{
    /** 기본 조회 건수 (미지정 시) */
    public const DEFAULT_LIMIT = 100;

    /** 한 번에 조회 가능한 최대 건수 */
    public const MAX_LIMIT = 500;

    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (권한 체크는 미들웨어에 위임)
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];

        // 모듈/플러그인이 목록 조회 파라미터를 확장할 수 있도록 훅을 노출한다
        return HookManager::applyFilters('core.layout_version.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 오류 메시지
     */
    public function messages(): array
    {
        return [
            'limit.integer' => __('validation.layout_version.limit.integer'),
            'limit.min' => __('validation.layout_version.limit.min'),
            'limit.max' => __('validation.layout_version.limit.max', ['max' => self::MAX_LIMIT]),
        ];
    }
}
