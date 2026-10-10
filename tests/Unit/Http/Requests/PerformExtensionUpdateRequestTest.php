<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\Module\PerformModuleUpdateRequest;
use App\Http\Requests\Plugin\PerformPluginUpdateRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 모듈/플러그인 업데이트 요청 검증 규칙 테스트.
 *
 * layout_strategy 와 vendor_mode 가 유효한 값만 수용하는지 확인.
 */
class PerformExtensionUpdateRequestTest extends TestCase
{
    /**
     * 모듈 업데이트 요청은 허용된 레이아웃 전략을 받는다.
     *
     * @scenario concern=request_validation, extension_type=module
     *
     * @effects module_update_request_accepts_valid_layout_strategy
     *
     * @param  string  $strategy  레이아웃 전략
     * @return void 반환값 없음
     */
    #[DataProvider('validLayoutStrategyProvider')]
    public function test_module_update_request_accepts_valid_layout_strategy(string $strategy): void
    {
        $rules = (new PerformModuleUpdateRequest)->rules();
        $validator = Validator::make(['layout_strategy' => $strategy], $rules);

        $this->assertFalse($validator->fails(), "layout_strategy={$strategy} 은 유효해야 합니다");
    }

    /**
     * 플러그인 업데이트 요청은 허용된 레이아웃 전략을 받는다.
     *
     * @scenario concern=request_validation, extension_type=plugin
     *
     * @effects plugin_update_request_accepts_valid_layout_strategy
     *
     * @param  string  $strategy  레이아웃 전략
     * @return void 반환값 없음
     */
    #[DataProvider('validLayoutStrategyProvider')]
    public function test_plugin_update_request_accepts_valid_layout_strategy(string $strategy): void
    {
        $rules = (new PerformPluginUpdateRequest)->rules();
        $validator = Validator::make(['layout_strategy' => $strategy], $rules);

        $this->assertFalse($validator->fails(), "layout_strategy={$strategy} 은 유효해야 합니다");
    }

    /**
     * 모듈 업데이트 요청은 허용 밖 레이아웃 전략을 거절한다.
     *
     * @scenario concern=request_validation, extension_type=module
     *
     * @effects module_update_request_rejects_invalid_layout_strategy
     *
     * @return void 반환값 없음
     */
    public function test_module_update_request_rejects_invalid_layout_strategy(): void
    {
        $rules = (new PerformModuleUpdateRequest)->rules();
        $validator = Validator::make(['layout_strategy' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('layout_strategy', $validator->errors()->toArray());
    }

    /**
     * 플러그인 업데이트 요청은 허용 밖 레이아웃 전략을 거절한다.
     *
     * @scenario concern=request_validation, extension_type=plugin
     *
     * @effects plugin_update_request_rejects_invalid_layout_strategy
     *
     * @return void 반환값 없음
     */
    public function test_plugin_update_request_rejects_invalid_layout_strategy(): void
    {
        $rules = (new PerformPluginUpdateRequest)->rules();
        $validator = Validator::make(['layout_strategy' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('layout_strategy', $validator->errors()->toArray());
    }

    /**
     * 모듈 업데이트 요청의 레이아웃 전략은 생략할 수 있다.
     *
     * @scenario concern=request_validation, extension_type=module
     *
     * @effects module_update_request_layout_strategy_is_optional
     *
     * @return void 반환값 없음
     */
    public function test_module_update_request_layout_strategy_is_optional(): void
    {
        $rules = (new PerformModuleUpdateRequest)->rules();
        $validator = Validator::make([], $rules);

        $this->assertFalse($validator->fails(), 'layout_strategy 미지정 시에도 통과해야 함');
    }

    /**
     * 모듈 업데이트 요청은 허용된 vendor 설치 방식을 받는다.
     *
     * @scenario concern=request_validation, extension_type=module
     *
     * @effects module_update_request_accepts_valid_vendor_mode
     *
     * @param  string  $mode  vendor 설치 방식
     * @return void 반환값 없음
     */
    #[DataProvider('validVendorModeProvider')]
    public function test_module_update_request_accepts_valid_vendor_mode(string $mode): void
    {
        $rules = (new PerformModuleUpdateRequest)->rules();
        $validator = Validator::make(['vendor_mode' => $mode], $rules);

        $this->assertFalse($validator->fails(), "vendor_mode={$mode} 은 유효해야 합니다");
    }

    /**
     * 플러그인 업데이트 요청은 허용 밖 vendor 설치 방식을 거절한다.
     *
     * @scenario concern=request_validation, extension_type=plugin
     *
     * @effects plugin_update_request_rejects_invalid_vendor_mode
     *
     * @return void 반환값 없음
     */
    public function test_plugin_update_request_rejects_invalid_vendor_mode(): void
    {
        $rules = (new PerformPluginUpdateRequest)->rules();
        $validator = Validator::make(['vendor_mode' => 'invalid'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vendor_mode', $validator->errors()->toArray());
    }

    /**
     * 허용되는 레이아웃 전략을 돌려줍니다.
     *
     * @return array<string, array{0: string}> 레이아웃 전략
     */
    public static function validLayoutStrategyProvider(): array
    {
        return [
            'overwrite' => ['overwrite'],
            'keep' => ['keep'],
        ];
    }

    /**
     * 허용되는 vendor 설치 방식을 돌려줍니다.
     *
     * @return array<string, array{0: string}> vendor 설치 방식
     */
    public static function validVendorModeProvider(): array
    {
        return [
            'auto' => ['auto'],
            'composer' => ['composer'],
            'bundled' => ['bundled'],
        ];
    }
}
