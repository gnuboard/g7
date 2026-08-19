<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\AbstractPlugin;
use App\Extension\PluginManager;
use App\Http\Requests\Admin\UpdatePluginSettingsRequest;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 플러그인 공개 자산 디스크 저장 검증 테스트 (공개#100)
 *
 * 코어가 카탈로그를 부착하는 게이트(스키마에 public_asset_disk 선언)와 동일한
 * 조건에서 값 검증도 걸린다. 선택지를 내려주는 표면과 값을 받는 표면의 강도가
 * 갈리면, 오타 디스크명이 200 으로 저장된 뒤 런타임 폴백만 남아 운영자에게는
 * "저장은 됐는데 CDN 이 안 붙는" 무증상 상태로만 보인다.
 *
 * 스키마 타입만으로는 검증할 수 없다 — 선택지가 코어 3종 + 플러그인 훅 등록
 * 디스크라 런타임에만 확정되기 때문이다.
 *
 * @effects invalid_disk_rejected_with_422
 */
class PluginSettingsPublicAssetDiskValidationTest extends PluginTestCase
{
    /**
     * 플러그인 설정 저장 요청을 해석합니다.
     *
     * @param  string  $disk  public_asset_disk 값
     */
    private function resolve(string $disk): UpdatePluginSettingsRequest
    {
        $request = UpdatePluginSettingsRequest::create('/', 'PUT', [
            'public_asset_disk' => $disk,
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $key, $default = null): string
            {
                return 'sirsoft-ckeditor5';
            }
        });
        $request->validateResolved();

        return $request;
    }

    #[Test]
    public function core_catalog_disk_is_accepted(): void
    {
        $request = $this->resolve('public');

        $this->assertSame('public', $request->validated()['public_asset_disk']);
    }

    #[Test]
    public function empty_value_is_accepted_as_follow_core(): void
    {
        $request = $this->resolve('');

        $this->assertSame('', $request->validated()['public_asset_disk'] ?? '');
    }

    #[Test]
    public function unknown_disk_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->resolve('nonexistent_disk');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey(
                'public_asset_disk',
                $e->validator->errors()->toArray(),
                '카탈로그에 없는 디스크는 public_asset_disk 오류를 내야 한다'
            );

            throw $e;
        }
    }

    #[Test]
    public function validation_gate_is_closed_when_schema_lacks_the_key(): void
    {
        // 이 키를 선언하지 않은 플러그인에는 검증도 붙지 않아야 한다 —
        // 부착 게이트(PluginSettingsController)와 검증 게이트가 같은 조건이어야 한다.
        $plugin = $this->createMock(AbstractPlugin::class);
        $plugin->method('getSettingsSchema')->willReturn([
            'some_other_key' => ['type' => 'string'],
        ]);

        $manager = $this->createMock(PluginManager::class);
        $manager->method('getPlugin')->willReturn($plugin);
        $this->app->instance(PluginManager::class, $manager);

        $request = UpdatePluginSettingsRequest::create('/', 'PUT', [
            'some_other_key' => 'value',
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $key, $default = null): string
            {
                return 'other-plugin';
            }
        });

        $this->assertArrayNotHasKey('public_asset_disk', $request->rules());
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(PluginManager::class);

        parent::tearDown();
    }
}
