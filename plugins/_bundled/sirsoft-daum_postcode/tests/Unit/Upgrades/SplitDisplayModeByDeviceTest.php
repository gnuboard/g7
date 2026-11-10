<?php

namespace Plugins\Sirsoft\DaumPostcode\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftDaumPostcode\V1_2_0\Migrations\SplitDisplayModeByDevice;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// audit:allow test-extension-base-class reason: 설정 파일(전역 페이크 settings 디스크)만 쓰는 업그레이드 스텝 테스트라 DB 가 필요 없다 — PluginTestCase 의 RefreshDatabase(migrate:fresh)는 로컬 MySQL OOM 규율(mem:migrate-fresh-ddl-crashes-local-mysql)에 걸린다

/**
 * 1.2.0 업그레이드 스텝 — 표시 방식을 기기별 두 칸으로 나누기
 *
 * 1.1.x 는 표시 방식 한 칸(`display_mode`: popup·layer)이었다. 1.2.0 은 PC(`display_mode_pc`: popup·layer·inline)와
 * 모바일(`display_mode_mobile`: inline·fullscreen)로 나눈다. 저장된 값은 PC 칸으로 옮기고 모바일은 inline 으로 두며
 * 옛 키는 지운다. 저장값이 없으면 아무것도 쓰지 않는다(조회가 기본값으로 폴백한다).
 *
 * 시나리오 SSoT: tests/scenarios/upgrade-split-display-mode-by-device.yaml
 */
class SplitDisplayModeByDeviceTest extends TestCase
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
        $file = base_path('plugins/_bundled/sirsoft-daum_postcode/upgrades/data/1.2.0/migrations/01_SplitDisplayModeByDevice.php');
        if (is_file($file)) {
            require_once $file;
        }

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
     * 저장된 popup·layer 는 PC 칸으로 옮기고, 모바일은 inline, 옛 키는 지우고, 다른 키는 그대로 둔다.
     *
     * @scenario stored=popup_or_layer
     *
     * @effects legacy_display_mode_moves_to_pc, mobile_display_mode_set_to_inline, legacy_display_mode_key_removed
     *
     * @return void 반환값 없음
     */
    public function test_저장된_표시_방식을_PC_칸으로_옮긴다(): void
    {
        foreach (['popup', 'layer'] as $mode) {
            $this->writeSettings(['display_mode' => $mode, 'popup_width' => 700, 'popup_height' => 800, 'theme_color' => '#000000']);

            $this->runStep();

            $this->assertSame([
                'popup_width' => 700,
                'popup_height' => 800,
                'theme_color' => '#000000',
                'display_mode_pc' => $mode,
                'display_mode_mobile' => 'inline',
            ], $this->readSettings(), $mode);
        }
    }

    /**
     * 옛 칸에 PC 선택지 밖의 값이 있으면 PC 칸을 쓰지 않아(기본값 layer 로 폴백) 옛 키만 지우고 모바일은 inline 으로 둔다.
     *
     * @scenario stored=unknown_value
     *
     * @effects legacy_display_mode_key_removed, mobile_display_mode_set_to_inline
     *
     * @return void 반환값 없음
     */
    public function test_알_수_없는_옛_값은_옮기지_않고_지운다(): void
    {
        $this->writeSettings(['display_mode' => 'zz', 'theme_color' => '#111111']);

        $this->runStep();

        $this->assertSame(['theme_color' => '#111111', 'display_mode_mobile' => 'inline'], $this->readSettings());
    }

    /**
     * 이미 나뉜 값이 있으면 그대로 두고, 두 번 실행해도 결과가 같다.
     *
     * @scenario stored=already_split
     *
     * @effects display_mode_split_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_이미_나뉜_값은_보존하고_재실행해도_같다(): void
    {
        $this->writeSettings(['display_mode' => 'popup', 'display_mode_pc' => 'inline', 'display_mode_mobile' => 'fullscreen']);

        $this->runStep();
        $once = File::get($this->path);
        $this->runStep();

        $this->assertSame($once, File::get($this->path));
        $this->assertSame(['display_mode_pc' => 'inline', 'display_mode_mobile' => 'fullscreen'], $this->readSettings());
    }

    /**
     * 옛 키가 없으면(저장값 없음) 파일을 건드리지 않는다 — 조회가 기본값(PC layer · 모바일 inline)으로 폴백한다.
     *
     * @scenario stored=no_legacy_key
     *
     * @effects missing_display_mode_falls_back_to_defaults
     *
     * @return void 반환값 없음
     */
    public function test_옛_키가_없으면_건드리지_않는다(): void
    {
        $this->writeSettings(['popup_width' => 500]);
        $before = File::get($this->path);

        $this->runStep();

        $this->assertSame($before, File::get($this->path));
    }

    /**
     * 설정 파일이 없거나 JSON 이 깨졌으면 건너뛴다.
     *
     * @scenario stored=missing_or_malformed
     *
     * @effects display_mode_split_skips_missing_or_malformed_file
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
     * 스텝을 실행합니다. (공개판 1.1.2 에서 올리는 경로)
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new SplitDisplayModeByDevice)->run(new UpgradeContext(
            fromVersion: '1.1.2',
            toVersion: '1.2.0',
            currentStep: '1.2.0',
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
