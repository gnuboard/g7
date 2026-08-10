<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Settings;

use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductNoticeTemplateListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 설정 화면 ↔ 저장 규칙 경계 정합 테스트 (E10 · E11)
 *
 * 설정 JSON 의 `min`/`max`/`step` 과 FormRequest 규칙이 어긋나면 "화면이 허용한 값인데
 * 저장에서 422" 또는 그 반대가 됩니다. 어느 쪽이 옳은지는 건별로 판정했습니다.
 */
class SettingsContractBoundaryTest extends ModuleTestCase
{
    /**
     * FormRequest 규칙으로 검증기를 실행합니다.
     *
     * @param  class-string  $requestClass  FormRequest 클래스
     * @param  array  $data  요청 데이터
     * @return Validator 실행된 검증기
     */
    private function validate(string $requestClass, array $data)
    {
        $request = $requestClass::create('/', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);

        $validator = $this->app['validator']->make($data, $request->rules());
        $request->setValidator($validator);

        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        $validator->passes();

        return $validator;
    }

    /**
     * 리뷰 이미지 크기는 정수만 허용되는지 테스트합니다 (JSON step 0.5 가 오류였다).
     */
    public function test_review_image_size_rejects_fractional_value(): void
    {
        $validator = $this->validate(StoreEcommerceSettingsRequest::class, [
            'review_settings' => ['max_image_size_mb' => 5.5],
        ]);

        $this->assertTrue($validator->errors()->has('review_settings.max_image_size_mb'));
    }

    /**
     * 자동취소 기한 0 이 차단되는지 테스트합니다.
     *
     * 0 은 `now()->addDays(0)` = 즉시 만료라 실질적으로 쓸 수 없는 값 → UI(min:1)가 옳다.
     */
    public function test_auto_cancel_days_zero_is_rejected(): void
    {
        $validator = $this->validate(StoreEcommerceSettingsRequest::class, [
            'order_settings' => ['auto_cancel_days' => 0],
        ]);

        $this->assertTrue($validator->errors()->has('order_settings.auto_cancel_days'));
    }

    /**
     * 자동취소 기한 1 은 통과하는지 테스트합니다 (경계).
     */
    public function test_auto_cancel_days_one_is_allowed(): void
    {
        $validator = $this->validate(StoreEcommerceSettingsRequest::class, [
            'order_settings' => ['auto_cancel_days' => 1],
        ]);

        $this->assertFalse($validator->errors()->has('order_settings.auto_cancel_days'));
    }

    /**
     * per_page 숫자 값에 상한이 적용되는지 테스트합니다 (E11).
     */
    public function test_per_page_numeric_upper_bound_is_enforced(): void
    {
        $validator = $this->validate(ProductNoticeTemplateListRequest::class, [
            'per_page' => '100000',
        ]);

        $this->assertTrue($validator->errors()->has('per_page'));
    }

    /**
     * per_page = all 은 기존 계약대로 허용되는지 테스트합니다.
     */
    public function test_per_page_all_is_still_allowed(): void
    {
        $validator = $this->validate(ProductNoticeTemplateListRequest::class, [
            'per_page' => 'all',
        ]);

        $this->assertFalse($validator->errors()->has('per_page'));
    }

    /**
     * per_page 상한 이내는 통과하는지 테스트합니다.
     */
    public function test_per_page_within_bound_passes(): void
    {
        $validator = $this->validate(ProductNoticeTemplateListRequest::class, [
            'per_page' => '100',
        ]);

        $this->assertFalse($validator->errors()->has('per_page'));
    }
}
