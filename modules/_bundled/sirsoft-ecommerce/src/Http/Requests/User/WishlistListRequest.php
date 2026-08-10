<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 찜 목록 조회 요청
 *
 * 페이지네이션 파라미터의 상·하한을 게이트에서 닫는다. 상한만 있던 기존 클램프는
 * per_page=0 / 비숫자에서 모델 기본값(15)으로 조용히 바뀌고 음수에서 예외가 되었다.
 */
class WishlistListRequest extends FormRequest
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
