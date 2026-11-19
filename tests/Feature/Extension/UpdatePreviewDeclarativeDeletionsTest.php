<?php

namespace Tests\Feature\Extension;

use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Schedule;
use App\Services\Extension\ExtensionUpdatePreviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\Concerns\BuildsLayoutSyncScenario;
use Tests\TestCase;

/**
 * 업데이트 미리보기의 「지워질 선언 항목」 — 새 판 선언에서 빠진 알림 정의가 운영자 수정본 표시와 함께 나온다(API·CLI 같은 값).
 *
 * 새 판 진입 클래스(`module.php`)는 지금 판과 겹치지 않게 미리보기 이름공간으로 읽고, 지워질 행 판정은 실제 정리와 같은
 * `NotificationSyncHelper::planCleanup()`·`planTemplateCleanup()`·`ExtensionScheduleSyncHelper::planCleanup()` 이 한다. DB 는 바뀌지 않는다.
 */
class UpdatePreviewDeclarativeDeletionsTest extends TestCase
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
     * 새 판(번들) 모듈을 씁니다 — 알림 정의 `keep_me`(발송 수단 mail 템플릿)만 선언한다.
     *
     * @param  string  $extraMethods  진입 클래스에 더할 메서드 소스 (예: 스케줄 선언)
     * @return void 반환값 없음
     */
    private function writeBundledModuleDeclaringOnlyKeepMe(string $extraMethods = ''): void
    {
        $bundled = base_path('modules/_bundled/'.$this->fixtureModule);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path('modules/'.$this->fixtureModule), $bundled);
        File::put($bundled.'/module.json', (string) json_encode(['identifier' => $this->fixtureModule, 'vendor' => 'testmerge', 'name' => ['ko' => 'm', 'en' => 'm'], 'version' => '1.0.1']));
        $source = (string) File::get($bundled.'/module.php');
        $source = preg_replace('/\n}\s*$/', "\n    public function getNotificationDefinitions(): array\n    {\n        return [['type' => 'keep_me', 'hook_prefix' => 'x', 'name' => ['ko' => 'k'], 'channels' => ['mail'], 'templates' => [['channel' => 'mail', 'subject' => ['ko' => 's'], 'body' => ['ko' => 'b']]]]];\n    }\n".$extraMethods."}\n", $source);
        File::put($bundled.'/module.php', $source);
        clearstatcache();
    }

    /**
     * 픽스처 모듈 소유 스케줄 행을 만듭니다.
     *
     * @param  string  $name  스케줄 name
     * @param  array<int, string>|null  $overrides  운영자 수정 필드
     * @return Schedule 스케줄
     */
    private function schedule(string $name, ?array $overrides = null): Schedule
    {
        return Schedule::create([
            'name' => $name, 'label' => ['ko' => $name], 'type' => 'artisan', 'command' => 'inspire', 'expression' => '0 4 * * *',
            'frequency' => 'custom', 'dispatch_mode' => 'sync', 'history_mode' => 'all', 'is_active' => true,
            'extension_type' => 'module', 'extension_identifier' => $this->fixtureModule, 'user_overrides' => $overrides,
        ]);
    }

    /**
     * 남는 정의에서 선언이 빠진 발송 수단의 템플릿이 삭제 예정으로 나온다 — 운영자가 만든 템플릿은 대상이 아니고 DB 는 그대로다.
     *
     * @scenario source=module, entry=service, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions, dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_preview_lists_stale_notification_templates_of_kept_definition(): void
    {
        $this->installScenario('module');
        $keep = $this->definition('keep_me');
        NotificationTemplate::create(['definition_id' => $keep->id, 'channel' => 'database', 'subject' => ['ko' => 's'], 'body' => ['ko' => '운영자 문구'], 'is_active' => true, 'is_default' => false, 'user_overrides' => ['body']]);
        NotificationTemplate::create(['definition_id' => $keep->id, 'channel' => 'sms', 'subject' => ['ko' => 's'], 'body' => ['ko' => '운영자가 만든 템플릿'], 'is_active' => true, 'is_default' => false, 'is_user_created' => true]);
        $this->writeBundledModuleDeclaringOnlyKeepMe();
        $before = $this->declarationChecksum('module', $this->fixtureModule);

        $preview = app(ExtensionUpdatePreviewService::class)->preview('module', $this->fixtureModule, 'bundled');

        $this->assertSame([['type' => 'notification_template', 'key' => 'keep_me:database', 'has_operator_overrides' => true]], $preview['declarative_deletions'], '선언에 남은 mail·운영자가 만든 sms 는 지워지지 않는다');
        $this->assertSame($preview['declarative_deletions'], $this->dryRunJson('module', $this->fixtureModule)['declarative_deletions'] ?? null, 'CLI 와 API 의 삭제 예정이 같아야 한다');
        $this->assertSame($before, $this->declarationChecksum('module', $this->fixtureModule), '미리보기가 알림 행을 바꿨다');
    }

    /**
     * 새 판 선언에서 빠진 스케줄이 운영자 수정본 표시와 함께 삭제 예정으로 나온다 — 남는 이름·옛 자동 이름에서 이어받을 행은
     * 대상이 아니고 DB 는 그대로다.
     *
     * @scenario source=module, entry=service, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions, dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_preview_lists_removed_schedules(): void
    {
        $this->installScenario('module');
        $this->definition('keep_me');
        $this->schedule('keep-job');
        $this->schedule('gone-job', ['expression']);
        $this->schedule('gone-plain');
        // 옛 형식이 명령에서 만든 자동 이름 — 새 판이 같은 명령을 새 name 으로 선언하면 동기화가 이름만 바꿔 이어받는다
        $this->schedule('testmerge-legacy-task');
        $this->writeBundledModuleDeclaringOnlyKeepMe(
            "\n    public function getSchedules(): array\n    {\n        return [\n            ['name' => 'keep-job', 'label' => ['ko' => 'k'], 'command' => 'inspire', 'expression' => '0 4 * * *'],\n            ['name' => 'renamed-task', 'label' => ['ko' => 'r'], 'command' => 'testmerge:legacy-task', 'expression' => '0 5 * * *'],\n        ];\n    }\n"
        );
        $before = Schedule::query()->where('extension_identifier', $this->fixtureModule)->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all();

        $preview = app(ExtensionUpdatePreviewService::class)->preview('module', $this->fixtureModule, 'bundled');
        $schedules = array_values(array_filter($preview['declarative_deletions'], fn ($row) => $row['type'] === 'schedule'));
        usort($schedules, fn ($a, $b) => strcmp($a['key'], $b['key']));

        $this->assertSame([
            ['type' => 'schedule', 'key' => 'gone-job', 'has_operator_overrides' => true],
            ['type' => 'schedule', 'key' => 'gone-plain', 'has_operator_overrides' => false],
        ], $schedules);
        $this->assertSame($preview['declarative_deletions'], $this->dryRunJson('module', $this->fixtureModule)['declarative_deletions'] ?? null, 'CLI 와 API 의 삭제 예정이 같아야 한다');
        $this->assertSame($before, Schedule::query()->where('extension_identifier', $this->fixtureModule)->orderBy('id')->get()->map(fn ($row) => $row->getAttributes())->all(), '미리보기가 스케줄 행을 바꿨다');
    }

    /**
     * 스케줄 선언이 그대로면 스케줄 삭제 예정은 없다(빈 배열).
     *
     * @scenario source=module, entry=service, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions
     *
     * @return void 반환값 없음
     */
    public function test_unchanged_schedules_give_no_schedule_deletions(): void
    {
        $this->installScenario('module');
        $this->definition('keep_me');
        $this->schedule('keep-job', ['expression']);
        $this->writeBundledModuleDeclaringOnlyKeepMe(
            "\n    public function getSchedules(): array\n    {\n        return [['name' => 'keep-job', 'label' => ['ko' => 'k'], 'command' => 'inspire', 'expression' => '0 4 * * *']];\n    }\n"
        );

        $preview = app(ExtensionUpdatePreviewService::class)->preview('module', $this->fixtureModule, 'bundled');

        $this->assertSame([], $preview['declarative_deletions']);
    }

    /**
     * 확장 소유 알림 정의를 만듭니다 (기본은 픽스처 모듈 소유).
     *
     * @param  string  $type  알림 정의 type
     * @param  array<int, string>|null  $templateOverrides  템플릿의 운영자 수정 필드
     * @param  string  $extensionType  소유 확장 종류 (`module`·`plugin`)
     * @param  string|null  $extensionIdentifier  소유 확장 식별자 (null 이면 픽스처 모듈)
     * @return NotificationDefinition 정의
     */
    private function definition(string $type, ?array $templateOverrides = null, string $extensionType = 'module', ?string $extensionIdentifier = null): NotificationDefinition
    {
        $def = NotificationDefinition::create(['type' => $type, 'hook_prefix' => 'x', 'extension_type' => $extensionType, 'extension_identifier' => $extensionIdentifier ?? $this->fixtureModule, 'name' => ['ko' => $type], 'description' => ['ko' => ''], 'channels' => ['mail'], 'hooks' => [], 'variables' => [], 'is_active' => true, 'is_default' => true]);
        NotificationTemplate::create(['definition_id' => $def->id, 'channel' => 'mail', 'subject' => ['ko' => 's'], 'body' => ['ko' => '운영자 문구'], 'is_active' => true, 'is_default' => true, 'user_overrides' => $templateOverrides]);

        return $def;
    }

    /**
     * 새 판에서 빠진 알림 정의가 삭제 예정으로 나오고 운영자 수정본이 표시된다 — 서비스(API)와 CLI JSON 이 같다.
     *
     * @scenario source=module, entry=service, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions, cli_update_preview_matches_api, dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_preview_lists_removed_notification_definition_with_operator_flag(): void
    {
        $this->installScenario('module');
        $this->definition('keep_me');
        $gone = $this->definition('gone_one', ['body']);
        $this->writeBundledModuleDeclaringOnlyKeepMe();

        $preview = app(ExtensionUpdatePreviewService::class)->preview('module', $this->fixtureModule, 'bundled');

        $this->assertContains(['type' => 'notification_definition', 'key' => 'gone_one', 'has_operator_overrides' => true], $preview['declarative_deletions']);
        $this->assertNotContains('keep_me', array_column($preview['declarative_deletions'], 'key'));
        $this->assertNotNull(NotificationDefinition::find($gone->id), '미리보기는 지우지 않는다');

        Artisan::call('module:update', ['identifier' => $this->fixtureModule, '--dry-run' => true, '--json' => true, '--source' => 'bundled']);
        $output = trim(Artisan::output());
        $json = json_decode(substr($output, (int) strpos($output, '{"dry_run"')), true);
        $this->assertSame($preview['declarative_deletions'], $json['declarative_deletions'] ?? null, 'CLI 와 API 의 삭제 예정이 같아야 한다');
    }

    /**
     * 삭제 예정이 없으면 빈 배열이다.
     *
     * @scenario source=module, entry=cli, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions
     *
     * @return void 반환값 없음
     */
    public function test_no_removed_declarations_gives_empty_list(): void
    {
        $this->installScenario('module');
        $this->definition('keep_me');
        $this->writeBundledModuleDeclaringOnlyKeepMe();

        $preview = app(ExtensionUpdatePreviewService::class)->preview('module', $this->fixtureModule, 'bundled');

        $this->assertSame([], $preview['declarative_deletions']);
    }

    /**
     * 플러그인 — 새 판에서 빠진 알림 정의가 삭제 예정으로 나오고, CLI JSON 이 서비스(API)와 같으며 dry-run 은 DB 를 바꾸지 않는다.
     *
     * @scenario source=plugin, entry=cli, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions, cli_update_preview_matches_api, dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_plugin_preview_lists_removed_notification_definition(): void
    {
        $this->installScenario('plugin');
        $this->definition('keep_me', null, 'plugin', $this->fixturePlugin);
        $this->definition('gone_one', ['body'], 'plugin', $this->fixturePlugin);
        $this->writeBundledPluginDeclaringOnlyKeepMe();
        $before = $this->declarationChecksum('plugin', $this->fixturePlugin);

        $preview = app(ExtensionUpdatePreviewService::class)->preview('plugin', $this->fixturePlugin, 'bundled');
        $this->assertContains(['type' => 'notification_definition', 'key' => 'gone_one', 'has_operator_overrides' => true], $preview['declarative_deletions']);
        $this->assertNotContains('keep_me', array_column($preview['declarative_deletions'], 'key'));

        $json = $this->dryRunJson('plugin', $this->fixturePlugin);
        $this->assertSame($preview['declarative_deletions'], $json['declarative_deletions'] ?? null, 'CLI 와 API 의 삭제 예정이 같아야 한다');
        $this->assertSame($before, $this->declarationChecksum('plugin', $this->fixturePlugin), 'dry-run 이 선언 항목 행을 바꿨다');
    }

    /**
     * 템플릿 — 선언형 산출물이 없어 삭제 예정은 늘 빈 배열이고, CLI JSON 에서도 빈 배열(`[]`)로 나온다.
     *
     * @scenario source=template, entry=cli, strategy=default
     *
     * @effects update_preview_lists_declarative_deletions, cli_update_preview_matches_api
     *
     * @return void 반환값 없음
     */
    public function test_template_preview_has_empty_deletion_list(): void
    {
        $this->installScenario('template');
        $bundled = base_path('templates/_bundled/'.$this->fixtureTemplate);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path('templates/'.$this->fixtureTemplate), $bundled);
        $manifest = json_decode((string) File::get($bundled.'/template.json'), true);
        $manifest['version'] = '1.0.1';
        File::put($bundled.'/template.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE));
        clearstatcache();

        $preview = app(ExtensionUpdatePreviewService::class)->preview('template', $this->fixtureTemplate, 'bundled');
        $this->assertTrue($preview['update_available']);
        $this->assertSame([], $preview['declarative_deletions']);

        Artisan::call('template:update', ['identifier' => $this->fixtureTemplate, '--dry-run' => true, '--json' => true, '--source' => 'bundled']);
        $this->assertStringContainsString('"declarative_deletions":[]', Artisan::output(), '0건이면 JSON 빈 배열이어야 한다');
    }

    /**
     * 사람용 출력(`--json` 없음)에도 지워질 선언 항목 표와 운영자 수정본 표시가 나오고, DB 는 그대로다.
     *
     * @scenario source=module, entry=cli, strategy=overwrite
     *
     * @effects update_preview_lists_declarative_deletions, dry_run_writes_nothing
     *
     * @return void 반환값 없음
     */
    public function test_human_dry_run_prints_deletion_table(): void
    {
        $this->installScenario('module');
        $this->definition('keep_me');
        $this->definition('gone_one', ['body']);
        $this->writeBundledModuleDeclaringOnlyKeepMe();
        $before = $this->declarationChecksum('module', $this->fixtureModule);
        $layoutsBefore = $this->scenarioChecksum();

        $exit = Artisan::call('module:update', ['identifier' => $this->fixtureModule, '--dry-run' => true, '--source' => 'bundled', '--layout-strategy' => 'overwrite']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString((string) __('extensions.commands.update.dry_run_deletions_header'), $output);
        $this->assertStringContainsString('gone_one', $output);
        $this->assertStringContainsString((string) __('extensions.commands.update.dry_run_operator_modified'), $output);
        $this->assertSame($before, $this->declarationChecksum('module', $this->fixtureModule), 'dry-run 이 선언 항목 행을 바꿨다');
        $this->assertSame($layoutsBefore, $this->scenarioChecksum(), 'dry-run 이 레이아웃 행을 바꿨다');
    }

    /**
     * 새 판(번들) 플러그인을 씁니다 — 알림 정의 `keep_me` 만 선언한다.
     *
     * @return void 반환값 없음
     */
    private function writeBundledPluginDeclaringOnlyKeepMe(): void
    {
        $bundled = base_path('plugins/_bundled/'.$this->fixturePlugin);
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path('plugins/'.$this->fixturePlugin), $bundled);
        File::put($bundled.'/plugin.json', (string) json_encode(['identifier' => $this->fixturePlugin, 'vendor' => 'testmerge', 'name' => ['ko' => 'p', 'en' => 'p'], 'version' => '1.0.1']));
        $source = (string) File::get($bundled.'/plugin.php');
        $source = preg_replace('/\n}\s*$/', "\n    public function getNotificationDefinitions(): array\n    {\n        return [['type' => 'keep_me', 'hook_prefix' => 'x', 'name' => ['ko' => 'k'], 'channels' => ['mail'], 'templates' => [['channel' => 'mail', 'subject' => ['ko' => 's'], 'body' => ['ko' => 'b']]]]];\n    }\n}\n", $source);
        File::put($bundled.'/plugin.php', $source);
        clearstatcache();
    }

    /**
     * CLI 미리보기 JSON 을 읽습니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  식별자
     * @return array<string, mixed> JSON 출력을 해석한 배열
     */
    private function dryRunJson(string $type, string $identifier): array
    {
        $exit = Artisan::call("{$type}:update", ['identifier' => $identifier, '--dry-run' => true, '--json' => true, '--source' => 'bundled']);
        $output = trim(Artisan::output());
        $this->assertSame(0, $exit, $output);
        $json = json_decode(substr($output, (int) strpos($output, '{"dry_run"')), true);
        $this->assertIsArray($json, 'JSON 출력이어야 한다: '.$output);

        return $json;
    }

    /**
     * 확장이 소유한 알림 정의·템플릿 행 전체 체크섬.
     *
     * @param  string  $type  확장 종류
     * @param  string  $identifier  식별자
     * @return string 체크섬 (sha256 16진수 문자열)
     */
    private function declarationChecksum(string $type, string $identifier): string
    {
        $definitions = NotificationDefinition::withoutGlobalScopes()->where('extension_type', $type)->where('extension_identifier', $identifier)->orderBy('id')->get();
        $templates = NotificationTemplate::withoutGlobalScopes()->whereIn('definition_id', $definitions->pluck('id'))->orderBy('id')->get();

        return hash('sha256', (string) json_encode([
            $definitions->map(fn ($row) => $row->getAttributes())->all(),
            $templates->map(fn ($row) => $row->getAttributes())->all(),
        ]));
    }
}
