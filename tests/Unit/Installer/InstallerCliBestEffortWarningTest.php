<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerCli;

/**
 * 터미널 설치 완료 경고 — 끝나지 않은 best-effort 작업을 안내하는 문구.
 *
 * 회귀 가드: 터미널 설치는 끝나지 않은 best-effort 작업(언어팩·설정/라우트 캐시·정적 게시)마다 경고를 만들 때
 * 이미 지워진 함수를 불러, 그런 작업이 하나라도 실패하면 설치 마지막에 「정의되지 않은 함수」로 멈췄다.
 * 설치 직후 사이트맵 만들기(`sitemap_generate`)도 같은 best-effort 작업이라 목록에 들어야 한다.
 */
class InstallerCliBestEffortWarningTest extends TestCase
{
    use RunsInstallerCli;

    /**
     * 임시 BASE_PATH 를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
        $this->putBaseFile('.env', "APP_KEY=\n");
    }

    /**
     * 임시 BASE_PATH 를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();
        parent::tearDown();
    }

    /**
     * 끝나지 않은 best-effort 작업마다 작업 이름이 든 경고가 나오고, 끝난 작업은 나오지 않는다.
     *
     * @scenario case=cli_best_effort_warnings
     *
     * @effects cli_install_warns_each_unfinished_best_effort_task_without_fatal
     *
     * @return void 반환값 없음
     */
    public function test_unfinished_best_effort_tasks_become_warnings_without_fatal(): void
    {
        $run = $this->runCliCode(<<<'PHP'
            $state = [
                'completed_tasks' => ['config_cache', 'language_pack_install:g7-core-ja'],
                'selected_extensions' => ['language_packs' => ['g7-core-ja', 'g7-module-sirsoft-board-ja']],
            ];
            echo '__RESULT__='.json_encode(g7_cli_best_effort_warnings($state), JSON_UNESCAPED_UNICODE);
            PHP);

        $this->assertIsArray($run['result'], 'best-effort 경고를 만들지 못했다: '.$run['raw']);
        $joined = implode("\n", $run['result']);

        $this->assertCount(4, $run['result']);
        $this->assertStringContainsString('g7-module-sirsoft-board-ja', $joined);
        $this->assertStringContainsString(self::label('route_cache'), $joined);
        $this->assertStringContainsString(self::label('static_publish'), $joined);
        $this->assertStringContainsString(self::label('sitemap_generate'), $joined);
        $this->assertStringNotContainsString(self::label('config_cache'), $joined);
        $this->assertStringNotContainsString('g7-core-ja)', $joined);
    }

    /**
     * 설치기 한국어 작업 이름.
     *
     * @param  string  $taskId  작업 식별자
     * @return string 작업 이름
     */
    private static function label(string $taskId): string
    {
        $lang = require dirname(__DIR__, 3).'/public/install/lang/ko.php';

        return (string) $lang['task_'.$taskId];
    }
}
