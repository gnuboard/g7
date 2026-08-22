<?php

namespace Tests\Feature\LanguagePack;

use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * `language-pack:provision` 커맨드 Feature 테스트.
 *
 * 파일시스템 설치를 피하기 위해 LanguagePackService 를 목킹하여 커맨드의
 * 로케일/스코프 필터, 차단 사유 skip, 드리프트 복구, 멱등성(후보 없음 → 설치 0) 을 검증한다.
 *
 * 시나리오 매트릭스: tests/scenarios/language-pack-provision-and-drift.yaml
 */
class ProvisionLanguagePackCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 미설치 번들 가상 팩을 만든다.
     *
     * @param  string  $identifier  번들 식별자
     * @param  string  $locale  로케일
     * @param  string  $scope  스코프 (core/module/plugin/template)
     * @param  string|null  $blockedReason  설치 차단 사유
     * @return LanguagePack 미설치 가상 팩
     */
    private function bundledPack(string $identifier, string $locale, string $scope = 'core', ?string $blockedReason = null): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->identifier = $identifier;
        $pack->locale = $locale;
        $pack->scope = $scope;
        $pack->version = '1.0.0';
        $pack->status = 'uninstalled';
        $pack->setAttribute('bundled_identifier', $identifier);
        $pack->setAttribute('install_blocked_reason', $blockedReason);
        $pack->exists = false;

        return $pack;
    }

    /**
     * 드리프트(설치 행 존재 + 설치본 파일 부재) 팩을 만든다.
     *
     * @param  string  $identifier  식별자
     * @param  string  $locale  로케일
     * @param  string  $scope  스코프
     * @return LanguagePack 드리프트 설치 행
     */
    private function driftedPack(string $identifier, string $locale, string $scope = 'core'): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->identifier = $identifier;
        $pack->locale = $locale;
        $pack->scope = $scope;
        $pack->version = '1.0.0';
        $pack->status = 'active';
        $pack->setAttribute('files_missing', true);
        $pack->setAttribute('bundled_source_available', true);
        $pack->exists = true;

        return $pack;
    }

    /**
     * installFromBundled 가 반환할 설치 완료 팩 스텁.
     *
     * @param  string  $identifier  식별자
     * @param  string  $locale  로케일
     * @return LanguagePack 설치 완료 팩
     */
    private function installedResult(string $identifier, string $locale): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->identifier = $identifier;
        $pack->locale = $locale;
        $pack->version = '1.0.0';
        $pack->status = 'active';

        return $pack;
    }

    /**
     * Service 목을 만들어 컨테이너에 바인딩한다.
     *
     * @param  array<int, LanguagePack>  $uninstalled  미설치 번들 후보
     * @param  array<int, LanguagePack>  $drifted  드리프트 복구 후보
     * @return MockInterface Service 목
     */
    private function mockService(array $uninstalled = [], array $drifted = [])
    {
        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldReceive('getUninstalledBundledPacks')->andReturn(new Collection($uninstalled));
        $service->shouldReceive('getDriftedInstalledPacks')->andReturn(new Collection($drifted));
        $this->app->instance(LanguagePackService::class, $service);

        return $service;
    }

    // ─── 미설치 → 신규 프로비저닝 ──────────────────────────────────────

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=uninstalled, scope=core
     *
     * @effects provisions_only_supported_non_base_locales
     */
    public function test_provisions_only_supported_non_base_locales(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $service = $this->mockService([
            $this->bundledPack('g7-core-ja', 'ja'),
            $this->bundledPack('g7-core-fr', 'fr'),   // supported_locales 에 없음 → 설치 대상 아님
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-core-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-core-ja', 'ja'));
        $service->shouldNotReceive('installFromBundled')->with('g7-core-fr', Mockery::any());

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=uninstalled, scope=extension
     *
     * @effects provisions_only_supported_non_base_locales
     */
    public function test_provisions_extension_scoped_bundled_pack(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $service = $this->mockService([
            $this->bundledPack('g7-module-sirsoft-board-ja', 'ja', 'module'),
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-module-sirsoft-board-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-module-sirsoft-board-ja', 'ja'));

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=uninstalled, scope=core
     *
     * @effects explicit_locale_option_overrides_supported_locales
     */
    public function test_explicit_locale_option_overrides_supported_locales(): void
    {
        config(['app.supported_locales' => ['ko']]);   // supported 에 ja 없음

        $service = $this->mockService([
            $this->bundledPack('g7-core-ja', 'ja'),
        ]);

        // --locale=ja 명시 시 supported 에 없어도 설치
        $service->shouldReceive('installFromBundled')
            ->with('g7-core-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-core-ja', 'ja'));

        $this->artisan('language-pack:provision', ['--locale' => ['ja']])->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=uninstalled, scope=extension
     *
     * @effects explicit_locale_option_overrides_supported_locales, scope_filter_limits_provision_targets
     */
    public function test_scope_option_limits_explicit_locale_targets(): void
    {
        config(['app.supported_locales' => ['ko']]);

        $service = $this->mockService([
            $this->bundledPack('g7-core-ja', 'ja'),
            $this->bundledPack('g7-module-sirsoft-board-ja', 'ja', 'module'),
        ]);

        // --scope=module 이면 코어 팩은 대상에서 빠진다
        $service->shouldReceive('installFromBundled')
            ->with('g7-module-sirsoft-board-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-module-sirsoft-board-ja', 'ja'));
        $service->shouldNotReceive('installFromBundled')->with('g7-core-ja', Mockery::any());

        $this->artisan('language-pack:provision', ['--locale' => ['ja'], '--scope' => ['module']])
            ->assertExitCode(0);
    }

    // ─── 이미 설치됨 → 멱등 ────────────────────────────────────────────

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=installed_active, scope=core
     *
     * @effects is_idempotent_when_no_uninstalled_candidates
     */
    public function test_is_idempotent_when_no_uninstalled_candidates(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        // 이미 전부 설치됨 → 미설치 후보 없음, 드리프트도 없음
        $service = $this->mockService();
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=installed_active, scope=extension
     *
     * @effects is_idempotent_when_no_uninstalled_candidates
     */
    public function test_is_idempotent_for_installed_extension_packs(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $service = $this->mockService();
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision', ['--scope' => ['module']])->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=installed_active, scope=core
     *
     * @effects is_idempotent_when_no_uninstalled_candidates
     */
    public function test_explicit_locale_is_idempotent_for_installed_core_pack(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $service = $this->mockService();
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision', ['--locale' => ['ja']])->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=installed_active, scope=extension
     *
     * @effects is_idempotent_when_no_uninstalled_candidates
     */
    public function test_explicit_locale_is_idempotent_for_installed_extension_pack(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $service = $this->mockService();
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision', ['--locale' => ['ja'], '--scope' => ['module']])
            ->assertExitCode(0);
    }

    // ─── 설치 차단 → best-effort skip ──────────────────────────────────

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=install_blocked, scope=extension
     *
     * @effects skips_install_blocked_packs
     */
    public function test_skips_blocked_packs(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $service = $this->mockService([
            $this->bundledPack('g7-module-board-ja', 'ja', 'module', 'target_not_installed'),
        ]);
        // 차단 사유가 있으면 설치 시도 자체를 하지 않는다
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=install_blocked, scope=extension
     *
     * @effects skips_install_blocked_packs
     */
    public function test_skips_blocked_packs_with_explicit_locale(): void
    {
        config(['app.supported_locales' => ['ko']]);

        $service = $this->mockService([
            $this->bundledPack('g7-plugin-gdpr-ja', 'ja', 'plugin', 'target_inactive'),
        ]);
        $service->shouldNotReceive('installFromBundled');

        $this->artisan('language-pack:provision', ['--locale' => ['ja']])->assertExitCode(0);
    }

    // ─── 드리프트 → 복구 ───────────────────────────────────────────────

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=drifted_missing_files, scope=core
     *
     * @effects drifted_pack_repaired_by_provision
     */
    public function test_repairs_drifted_core_pack(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        // 미설치 후보는 없지만 드리프트 행이 있다 — 슬롯이 점유돼 있어 미설치 목록엔 안 잡힌다
        $service = $this->mockService([], [
            $this->driftedPack('g7-core-ja', 'ja'),
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-core-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-core-ja', 'ja'));

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=non_base_declared, pack_state=drifted_missing_files, scope=extension
     *
     * @effects drifted_pack_repaired_by_provision
     */
    public function test_repairs_drifted_extension_pack(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $service = $this->mockService([], [
            $this->driftedPack('g7-module-sirsoft-board-ja', 'ja', 'module'),
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-module-sirsoft-board-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-module-sirsoft-board-ja', 'ja'));

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=drifted_missing_files, scope=core
     *
     * @effects drifted_pack_repaired_by_provision, explicit_locale_option_overrides_supported_locales
     */
    public function test_repairs_drifted_core_pack_with_explicit_locale(): void
    {
        config(['app.supported_locales' => ['ko']]);   // 선언에 없어도 명시하면 복구

        $service = $this->mockService([], [
            $this->driftedPack('g7-core-ja', 'ja'),
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-core-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-core-ja', 'ja'));

        $this->artisan('language-pack:provision', ['--locale' => ['ja']])->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=explicit_locale_option, locale_membership=non_base_declared, pack_state=drifted_missing_files, scope=extension
     *
     * @effects drifted_pack_repaired_by_provision, scope_filter_limits_provision_targets
     */
    public function test_repairs_drifted_extension_pack_within_scope_filter(): void
    {
        config(['app.supported_locales' => ['ko']]);

        $service = $this->mockService([], [
            $this->driftedPack('g7-core-ja', 'ja'),
            $this->driftedPack('g7-module-sirsoft-board-ja', 'ja', 'module'),
        ]);

        $service->shouldReceive('installFromBundled')
            ->with('g7-module-sirsoft-board-ja', true)
            ->once()
            ->andReturn($this->installedResult('g7-module-sirsoft-board-ja', 'ja'));
        $service->shouldNotReceive('installFromBundled')->with('g7-core-ja', Mockery::any());

        $this->artisan('language-pack:provision', ['--locale' => ['ja'], '--scope' => ['module']])
            ->assertExitCode(0);
    }

    // ─── base locale 만 선언 → 대상 없음 ───────────────────────────────

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=base_only, pack_state=uninstalled, scope=core
     *
     * @effects does_nothing_when_no_non_base_locale_declared
     */
    public function test_does_nothing_when_no_non_base_locale_declared(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $service = Mockery::mock(LanguagePackService::class);
        // 대상 로케일이 없으므로 후보 조회조차 하지 않아야 한다
        $service->shouldNotReceive('getUninstalledBundledPacks');
        $service->shouldNotReceive('getDriftedInstalledPacks');
        $service->shouldNotReceive('installFromBundled');
        $this->app->instance(LanguagePackService::class, $service);

        $this->artisan('language-pack:provision')->assertExitCode(0);
    }

    /**
     * @scenario target_locale_source=supported_locales_default, locale_membership=base_only, pack_state=uninstalled, scope=extension
     *
     * @effects does_nothing_when_no_non_base_locale_declared
     */
    public function test_does_nothing_for_extension_scope_when_base_only(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $service = Mockery::mock(LanguagePackService::class);
        $service->shouldNotReceive('getUninstalledBundledPacks');
        $service->shouldNotReceive('getDriftedInstalledPacks');
        $service->shouldNotReceive('installFromBundled');
        $this->app->instance(LanguagePackService::class, $service);

        $this->artisan('language-pack:provision', ['--scope' => ['module']])->assertExitCode(0);
    }
}
