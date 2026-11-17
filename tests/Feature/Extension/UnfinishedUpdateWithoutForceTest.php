<?php

namespace Tests\Feature\Extension;

use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\Helpers\StuckUpdateRecovery;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 이 서버의 업데이트가 끝나지 않은 채 남아 있으면 `--force` 없는 `*:update` 가 「업데이트 없음」으로 끝내지 않는다 (#888).
 *
 * 업데이트는 DB 판을 새 판으로 적은 뒤 업그레이드 스텝·상태 복원·레이아웃 갱신을 한다. 검수 서버(stage)는 공유 상태 칸에
 * `updating` 을 쓰지 않으므로 그 사이에 프로세스가 죽으면 멈춘 업데이트 판정(`StuckUpdateRecovery::looksStale`)이 잡지 못했다.
 * 운영 서버도 상태를 되돌린 뒤(레이아웃 갱신 중) 죽으면 같다. 다음 `*:update`(`--force` 없이)는 DB 판이 이미 새 판이라
 * 「업데이트 없음」으로 끝나 남은 스텝·레이아웃 갱신이 영영 건너뛰어졌다.
 *
 * 이 서버의 진행 기록(`ExtensionUpdateJournal`, 서버 로컬)이 남아 있으면 끝나지 않은 업데이트가 있다고 알리고 `--force` 로
 * 이어받으라고 안내하며 종료 1 로 끝난다. 같은 서버에서 그 업데이트가 아직 돌고 있으면(작업 잠금) 진행 중이라고 알린다.
 *
 * @scenario-manifest tests/scenarios/extension-unfinished-update-guard.yaml
 */
class UnfinishedUpdateWithoutForceTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 테스트 준비 — 진행 기록·정리 기록을 비우고 라우트 캐시 재생성을 미룬다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->discardJournals();
        StuckUpdateRecovery::takeRecovered();
        $this->setRouteCacheDeferDepth(1);
    }

    /**
     * 픽스처 디스크 산출물·진행 기록을 지우고 라우트 캐시 미룸을 푼다(재생성은 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();
        $this->discardJournals();
        $this->setRouteCacheDeferDepth(0);

        parent::tearDown();
    }

    // 아래 시험이 함께 도는 확장 종류·역할 — docblock 은 @scenario 를 1건만 인식하므로 나머지 조합은 라인 주석으로 적는다.
    // @scenario source=module, role=live, path=unfinished_journal
    // @scenario source=plugin, role=stage, path=unfinished_journal
    // @scenario source=plugin, role=live, path=unfinished_journal
    // @scenario source=template, role=stage, path=unfinished_journal
    // @scenario source=template, role=live, path=unfinished_journal

    /**
     * DB 판은 이미 새 판인데 이 서버의 진행 기록이 남아 있으면, `--force` 없는 업데이트는 「업데이트 없음」 대신
     * 끝나지 않은 업데이트를 알리고 `--force` 이어받기를 안내하며 종료 1 — 기록과 DB 판은 그대로 둔다.
     *
     * @scenario source=module, role=stage, path=unfinished_journal
     *
     * @effects unfinished_update_is_reported_instead_of_no_update, unfinished_update_points_to_force_resume, unfinished_update_guard_changes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_update_without_force_refuses_while_journal_is_unfinished(): void
    {
        $this->makeFixtures();

        foreach (['stage', 'live'] as $role) {
            config(['core.runtime_role' => $role]);

            foreach ($this->sources() as [$type, $identifier, $model, $noUpdate]) {
                // 0.9.0 → 1.0.0 업데이트가 DB 판을 1.0.0 으로 적은 뒤 스텝·레이아웃 갱신 도중 죽었다 — 상태 칸은 active 그대로
                $journal = ExtensionUpdateJournal::begin($type, $identifier, '0.9.0', '1.0.0', 'bundled');
                $journal->markFilesTouched();

                $exit = Artisan::call("{$type}:update", ['identifier' => $identifier, '--no-interaction' => true]);
                $output = Artisan::output();

                $label = "{$role}/{$type}";
                $this->assertSame(1, $exit, "{$label}: 끝나지 않은 업데이트가 있으면 실패로 끝나야 합니다. 출력: {$output}");
                $this->assertStringNotContainsString($noUpdate, $output, "{$label}: 「업데이트 없음」으로 끝나면 남은 스텝·레이아웃 갱신이 영영 건너뛰어진다");
                $this->assertStringContainsString(__('extensions.commands.update.unfinished_update_needs_force', [
                    'name' => $identifier,
                    'from' => '0.9.0',
                    'to' => '1.0.0',
                    'command' => "php artisan {$type}:update {$identifier} --force",
                ]), $output, "{$label}: 끝나지 않은 업데이트와 --force 이어받기를 안내해야 합니다");

                $left = ExtensionUpdateJournal::find($type, $identifier);
                $this->assertNotNull($left, "{$label}: 안내만 하고 진행 기록은 지우지 않는다(--force 가 이어받는다)");
                $this->assertSame('0.9.0', $left->fromVersion(), $label);
                $this->assertSame('1.0.0', $model::where('identifier', $identifier)->value('version'), "{$label}: DB 판은 건드리지 않는다");

                ExtensionUpdateJournal::discard($type, $identifier);
            }
        }
    }

    // 아래 시험이 함께 도는 확장 종류 — docblock 은 @scenario 를 1건만 인식하므로 나머지 종류는 라인 주석으로 적는다.
    // @scenario source=plugin, role=stage, path=update_running_on_this_server
    // @scenario source=template, role=stage, path=update_running_on_this_server

    /**
     * 진행 기록이 남아 있어도 이 서버에서 그 업데이트가 아직 돌고 있으면(작업 잠금) 「이어받기」가 아니라 진행 중이라고 알린다.
     *
     * @scenario source=module, role=stage, path=update_running_on_this_server
     *
     * @effects running_update_is_reported_as_in_progress
     *
     * @return void 반환값 없음
     */
    public function test_update_without_force_reports_update_still_running(): void
    {
        $this->makeFixtures();
        config(['core.runtime_role' => 'stage']);

        foreach ($this->sources() as [$type, $identifier, , $noUpdate]) {
            ExtensionUpdateJournal::begin($type, $identifier, '0.9.0', '1.0.0', 'bundled')->markFilesTouched();

            // 다른 프로세스가 같은 확장을 업데이트하는 중 — 같은 프로세스의 acquire() 는 재진입 합류라 저장소에 직접 잡는다
            $lock = Cache::lock(ExtensionOperationLock::key($type, $identifier), 60);
            $this->assertTrue($lock->get());
            try {
                $exit = Artisan::call("{$type}:update", ['identifier' => $identifier, '--no-interaction' => true]);
                $output = Artisan::output();
            } finally {
                $lock->release();
            }

            $this->assertSame(1, $exit, "{$type}: 출력: {$output}");
            $this->assertStringNotContainsString($noUpdate, $output, $type);
            $this->assertStringContainsString(__('extensions.errors.update_already_running', ['name' => $identifier]), $output, "{$type}: 돌고 있는 업데이트는 진행 중으로 알린다");
            $this->assertNotNull(ExtensionUpdateJournal::find($type, $identifier), $type);

            ExtensionUpdateJournal::discard($type, $identifier);
        }
    }

    /**
     * 가짜 모듈·플러그인·템플릿(판 1.0.0, active)을 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function makeFixtures(): void
    {
        $this->makeFixtureTemplate();
        $this->writeFixtureTemplateManifest();
        $this->makeFixtureModule([]);
        $this->makeFixturePlugin([]);
    }

    /**
     * 확장 종류별 시험 대상.
     *
     * @return list<array{0: string, 1: string, 2: class-string, 3: string}> [종류, 식별자, 모델, 「업데이트 없음」 문구]
     */
    private function sources(): array
    {
        return [
            ['module', $this->fixtureModule, Module::class, __('modules.commands.update.no_update', ['module' => $this->fixtureModule])],
            ['plugin', $this->fixturePlugin, Plugin::class, __('plugins.commands.update.no_update', ['plugin' => $this->fixturePlugin])],
            ['template', $this->fixtureTemplate, Template::class, __('templates.commands.update.no_update', ['template' => $this->fixtureTemplate])],
        ];
    }

    /**
     * 픽스처 확장의 진행 기록을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function discardJournals(): void
    {
        ExtensionUpdateJournal::discard('module', $this->fixtureModule);
        ExtensionUpdateJournal::discard('plugin', $this->fixturePlugin);
        ExtensionUpdateJournal::discard('template', $this->fixtureTemplate);
    }

    /**
     * 라우트 캐시 재생성 미룸 깊이를 정합니다 — 0 으로 되돌릴 때 쌓인 재생성 요청은 버린다.
     *
     * @param  int  $depth  미룸 깊이
     * @return void 반환값 없음
     */
    private function setRouteCacheDeferDepth(int $depth): void
    {
        $ref = new \ReflectionClass(RouteCacheHelper::class);
        $ref->setStaticPropertyValue('deferDepth', $depth);
        if ($depth === 0) {
            $ref->setStaticPropertyValue('rebuildPending', false);
        }
    }
}
