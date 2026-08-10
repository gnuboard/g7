<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 활성 게시판 목록(경량) 조회 요청을 검증합니다.
 *
 * 비로그인도 접근하는 공개 엔드포인트라 권한 검사는 없고, 쿼리 파라미터의 범위만 닫습니다.
 */
class IndexBoardRequest extends FormRequest
{
    /** 게시판별 최신글 동시 조회 상한 */
    public const MAX_RECENT_POSTS = 10;

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
     * 게시판별로 함께 조회할 최신글 개수를 반환합니다.
     *
     * @return int 0 이면 최신글 미포함 (상한 초과 요청은 상한으로 클램프)
     */
    public function recentPostsLimit(): int
    {
        return min((int) $this->validated('limit', 0), self::MAX_RECENT_POSTS);
    }
}
