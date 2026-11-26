<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 마이페이지 내 리뷰 목록 요청
 */
class UserReviewListRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 항상 true (인증 미들웨어가 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.product-review.user_list_validation_rules', $rules, $this);
    }
}
