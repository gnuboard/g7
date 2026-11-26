<?php

namespace Tests\Feature\RuntimeRole;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CacheDriverFactory;
use App\Extension\Cache\CacheScope;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\TemplateLayout;
use App\Services\LayoutFileService;
use App\Services\LayoutResolverService;
use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 캐시 범위 — 검수 서버(stage)의 Code 범위 키는 `g7:stage:` 아래로 가고 Data 범위 키는 공유한다.
 *
 * @scenario-manifest tests/scenarios/runtime-role-cache-scope.yaml
 */
class CacheScopeTest extends TestCase
{
    /**
     * 역할을 정합니다.
     *
     * @param  string  $role  실행 역할 (live|stage)
     * @return void 반환값 없음
     */
    private function role(string $role): void
    {
        config(['core.runtime_role' => $role, 'cache.default' => 'array']);
    }

    /**
     * stage 의 Code 키는 `g7:stage:` 아래이고 live 키와 겹치지 않는다. Data 키는 같다. live 는 종전 키(회귀).
     *
     * @scenario case=key_resolution
     *
     * @effects stage_code_keys_are_separated
     *
     * @return void 반환값 없음
     */
    public function test_code_keys_are_separated_and_data_keys_shared(): void
    {
        $this->role('live');
        $liveCode = CacheDriverFactory::core()->resolveKey('ext.cache_version');
        $liveData = CacheDriverFactory::core()->resolveKey('modules.active');
        $liveModule = CacheDriverFactory::extension('module', 'acme-x')->resolveKey('template.routes.admin');

        $this->role('stage');
        $stageCode = CacheDriverFactory::core()->resolveKey('ext.cache_version');
        $stageData = CacheDriverFactory::core()->resolveKey('modules.active');
        $stageModule = CacheDriverFactory::extension('module', 'acme-x')->resolveKey('template.routes.admin');

        $this->assertSame('g7:core:ext.cache_version', $liveCode);
        $this->assertSame('g7:stage:core:ext.cache_version', $stageCode);
        $this->assertNotSame($liveCode, $stageCode);
        $this->assertSame($liveData, $stageData, 'Data 키는 공유');
        $this->assertSame('g7:stage:module.acme-x:template.routes.admin', $stageModule);
        $this->assertNotSame($liveModule, $stageModule);
        $this->assertSame('g7:stage:core:modules.active', CacheDriverFactory::core(CacheScope::Code)->resolveKey('modules.active'), '범위 고정');
        $this->assertSame(CacheScope::Code, CacheScope::classify('layout.sirsoft-admin_basic.home.v3'));
        $this->assertSame(CacheScope::Data, CacheScope::classify('settings.general'));
    }

    /**
     * stage 에서 캐시 버전을 올려도 live 의 `ext.cache_version` 은 그대로다 — 공용 바인딩(CacheInterface)도 같다.
     *
     * @scenario case=cache_version_bump
     *
     * @effects stage_cache_clear_keeps_live_version
     *
     * @return void 반환값 없음
     */
    public function test_stage_cache_version_bump_keeps_live_version(): void
    {
        $this->role('live');
        app(CacheInterface::class)->put('ext.cache_version', 111);

        $this->role('stage');
        $bumper = new class
        {
            use ClearsTemplateCaches;

            /**
             * 캐시 버전을 올립니다.
             *
             * @return void 반환값 없음
             */
            public function bump(): void
            {
                $this->incrementExtensionCacheVersion();
            }
        };
        $bumper->bump();
        $this->assertNotSame(111, CacheDriverFactory::core()->get('ext.cache_version'), 'stage 쪽 버전은 올라갔다');

        $this->role('live');
        $this->assertSame(111, CacheDriverFactory::core()->get('ext.cache_version'), 'live 버전은 그대로');
    }

    /**
     * 레이아웃 해석 캐시(`layout_resolver.{템플릿ID}.{이름}`)는 서버마다 따로다 — 값이 그 서버 역할의 레이아웃 표 행 ID 라서다.
     *
     * 종전 Code 패턴 `^layout\.` 은 `layout_resolver.` 와 맞지 않아 공유됐다. 검수 서버 전용 행 ID(10억 이상)가 들어가면 운영 서버가
     * 그 ID 로 찾다 null(404)이 되고, 「해석 결과 없음」 표식 0 이 들어가면 상대 서버가 그 화면을 찾지 못했다.
     *
     * @scenario case=server_scoped_keys
     *
     * @effects server_specific_keys_are_code_scoped
     *
     * @return void 반환값 없음
     */
    public function test_layout_resolver_cache_is_server_scoped(): void
    {
        $this->role('live');
        $this->assertNull(app(LayoutResolverService::class)->resolve('acme-probe_missing', 987654));
        $key = 'layout_resolver.987654.acme-probe_missing';
        $this->assertSame(0, CacheDriverFactory::core()->get($key), '운영 서버는 「해석 결과 없음」 표식 0 을 캐시했다');

        $this->role('stage');
        $this->assertNull(CacheDriverFactory::core()->get($key), '검수 서버는 운영 서버의 해석 캐시를 보지 않는다');
        $this->assertSame(CacheScope::Code, CacheScope::classify($key));
        $this->assertStringStartsWith('g7:stage:', CacheDriverFactory::core()->resolveKey($key));
    }

    /**
     * 시스템 정보 캐시(`settings.system_info.{locale}`)는 서버마다 따로다 — 판·설치 경로·PHP 등 그 서버의 값이다.
     *
     * 공유되면 운영 서버 관리자 [환경설정 > 시스템 정보] 에 검수 서버의 판·경로가 최대 1시간 보였다.
     *
     * @scenario case=server_scoped_keys
     *
     * @effects server_specific_keys_are_code_scoped
     *
     * @return void 반환값 없음
     */
    public function test_system_info_cache_is_server_scoped(): void
    {
        $key = 'settings.system_info.'.app()->getLocale();

        $this->role('stage');
        app(CacheInterface::class)->put($key, ['g7_version' => 'stage-only-probe', 'install_path' => '/stage-only-probe'], 3600);

        $this->role('live');
        $info = app(SettingsService::class)->getSystemInfo();

        $this->assertNotSame('stage-only-probe', $info['g7_version'] ?? null, '운영 서버 화면에 검수 서버의 시스템 정보가 보이면 안 된다');
        $this->assertSame(base_path(), $info['install_path'] ?? null);
        $this->assertSame(CacheScope::Code, CacheScope::classify($key));
    }

    /**
     * 레이아웃 역변환 마크업 캐시(`layout:markup:{ID}:{lock_version}`, 조각은 `layout:markup:ext:…`)는 서버마다 따로다.
     *
     * 검수 서버 표는 운영 행을 ID·잠금 판 그대로 복사한 뒤 각자 고치므로 같은 `ID:lock_version` 이 서로 다른 본문을 가리킬 수 있다.
     *
     * @scenario case=server_scoped_keys
     *
     * @effects server_specific_keys_are_code_scoped
     *
     * @return void 반환값 없음
     */
    public function test_layout_markup_cache_is_server_scoped(): void
    {
        $layout = new TemplateLayout;
        $layout->setRawAttributes(['id' => 987654, 'lock_version' => 3, 'content' => '{"version":"1.0.0","components":[]}'], true);

        $this->role('stage');
        app(CacheInterface::class)->put('layout:markup:987654:3', 'stage-only-markup', 3600);

        $this->role('live');
        $markup = app(LayoutFileService::class)->markupFor($layout, 'sirsoft-admin_basic');

        $this->assertNotSame('stage-only-markup', $markup, '운영 서버는 검수 서버 표의 본문으로 만든 마크업을 쓰면 안 된다');
        $this->assertSame(CacheScope::Code, CacheScope::classify('layout:markup:987654:3'));
        $this->assertSame(CacheScope::Code, CacheScope::classify('layout:markup:ext:12:1'));
    }
}
