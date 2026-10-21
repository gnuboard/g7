<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesGuestCartKeyHeader;

/**
 * X-Cart-Key 헤더 검증 베이스 클래스
 *
 * 장바구니/체크아웃 관련 요청에서 비회원 cart_key 헤더를 검증합니다.
 * 판정은 {@see ValidatesGuestCartKeyHeader} 한 곳에 있다 — 본문 규칙이 따로 있는 임시 주문 생성·갱신 요청도 같은 판정을 쓴다.
 */
abstract class CartKeyRequest extends FormRequest
{
    use ValidatesGuestCartKeyHeader;

    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 허용(주인 판정은 헤더 검증이 맡음)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = $this->cartKeyRules();

        return HookManager::applyFilters($this->hookFilterName(), $rules, $this);
    }

    /**
     * cart_key 검증 규칙
     *
     * @return array
     */
    protected function cartKeyRules(): array
    {
        return [];
    }

    /**
     * 훅 필터 이름
     *
     * @return string
     */
    abstract protected function hookFilterName(): string;

    /**
     * 추가 검증 수행
     *
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateCartKeyHeader($validator);
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'cart_key.required' => __('sirsoft-ecommerce::validation.cart.cart_key_required'),
            'cart_key.regex' => __('sirsoft-ecommerce::validation.cart.invalid_cart_key'),
        ];
    }

    /**
     * 헤더에서 cart_key 가져오기
     *
     * @return string|null
     */
    public function getCartKey(): ?string
    {
        return $this->header('X-Cart-Key');
    }
}
