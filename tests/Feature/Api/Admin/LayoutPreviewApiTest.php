<?php

namespace Tests\Feature\Api\Admin;

use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\ModuleManager;
use App\Extension\Update\ExtensionUpdatePreviewStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\Support\Concerns\GrantsExtensionAdminToken;
use Tests\TestCase;

/**
 * 레이아웃 갱신 미리보기(`…/refresh-layouts/preview`)·업데이트 미리보기(`…/{id}/update/preview`) API.
 *
 * 둘 다 DB 를 바꾸지 않고 화면별 판정(entries)을 낸다. 업데이트 미리보기는 새 판 파일(번들 또는 내려받은 `_pending`)을
 * 파일 출처로 쓰고, GitHub 소스면 `pending_token` 을 돌려준다 — 이어지는 업데이트가 토큰으로 같은 준비본을 다시 쓴다.
 */
class LayoutPreviewApiTest extends TestCase
{
    use BuildsLayoutSyncScenario;
    use DatabaseTransactions;
    use GrantsExtensionAdminToken;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ExtensionUpdatePreviewStore::useToken(null);
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 출처의 디렉토리 종류.
     *
     * @param  string  $source  출처
     * @return string 디렉토리 종류 (`templates`·`modules`·`plugins`)
     */
    private function dirOf(string $source): string
    {
        return $source.'s';
    }

    /**
     * 관리자 토큰 헤더.
     *
     * @return array<string, string> 헤더
     */
    private function adminHeaders(): array
    {
        $token = $this->extensionAdminToken([
            'core.modules.activate', 'core.plugins.activate', 'core.templates.activate',
            'core.modules.install', 'core.plugins.install', 'core.templates.install',
        ]);

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * 활성 디렉토리를 번들 디렉토리로 복사하고 판 번호를 올린 뒤 새 판 문구를 씁니다.
     *
     * @param  string  $source  출처
     * @param  string  $version  번들 판 번호
     * @return string 번들 디렉토리
     */
    private function writeBundledVersion(string $source, string $version): string
    {
        $id = $this->scenarioIdentifier($source);
        $active = base_path($this->dirOf($source).'/'.$id);
        $bundled = base_path($this->dirOf($source).'/_bundled/'.$id);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory($active, $bundled);

        $manifest = $bundled.'/'.$source.'.json';
        $data = json_decode((string) File::get($manifest), true);
        $data['version'] = $version;
        File::put($manifest, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 새 판 문구 — 활성 디렉토리는 그대로 두고 번들 쪽 조각 B 만 바꾼다.
        $partB = $source === 'template' ? '/layouts/partials/merge_list/_b.json' : '/resources/layouts/admin/partials/merge_list/_b.json';
        $b = json_decode((string) File::get($bundled.$partB), true);
        $b['text'] = 'B2-bundled';
        File::put($bundled.$partB, (string) json_encode($b, JSON_UNESCAPED_UNICODE));
        clearstatcache();

        return $bundled;
    }

    // @scenario source=module, entry=api, strategy=default
    // @scenario source=plugin, entry=api, strategy=default

    /**
     * 갱신 미리보기는 200 + 판정 목록이고 DB 는 그대로다.
     *
     * @scenario source=template, entry=api, strategy=default
     *
     * @effects dry_run_writes_nothing, refresh_response_carries_verdict_counts
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_refresh_preview_returns_entries_without_writing(string $source): void
    {
        $this->installScenario($source);
        $this->editScenarioPartA($source);
        $this->writeScenarioVersion($source, ['b' => 'B2']);
        $before = $this->scenarioChecksum();
        $field = $source.'_name';

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/{$this->dirOf($source)}/refresh-layouts/preview", [$field => $this->scenarioIdentifier($source)]);

        $response->assertOk();
        $this->assertSame($before, $this->scenarioChecksum());
        $verdicts = array_column($response->json('data.entries'), 'verdict', 'key');
        $this->assertSame('merged', $verdicts['layout:'.$this->fixtureTemplate.':'.$this->scenarioLayoutName($source)] ?? null);
        $this->assertSame('keep', $response->json('data.strategy'));
    }

    // @scenario source=module, entry=api, strategy=overwrite
    // @scenario source=plugin, entry=api, strategy=overwrite

    /**
     * 업데이트 미리보기(번들 소스)는 새 판 파일로 판정하고 DB·활성 파일은 그대로다.
     *
     * @scenario source=template, entry=api, strategy=overwrite
     *
     * @effects update_preview_uses_new_version_files, dry_run_writes_nothing
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_update_preview_reads_bundled_new_version(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);
        $this->writeBundledVersion($source, '1.0.1');
        $before = $this->scenarioChecksum();

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/{$this->dirOf($source)}/{$this->scenarioIdentifier($source)}/update/preview");

        $response->assertOk();
        $this->assertTrue($response->json('data.update_available'));
        $this->assertSame('bundled', $response->json('data.update_source'));
        $this->assertSame('1.0.1', $response->json('data.to_version'));
        $this->assertNull($response->json('data.pending_token'), '번들 소스는 내려받지 않는다');
        $this->assertIsArray($response->json('data.declarative_deletions'));

        $entries = collect($response->json('data.layouts.entries'));
        $screen = $entries->firstWhere('key', 'layout:'.$this->fixtureTemplate.':'.$this->scenarioLayoutName($source));
        $this->assertSame('merged', $screen['verdict'] ?? null, '운영자가 고친 화면에 새 판 조각 B 가 병합될 것으로 판정돼야 한다');

        $this->assertSame($before, $this->scenarioChecksum(), '미리보기가 DB 를 바꿨다');
        $this->assertSame('운영자 A', $this->scenarioPartAText($row));
        $this->assertStringNotContainsString('B2-bundled', $this->flat($row->fresh()->content));
    }

    // @scenario source=module, entry=api, strategy=default
    // @scenario source=plugin, entry=api, strategy=default

    /**
     * 설치되지 않은 확장의 업데이트 미리보기는 서버 오류(500)가 아니라 404 와 「설치돼 있지 않음」 안내다.
     *
     * @scenario source=template, entry=api, strategy=default
     *
     * @effects update_preview_not_installed_returns_404
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_update_preview_for_not_installed_extension_returns_404(string $source): void
    {
        $identifier = 'sirsoft-preview_missing888';

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/{$this->dirOf($source)}/{$identifier}/update/preview");

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('extensions.errors.update_preview_not_installed', ['identifier' => $identifier]));
    }

    /**
     * GitHub 소스 미리보기는 `_pending` 준비본을 토큰과 함께 돌려주고, 그 토큰을 쓴 다운로드는 같은 준비본을 되돌려준다.
     *
     * @scenario source=module, entry=api, strategy=default
     *
     * @effects update_preview_reuses_pending_download
     *
     * @return void 반환값 없음
     */
    public function test_github_preview_token_is_reused_by_update_download(): void
    {
        $this->installScenario('module');
        $staged = $this->writeBundledVersion('module', '1.0.2');

        // 실제 관리자에 위임하는 대역 — 업데이트 확인·다운로드 두 메서드만 바꾼다.
        $manager = Mockery::mock(app(ModuleManager::class));
        $manager->shouldReceive('checkModuleUpdate')->andReturn([
            'update_available' => true, 'update_source' => 'github', 'latest_version' => '1.0.2', 'current_version' => '1.0.0',
        ]);
        // 미리보기 다운로드는 서명을 검증하고 풀어 둔 받은 소스(임시 디렉토리·풀린 디렉토리·검증 결과)를 돌려준다(7.2.0)
        $manager->shouldReceive('prepareUpdatePreviewSource')->once()->with($this->fixtureModule, '1.0.2')->andReturn([
            'temp_dir' => $staged, 'extracted_dir' => $staged, 'verification' => new PackageVerification(PackageVerificationStatus::Verified),
        ]);
        $this->app->instance(ModuleManager::class, $manager);

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/modules/{$this->fixtureModule}/update/preview");

        $response->assertOk();
        $token = $response->json('data.pending_token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $token);

        // 업데이트 다운로드 단계는 미리보기가 적어 둔 검증 결과와 함께 받은 소스를 꺼낸다
        $store = app(ExtensionUpdatePreviewStore::class);
        $none = PackageIntegrityOptions::none();
        ExtensionUpdatePreviewStore::useToken($token);
        $acquired = $store->claimAcquired('module', $this->fixtureModule, '1.0.2', $none);
        $this->assertNotNull($acquired, '미리보기가 등록한 준비본을 같은 판의 업데이트가 쓴다');
        $this->assertSame($staged, $acquired['extracted_dir']);
        $this->assertSame(PackageVerificationStatus::Verified, $acquired['verification']->status);
        $this->assertNull($store->claimAcquired('module', $this->fixtureModule, '1.0.2', $none), '토큰은 한 번만 쓴다');

        // 판이 다르면 준비본을 쓰지 않는다(토큰은 소모된다)
        $token = $store->put('module', $this->fixtureModule, '1.0.2', $staged, (new PackageVerification(PackageVerificationStatus::Verified))->toArray());
        ExtensionUpdatePreviewStore::useToken($token);
        $this->assertNull($store->claimAcquired('module', $this->fixtureModule, '9.9.9', $none), '판이 다르면 준비본을 쓰지 않는다');
        ExtensionUpdatePreviewStore::useToken($token);
        $this->assertNull($store->claimAcquired('module', $this->fixtureModule, '1.0.2', $none), '맞지 않은 토큰은 소모된다');
    }

    /**
     * 미서명 준비본은 업데이트 요청의 무결성 선택으로 다시 판정한다 — 미리보기를 거쳐도 「미서명으로 계속」 확인을 건너뛰지
     * 않고, 확인이 필요해 멈춘 뒤에도 준비본은 남아 확인한 재요청이 같은 파일을 쓴다.
     *
     * @scenario source=module, entry=api, strategy=default
     *
     * @effects update_preview_reuses_pending_download
     *
     * @return void 반환값 없음
     */
    public function test_unsigned_prepared_source_requires_the_update_requests_consent(): void
    {
        $this->installScenario('module');
        $staged = $this->writeBundledVersion('module', '1.0.2');

        $store = app(ExtensionUpdatePreviewStore::class);
        $token = $store->put('module', $this->fixtureModule, '1.0.2', $staged, PackageVerification::unsigned()->toArray());
        ExtensionUpdatePreviewStore::useToken($token);

        try {
            $store->claimAcquired('module', $this->fixtureModule, '1.0.2', PackageIntegrityOptions::none());
            $this->fail('미서명 준비본이 확인 없이 쓰였다');
        } catch (PackageIntegrityException $e) {
            $this->assertTrue($e->canContinue, '미서명은 확인하면 계속할 수 있다');
        }

        $acquired = $store->claimAcquired('module', $this->fixtureModule, '1.0.2', PackageIntegrityOptions::none()->allowingUnsigned());
        $this->assertNotNull($acquired, '확인한 재요청은 남아 있던 같은 준비본을 쓴다');
        $this->assertSame($staged, $acquired['extracted_dir']);
        $this->assertSame(PackageVerificationStatus::Unsigned, $acquired['verification']->status);
        $this->assertNull($store->claimAcquired('module', $this->fixtureModule, '1.0.2', PackageIntegrityOptions::none()->allowingUnsigned()), '토큰은 한 번만 쓴다');
    }
}
