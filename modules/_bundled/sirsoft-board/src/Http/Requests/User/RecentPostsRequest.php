<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 전체 게시판 통합 최근 게시글 조회 요청을 검증합니다.
 */
class RecentPostsRequest extends FormRequest
{
    /** 조회 상한 */
    public const MAX_LIMIT = 20;

    /** 기본 조회 개수 */
    public const DEFAULT_LIMIT = 5;

    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
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
        // 상한은 규칙이 아니라 접근자에서 클램프한다 — 이 엔드포인트는 공개 API 이고, 상한
        // 초과 요청을 거부하지 않고 상한까지만 돌려주는 것이 기존 계약이다.
        return [
            'limit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 조회할 게시글 개수를 반환합니다.
     *
     * @return int 조회 개수 (상한 초과 요청은 상한으로 클램프)
     */
    public function limit(): int
    {
        return min((int) $this->validated('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
    }
}
