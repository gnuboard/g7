<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * 언어팩 운영 풀 패리티 (확장 관리 동등 수준) 회귀 테스트.
 *
 * 검증 범위:
 *  - 동시성 가드: status=Updating 인 팩에 대해 activate/deactivate/uninstall/performUpdate 차단
 *  - 라이프사이클 훅: installed / updated / uninstalled / activated / deactivated
 *  - 번들 소스 설치: lang-packs/_bundled/{identifier} 디렉토리에서 (재)설치
 *
 * 모듈/플러그인/템플릿 관리 플로우와 동일한 안전 장치/확장성을 보장합니다.
 */
class LanguagePackServiceParityTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackService $service;

    private LanguagePackRegistry $registry;

    /**
     * 테스트 픽스처 초기화.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(LanguagePackService::class);
        $this->registry = $this->app->make(LanguagePackRegistry::class);
    }

    /**
     * status 가 Updating 인 팩의 activate 진입을 차단합니다.
     *
     * @return void
     */
    public function test_activate_blocks_when_status_is_updating(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Updating->value);

        $this->expectException(RuntimeException::class);
        $this->service->activate($pack);
    }

    /**
     * status 가 Updating 인 팩의 deactivate 진입을 차단합니다.
     *
     * @return void
     */
    public function test_deactivate_blocks_when_status_is_updating(): void
    {
        // deactivate 는 isActive() 검사가 먼저이므로 Active 로 만든 후 Updating 으로 직접 변경
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $pack->status = LanguagePackStatus::Updating->value;
        $pack->save();

        $this->expectException(RuntimeException::class);
        $this->service->deactivate($pack);
    }

    /**
     * status 가 Updating 인 팩의 uninstall 진입을 차단합니다.
     *
     * @return void
     */
    public function test_uninstall_blocks_when_status_is_updating(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Updating->value);

        $this->expectException(RuntimeException::class);
        $this->service->uninstall($pack);
    }

    /**
     * activate 시 `core.language_packs.activated` 훅을 발행합니다.
     *
     * @return void
     */
    public function test_activate_dispatches_activated_hook(): void
    {
        $captured = [];
        HookManager::addAction('core.language_packs.activated', function ($pack) use (&$captured) {
            $captured[] = $pack->identifier;
        });

        $pack = $this->makePack('sirsoft', 'ko', LanguagePackStatus::Inactive->value);
        $this->service->activate($pack);

        $this->assertContains($pack->identifier, $captured);
    }

    /**
     * deactivate 시 `core.language_packs.deactivated` 훅을 발행합니다.
     *
     * @return void
     */
    public function test_deactivate_dispatches_deactivated_hook(): void
    {
        $captured = [];
        HookManager::addAction('core.language_packs.deactivated', function ($pack) use (&$captured) {
            $captured[] = $pack->identifier;
        });

        $pack = $this->makePack('sirsoft', 'ko', LanguagePackStatus::Active->value);
        $this->service->deactivate($pack);

        $this->assertContains($pack->identifier, $captured);
    }

    /**
     * uninstall 시 `core.language_packs.uninstalled` 훅을 발행합니다.
     *
     * @return void
     */
    public function test_uninstall_dispatches_uninstalled_hook(): void
    {
        $captured = [];
        HookManager::addAction('core.language_packs.uninstalled', function ($pack) use (&$captured) {
            $captured[] = $pack->identifier;
        });

        $pack = $this->makePack('sirsoft', 'ko', LanguagePackStatus::Inactive->value);
        $this->service->uninstall($pack);

        $this->assertContains($pack->identifier, $captured);
    }

    /**
     * `lang-packs/_bundled/{identifier}` 가 없으면 RuntimeException 으로 차단합니다.
     *
     * @return void
     */
    public function test_install_from_bundled_throws_when_directory_missing(): void
    {
        $missing = 'nonexistent-bundle-'.uniqid();

        $this->expectException(RuntimeException::class);
        $this->service->installFromBundled($missing);
    }

    /**
     * `lang-packs/_bundled/{identifier}` 디렉토리에서 설치하면 활성 디렉토리로 복사 + DB 등록.
     *
     * @return void
     */
    public function test_install_from_bundled_copies_to_active_and_registers(): void
    {
        // identifier 네이밍: {vendor}-{scope}-{locale} for core (LanguagePackManifestValidator 규칙)
        $vendor = 'parity'.substr((string) hexdec(uniqid()), -4);
        $identifier = $vendor.'-core-ja';
        $bundlePath = base_path('lang-packs/_bundled/'.$identifier);

        File::ensureDirectoryExists($bundlePath);
        File::put($bundlePath.'/language-pack.json', json_encode([
            'identifier' => $identifier,
            'namespace' => $vendor,
            'vendor' => $vendor,
            'name' => ['ko' => 'Parity Bundled Pack', 'en' => 'Parity Bundled Pack'],
            'scope' => 'core',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'version' => '1.0.0',
        ]));
        File::ensureDirectoryExists($bundlePath.'/lang/ja');
        File::put($bundlePath.'/lang/ja/messages.json', json_encode(['hello' => 'こんにちは']));

        try {
            $pack = $this->service->installFromBundled($identifier, false);

            $this->assertSame($identifier, $pack->identifier);
            $this->assertSame('bundled', $pack->source_type);
            $this->assertTrue(File::isDirectory(base_path('lang-packs/'.$identifier)));
        } finally {
            File::deleteDirectory($bundlePath);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * assertNotInProgress 헬퍼는 Updating 외 상태에 대해서는 통과합니다.
     *
     * @return void
     */
    public function test_assert_not_in_progress_passes_for_non_updating_states(): void
    {
        foreach ([
            LanguagePackStatus::Active,
            LanguagePackStatus::Inactive,
            LanguagePackStatus::Installed,
            LanguagePackStatus::Error,
        ] as $status) {
            $pack = $this->makePack('sirsoft', 'ko-'.$status->value, $status->value);
            $this->service->assertNotInProgress($pack); // throws if blocked
            $pack->delete();
        }

        $this->assertTrue(true);
    }

    /**
     * 테스트 언어팩 1건을 DB 에 직접 생성합니다.
     *
     * @param  string  $vendor  벤더
     * @param  string  $locale  로케일
     * @param  string  $status  상태
     * @return LanguagePack 생성된 언어팩
     */
    private function makePack(string $vendor, string $locale, string $status = LanguagePackStatus::Installed->value): LanguagePack
    {
        return LanguagePack::query()->create([
            'identifier' => sprintf('%s-core-%s-%s', $vendor, $locale, uniqid()),
            'vendor' => $vendor,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => $locale,
            'locale_name' => strtoupper($locale),
            'locale_native_name' => $locale,
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => $status,
            'is_protected' => false,
            'manifest' => [],
        ]);
    }
}
