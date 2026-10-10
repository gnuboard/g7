<?php

namespace Plugins\Sirsoft\DaumPostcode\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftDaumPostcode\V1_1_0\Migrations\ResetOutOfRangePopupSize;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 설정 파일(전역 페이크 settings 디스크)만 쓰는 업그레이드 스텝 테스트라 DB 가 필요 없다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * 1.1.0 업그레이드 스텝 — 범위 밖 팝업 크기 보정 (U5-4)
 *
 * 1.0.x 는 팝업 크기의 정수 여부만 봐서 범위 밖 값이 저장됐다. 1.1.0 검증(100~2000)이 들어오면 그 사이트는
 * 환경설정 화면에서 다른 항목을 저장할 때도 막힌다. 스텝이 범위 밖 값만 기본값으로 되돌린다.
 *
 * 검사 축: 범위 밖 → 기본값 · 범위 안(문자열 숫자 포함) 보존 · 키 없음 보존 · 다른 키 보존 · 멱등 · 파일 없음/깨짐 skip.
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-reset-out-of-range-popup-size.yaml
 */
class ResetOutOfRangePopupSizeTest extends TestCase
{
    /** @var string 설정 파일 절대 경로 */
    private string $path;

    /**
     * 스텝 파일을 불러오고 잔여 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/sirsoft-daum_postcode/upgrades/data/1.1.0/migrations/01_ResetOutOfRangePopupSize.php');

        $this->path = ExtensionStoragePath::plugin('sirsoft-daum_postcode', 'settings').'/setting.json';
        File::delete($this->path);
    }

    /**
     * 테스트가 만든 설정 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    /**
     * 범위 밖 값은 기본값으로 되돌리고 다른 키는 그대로 둔다.
     *
     * @scenario stored=out_of_range
     *
     * @effects out_of_range_popup_size_reset_to_default
     *
     * @return void 반환값 없음
     */
    public function test_범위_밖_값은_기본값으로_되돌린다(): void
    {
        $this->writeSettings(['display_mode' => 'popup', 'popup_width' => -10, 'popup_height' => 99999]);

        $this->runStep();

        $this->assertSame(['display_mode' => 'popup', 'popup_width' => 500, 'popup_height' => 600], $this->readSettings());
    }

    /**
     * 범위 안의 값(문자열 숫자 포함)과 없는 키는 건드리지 않는다.
     *
     * @scenario stored=in_range
     *
     * @effects in_range_popup_size_preserved
     *
     * @return void 반환값 없음
     */
    public function test_범위_안_값과_없는_키는_그대로_둔다(): void
    {
        $this->writeSettings(['popup_width' => '800', 'display_mode' => 'layer']);
        $before = File::get($this->path);

        $this->runStep();

        $this->assertSame($before, File::get($this->path));
    }

    /**
     * 숫자가 아닌 값도 기본값으로 되돌리고, 두 번 실행해도 결과가 같다.
     *
     * @scenario stored=non_numeric
     *
     * @effects out_of_range_popup_size_reset_to_default, popup_size_reset_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_숫자가_아닌_값도_되돌리고_재실행해도_같다(): void
    {
        $this->writeSettings(['popup_width' => 'wide', 'popup_height' => 2000]);

        $this->runStep();
        $once = File::get($this->path);
        $this->runStep();

        $this->assertSame($once, File::get($this->path));
        $this->assertSame(['popup_width' => 500, 'popup_height' => 2000], $this->readSettings());
    }

    /**
     * 설정 파일이 없거나 JSON 이 깨졌으면 건드리지 않는다.
     *
     * @scenario stored=missing_or_malformed
     *
     * @effects popup_size_reset_skips_missing_or_malformed_file
     *
     * @return void 반환값 없음
     */
    public function test_파일이_없거나_깨졌으면_건너뛴다(): void
    {
        $this->runStep();
        $this->assertFalse(File::exists($this->path));

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, '{ broken');
        $this->runStep();
        $this->assertSame('{ broken', File::get($this->path));
    }

    /**
     * 스텝을 실행합니다. (공개판 1.0.3 에서 올리는 경로)
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new ResetOutOfRangePopupSize)->run(new UpgradeContext(
            fromVersion: '1.0.3',
            toVersion: '1.1.0',
            currentStep: '1.1.0',
            logChannel: 'extension-upgrade',
        ));
    }

    /**
     * 설정 파일을 씁니다.
     *
     * @param  array<string, mixed>  $settings  설정 내용
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 설정 파일을 읽습니다.
     *
     * @return array<string, mixed> 설정 내용
     */
    private function readSettings(): array
    {
        return json_decode(File::get($this->path), true);
    }
}
