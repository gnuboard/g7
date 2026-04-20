<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Requests\Module\InstallModuleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 모듈 설치 API 의 vendor_mode 파라미터 검증.
 *
 * `POST /api/admin/modules/install` 엔드포인트가 `vendor_mode` 를 올바르게 받아
 * FormRequest 검증을 통과하고, Service 레이어로 전달하는지 검증한다.
 *
 * 실제 모듈 설치는 마이그레이션/composer install 등 사이드 이펙트가 크므로
 * FormRequest 규칙 검증 + 컨트롤러 파라미터 수신만 격리 검증한다.
 */
class ModuleInstallWithVendorModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_request_accepts_valid_vendor_modes(): void
    {
        foreach (['auto', 'composer', 'bundled'] as $mode) {
            $request = new InstallModuleRequest;
            $request->replace([
                'module_name' => 'test-valid-module',
                'vendor_mode' => $mode,
            ]);
            $request->setContainer($this->app);

            $validator = \Validator::make($request->all(), $request->rules());

            $this->assertTrue(
                $validator->passes() || ! in_array('vendor_mode', array_keys($validator->failed()), true),
                "vendor_mode={$mode} 는 유효해야 합니다"
            );
        }
    }

    public function test_install_request_rejects_invalid_vendor_mode(): void
    {
        $request = new InstallModuleRequest;
        $request->replace([
            'module_name' => 'test-module',
            'vendor_mode' => 'invalid-mode',
        ]);
        $request->setContainer($this->app);

        $validator = \Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vendor_mode', $validator->errors()->toArray());
    }

    public function test_install_request_allows_nullable_vendor_mode(): void
    {
        // vendor_mode 를 생략해도 검증 통과 — 기본값 auto 사용
        $request = new InstallModuleRequest;
        $request->replace(['module_name' => 'test-module']);
        $request->setContainer($this->app);

        $validator = \Validator::make($request->all(), $request->rules());

        $failed = $validator->failed();
        $this->assertArrayNotHasKey('vendor_mode', $failed);
    }

    public function test_module_service_install_signature_accepts_vendor_mode(): void
    {
        $reflection = new \ReflectionClass(\App\Services\ModuleService::class);
        $method = $reflection->getMethod('installModule');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');
        $this->assertNotNull($vendorParam, 'ModuleService::installModule 에 vendorMode 파라미터가 있어야 합니다');
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::class,
            $vendorParam->getType()?->getName()
        );
    }

    public function test_module_service_update_signature_accepts_vendor_mode(): void
    {
        $reflection = new \ReflectionClass(\App\Services\ModuleService::class);
        $method = $reflection->getMethod('updateModule');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');
        $this->assertNotNull($vendorParam, 'ModuleService::updateModule 에 vendorMode 파라미터가 있어야 합니다');
    }

    public function test_vendor_mode_from_request_input_parses_correctly(): void
    {
        // Controller 측에서 VendorMode::fromStringOrAuto() 사용 — 그 동작 검증
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::Auto,
            \App\Extension\Vendor\VendorMode::fromStringOrAuto(null)
        );
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::Auto,
            \App\Extension\Vendor\VendorMode::fromStringOrAuto('')
        );
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::Composer,
            \App\Extension\Vendor\VendorMode::fromStringOrAuto('composer')
        );
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::Bundled,
            \App\Extension\Vendor\VendorMode::fromStringOrAuto('bundled')
        );
        // 유효하지 않은 값은 Auto 로 폴백
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::Auto,
            \App\Extension\Vendor\VendorMode::fromStringOrAuto('invalid')
        );
    }
}
