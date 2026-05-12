<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LanguagePackService 단위 테스트.
 *
 * 슬롯 스위칭, 자동 승격, 비활성화 후 후속 활성 등 활성/비활성 사이클의 핵심 동작을 검증합니다.
 */
class LanguagePackServiceTest extends TestCase
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
            'identifier' => sprintf('%s-core-%s', $vendor, $locale),
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

    public function test_activate_promotes_inactive_to_active(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Inactive->value);

        $result = $this->service->activate($pack);

        $this->assertSame(LanguagePackStatus::Active->value, $result->status);
        $this->assertNotNull($result->activated_at);
    }

    public function test_activate_demotes_existing_active_in_same_slot(): void
    {
        $existing = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $candidate = $this->makePack('acme', 'ja', LanguagePackStatus::Installed->value);

        // 슬롯 충돌은 force=true 로 명시적 교체 의사 확인 후 demotion 수행 (기본은 SlotConflictException).
        $this->service->activate($candidate, force: true);

        $existing->refresh();
        $this->assertSame(LanguagePackStatus::Inactive->value, $existing->status);
        $this->assertSame(LanguagePackStatus::Active->value, $candidate->fresh()->status);
    }

    public function test_activate_is_idempotent_for_already_active_pack(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $originalActivatedAt = $pack->activated_at;

        $result = $this->service->activate($pack);

        $this->assertSame(LanguagePackStatus::Active->value, $result->status);
        $this->assertEquals($originalActivatedAt, $result->activated_at);
    }

    public function test_deactivate_promotes_slot_successor(): void
    {
        $active = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $other = $this->makePack('acme', 'ja', LanguagePackStatus::Inactive->value);

        $this->service->deactivate($active);

        $this->assertSame(LanguagePackStatus::Inactive->value, $active->fresh()->status);
        $this->assertSame(LanguagePackStatus::Active->value, $other->fresh()->status);
    }

    public function test_deactivate_protected_pack_throws(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'g7-core-ko',
            'vendor' => 'g7',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ko',
            'locale_name' => 'Korean',
            'locale_native_name' => '한국어',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => true,
            'manifest' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->deactivate($pack);
    }

    public function test_uninstall_removes_db_record(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Inactive->value);
        $id = $pack->id;

        $this->service->uninstall($pack, false);

        $this->assertNull(LanguagePack::query()->find($id));
    }

    public function test_uninstall_protected_pack_throws(): void
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'g7-core-en',
            'vendor' => 'g7',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'en',
            'locale_name' => 'English',
            'locale_native_name' => 'English',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => true,
            'manifest' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->uninstall($pack, false);
    }

    public function test_list_returns_paginator(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $this->makePack('acme', 'ja', LanguagePackStatus::Inactive->value);
        $this->makePack('sirsoft', 'fr', LanguagePackStatus::Active->value);

        // installed 상태로만 필터해 미설치 번들 가상 레코드를 제외하고 DB 만 검증.
        $paginator = $this->service->list(['locale' => 'ja', 'status' => LanguagePackStatus::Installed->value], 20);
        $this->assertSame(0, $paginator->total(), 'Installed 상태 일치 0건');

        // 활성/비활성 + locale=ja 로 DB 의 2건이 정확히 잡히는지(번들 가상은 status filter 가 차단).
        $activeOnly = $this->service->list(['locale' => 'ja', 'status' => LanguagePackStatus::Active->value], 20);
        $this->assertSame(1, $activeOnly->total());

        $inactiveOnly = $this->service->list(['locale' => 'ja', 'status' => LanguagePackStatus::Inactive->value], 20);
        $this->assertSame(1, $inactiveOnly->total());
    }

    public function test_find_returns_pack_or_null(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);

        $this->assertNotNull($this->service->find($pack->id));
        $this->assertNull($this->service->find(999999));
    }

    public function test_resolve_install_blocked_reason_returns_null_for_core(): void
    {
        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Core->value,
            'locale' => 'ja',
        ]);

        $this->assertNull($reason);
    }

    public function test_resolve_install_blocked_reason_core_locale_missing(): void
    {
        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'gnuboard7-hello_module',
            'locale' => 'ja',
        ]);

        $this->assertSame('core_locale_missing', $reason);
    }

    public function test_resolve_install_blocked_reason_target_not_installed(): void
    {
        // 코어 ja 활성화 → 코어 의존성 통과시키고 target 검증으로 진입.
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $this->registry->invalidate();

        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'no-such-module',
            'locale' => 'ja',
        ]);

        $this->assertSame('target_not_installed', $reason);
    }

    public function test_resolve_install_blocked_reason_target_inactive(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $this->registry->invalidate();

        \Illuminate\Support\Facades\DB::table('modules')->insert([
            'identifier' => 'gnuboard7-hello_module',
            'name' => 'Hello',
            'vendor' => 'gnuboard7',
            'version' => '1.0.0',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'gnuboard7-hello_module',
            'locale' => 'ja',
        ]);

        $this->assertSame('target_inactive', $reason);
    }

    public function test_resolve_install_blocked_reason_target_version_too_old(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $this->registry->invalidate();

        \Illuminate\Support\Facades\DB::table('modules')->insert([
            'identifier' => 'gnuboard7-hello_module',
            'name' => 'Hello',
            'vendor' => 'gnuboard7',
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'gnuboard7-hello_module',
            'locale' => 'ja',
            'requires' => ['target_version' => '^2.0.0'],
        ]);

        $this->assertSame('target_version_too_old', $reason);
    }

    public function test_resolve_install_blocked_reason_returns_null_when_all_satisfied(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $this->registry->invalidate();

        \Illuminate\Support\Facades\DB::table('modules')->insert([
            'identifier' => 'gnuboard7-hello_module',
            'name' => 'Hello',
            'vendor' => 'gnuboard7',
            'version' => '1.5.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reason = $this->service->resolveInstallBlockedReason([
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'gnuboard7-hello_module',
            'locale' => 'ja',
            'requires' => ['target_version' => '^1.0.0'],
        ]);

        $this->assertNull($reason);
    }

    public function test_check_updates_skips_non_github_packs(): void
    {
        $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);

        $result = $this->service->checkUpdates();

        $this->assertSame(['checked' => 0, 'updates' => 0, 'details' => []], $result);
    }

    public function test_perform_update_throws_when_no_github_source(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);

        $this->expectException(\RuntimeException::class);
        $this->service->performUpdate($pack);
    }

    /**
     * 모듈/플러그인/템플릿과 일관되게, 매니페스트 `github_url` 이 SSoT 로 사용되는지 검증.
     *
     * source_type=bundled 로 설치된 팩이라도 매니페스트에 github_url 이 명시되어 있으면
     * checkUpdates() 의 점검 대상이 되어야 함 (count > 0). 실제 GitHub 호출은 발생하지만
     * 네트워크 결과와 무관하게 `checked` 가 증가하면 SSoT 해석이 정상 동작한 것.
     *
     * @return void
     */
    public function test_check_updates_includes_packs_with_manifest_github_url(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $pack->forceFill([
            'source_type' => 'bundled',
            'source_url' => $pack->identifier,
            'manifest' => [
                'identifier' => $pack->identifier,
                'namespace' => 'g7',
                'vendor' => 'sirsoft',
                'github_url' => 'https://github.com/gnuboard/'.$pack->identifier,
            ],
        ])->save();

        $result = $this->service->checkUpdates();

        $this->assertSame(1, $result['checked'], '매니페스트 github_url 이 있으면 점검 대상에 포함되어야 한다');
        $this->assertCount(1, $result['details']);
        $this->assertSame($pack->identifier, $result['details'][0]['identifier']);
    }

    /**
     * 매니페스트 github_url 이 비어있고 source_url 도 GitHub 가 아니면서
     * 번들도 없으면 checkUpdates 점검 대상에서 제외되는지 확인 (회귀 가드).
     *
     * @return void
     */
    public function test_check_updates_excludes_packs_with_blank_manifest_github_url(): void
    {
        $pack = $this->makePack('sirsoft', 'ja', LanguagePackStatus::Active->value);
        $pack->forceFill([
            'manifest' => [
                'identifier' => $pack->identifier,
                'github_url' => '',
            ],
        ])->save();

        $result = $this->service->checkUpdates();

        $this->assertSame(0, $result['checked']);
    }

    public function test_refresh_cache_returns_status_map(): void
    {
        $result = $this->service->refreshCache();

        $this->assertArrayHasKey('registry', $result);
        $this->assertArrayHasKey('translator', $result);
        $this->assertArrayHasKey('template', $result);
        $this->assertTrue($result['registry']);
    }

    /**
     * 공식 일본어 번들 언어팩(g7-core-ja) 을 bundled 소스로 설치 → 자동 활성 검증.
     *
     * 본 테스트는 빌드 산출 디렉토리(`lang-packs/_bundled/g7-core-ja/`)가 존재할 때만 의미가 있으며,
     * 빌드 미완료 시 자동 스킵하여 CI 가 깨지지 않도록 한다.
     *
     * @return void
     */
    public function test_install_g7_core_ja_from_bundled_promotes_to_active(): void
    {
        $bundledPath = base_path('lang-packs/_bundled/g7-core-ja');
        if (! is_dir($bundledPath) || ! is_file($bundledPath.'/language-pack.json')) {
            $this->markTestSkipped('g7-core-ja 번들이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }

        // 번들 manifest 의 현재 버전을 기준으로 설치 결과 검증
        // (코어 lang 변경 시 g7-core-ja 버전이 patch bump 되므로 하드코딩 회피)
        $bundledManifest = json_decode(file_get_contents($bundledPath.'/language-pack.json'), true);
        $bundledVersion = $bundledManifest['version'] ?? null;
        $this->assertNotNull($bundledVersion, '번들 manifest 에 version 누락');

        $pack = $this->service->installFromBundled('g7-core-ja', autoActivate: true);

        $this->assertSame('g7-core-ja', $pack->identifier);
        $this->assertSame(LanguagePackScope::Core->value, $pack->scope);
        $this->assertNull($pack->target_identifier);
        $this->assertSame('ja', $pack->locale);
        $this->assertSame($bundledVersion, $pack->version);
        $this->assertSame(LanguagePackStatus::Active->value, $pack->status);
        $this->assertSame('bundled', $pack->source_type);
        // is_protected 는 모든 install 흐름에서 항상 false (보호 행은 코어/번들 확장의 lang/ 디렉토리를 가상 행으로 합성하는 경로에서만 true)
        $this->assertFalse($pack->is_protected);

        // 산출 자산 검증 — backend/ja/*.php 가 실제로 복사되었는지
        $installedDir = base_path('lang-packs/g7-core-ja');
        $this->assertFileExists($installedDir.'/backend/ja/common.php');
        $this->assertFileExists($installedDir.'/seed/permissions.json');

        // cleanup — 활성 디렉토리 제거 (RefreshDatabase 와 별개)
        \Illuminate\Support\Facades\File::deleteDirectory($installedDir);
    }

    /**
     * 회귀 가드 — 재설치(installFromBundled 두 번째 호출) 시 자기 자신을
     * 슬롯 충돌로 오인하여 status 가 active → installed 로 강등되는 회귀 차단.
     *
     * 인스톨러가 retry 되거나 사용자가 동일 identifier 를 재설치할 때, 이전엔
     * `findActiveForSlot` 가 자기 자신(이미 active 인 row)을 반환 → `shouldActivate=false` →
     * 새 status='installed' 로 떨어져 의존하는 확장 언어팩이 'core_locale_missing' 으로
     * 차단되는 인스톨러 hang/실패가 발생했음.
     *
     * fix: `finalizeInstall` 가 `findActiveForSlot` 호출 시 `$existing?->id` 를 excludeId 로 전달.
     */
    public function test_reinstall_active_pack_keeps_active_status(): void
    {
        $bundledPath = base_path('lang-packs/_bundled/g7-core-ja');
        if (! is_dir($bundledPath) || ! is_file($bundledPath.'/language-pack.json')) {
            $this->markTestSkipped('g7-core-ja 번들이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }

        // 첫 install — autoActivate=true → status=active
        $first = $this->service->installFromBundled('g7-core-ja', autoActivate: true);
        $this->assertSame(LanguagePackStatus::Active->value, $first->status, '첫 install 은 자동 활성화되어야 함');

        // 재설치 — 같은 identifier 가 update path 로 진입.
        // self-conflict fix 가 없다면 status 가 'installed' 로 강등됨 (회귀).
        // fix 후엔 자기 자신 제외하고 슬롯 검사하므로 active 유지.
        $second = $this->service->installFromBundled('g7-core-ja', autoActivate: true);
        $this->assertSame($first->id, $second->id, '같은 row 가 update 되어야 함');
        $this->assertSame(LanguagePackStatus::Active->value, $second->status,
            '재설치 시 자기 자신을 슬롯 충돌로 오인하여 active → installed 로 강등되면 안 됨 (회귀)');

        // cleanup
        \Illuminate\Support\Facades\File::deleteDirectory(base_path('lang-packs/g7-core-ja'));
    }

    public function test_install_blocks_downgrade_without_force(): void
    {
        $identifier = 'dgblock-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, [
            'identifier' => $identifier,
            'namespace' => 'dgblock',
            'vendor' => 'dgblock',
            'name' => ['ko' => 'Downgrade Test', 'en' => 'Downgrade Test', 'ja' => 'Downgrade Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0-beta.1',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
        ]);

        try {
            LanguagePack::query()->create([
                'identifier' => $identifier,
                'vendor' => 'dgblock',
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => 'ja',
                'locale_name' => 'JA',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0-beta.2',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'bundled',
            ]);

            // force=false → 다운그레이드 차단 예외
            $this->expectException(\App\Exceptions\LanguagePackOperationException::class);
            $this->service->installFromBundled($identifier);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($bundledDir);
            \Illuminate\Support\Facades\File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    public function test_install_allows_downgrade_with_force(): void
    {
        $identifier = 'dgforce-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, [
            'identifier' => $identifier,
            'namespace' => 'dgforce',
            'vendor' => 'dgforce',
            'name' => ['ko' => 'Downgrade Force Test', 'en' => 'Downgrade Force Test', 'ja' => 'Downgrade Force Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0-beta.1',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
        ]);

        try {
            LanguagePack::query()->create([
                'identifier' => $identifier,
                'vendor' => 'dgforce',
                'scope' => LanguagePackScope::Core->value,
                'target_identifier' => null,
                'locale' => 'ja',
                'locale_name' => 'JA',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0-beta.2',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'bundled',
            ]);

            // force=true → 다운그레이드 허용
            $pack = $this->service->installFromBundled($identifier, force: true);
            $this->assertSame('1.0.0-beta.1', $pack->version);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($bundledDir);
            \Illuminate\Support\Facades\File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * 임시 번들 디렉토리를 생성합니다 (테스트 격리용).
     *
     * @param  string  $identifier  번들 디렉토리 식별자 (lang-packs/_bundled/{identifier})
     * @param  array<string, mixed>  $manifest  manifest JSON 으로 직렬화할 데이터
     * @return string 생성된 디렉토리 절대 경로
     */
    private function createBundledFixture(string $identifier, array $manifest): string
    {
        $path = base_path('lang-packs/_bundled/'.$identifier);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($path);
        \Illuminate\Support\Facades\File::put(
            $path.DIRECTORY_SEPARATOR.'language-pack.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $path;
    }

    public function test_get_uninstalled_bundled_packs_returns_virtual_records(): void
    {
        $identifier = 'test-virtual-acme-zz-'.uniqid();
        $manifest = [
            'identifier' => $identifier,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'zz',
            'locale_name' => 'Zzland',
            'locale_native_name' => 'Zzland Native',
            'text_direction' => 'ltr',
            'version' => '0.1.0',
        ];
        $path = $this->createBundledFixture($identifier, $manifest);

        try {
            $packs = $this->service->getUninstalledBundledPacks();
            $match = $packs->firstWhere('identifier', $identifier);

            $this->assertNotNull($match, '미설치 번들이 가상 레코드로 반환되어야 함');
            $this->assertSame(LanguagePackStatus::Uninstalled->value, $match->status);
            $this->assertSame('bundled', $match->source_type);
            $this->assertSame($identifier, $match->getAttribute('bundled_identifier'));
            $this->assertFalse($match->exists, '가상 레코드는 exists=false 여야 함');
            $this->assertNull($match->id, '가상 레코드는 id 가 null 이어야 함');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($path);
        }
    }

    public function test_get_uninstalled_bundled_packs_excludes_already_installed_slot(): void
    {
        $identifier = 'test-installed-acme-zz-'.uniqid();
        $manifest = [
            'identifier' => $identifier,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'zz',
            'locale_name' => 'Zzland',
            'locale_native_name' => 'Zzland Native',
            'text_direction' => 'ltr',
            'version' => '0.1.0',
        ];
        $path = $this->createBundledFixture($identifier, $manifest);
        // 동일 슬롯(scope=core, target_identifier=null, locale=zz) 의 DB 레코드를 만든다 → 가상 레코드 미포함되어야 함.
        LanguagePack::query()->create([
            'identifier' => 'other-vendor-core-zz',
            'vendor' => 'other',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'zz',
            'locale_name' => 'Zzland',
            'locale_native_name' => 'Zzland',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
        ]);

        try {
            $packs = $this->service->getUninstalledBundledPacks();
            $match = $packs->firstWhere('identifier', $identifier);
            $this->assertNull($match, '동일 슬롯에 DB 레코드가 있으면 가상 레코드가 반환되지 않아야 함');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($path);
        }
    }

    public function test_list_merges_db_records_and_uninstalled_bundled(): void
    {
        $this->makePack('sirsoft', 'fr', LanguagePackStatus::Active->value);

        $identifier = 'test-merge-acme-yy-'.uniqid();
        $manifest = [
            'identifier' => $identifier,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'yy',
            'locale_name' => 'Yyland',
            'locale_native_name' => 'Yyland',
            'text_direction' => 'ltr',
            'version' => '0.1.0',
        ];
        $path = $this->createBundledFixture($identifier, $manifest);

        try {
            $paginator = $this->service->list([], 100);

            $items = collect($paginator->items());
            $this->assertGreaterThanOrEqual(
                2,
                $paginator->total(),
                'DB 레코드(fr) + 가상 번들(yy) 이 합쳐져야 함'
            );

            $virtual = $items->firstWhere('identifier', $identifier);
            $this->assertNotNull($virtual);
            $this->assertSame(LanguagePackStatus::Uninstalled->value, $virtual->status);
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($path);
        }
    }

    public function test_list_filters_uninstalled_status_excludes_db_records(): void
    {
        $this->makePack('sirsoft', 'fr', LanguagePackStatus::Active->value);

        $identifier = 'test-filter-acme-xx-'.uniqid();
        $manifest = [
            'identifier' => $identifier,
            'vendor' => 'acme',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'xx',
            'locale_name' => 'Xxland',
            'locale_native_name' => 'Xxland',
            'text_direction' => 'ltr',
            'version' => '0.1.0',
        ];
        $path = $this->createBundledFixture($identifier, $manifest);

        try {
            $paginator = $this->service->list(['status' => LanguagePackStatus::Uninstalled->value], 100);
            $items = collect($paginator->items());

            $this->assertNotNull($items->firstWhere('identifier', $identifier));
            $this->assertNull($items->firstWhere('status', LanguagePackStatus::Active->value));
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($path);
        }
    }
}
