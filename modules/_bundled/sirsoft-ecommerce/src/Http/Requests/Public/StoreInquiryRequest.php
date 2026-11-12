<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Concerns\EnsuresPublicProduct;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ValidatesTempKey;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 1:1 문의 작성 요청 (회원 전용)
 */
class StoreInquiryRequest extends FormRequest
{
    use EnsuresPublicProduct;
    use ValidatesTempKey;

    /**
     * 권한 확인
     *
     * @return bool 항상 허용 (로그인 여부는 라우트 미들웨어가 본다)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전에 문의 대상 상품이 공개 상태인지 확인합니다.
     *
     * 숨김·삭제 상품에는 문의를 받지 않는다 — 본문 검증(422)보다 먼저 상품 없음(404)으로 답한다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $product = $this->route('product');

        $this->ensurePublicProduct($product instanceof Product ? $product : null);
    }

    /**
     * 유효성 검사 규칙
     *
     * 기본 규칙은 최소한으로 정의하며, 게시판 설정 기반 동적 규칙은
     * BoardInquiryListener가 store_validation_rules Filter 훅으로 주입합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'is_secret' => ['boolean'],
            'temp_key' => $this->tempKeyRules(),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.inquiry.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'content.required' => __('sirsoft-ecommerce::validation.inquiries.content.required'),
            'content.min' => __('sirsoft-ecommerce::validation.inquiries.content.min'),
            'content.max' => __('sirsoft-ecommerce::validation.inquiries.content.max'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.inquiry.store_validation_messages', $messages, $this);
    }
}
