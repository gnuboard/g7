<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Upgrade_7_0_0_beta_4 의 lang-packs/_bundled 사후 복구 (recoverLangPacksBundled) 검증.
 *
 * 회귀 시나리오: beta.3 → beta.4 업그레이드에서 부모(beta.3) 프로세스의 stale targets 가
 * 신규 디렉토리 lang-packs/_bundled 를 인식하지 못해 Step 7 applyUpdate 가 활성으로
 * 복사를 누락한 결함의 사후 보정 로직.
 *
 * 실제 _pending 구조 (production 로그 기반):
 *
 *     {pending_path}/
 *       core_{Ymd_His}/                ← 타임스탬프 격리 디렉토리
 *         extracted/
 *           {wrapper}/                  ← ZIP 안 최상위 디렉토리
 *             lang-packs/_bundled/{identifier}/
 *
 * 첫 패치(commit ecf1e86d0) 의 candidate 경로가 timestamp 격리 디렉토리를 건너뛰어
 * 모든 시도가 null 을 반환하던 결함을 회귀 테스트로 차단.
 */
class LangPacksRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private object $upgrade;

    private UpgradeContext $context;

    /** @var array<int, string> 정리할 임시 디렉토리 */
    private array $tempDirs = [];

    private ?string $originalPendingPath = null;

    private ?string $originalBasePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('upgrades/Upgrade_7_0_0_beta_4.php');

        $class = 'App\\Upgrades\\Upgrade_7_0_0_beta_4';
        $this->upgrade = new $class();
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.3',
            toVersion: '7.0.0-beta.4',
            currentStep: '7.0.0-beta.4',
        );

        $this->originalBasePath = base_path();
        $this->originalPendingPath = config('app.update.pending_path');
    }

    protected function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            app()->setBasePath($this->originalBasePath);
        }
        if ($this->originalPendingPath !== null) {
            config(['app.update.pending_path' => $this->originalPendingPath]);
        }

        foreach ($this->tempDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    /**
     * 표준 production 경로: {pending}/core_{TS}/extracted/{wrapper}/lang-packs/_bundled
     *
     * 가장 흔한 시나리오 — 회귀 차단 핵심 케이스.
     */
    public function test_recovery_finds_lang_packs_under_timestamped_extracted_wrapper(): void
    {
        $this->arrangeFakeBaseAndPending();

        $pending = config('app.update.pending_path');
        $sourceBundled = $pending.'/core_20260508_143749/extracted/dev-g7-pre-release/lang-packs/_bundled';
        File::ensureDirectoryExists($sourceBundled.'/g7-core-ja');
        File::put($sourceBundled.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja"}');

        $this->invokeRecover();

        $activeBundled = base_path('lang-packs/_bundled/g7-core-ja/language-pack.json');
        $this->assertFileExists(
            $activeBundled,
            'core_<TS>/extracted/<wrapper>/lang-packs/_bundled/ 경로의 소스가 활성 디렉토리로 복사되어야 함'
        );
    }

    /**
     * legacy 경로: {pending}/lang-packs/_bundled 직속 — 구버전 흐름 호환.
     */
    public function test_recovery_finds_lang_packs_directly_under_pending(): void
    {
        $this->arrangeFakeBaseAndPending();

        $pending = config('app.update.pending_path');
        $sourceBundled = $pending.'/lang-packs/_bundled';
        File::ensureDirectoryExists($sourceBundled.'/g7-core-ja');
        File::put($sourceBundled.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja"}');

        $this->invokeRecover();

        $this->assertFileExists(base_path('lang-packs/_bundled/g7-core-ja/language-pack.json'));
    }

    /**
     * 활성 디렉토리에 이미 정상 데이터가 있으면 복구를 건너뛰는지 검증 (멱등).
     */
    public function test_recovery_skips_when_active_lang_packs_already_present(): void
    {
        $this->arrangeFakeBaseAndPending();

        // 활성에 이미 패키지가 있는 상태
        File::ensureDirectoryExists(base_path('lang-packs/_bundled/g7-core-ja'));
        File::put(base_path('lang-packs/_bundled/g7-core-ja/language-pack.json'), '{"identifier":"g7-core-ja","preserve":true}');

        // _pending 에 다른 내용을 두어, 복구가 잘못 트리거되면 덮어쓸지 검증
        $pending = config('app.update.pending_path');
        $sourceBundled = $pending.'/core_20260508_143749/extracted/dev-g7-pre-release/lang-packs/_bundled';
        File::ensureDirectoryExists($sourceBundled.'/g7-core-ja');
        File::put($sourceBundled.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja","preserve":false}');

        $this->invokeRecover();

        $existing = json_decode(File::get(base_path('lang-packs/_bundled/g7-core-ja/language-pack.json')), true);
        $this->assertTrue($existing['preserve'] ?? false, '활성 데이터가 이미 있을 때 복구가 트리거되어 덮어써서는 안 됨');
    }

    /**
     * _pending 부재 시 안전하게 종료 + warning 로그.
     */
    public function test_recovery_warns_when_no_pending_source_found(): void
    {
        $this->arrangeFakeBaseAndPending();
        // _pending 자체는 만들었지만 lang-packs 소스를 두지 않음

        $this->invokeRecover();

        // 활성에 lang-packs/_bundled 디렉토리가 만들어지지 않거나, 비어있어야 함
        $activeBundled = base_path('lang-packs/_bundled');
        $this->assertTrue(
            ! File::isDirectory($activeBundled) || count(File::directories($activeBundled)) === 0,
            '_pending 에 소스가 없으면 활성 디렉토리에도 패키지가 만들어지면 안 됨'
        );
    }

    /**
     * 가장 최근 timestamp 디렉토리를 우선 선택하는지 검증 (다중 격리 디렉토리 시나리오).
     */
    public function test_recovery_prefers_newest_timestamped_directory(): void
    {
        $this->arrangeFakeBaseAndPending();
        $pending = config('app.update.pending_path');

        // 오래된 격리 디렉토리 (지연된 mtime 시뮬레이션)
        $oldBundled = $pending.'/core_20260101_000000/extracted/old-wrapper/lang-packs/_bundled';
        File::ensureDirectoryExists($oldBundled.'/g7-core-ja');
        File::put($oldBundled.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja","source":"old"}');
        @touch(dirname($oldBundled, 3), strtotime('2026-01-01 00:00:00'));

        // 최신 격리 디렉토리
        $newBundled = $pending.'/core_20260508_143749/extracted/new-wrapper/lang-packs/_bundled';
        File::ensureDirectoryExists($newBundled.'/g7-core-ja');
        File::put($newBundled.'/g7-core-ja/language-pack.json', '{"identifier":"g7-core-ja","source":"new"}');
        @touch(dirname($newBundled, 3), strtotime('2026-05-08 14:37:49'));

        $this->invokeRecover();

        $copied = json_decode(File::get(base_path('lang-packs/_bundled/g7-core-ja/language-pack.json')), true);
        $this->assertSame('new', $copied['source'] ?? null, '가장 최근 timestamp 격리 디렉토리의 소스가 우선 채택되어야 함');
    }

    /**
     * 격리된 base_path + pending_path 구성.
     */
    private function arrangeFakeBaseAndPending(): void
    {
        $fakeBase = storage_path('test_langpacks_base_'.uniqid());
        File::ensureDirectoryExists($fakeBase);
        $this->tempDirs[] = $fakeBase;
        app()->setBasePath($fakeBase);

        $fakePending = $fakeBase.'/storage/app/core_pending';
        File::ensureDirectoryExists($fakePending);
        config(['app.update.pending_path' => $fakePending]);
    }

    private function invokeRecover(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('recoverLangPacksBundled');
        $method->setAccessible(true);
        $method->invoke($this->upgrade, $this->context);
    }
}
