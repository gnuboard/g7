<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Services\ModuleSettingsService;
use Mockery;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadReviewImageRequest;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁
 */
abstract class UploadReviewImageModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 리뷰 이미지 업로드 요청 검증 테스트 (A6)
 *
 * max_image_size_mb 설정이 업로드 최대 용량(max:KB) rule 에 동적 반영되는지 검증.
 */
class UploadReviewImageRequestTest extends ModuleTestCase
{
    private array $moduleSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleSettings = [];
        $this->mockModuleSetting();
    }

    private function mockModuleSetting(): void
    {
        $mockModule = $this->createMock(UploadReviewImageModuleStub::class);
        $mockModule->method('getSetting')
            ->willReturnCallback(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });

        $mockModuleManager = $this->createMock(ModuleManagerInterface::class);
        $mockModuleManager->method('getModule')
            ->with('sirsoft-ecommerce')
            ->willReturn($mockModule);

        $this->app->instance(ModuleManagerInterface::class, $mockModuleManager);
        $this->app->forgetInstance(ModuleSettingsService::class);

        $mockEcommerceSettings = Mockery::mock(EcommerceSettingsService::class)->makePartial();
        $mockEcommerceSettings->shouldReceive('getSetting')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });
        $this->app->instance(EcommerceSettingsService::class, $mockEcommerceSettings);
    }

    public function test_max_rule_uses_configured_size(): void
    {
        // max_image_size_mb = 5 → max:5120 (KB)
        $this->moduleSettings['review_settings.max_image_size_mb'] = 5;

        $rules = (new UploadReviewImageRequest)->rules();

        $this->assertContains('max:5120', $rules['image']);
    }

    public function test_max_rule_falls_back_to_default_when_unset(): void
    {
        // 설정 미존재 → 기본 10MB → max:10240
        $rules = (new UploadReviewImageRequest)->rules();

        $this->assertContains('max:10240', $rules['image']);
    }

    public function test_max_message_uses_configured_size(): void
    {
        $this->moduleSettings['review_settings.max_image_size_mb'] = 3;

        $messages = (new UploadReviewImageRequest)->messages();

        // :maxMB 치환 — 키 원문/하드코딩 10MB 아님
        $this->assertStringContainsString('3', $messages['image.max']);
        $this->assertStringNotContainsString('review_image.image_max', $messages['image.max']);
    }
}
