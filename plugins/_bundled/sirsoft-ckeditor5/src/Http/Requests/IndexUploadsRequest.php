<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 업로드 이미지 목록 조회 요청 검증
 *
 * 정렬 허용 어휘(`sort_by`)는 Repository 화이트리스트의 부분집합이어야 합니다 —
 * 게이트가 더 넓으면 저장소가 조용히 기본 정렬로 되돌리고, 화면 옵션이 더 넓으면
 * 그 옵션을 고를 때마다 목록 전체가 422 가 됩니다.
 */
class IndexUploadsRequest extends FormRequest
{
    /** 기본 페이지 크기 */
    public const DEFAULT_PER_PAGE = 20;

    /** 페이지 크기 상한 */
    public const MAX_PER_PAGE = 100;

    /**
     * 정렬 허용 컬럼 (Repository 화이트리스트와 동일 어휘)
     *
     * @var list<string>
     */
    public const SORTABLE = ['created_at', 'file_size', 'original_name'];

    /**
     * 참조 상태 필터 허용 어휘
     *
     * @var list<string>
     */
    public const REFERENCED_STATES = ['all', 'referenced', 'unreferenced'];

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
     * 빈 문자열로 도착한 필터를 검증 전에 제거합니다.
     *
     * 화면은 값을 비울 때 `?referenced=` 처럼 빈 문자열을 보냅니다 — 그대로 두면
     * `Rule::in` 에 걸려 목록 전체가 422 가 됩니다.
     */
    protected function prepareForValidation(): void
    {
        $cleared = [];

        foreach (['search', 'referenced', 'date_from', 'date_to', 'sort_by', 'sort_order', 'per_page', 'page'] as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $cleared[$key] = null;
            }
        }

        if ($cleared !== []) {
            $this->merge($cleared);
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
            'search' => ['nullable', 'string', 'max:255'],
            'referenced' => ['nullable', 'string', Rule::in(self::REFERENCED_STATES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * 목록 필터를 반환합니다.
     *
     * @return array<string, mixed> 필터 배열
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'referenced' => $this->validated('referenced') ?? 'all',
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
            'sort_by' => $this->validated('sort_by'),
            'sort_order' => $this->validated('sort_order'),
        ];
    }

    /**
     * 페이지 크기를 반환합니다.
     *
     * @return int 페이지 크기
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }

    /**
     * 페이지 번호를 반환합니다.
     *
     * @return int 페이지 번호
     */
    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
