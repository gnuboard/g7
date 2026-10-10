<?php

namespace Modules\Sirsoft\Board\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Http\Requests\Concerns\FallsBackUnknownSearchField;
use Modules\Sirsoft\Board\Http\Requests\Concerns\ProvidesFallbackRuleMessages;

/**
 * 마이페이지 「내 신고」 목록 조회 요청
 *
 * 본인이 남긴 신고 로그만 대상이며, 검색은 신고 시점 스냅샷(제목·내용)과 사유 상세를 본다.
 */
class IndexMyReportRequest extends FormRequest
{
    use FallsBackUnknownSearchField;
    use ProvidesFallbackRuleMessages;

    /**
     * 검색 대상 목록.
     *
     * @var array<int, string>
     */
    public const SEARCH_FIELDS = ['title_content', 'title', 'content', 'reason'];

    /**
     * 권한 확인 (인증은 라우트 미들웨어가 담당).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 허용 목록 밖의 검색 대상은 422 대신 기본 검색 대상(제목+내용)으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->fallBackUnknownSearchField(self::SEARCH_FIELDS, 'title_content');
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search_field' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::SEARCH_FIELDS)],
            'target_type' => ['sometimes', 'nullable', 'string', Rule::in(ReportType::values())],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ReportStatus::values())],
            'board_slug' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:latest,oldest'],
        ];
    }

    /**
     * 검증 실패 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search_field.in' => __('sirsoft-board::validation.report_my.search_field.in'),
            'target_type.in' => __('sirsoft-board::validation.report_my.target_type.in'),
            'status.in' => __('sirsoft-board::validation.report_my.status.in'),
        ];
    }

    /**
     * 검증 속성명
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search_field' => __('sirsoft-board::validation.attributes.report_my.search_field'),
            'target_type' => __('sirsoft-board::validation.attributes.report_my.target_type'),
            'status' => __('sirsoft-board::validation.attributes.report_my.status'),
        ];
    }
}
