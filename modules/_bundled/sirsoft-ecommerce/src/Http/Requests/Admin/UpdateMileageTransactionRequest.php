<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 마일리지 적립건 편집 요청 (사유 변경 + 만료일 직접 지정)
 *
 * 마일리지 원장은 불변이므로 적립계 거래의 부가 필드(memo / expires_at)만 보정한다.
 * 적립계 여부·소멸/사용 상태에 따른 편집 가능성은 Service 가 도메인 규칙으로 판정한다.
 */
class UpdateMileageTransactionRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * memo / expires_at 둘 다 nullable — 전달된 키만 갱신한다 (memo 비우기 / 만료일 무기한 허용).
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'memo' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    /**
     * 다국어 검증 메시지
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'expires_at.date' => __('sirsoft-ecommerce::validation.mileage.expires_at_invalid'),
        ];
    }
}
