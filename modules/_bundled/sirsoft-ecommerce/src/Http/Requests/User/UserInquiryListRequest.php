<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 마이페이지 내 문의 목록 조회 요청
 *
 * 페이지네이션 파라미터의 상·하한을 게이트에서 닫아, 저장소가 음수·0·과대 per_page 를
 * 그대로 받지 않도록 한다.
 */
class UserInquiryListRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 불리언 쿼리 파라미터를 정규화합니다.
     *
     * 쿼리 스트링은 언제나 문자열로 도착하는데 Laravel `boolean` 규칙은 `"true"` / `"false"` 를
     * 받지 않는다. 정규화가 없으면 화면이 그 형태로 보내는 순간 목록이 통째로 422 가 된다
     * (#492 7차 브라우저 실측 D-38 과 같은 원인).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_answered')) {
            $normalized = filter_var(
                $this->query('is_answered'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            // 해석 불가한 값은 건드리지 않는다 — null 로 바꿔 버리면 오타 파라미터가
            // 조용히 "필터 없음" 으로 통과해 게이트가 무력해진다.
            if ($normalized !== null) {
                $this->merge(['is_answered' => $normalized]);
            }
        }
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
            'search' => ['nullable', 'string', 'max:100'],
            'is_answered' => ['nullable', 'boolean'],
        ];
    }
}
