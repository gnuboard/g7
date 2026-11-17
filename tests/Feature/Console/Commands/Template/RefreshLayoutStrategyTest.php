<?php

namespace Tests\Feature\Console\Commands\Template;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * `{template|module|plugin}:refresh-layout` 의 레이아웃 갱신 전략 옵션 (`--layout-strategy=keep|overwrite`).
 *
 * 기본은 keep — 운영자가 편집기로 고친 화면은 갱신 뒤에도 남는다. 덮어쓰기는 `--layout-strategy=overwrite` 로 명시할 때만.
 */
class RefreshLayoutStrategyTest extends TestCase
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

    // @scenario source=module, entry=cli, strategy=default
    // @scenario source=plugin, entry=cli, strategy=default

    /**
     * 옵션 없이 갱신하면 운영자가 고친 화면이 그대로 남는다.
     *
     * @scenario source=template, entry=cli, strategy=default
     *
     * @effects refresh_default_keeps_operator_edit
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_default_refresh_keeps_operator_edit(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);

        $this->artisan("{$source}:refresh-layout", ['identifier' => $this->scenarioIdentifier($source)])->assertExitCode(0);

        $this->assertSame('운영자 A', $this->scenarioPartAText($row), '기본 갱신은 운영자 수정본을 지키는 keep 이어야 한다');
    }

    // @scenario source=module, entry=cli, strategy=overwrite
    // @scenario source=plugin, entry=cli, strategy=overwrite

    /**
     * `--layout-strategy=overwrite` 면 파일 판으로 덮는다.
     *
     * @scenario source=template, entry=cli, strategy=overwrite
     *
     * @effects refresh_overwrite_replaces_operator_edit
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_overwrite_strategy_replaces_operator_edit(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);

        $this->artisan("{$source}:refresh-layout", ['identifier' => $this->scenarioIdentifier($source), '--layout-strategy' => 'overwrite'])->assertExitCode(0);

        $this->assertSame('A1', $this->scenarioPartAText($row), 'overwrite 는 파일 판으로 덮어야 한다');
    }

    // @scenario source=module, entry=cli, strategy=default
    // @scenario source=plugin, entry=cli, strategy=default

    /**
     * 식별자 없이 전체 갱신해도 확장마다 판정별 화면 수와 운영자 수정과 겹친 화면 목록을 낱개 갱신과 같은 꼴로 보여 준다.
     *
     * @scenario source=template, entry=cli, strategy=default
     *
     * @effects refresh_all_prints_verdict_counts_and_conflicts
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_refresh_all_prints_verdict_counts_and_conflict_screens(string $source): void
    {
        $this->installScenario($source);
        $row = $this->editScenarioPartA($source);
        // 운영자가 고친 부분(A)을 새 판도 바꾼다 — 겹친 화면이 된다
        $this->writeScenarioVersion($source, ['a' => 'A2']);

        $exit = Artisan::call("{$source}:refresh-layout");
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame('운영자 A', $this->scenarioPartAText($row), '겹친 곳은 운영자 판이 남아야 한다');
        $this->assertStringContainsString((string) __('extensions.layout_sync.cli.counts_columns')[0], $output, '판정별 화면 수 표가 나와야 한다');
        $this->assertStringContainsString((string) __('extensions.layout_sync.verdict.conflict'), $output);
        $this->assertStringContainsString(trans_choice('extensions.layout_sync.cli.conflict_screens', 1), $output, '겹친 화면 목록 머리말이 나와야 한다');
        $this->assertStringContainsString($this->scenarioLayoutName($source), $output, '겹친 화면 이름이 나와야 한다');
    }

    // @scenario source=module, entry=cli, strategy=invalid
    // @scenario source=plugin, entry=cli, strategy=invalid

    /**
     * 알 수 없는 전략 값이면 아무것도 하지 않고 종료 1.
     *
     * @scenario source=template, entry=cli, strategy=invalid
     *
     * @effects refresh_invalid_strategy_fails
     *
     * @param  string  $source  출처
     * @return void 반환값 없음
     */
    #[DataProvider('scenarioSources')]
    public function test_invalid_strategy_fails_without_writing(string $source): void
    {
        $this->installScenario($source);
        $this->editScenarioPartA($source);
        $before = $this->scenarioChecksum();

        $this->artisan("{$source}:refresh-layout", ['identifier' => $this->scenarioIdentifier($source), '--layout-strategy' => 'merge'])->assertExitCode(1);

        $this->assertSame($before, $this->scenarioChecksum(), '잘못된 전략이면 DB 를 건드리지 않아야 한다');
    }
}
