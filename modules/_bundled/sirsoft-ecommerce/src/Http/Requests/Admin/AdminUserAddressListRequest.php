<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 관리자 회원 배송지 목록 조회 요청
 *
 * 전 회원의 배송지를 조회할 때의 필터·정렬·페이지네이션 형식을 검증합니다.
 * 권한은 라우트 permission 미들웨어가 담당하므로 여기서는 형식만 본다.
 */
class AdminUserAddressListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 회원 한정 필터를 배열로 정규화합니다.
     *
     * 화면은 `user_uuids[]` 로 보내지만 주소창을 손으로 고친 진입(`user_uuids=a,b`)도 같은 뜻이다 —
     * 문자열로 도착하면 `array` 규칙이 422 로 막아 목록 화면이 통째로 서지 않는다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('user_uuids');

        if (is_string($raw)) {
            $this->merge([
                'user_uuids' => array_values(array_filter(array_map('trim', explode(',', $raw)))),
            ]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc'],
            // 'all' 은 화면의 기본 갈래다 — 수령인·주소·회원을 한 번에 훑는다. member/member_id/email
            // 은 회원 전용 필터가 생기기 전의 링크 호환으로 남겨 둔다.
            'search_field' => ['nullable', 'string', 'in:all,member,member_id,email,recipient,address'],
            'search_keyword' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'user_uuid' => ['nullable', 'string', 'uuid'],
            // 여러 회원으로 좁히는 전용 필터. 상한을 둔 것은 IN 절이 URL 길이만큼 길어지는 것을 막기 위함이다.
            'user_uuids' => ['nullable', 'array', 'max:50'],
            'user_uuids.*' => ['string', 'uuid'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
