<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

/**
 * 배송정책 목록 조회 요청
 */
class ShippingPolicyListRequest extends FormRequest
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
     * 검증 전 쿼리 파라미터를 정규화합니다.
     *
     * 쿼리스트링 값은 항상 문자열로 도착한다. `boolean` 규칙은 `"true"`/`"false"` 를 받지 않으므로
     * 정규화하지 않으면 화면이 그 형태로 보낼 때 목록 전체가 422 가 된다. 해석할 수 없는 값은
     * 건드리지 않는다 — null 로 바꾸면 오타 파라미터가 "지정 안 함" 으로 조용히 통과한다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('with_country_settings')) {
            return;
        }

        $normalized = filter_var(
            $this->input('with_country_settings'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($normalized !== null) {
            $this->merge(['with_country_settings' => $normalized]);
        }
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 정책명 검색
            'search' => ['nullable', 'string', 'max:200'],

            // 배송방법 (다중선택)
            'shipping_methods' => ['nullable', 'array'],
            'shipping_methods.*' => ['string', Rule::in(
                app(ShippingTypeRepositoryInterface::class)->getAll()->pluck('code')->toArray()
            )],

            // 부과정책 (다중선택)
            'charge_policies' => ['nullable', 'array'],
            'charge_policies.*' => ['string', Rule::in(ChargePolicyEnum::values())],

            // 배송국가 (다중선택, Settings 기반 동적 국가)
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:10'],

            // 사용여부
            'is_active' => ['nullable', 'in:,true,false'],

            // 국가별 설정 동반 조회 (기본 미포함)
            //
            // 배송정책 관리 목록 화면은 국가별 설정도 배송비 요약도 쓰지 않는다. 반면 상품 등록/
            // 수정 화면의 배송정책 선택기는 같은 엔드포인트를 쓰면서 선택한 정책의 국가 국기와
            // 배송비 요약을 그린다. 그래서 기본은 경량으로 두고, 필요한 화면만 이 값을 켠다
            // (정책당 국가 설정이 최대 수백 행이라 기본 포함 시 목록 한 페이지가 그만큼 커진다).
            'with_country_settings' => ['nullable', 'boolean'],

            // 정렬 및 페이지네이션
            'sort_by' => ['nullable', 'in:id,name,is_active,sort_order,created_at,updated_at'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'search.string' => __('sirsoft-ecommerce::validation.list.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.list.search.max'),
            'shipping_methods.array' => __('sirsoft-ecommerce::validation.list.shipping_methods.array'),
            'shipping_methods.*.string' => __('sirsoft-ecommerce::validation.list.shipping_methods.string'),
            'shipping_methods.*.in' => __('sirsoft-ecommerce::validation.list.shipping_methods.in'),
            'charge_policies.array' => __('sirsoft-ecommerce::validation.list.charge_policies.array'),
            'charge_policies.*.string' => __('sirsoft-ecommerce::validation.list.charge_policies.string'),
            'charge_policies.*.in' => __('sirsoft-ecommerce::validation.list.charge_policies.in'),
            'countries.array' => __('sirsoft-ecommerce::validation.list.countries.array'),
            'countries.*.string' => __('sirsoft-ecommerce::validation.list.countries.string'),
            'countries.*.max' => __('sirsoft-ecommerce::validation.list.countries.max'),
            'is_active.in' => __('sirsoft-ecommerce::validation.list.is_active.in'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
        ];

        // 훅을 통한 validation messages 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.list_validation_messages', $messages, $this);
    }
}
