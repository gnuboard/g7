<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 인기 게시판(게시글 수 기준) 조회 요청을 검증합니다.
 */
class PopularBoardsRequest extends FormRequest
{
    /** 조회 상한 */
    public const MAX_LIMIT = 20;

    /** 기본 조회 개수 */
    public const DEFAULT_LIMIT = 4;

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
        // 상한은 규칙이 아니라 접근자에서 클램프한다 (기존 공개 API 계약).
        return [
            'limit' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 조회할 게시판 개수를 반환합니다.
     *
     * @return int 조회 개수 (상한 초과 요청은 상한으로 클램프)
     */
    public function limit(): int
    {
        return min((int) $this->validated('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT);
    }
}
