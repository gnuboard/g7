<?php

namespace App\Http\Requests\Public;

use App\Extension\HookManager;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 통합 검색 요청 클래스
 *
 * 검색어, 페이지네이션 등 기본 검색 파라미터를 검증합니다.
 * 모듈별 추가 파라미터(type, sort, board_slug 등)는 훅을 통해 확장 가능합니다.
 */
class SearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 쿼리 파라미터를 정규화합니다.
     *
     * 쿼리스트링은 언제나 문자열로 도착하므로 `with_counts=false` 가 `boolean` 규칙에
     * 걸려 422 가 된다. 해석 가능한 값만 캐스팅하고, 그 외(오타 등)는 손대지 않아
     * 규칙이 그대로 걸러내게 둔다 — null 로 바꾸면 오타가 "미지정" 으로 통과한다.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('with_counts')) {
            return;
        }

        $normalized = filter_var($this->input('with_counts'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($normalized !== null) {
            $this->merge(['with_counts' => $normalized]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // 기본 검색 파라미터
            'q' => ['nullable', 'string', 'min:2', 'max:200'],
            'type' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            // 페이지 상한은 남용 차단용이다. 정상 탐색은 has_more_pages 로 계속 열려 있고,
            // 임의의 큰 페이지 번호를 직접 던져 초대형 OFFSET 을 만드는 것만 막는다.
            'page' => array_filter([
                'nullable',
                'integer',
                'min:1',
                ($maxPage = PaginationLimits::maxPage('search')) !== null ? 'max:'.$maxPage : null,
            ]),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // 깊은 페이지를 OFFSET 없이 넘기기 위한 커서. 형식이 깨진 값은
            // KeysetPaginator 가 첫 페이지로 되돌리므로 여기서는 길이만 본다.
            'cursor' => ['nullable', 'string', 'max:500'],
            // 특정 탭만 볼 때 다른 탭의 배지 건수를 세지 않게 하는 스위치.
            // 배지를 그리지 않는 화면에서 그 집계 비용을 없앤다 (기본값은 세는 쪽).
            'with_counts' => ['nullable', 'boolean'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        // 예: sort의 in 규칙, board_slug 등 모듈별 파라미터
        return HookManager::applyFiltersWithLegacyName(
            'core.search.index_validation_rules',
            'core.search.validation_rules',
            $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.min' => __('search.validation.q_min'),
            'q.max' => __('search.validation.q_max'),
            'page.integer' => __('search.validation.page_integer'),
            'page.min' => __('search.validation.page_min'),
            'page.max' => __('search.validation.page_max'),
            'per_page.integer' => __('search.validation.per_page_integer'),
            'per_page.min' => __('search.validation.per_page_min'),
            'per_page.max' => __('search.validation.per_page_max'),
        ];
    }
}
