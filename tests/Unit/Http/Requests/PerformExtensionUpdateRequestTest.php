<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Module\PerformModuleUpdateRequest;
use App\Http\Requests\Plugin\PerformPluginUpdateRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 모듈/플러그인 업데이트 요청 검증 규칙 테스트.
 *
 * layout_strategy 와 vendor_mode 가 유효한 값만 수용하는지 확인.
 */
class PerformExtensionUpdateRequestTest extends TestCase
{
    /**
     * @dataProvider validLayoutStrategyProvider
     */
    public function test_module_update_request_accepts_valid_layout_strategy(string $strategy): void
    {
        $rules = (new PerformModuleUpdateRequest())->rules();
        $validator = Validator::make(['layout_strategy' => $strategy], $rules);

        $this->assertFalse($validator->fails(), "layout_strategy={$strategy} 은 유효해야 합니다");
    }

    /**
     * @dataProvider validLayoutStrategyProvider
     */
    public function test_plugin_update_request_accepts_valid_layout_strategy(string $strategy): void
    {
        $rules = (new PerformPluginUpdateRequest())->rules();
        $validator = Validator::make(['layout_strategy' => $strategy], $rules);

        $this->assertFalse($validator->fails(), "layout_strategy={$strategy} 은 유효해야 합니다");
    }

    public function test_module_update_request_rejects_invalid_layout_strategy(): void
    {
        $rules = (new PerformModuleUpdateRequest())->rules();
        $validator = Validator::make(['layout_strategy' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('layout_strategy', $validator->errors()->toArray());
    }

    public function test_plugin_update_request_rejects_invalid_layout_strategy(): void
    {
        $rules = (new PerformPluginUpdateRequest())->rules();
        $validator = Validator::make(['layout_strategy' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('layout_strategy', $validator->errors()->toArray());
    }

    public function test_module_update_request_layout_strategy_is_optional(): void
    {
        $rules = (new PerformModuleUpdateRequest())->rules();
        $validator = Validator::make([], $rules);

        $this->assertFalse($validator->fails(), 'layout_strategy 미지정 시에도 통과해야 함');
    }

    /**
     * @dataProvider validVendorModeProvider
     */
    public function test_module_update_request_accepts_valid_vendor_mode(string $mode): void
    {
        $rules = (new PerformModuleUpdateRequest())->rules();
        $validator = Validator::make(['vendor_mode' => $mode], $rules);

        $this->assertFalse($validator->fails(), "vendor_mode={$mode} 은 유효해야 합니다");
    }

    public function test_plugin_update_request_rejects_invalid_vendor_mode(): void
    {
        $rules = (new PerformPluginUpdateRequest())->rules();
        $validator = Validator::make(['vendor_mode' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vendor_mode', $validator->errors()->toArray());
    }

    public static function validLayoutStrategyProvider(): array
    {
        return [
            'overwrite' => ['overwrite'],
            'keep' => ['keep'],
        ];
    }

    public static function validVendorModeProvider(): array
    {
        return [
            'auto' => ['auto'],
            'composer' => ['composer'],
            'bundled' => ['bundled'],
        ];
    }
}
