<?php

namespace Modules\Gnuboard7\HelloModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 메모 목록 조회 요청
 *
 * 목록 조회도 FormRequest 로 검증한다는 규정을 보여주는 샘플이다.
 * 페이지네이션 파라미터에 상·하한이 없으면 per_page=0 은 모델 기본값으로 조용히 바뀌고,
 * 음수는 예외가 되며, 과대값은 테이블 전량을 반환한다.
 */
class MemoListRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * 인증·권한은 라우트 미들웨어가 담당하므로 항상 true 를 반환합니다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array 검증 규칙
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
