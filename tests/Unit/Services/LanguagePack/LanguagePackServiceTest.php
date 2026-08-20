<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Exceptions\LanguagePackOperationException;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

        DB::table('modules')->insert([
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

        DB::table('modules')->insert([
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

        DB::table('modules')->insert([
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
        File::deleteDirectory($installedDir);
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
        File::deleteDirectory(base_path('lang-packs/g7-core-ja'));
    }

    /**
     * 자동 활성화는 `activated` 훅을 정확히 한 번만 발화한다.
     *
     * 이전 구현은 설치 트랜잭션에서 status 를 직접 active 로 쓴 뒤 훅을 발화했고,
     * 활성화 경로(activate)도 같은 훅을 발화해 두 경로가 공존했다. 활성화를 activate()
     * 한 곳으로 모았으므로 발화도 한 번이어야 한다 — 두 번 발화되면 훅 구독자가
     * 캐시 재생성·알림 등을 중복 수행한다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects activated_hook_fires_exactly_once_on_auto_activate
     */
    public function test_activated_hook_fires_exactly_once_on_auto_activate(): void
    {
        $bundledPath = base_path('lang-packs/_bundled/g7-core-ja');
        if (! is_dir($bundledPath) || ! is_file($bundledPath.'/language-pack.json')) {
            $this->markTestSkipped('g7-core-ja 번들이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }

        $fired = 0;
        HookManager::addAction('core.language_packs.activated', function () use (&$fired) {
            $fired++;
        });

        try {
            $pack = $this->service->installFromBundled('g7-core-ja', autoActivate: true);

            $this->assertSame(LanguagePackStatus::Active->value, $pack->status);
            $this->assertSame(1, $fired, 'activated 훅이 정확히 1회 발화되지 않음');
        } finally {
            HookManager::clearAction('core.language_packs.activated');
            File::deleteDirectory(base_path('lang-packs/g7-core-ja'));
        }
    }

    /**
     * 같은 슬롯에 이미 다른 활성 팩이 있으면 자동 활성화를 건너뛴다 (기존 동작 유지).
     *
     * 활성화를 activate() 경유로 바꾸면서 슬롯이 점유된 경우 예외가 새로 터지지 않아야 한다 —
     * 설치 자체는 성공하고 상태만 installed 로 남는 것이 기존 동작이다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects auto_activate_skipped_when_slot_occupied
     */
    public function test_auto_activate_skipped_when_slot_occupied(): void
    {
        $bundledPath = base_path('lang-packs/_bundled/g7-core-ja');
        if (! is_dir($bundledPath) || ! is_file($bundledPath.'/language-pack.json')) {
            $this->markTestSkipped('g7-core-ja 번들이 아직 빌드되지 않음 — build-language-pack-ja.cjs 실행 후 재시도');
        }

        // 같은 슬롯(core / ja)을 다른 팩이 이미 점유한 상태
        $this->makePack('other', 'ja', LanguagePackStatus::Active->value);

        try {
            $pack = $this->service->installFromBundled('g7-core-ja', autoActivate: true);

            $this->assertSame(
                LanguagePackStatus::Installed->value,
                $pack->status,
                '슬롯이 점유되어 있으면 설치는 성공하되 활성화는 건너뛰어야 한다'
            );
        } finally {
            File::deleteDirectory(base_path('lang-packs/g7-core-ja'));
        }
    }

    /**
     * 자동 활성화는 트랜잭션의 직접 기록이 아니라 activate() 를 경유하며,
     * 그 경로에는 의존성 검사가 실제로 걸린다.
     *
     * 두 가지를 각각 단언한다.
     *  (1) 경로 — `installed` 훅이 발화하는 시점(트랜잭션 직후)의 DB 상태가 `installed` 여야
     *      한다. 예전처럼 트랜잭션이 status 를 active 로 직접 썼다면 이 시점에 이미 active 다.
     *      그 뒤 `activated` 훅이 발화하고 최종 상태가 active 가 되는 순서까지 확인하면
     *      활성 승격이 activate() 에서 일어났다는 것이 확정된다.
     *  (2) 검사 — activate() 는 저장된 manifest 로 의존성을 검사한다. 코어 로케일이
     *      활성이 아닌 확장 스코프 팩은 activate() 단계에서 거부되어야 한다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects auto_activate_goes_through_activate_and_enforces_dependencies
     */
    public function test_finalize_install_with_auto_activate_goes_through_activate_and_runs_dependency_checks(): void
    {
        $identifier = 'actpath-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'actpath', '1.0.0'));

        /** @var list<string> $order 훅 발화 순서 */
        $order = [];
        $statusAtInstalledHook = null;

        HookManager::addAction('core.language_packs.installed', function () use (&$order, &$statusAtInstalledHook, $identifier) {
            $order[] = 'installed';
            $statusAtInstalledHook = DB::table('language_packs')
                ->where('identifier', $identifier)
                ->value('status');
        });
        HookManager::addAction('core.language_packs.activated', function () use (&$order) {
            $order[] = 'activated';
        });

        try {
            $pack = $this->service->installFromBundled($identifier, autoActivate: true);

            // (1) 경로 — 트랜잭션은 installed 로만 기록하고, 활성 승격은 그 뒤 activate() 에서 일어난다.
            $this->assertSame(
                LanguagePackStatus::Installed->value,
                $statusAtInstalledHook,
                '설치 트랜잭션이 status 를 active 로 직접 기록했다 — activate() 를 경유하지 않는다'
            );
            $this->assertSame(['installed', 'activated'], $order, '활성화가 설치 트랜잭션보다 먼저/동시에 일어났다');
            $this->assertSame(LanguagePackStatus::Active->value, $pack->status);
        } finally {
            HookManager::clearAction('core.language_packs.installed');
            HookManager::clearAction('core.language_packs.activated');
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }

        // (2) 검사 — activate() 는 저장된 manifest 의 의존성을 실제로 검사한다.
        //     코어 로케일이 활성이 아닌 상태에서 모듈 스코프 팩을 활성화하면 거부되어야 한다.
        //     (위에서 설치한 팩이 코어 ja 를 점유하므로 의존성이 미충족인 다른 로케일을 쓴다)
        $dependent = LanguagePack::query()->create([
            'identifier' => 'actpath-module-de',
            'vendor' => 'actpath',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'acme-demo',
            'locale' => 'de',
            'locale_name' => 'DE',
            'locale_native_name' => 'Deutsch',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Installed->value,
            'is_protected' => false,
            'manifest' => [
                'identifier' => 'actpath-module-de',
                'scope' => LanguagePackScope::Module->value,
                'target_identifier' => 'acme-demo',
                'locale' => 'de',
            ],
        ]);

        $this->assertFalse(
            $this->registry->hasActiveCoreLocale('de'),
            '전제 조건 붕괴 — 코어 de 가 활성이면 의존성 검사가 통과해버려 이 단언이 무의미해진다'
        );

        $this->expectException(LanguagePackOperationException::class);
        $this->service->activate($dependent);
    }

    /**
     * 활성 팩을 같은 identifier 로 재설치해도 자기 자신을 슬롯 충돌로 오인해 강등하지 않는다.
     *
     * `finalizeInstall` 은 트랜잭션에서 항상 `installed` 로 기록하고 그 뒤 activate() 를
     * 호출한다. 이때 `findActiveForSlot` 이 `$existing?->id` 를 제외하지 않으면 이미 활성인
     * 자기 자신이 슬롯 점유자로 잡혀 `shouldActivate=false` 가 되고, 팩이 조용히 `installed`
     * 로 내려간다 — 의존하는 확장 언어팩이 `core_locale_missing` 으로 차단되어 인스톨러가
     * 멈추던 회귀다.
     *
     * 같은 회귀를 고정하는 `test_reinstall_active_pack_keeps_active_status` 는 실제 ja 번들
     * 산출물을 요구해 미빌드 환경에서 skip 된다. 활성화 경로가 activate() 경유로 바뀐 뒤에도
     * 이 가드가 환경에 따라 조용히 건너뛰어지지 않도록, 합성 번들 픽스처로 항상 실행되는
     * 판을 따로 둔다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects reinstalling_an_active_pack_does_not_demote_it
     */
    public function test_finalize_install_does_not_demote_self_on_reinstall_of_active_pack(): void
    {
        $identifier = 'selfslot-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'selfslot', '1.0.0'));

        try {
            $first = $this->service->installFromBundled($identifier, autoActivate: true);
            $this->assertSame(
                LanguagePackStatus::Active->value,
                $first->status,
                '전제 조건 붕괴 — 첫 설치가 활성화되지 않으면 강등 여부를 관측할 수 없다'
            );

            $second = $this->service->installFromBundled($identifier, autoActivate: true);

            $this->assertSame($first->id, $second->id, '같은 identifier 는 같은 행을 갱신해야 한다');
            $this->assertSame(
                LanguagePackStatus::Active->value,
                $second->status,
                '재설치가 자기 자신을 슬롯 충돌로 오인해 active → installed 로 강등했다 (회귀)'
            );
            $this->assertSame(
                LanguagePackStatus::Active->value,
                DB::table('language_packs')->where('identifier', $identifier)->value('status'),
                '반환값만 active 이고 DB 에는 강등된 값이 남았다'
            );
        } finally {
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * CLI 설치는 HTTP 활성화 권한 게이트의 영향을 받지 않는다.
     *
     * `auto_activate` 의 활성화 권한 검사는 FormRequest 계층(`RequiresActivationPermission`)
     * 이 소유한다. 이 검사가 Service 계층으로 내려가면 인증 컨텍스트가 없는 CLI 가 전부
     * 깨진다 — `language-pack:install` 과 `language-pack:provision` 은 `--no-activate` 를
     * 주지 않는 한 설치 직후 자동 활성화를 기본 동작으로 삼기 때문이다.
     *
     * Service 를 목으로 바꾸지 않고 실제 커맨드를 인증 없이 실행해 활성화까지 도달하는지
     * 고정한다. 게이트가 서비스 계층으로 새어 들어오면 여기서 red 가 된다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects cli_installation_is_unaffected_by_the_http_activation_gate
     */
    public function test_cli_install_auto_activates_without_an_http_permission_context(): void
    {
        $identifier = 'cliact-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'cliact', '1.0.0'));

        try {
            $this->assertFalse(
                Auth::check(),
                '전제 조건 붕괴 — 인증된 사용자가 있으면 CLI 컨텍스트를 재현한 것이 아니다'
            );

            $this->artisan('language-pack:install', [
                'identifier' => $identifier,
                '--source' => 'bundled',
            ])->assertExitCode(0);

            $this->assertSame(
                LanguagePackStatus::Active->value,
                DB::table('language_packs')->where('identifier', $identifier)->value('status'),
                'CLI 설치가 자동 활성화되지 않았다 — 활성화 권한 검사가 Service 계층으로 내려왔을 가능성'
            );
        } finally {
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * 활성 팩의 업데이트는 활성 상태를 유지한다 — activate() 경유 전환의 회귀 가드.
     *
     * `performUpdate` 는 `$autoActivate = $pack->isActive()` 로 활성 여부를 승계해
     * 재설치 경로로 넘긴다. 활성화가 트랜잭션 직접 기록에서 activate() 경유로 바뀌면서
     * 이 경로에 의존성 검사·슬롯 충돌 검사가 새로 끼어들었으므로, 활성 팩의 업데이트가
     * 조용히 installed 로 떨어지거나 예외로 막히지 않는지 고정한다.
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects update_of_an_active_pack_keeps_it_active
     */
    public function test_perform_update_of_active_pack_keeps_it_active(): void
    {
        $identifier = 'updact-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'updact', '1.0.0'));

        try {
            $pack = $this->service->installFromBundled($identifier, autoActivate: true);
            $this->assertSame(LanguagePackStatus::Active->value, $pack->status, '전제 조건 — 업데이트 대상은 활성 팩이어야 한다');

            // 번들에 신버전 배치 후 업데이트 (bundled 소스 강제)
            $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'updact', '1.0.1'));

            $updated = $this->service->performUpdate($pack, force: true);

            $this->assertSame('1.0.1', $updated->version);
            $this->assertSame(
                LanguagePackStatus::Active->value,
                $updated->status,
                '활성 팩을 업데이트했더니 활성 상태를 잃었다 — activate() 경유 전환의 회귀'
            );
        } finally {
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * 비활성 팩의 업데이트는 활성으로 승격되지 않는다 (기존 동작 유지).
     *
     * @scenario vector=finalize_install_activation, actor_permission=cli_system
     *
     * @effects update_of_an_inactive_pack_does_not_promote_it
     */
    public function test_perform_update_of_inactive_pack_does_not_promote_it(): void
    {
        $identifier = 'updinact-core-ja';
        $bundledDir = $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'updinact', '1.0.0'));

        try {
            $pack = $this->service->installFromBundled($identifier, autoActivate: false);
            $this->assertSame(LanguagePackStatus::Installed->value, $pack->status);

            $this->createBundledFixture($identifier, $this->coreJaManifest($identifier, 'updinact', '1.0.1'));

            $updated = $this->service->performUpdate($pack, force: true);

            $this->assertSame('1.0.1', $updated->version);
            $this->assertSame(
                LanguagePackStatus::Installed->value,
                $updated->status,
                '비활성 팩이 업데이트만으로 활성 승격되면 안 된다'
            );
        } finally {
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * URL 설치가 성공해도 다운로드 임시 디렉토리(storage/app/temp/{uuid})가 남지 않는다.
     *
     * 임시 디렉토리 삭제가 catch 경로에만 있으면 실패 시에는 정리되고 성공 시에는
     * 100% 잔존한다 — 예외도 로그도 없이 설치할 때마다 ZIP 사본이 쌓이는 누수다.
     * 성공 경로를 실제로 통과시켜 잔존물이 0건임을 고정한다.
     *
     * @scenario vector=install_from_url, actor_permission=cli_system
     *
     * @effects successful_install_leaves_no_temp_directory
     */
    public function test_install_from_url_success_leaves_no_temp_directory(): void
    {
        $identifier = 'tmpleak-core-ja';
        $zipPath = tempnam(sys_get_temp_dir(), 'lp_zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString(
            'language-pack.json',
            (string) json_encode($this->coreJaManifest($identifier, 'tmpleak', '1.0.0'), JSON_UNESCAPED_UNICODE)
        );
        $zip->close();

        Http::fake(['https://packs.example.com/*' => Http::response((string) file_get_contents($zipPath), 200)]);

        $tempRoot = storage_path('app/temp');
        $before = File::isDirectory($tempRoot) ? File::directories($tempRoot) : [];

        try {
            $pack = $this->service->installFromUrl('https://packs.example.com/pack.zip', null);

            $this->assertSame($identifier, $pack->identifier);

            $after = File::isDirectory($tempRoot) ? File::directories($tempRoot) : [];
            $this->assertSame(
                [],
                array_values(array_diff($after, $before)),
                '설치가 성공했는데 storage/app/temp 에 다운로드 임시 디렉토리가 남았다'
            );
        } finally {
            @unlink($zipPath);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
        }
    }

    /**
     * 코어 ja 번들 픽스처용 manifest 를 만듭니다.
     *
     * @param  string  $identifier  번들 식별자
     * @param  string  $vendor  벤더
     * @param  string  $version  버전
     * @return array<string, mixed> manifest 데이터
     */
    private function coreJaManifest(string $identifier, string $vendor, string $version): array
    {
        return [
            'identifier' => $identifier,
            'namespace' => $vendor,
            'vendor' => $vendor,
            'name' => ['ko' => $identifier, 'en' => $identifier, 'ja' => $identifier],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => $version,
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'JA',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'g7_version' => '>=7.0.0-beta.4',
        ];
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
            $this->expectException(LanguagePackOperationException::class);
            $this->service->installFromBundled($identifier);
        } finally {
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
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
            File::deleteDirectory($bundledDir);
            File::deleteDirectory(base_path('lang-packs/'.$identifier));
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
        File::ensureDirectoryExists($path);
        File::put(
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
            File::deleteDirectory($path);
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
            File::deleteDirectory($path);
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
            File::deleteDirectory($path);
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
            File::deleteDirectory($path);
        }
    }
}
