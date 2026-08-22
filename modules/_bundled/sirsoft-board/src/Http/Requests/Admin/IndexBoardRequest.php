<?php

namespace Modules\Sirsoft\Board\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 게시판 목록 조회 요청을 검증합니다.
 *
 * 정렬 컬럼·페이지 크기를 닫힌 집합으로 제한합니다 — 요청 값이 그대로
 * orderBy 로 흐르면 스키마 노출·비인덱스 정렬 DoS 표면이 됩니다.
 * `name` 은 JSON 다국어 컬럼이라 정렬 대상에서 제외합니다.
 * 권한은 라우트 permission 미들웨어 체인이 담당합니다 (authorize true 고정 규정).
 */
class IndexBoardRequest extends FormRequest
{
    /**
     * 요청 권한 — permission 미들웨어 체인이 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력 데이터 정규화
     *
     * 정렬 방향을 소문자로 정규화합니다 (asc/ASC 혼용 클라이언트 수용).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sort_order'))) {
            $this->merge(['sort_order' => strtolower($this->input('sort_order'))]);
        }
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            // 게시판은 운영자 등록 설정성 테이블 — 전체 목록 셀렉트 소비처
            // (게시판 설정 999 / 쇼핑몰 환경설정 문의 카드 200)가 이 상한에 묶인다.
            // 100 으로 조이면 두 화면 진입이 422 로 깨진다 (BoardIndexSortTest 회귀 고정).
            'per_page' => ['nullable', 'integer', 'min:1', 'max:999'],
            'type' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:id,slug,type,is_active,posts_count,comments_count,created_at,updated_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
