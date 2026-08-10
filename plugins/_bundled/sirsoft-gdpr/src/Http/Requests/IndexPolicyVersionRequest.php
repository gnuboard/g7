<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 정책 버전 이력 목록 조회 요청을 검증합니다.
 */
class IndexPolicyVersionRequest extends FormRequest
{
    /** 기본 페이지 크기 */
    public const DEFAULT_PER_PAGE = 20;

    /** 페이지 크기 상한 */
    public const MAX_PER_PAGE = 100;

    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        // 상·하한은 규칙이 아니라 접근자에서 클램프한다 — 기존 컨트롤러가 범위를 벗어난
        // per_page 를 거부하지 않고 조정해 응답하던 계약을 유지한다.
        return [
            'per_page' => ['nullable', 'integer'],
        ];
    }

    /**
     * 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기 (1 ~ 상한으로 클램프)
     */
    public function perPage(): int
    {
        $perPage = (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
