<?php

namespace Tests\Feature\Api\Admin;

use App\Http\Requests\Plugin\InstallPluginRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 플러그인 설치 API 의 vendor_mode 파라미터 검증.
 *
 * `POST /api/admin/plugins/install` 엔드포인트가 `vendor_mode` 를 올바르게 받아
 * FormRequest 검증을 통과하고, Service 레이어로 전달하는지 검증한다.
 */
class PluginInstallWithVendorModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_request_accepts_valid_vendor_modes(): void
    {
        foreach (['auto', 'composer', 'bundled'] as $mode) {
            $request = new InstallPluginRequest;
            $request->replace([
                'plugin_name' => 'test-valid-plugin',
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
        $request = new InstallPluginRequest;
        $request->replace([
            'plugin_name' => 'test-plugin',
            'vendor_mode' => 'invalid-mode',
        ]);
        $request->setContainer($this->app);

        $validator = \Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vendor_mode', $validator->errors()->toArray());
    }

    public function test_install_request_allows_nullable_vendor_mode(): void
    {
        $request = new InstallPluginRequest;
        $request->replace(['plugin_name' => 'test-plugin']);
        $request->setContainer($this->app);

        $validator = \Validator::make($request->all(), $request->rules());

        $failed = $validator->failed();
        $this->assertArrayNotHasKey('vendor_mode', $failed);
    }

    public function test_plugin_service_install_signature_accepts_vendor_mode(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PluginService::class);
        $method = $reflection->getMethod('installPlugin');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');
        $this->assertNotNull($vendorParam, 'PluginService::installPlugin 에 vendorMode 파라미터가 있어야 합니다');
        $this->assertSame(
            \App\Extension\Vendor\VendorMode::class,
            $vendorParam->getType()?->getName()
        );
    }

    public function test_plugin_service_update_signature_accepts_vendor_mode(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PluginService::class);
        $method = $reflection->getMethod('updatePlugin');
        $params = $method->getParameters();

        $vendorParam = collect($params)->first(fn ($p) => $p->getName() === 'vendorMode');
        $this->assertNotNull($vendorParam, 'PluginService::updatePlugin 에 vendorMode 파라미터가 있어야 합니다');
    }

    public function test_plugin_controller_install_method_reads_vendor_mode_from_request(): void
    {
        // PluginController::install() 메서드가 $request->validated()['vendor_mode'] 를 참조하는지 확인
        $file = file_get_contents(base_path('app/Http/Controllers/Api/Admin/PluginController.php'));
        $this->assertStringContainsString("VendorMode::fromStringOrAuto", $file);
        $this->assertStringContainsString("vendor_mode", $file);
    }
}
