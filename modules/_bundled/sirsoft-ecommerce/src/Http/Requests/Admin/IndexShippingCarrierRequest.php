<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 배송사 목록 조회 요청을 검증합니다.
 *
 * 저장소(`ShippingCarrierRepository::getAll`)가 해석하는 필터만 통과시킵니다 — 검증되지 않은
 * 요청 배열을 그대로 Service 로 넘기지 않기 위함입니다.
 */
class IndexShippingCarrierRequest extends FormRequest
{
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
     * 쿼리 문자열로 도착하는 불리언을 검증 전에 정규화합니다.
     *
     * 해석 가능한 값만 캐스팅하고, 해석 불가한 값은 그대로 두어 422 로 드러낸다.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            return;
        }

        $value = filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value !== null) {
            $this->merge(['is_active' => $value]);
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
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 목록 필터를 반환합니다.
     *
     * @return array<string, mixed> 필터 배열
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn ($value) => $value !== null
        );
    }
}
