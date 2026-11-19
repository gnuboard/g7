<?php

namespace Tests\Feature\Console\Commands\Extension;

use App\Services\Extension\ExtensionUpdatePreviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * `<type>:update <id> --dry-run --json` — 업데이트하지 않고 관리자 [업데이트] 창과 같은 미리보기를 낸다.
 *
 * 레이아웃 판정(`layouts`)과 지워질 선언 항목(`declarative_deletions`)이 API 가 부르는 같은 서비스 결과와 같아야 하고,
 * DB·활성 파일은 그대로다.
 */
class UpdateDryRunCommandTest extends TestCase
{
    use BuildsLayoutSyncScenario;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 활성 디렉토리를 번들로 복사하고 판을 올린 뒤 조각 B 문구를 바꿉니다.
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    private function writeBundledVersion(string $source): void
    {
        $id = $this->scenarioIdentifier($source);
        $bundled = base_path($source.'s/_bundled/'.$id);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path($source.'s/'.$id), $bundled);

        $manifest = $bundled.'/'.$source.'.json';
        $data = json_decode((string) File::get($manifest), true);
        $data['version'] = '1.0.1';
        File::put($manifest, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $partB = $source === 'template' ? '/layouts/partials/merge_list/_b.json' : '/resources/layouts/admin/partials/merge_list/_b.json';
        $b = json_decode((string) File::get($bundled.$partB), true);
        $b['text'] = 'B2-bundled';
        File::put($bundled.$partB, (string) json_encode($b, JSON_UNESCAPED_UNICODE));
        clearstatcache();
    }

    // @scenario source=module, entry=cli, strategy=default
    // @scenario source=plugin, entry=cli, strategy=default

    /**
     * CLI 미리보기 JSON 이 서비스(API 와 같은) 결과와 같고 DB 는 그대로다.
     *
     * @scenario source=template, entry=cli, strategy=default
     *
     * @effects update_preview_uses_new_version_files, cli_update_preview_matches_api, dry_run_writes_nothing
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_cli_dry_run_json_matches_service_preview(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);
        $this->writeBundledVersion($source);
        $before = $this->scenarioChecksum();
        $id = $this->scenarioIdentifier($source);

        $exit = Artisan::call("{$source}:update", ['identifier' => $id, '--dry-run' => true, '--json' => true, '--source' => 'bundled', '--layout-strategy' => 'keep']);
        $output = trim(Artisan::output());
        $json = json_decode(substr($output, (int) strrpos($output, "\n{") ?: 0), true) ?? json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($json, 'JSON 출력이어야 한다: '.$output);
        $this->assertTrue($json['dry_run']);
        $this->assertSame('1.0.1', $json['to_version']);

        $expected = app(ExtensionUpdatePreviewService::class)->preview($source, $id, 'bundled');
        $this->assertSame($expected['layouts']['entries'], $json['layouts']['entries'], 'CLI 와 API(서비스) 레이아웃 판정이 갈렸다');
        $this->assertSame($expected['declarative_deletions'], $json['declarative_deletions']);
        $this->assertSame($before, $this->scenarioChecksum(), 'CLI 미리보기가 DB 를 바꿨다');
        $this->assertSame('운영자 A', $this->scenarioPartAText($row));
    }

    // @scenario source=module, entry=cli, strategy=default
    // @scenario source=plugin, entry=cli, strategy=default

    /**
     * 켜진 확장의 활성 디렉토리가 없으면(파일이 지워진 설치본) 레이아웃 판정을 건너뛰고 사유를 알린다 — 예외로 멈추지 않는다.
     *
     * 실제 업데이트는 새 판 파일을 활성 디렉토리로 복사한 뒤 레이아웃을 동기화하므로, 병합 기준이 없는 미리보기는 판정 목록을 비운다.
     *
     * @scenario source=template, entry=cli, strategy=default
     *
     * @effects update_preview_survives_missing_active_files, dry_run_writes_nothing
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_dry_run_reports_missing_active_files_instead_of_failing(string $source): void
    {
        $this->installScenario($source);
        $this->writeBundledVersion($source);
        $id = $this->scenarioIdentifier($source);
        $active = base_path($source.'s/'.$id);
        // 확장 루트 밖으로 옮긴다 — 같은 루트 안의 사본은 오토로드 스캔이 같은 클래스를 두 번 선언한다
        $aside = storage_path('framework/testing/update-dry-run-aside/'.$source.'-'.$id);
        File::ensureDirectoryExists(dirname($aside));
        $this->fixturePaths[] = $aside;
        File::moveDirectory($active, $aside);
        clearstatcache();
        $before = $this->scenarioChecksum();

        try {
            $exit = Artisan::call("{$source}:update", ['identifier' => $id, '--dry-run' => true, '--json' => true, '--source' => 'bundled']);
            $output = trim(Artisan::output());
            $json = json_decode(substr($output, (int) strrpos($output, "\n{") ?: 0), true) ?? json_decode($output, true);
            $preview = app(ExtensionUpdatePreviewService::class)->preview($source, $id, 'bundled');
        } finally {
            File::moveDirectory($aside, $active);
            clearstatcache();
        }

        $this->assertSame(0, $exit, $output);
        $this->assertIsArray($json, 'JSON 출력이어야 한다: '.$output);
        $this->assertSame('files_missing', $json['layouts_skipped']);
        $this->assertSame([], $json['layouts']['entries']);
        $this->assertSame('files_missing', $preview['layouts_skipped']);
        $this->assertSame($before, $this->scenarioChecksum(), '미리보기가 DB 를 바꿨다');
    }
}
