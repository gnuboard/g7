<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;

/**
 * 찜 토글 요청
 */
class ToggleWishlistRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 허용 (로그인 여부는 라우트 미들웨어가 본다)
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
        $rules = [
            'product_id' => [
                'required',
                'integer',
                // 전시 중이고 삭제되지 않은 상품만 새로 찜할 수 있다. 다만 숨기기 전에 찜해 둔 상품은
                // 해제할 수 있어야 하므로, 이 회원의 찜 행이 이미 있으면 통과시킨다(토글의 해제 갈래).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $isPublic = Validator::make(['id' => $value], [
                        'id' => Rule::exists(Product::class, 'id')
                            ->where('display_status', ProductDisplayStatus::VISIBLE->value)
                            ->whereNull('deleted_at'),
                    ])->passes();

                    $alreadyWished = Validator::make(['id' => $value], [
                        'id' => Rule::exists(ProductWishlist::class, 'product_id')
                            ->where('user_id', $this->user()?->getAuthIdentifier()),
                    ])->passes();

                    if (! $isPublic && ! $alreadyWished) {
                        $fail(__('sirsoft-ecommerce::validation.wishlist.product_not_found'));
                    }
                },
            ],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.wishlist.toggle_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_id.required' => __('sirsoft-ecommerce::validation.wishlist.product_id_required'),
            'product_id.exists' => __('sirsoft-ecommerce::validation.wishlist.product_not_found'),
        ];
    }
}
