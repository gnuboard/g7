<?php

namespace Tests\Feature\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailPregenerator;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Thumbnails\Support\FakeThumbnailSourceProvider;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 썸네일 명령 테스트 — `thumbnails:prune`·`thumbnails:pregenerate`
 *
 * 정리는 썸네일 디스크의 `thumbnails/` 아래에서 규격이 바뀐 파일·없는 규격 파일·원본이 없어진 파일·
 * `--days` 를 넘긴 파일만 지우고 현재 규격의 살아 있는 파생본은 남겨야 한다. 미리 만들기는 원본 제공자를
 * `next_cursor` 가 빌 때까지 돌며 파생본을 만든다. DB 를 쓰지 않는다(디스크는 가짜, 제공자는 시험용).
 */
class ThumbnailCommandsTest extends TestCase
{
    /** @var list<list<array<string, mixed>>> 퍼지 훅이 받은 묶음 */
    private array $purges = [];

    /** @var \Closure|null 퍼지 훅 기록기 */
    private ?\Closure $purgeListener = null;

    /** @var \Closure|null 시험용 원본 제공자 필터 */
    private ?\Closure $providerFilter = null;

    /**
     * 디스크를 가짜로 바꾸고 썸네일 설정을 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        foreach (['attachments', 'thumbnails'] as $disk) {
            Storage::fake($disk);
        }

        Config::set('attachment.disk', 'attachments');
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.prune_enabled', true);
        Config::set('core.thumbnail.pregenerate_batch', 2);

        $this->purges = [];
        $this->purgeListener = function (array $items) {
            $this->purges[] = $items;
        };
        HookManager::addAction('core.thumbnail.after_purge', $this->purgeListener);
    }

    /**
     * 테스트가 건 훅을 걷습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->purgeListener !== null) {
            HookManager::removeAction('core.thumbnail.after_purge', $this->purgeListener);
        }

        if ($this->providerFilter !== null) {
            HookManager::removeFilter('core.thumbnail.filter_source_providers', $this->providerFilter);
        }

        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * 현재 엔진 기준의 규격 지문 (GD 가 없으면 시험을 건너뛴다)
     *
     * @param  string  $preset  규격 이름
     * @return string 지문
     */
    private function currentFingerprint(string $preset): string
    {
        $engine = app(ImageProcessorResolver::class)->resolve();

        if ($engine === null) {
            $this->markTestSkipped('이미지 처리 엔진(GD)이 없는 환경');
        }

        return app(ThumbnailPresetRegistry::class)->find($preset)->fingerprint($engine->id());
    }

    /**
     * 코어 첨부 원본과 그 파생본 하나를 심습니다.
     *
     * @param  string  $sourcePath  원본 경로 (첨부 디스크 위)
     * @param  string  $fileName  파생본 파일명
     * @param  bool  $withSource  원본 파일도 만들지
     * @return string 파생본 키
     */
    private function plant(string $sourcePath, string $fileName, bool $withSource = true): string
    {
        if ($withSource) {
            Storage::disk('attachments')->put($sourcePath, 'original');
        }

        $key = ThumbnailStore::ROOT."/attachments/{$sourcePath}/{$fileName}";
        Storage::disk('thumbnails')->put($key, 'thumb');

        return $key;
    }

    /**
     * 정리 대상 네 갈래(유지·규격 바뀜·없는 규격·원본 없음)를 심습니다.
     *
     * @return array{keep: string, stale: string, unknown: string, orphan: string} 파생본 키
     */
    private function plantPruneFixtures(): array
    {
        $fingerprint = $this->currentFingerprint('sm');

        return [
            'keep' => $this->plant('2026/11/05/keep.png', "sm_{$fingerprint}.png"),
            'stale' => $this->plant('2026/11/05/stale.png', 'sm_00000000.png'),
            'unknown' => $this->plant('2026/11/05/unknown.png', 'removed_preset_'.$fingerprint.'.png'),
            'orphan' => $this->plant('2026/11/05/orphan.png', "sm_{$fingerprint}.png", withSource: false),
        ];
    }

    /**
     * `--dry-run` 은 사유별 대상 수만 출력하고 아무것도 지우지 않는다.
     *
     * @scenario surface=prune_command
     *
     * @effects prune_dry_run_counts_without_deleting
     *
     * @return void 반환값 없음
     */
    public function test_prune_dry_run_reports_counts_without_deleting(): void
    {
        $keys = $this->plantPruneFixtures();

        $this->artisan('thumbnails:prune', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] '.__('thumbnail.console.prune.dry_run', [
                'total' => 3, 'stale' => 1, 'unknown' => 1, 'orphan' => 1, 'expired' => 0,
                'days' => __('thumbnail.console.prune.days_unset'),
            ]))
            ->assertExitCode(0);

        foreach ($keys as $key) {
            Storage::disk('thumbnails')->assertExists($key);
        }

        $this->assertSame([], $this->purges, '미리보기는 퍼지 훅을 발화하지 않는다');
    }

    /**
     * 규격이 바뀐 파일·없는 규격 파일·원본이 없는 파일을 지우고 현재 규격의 파생본은 남긴다.
     * 비게 된 원본 디렉터리도 지우고, 퍼지 훅은 묶음 한 번으로 발화한다.
     *
     * @scenario surface=prune_command
     *
     * @effects prune_deletes_stale_fingerprint_files, prune_deletes_unknown_preset_files, prune_deletes_files_whose_source_is_gone, prune_keeps_current_files, prune_removes_emptied_directories, prune_purge_hook_fires_once
     *
     * @return void 반환값 없음
     */
    public function test_prune_deletes_stale_unknown_and_orphan_files(): void
    {
        $keys = $this->plantPruneFixtures();

        $this->artisan('thumbnails:prune')->assertExitCode(0);

        $disk = Storage::disk('thumbnails');
        $disk->assertExists($keys['keep']);
        $disk->assertMissing($keys['stale']);
        $disk->assertMissing($keys['unknown']);
        $disk->assertMissing($keys['orphan']);
        $this->assertFalse($disk->directoryExists(dirname($keys['orphan'])), '비게 된 원본 디렉터리도 지운다');

        $this->assertCount(1, $this->purges, '지운 접두는 한 번에 발화한다');
        $prefixes = array_column($this->purges[0], 'prefix');
        sort($prefixes);
        $this->assertSame(['2026/11/05/orphan.png', '2026/11/05/stale.png', '2026/11/05/unknown.png'], $prefixes);
        $this->assertSame(['attachments'], array_values(array_unique(array_column($this->purges[0], 'source_disk'))));
    }

    /**
     * 엔진을 바꾸면 지문이 달라져 옛 엔진 파일이 정리 대상이 된다 (같은 규격 값이라도 결과 바이트가 다르다).
     *
     * @scenario surface=prune_command
     *
     * @effects prune_engine_change_marks_stale
     *
     * @return void 반환값 없음
     */
    public function test_prune_treats_other_engine_fingerprint_as_stale(): void
    {
        $this->currentFingerprint('sm');
        $imagickFingerprint = app(ThumbnailPresetRegistry::class)->find('sm')->fingerprint('imagick');
        $key = $this->plant('2026/11/05/engine.png', "sm_{$imagickFingerprint}.png");

        $this->artisan('thumbnails:prune')->assertExitCode(0);

        Storage::disk('thumbnails')->assertMissing($key);
    }

    /**
     * 움직이는 원본의 첫 프레임 썸네일(현재 엔진의 첫 프레임 지문)은 현재 파생본이라 남기고, 다른 엔진의 첫 프레임 지문은 지운다.
     *
     * @scenario surface=prune_command
     *
     * @effects prune_keeps_first_frame_thumbnails
     *
     * @return void 반환값 없음
     */
    public function test_prune_keeps_current_first_frame_thumbnails(): void
    {
        $this->currentFingerprint('sm');
        $spec = app(ThumbnailPresetRegistry::class)->find('sm');
        $engineId = app(ImageProcessorResolver::class)->resolve()->id();
        $keep = $this->plant('2026/11/05/anim.gif', 'sm_'.$spec->fingerprint($engineId, true).'.gif');
        $other = $engineId === 'gd' ? 'imagick' : 'gd';
        $stale = $this->plant('2026/11/05/anim2.gif', 'sm_'.$spec->fingerprint($other, true).'.gif');

        $this->artisan('thumbnails:prune')->assertExitCode(0);

        Storage::disk('thumbnails')->assertExists($keep);
        Storage::disk('thumbnails')->assertMissing($stale);
    }

    /**
     * `--days` 를 주면 그 일수보다 오래 다시 만들어지지 않은 파일도 지운다.
     *
     * @scenario surface=prune_command
     *
     * @effects prune_days_deletes_old_files_only
     *
     * @return void 반환값 없음
     */
    public function test_prune_days_deletes_old_files_only(): void
    {
        $fingerprint = $this->currentFingerprint('sm');
        $old = $this->plant('2026/11/05/old.png', "sm_{$fingerprint}.png");
        $fresh = $this->plant('2026/11/05/fresh.png', "sm_{$fingerprint}.png");
        touch(Storage::disk('thumbnails')->path($old), now()->subDays(40)->getTimestamp());

        $this->artisan('thumbnails:prune', ['--days' => '30'])->assertExitCode(0);

        Storage::disk('thumbnails')->assertMissing($old);
        Storage::disk('thumbnails')->assertExists($fresh);
    }

    /**
     * 스케줄 호출(`--scheduled`)은 자동 정리 토글이 꺼져 있으면 아무것도 지우지 않는다. 수동 호출은 토글과 무관하다.
     *
     * @scenario surface=prune_command
     *
     * @effects scheduled_prune_respects_toggle
     *
     * @return void 반환값 없음
     */
    public function test_scheduled_prune_does_nothing_when_disabled(): void
    {
        $keys = $this->plantPruneFixtures();
        Config::set('core.thumbnail.prune_enabled', false);

        $this->artisan('thumbnails:prune', ['--scheduled' => true])
            ->expectsOutputToContain(__('thumbnail.console.prune.disabled'))
            ->assertExitCode(0);

        foreach ($keys as $key) {
            Storage::disk('thumbnails')->assertExists($key);
        }

        $this->artisan('thumbnails:prune')->assertExitCode(0);
        Storage::disk('thumbnails')->assertMissing($keys['stale']);
    }

    /**
     * 시험용 원본 제공자만 쓰도록 겁니다 (원본은 공개 분류, 첨부 디스크의 `fake/` 아래).
     *
     * @param  int  $count  원본 수
     * @return list<ThumbnailSource> 원본
     */
    private function useFakeSources(int $count): array
    {
        $sources = [];

        for ($i = 1; $i <= $count; $i++) {
            $path = "fake/source-{$i}.png";
            Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::halves(400, 300, 'vertical', [255, 0, 0], [0, 0, 255])));
            $sources[] = new ThumbnailSource('attachments', $path, 'image/png', false, 400, 300);
        }

        $provider = new FakeThumbnailSourceProvider($sources);
        $this->providerFilter = fn () => [$provider];
        HookManager::addFilter('core.thumbnail.filter_source_providers', $this->providerFilter, 999);

        return $sources;
    }

    /**
     * 미리 만들기는 원본을 배치 크기(2)씩 끝까지 돌며 고른 규격의 파생본을 만든다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_command_runs_every_batch, pregenerate_skips_existing_thumbnails
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_runs_every_batch_until_cursor_is_empty(): void
    {
        $fingerprint = $this->currentFingerprint('sm');
        $sources = $this->useFakeSources(3);

        $this->artisan('thumbnails:pregenerate', ['--preset' => ['sm']])
            ->expectsOutputToContain(__('thumbnail.console.pregenerate.done', ['processed' => 3, 'generated' => 3, 'total' => 3]))
            ->assertExitCode(0);

        foreach ($sources as $source) {
            Storage::disk('thumbnails')->assertExists(ThumbnailStore::ROOT."/attachments/{$source->path}/sm_{$fingerprint}.png");
        }

        // 다시 돌리면 이미 있는 파생본은 건너뛴다(이어하기와 같다).
        $this->artisan('thumbnails:pregenerate', ['--preset' => ['sm']])
            ->expectsOutputToContain(__('thumbnail.console.pregenerate.done', ['processed' => 3, 'generated' => 0, 'total' => 3]))
            ->assertExitCode(0);
    }

    /**
     * `--dry-run` 은 대상 원본 수만 출력하고 파생본을 만들지 않는다. `--limit` 은 처리할 원본 수를 줄인다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_dry_run_creates_nothing, pregenerate_limit_caps_sources
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_dry_run_and_limit(): void
    {
        $this->currentFingerprint('sm');
        $this->useFakeSources(3);

        $this->artisan('thumbnails:pregenerate', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] '.__('thumbnail.console.pregenerate.dry_run', ['processed' => 3, 'total' => 3]))
            ->assertExitCode(0);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(ThumbnailStore::ROOT));

        $this->artisan('thumbnails:pregenerate', ['--preset' => ['sm'], '--limit' => '1'])
            ->expectsOutputToContain(__('thumbnail.console.pregenerate.done', ['processed' => 1, 'generated' => 1, 'total' => 3]))
            ->assertExitCode(0);

        $this->assertCount(1, Storage::disk('thumbnails')->allFiles(ThumbnailStore::ROOT));
    }

    /**
     * 모르는 원본 종류·규격 이름은 「전부」로 해석하지 않고 오류로 끝낸다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_rejects_unknown_names
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_rejects_unknown_source_and_preset(): void
    {
        $this->useFakeSources(1);

        $this->artisan('thumbnails:pregenerate', ['--source' => ['nope']])->assertExitCode(1);
        $this->artisan('thumbnails:pregenerate', ['--preset' => ['nope']])->assertExitCode(1);
        $this->artisan('thumbnails:pregenerate', ['--limit' => '0'])->assertExitCode(1);

        $this->assertSame([], Storage::disk('thumbnails')->allFiles(ThumbnailStore::ROOT));
    }

    /**
     * 정리 명령의 퍼지 항목도 저장소 경로와 같은 6필드를 싣는다 — 구독자가 키 유무로 경로를 가리지 않게.
     *
     * @scenario surface=prune_command
     *
     * @effects prune_purge_payload_has_every_key
     *
     * @return void 반환값 없음
     */
    public function test_prune_purge_payload_carries_every_key(): void
    {
        $this->plantPruneFixtures();

        $this->artisan('thumbnails:prune')->assertExitCode(0);

        $this->assertNotEmpty($this->purges[0] ?? []);

        foreach ($this->purges[0] as $item) {
            $this->assertSame(
                ['source_disk', 'prefix', 'thumbnail_disk', 'public_url_prefix', 'original_public_url', 'original_is_directory'],
                array_keys($item)
            );
            $this->assertFalse($item['original_is_directory']);
        }
    }

    /**
     * 명령 진입점도 미리 만들기 앞뒤 훅을 배치마다 한 번 발화한다. dry-run 은 만들지 않으므로 발화하지 않는다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_hooks_fire_once_per_batch, pregenerate_dry_run_fires_no_hooks
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_command_fires_hooks_per_batch_and_not_on_dry_run(): void
    {
        $this->currentFingerprint('sm');
        $this->useFakeSources(3);
        $calls = ['before' => [], 'after' => []];
        $before = function (...$args) use (&$calls) {
            $calls['before'][] = $args;
        };
        $after = function (...$args) use (&$calls) {
            $calls['after'][] = $args;
        };
        HookManager::addAction('core.thumbnail.before_pregenerate', $before);
        HookManager::addAction('core.thumbnail.after_pregenerate', $after);

        try {
            $this->artisan('thumbnails:pregenerate', ['--dry-run' => true])->assertExitCode(0);
            $this->assertSame(['before' => [], 'after' => []], $calls, 'dry-run 은 훅을 발화하지 않는다');

            $this->artisan('thumbnails:pregenerate', ['--preset' => ['sm']])->assertExitCode(0);
        } finally {
            HookManager::removeAction('core.thumbnail.before_pregenerate', $before);
            HookManager::removeAction('core.thumbnail.after_pregenerate', $after);
        }

        $this->assertCount(2, $calls['before'], '원본 3건 ÷ 배치 2 = 2회');
        $this->assertCount(2, $calls['after']);
        $this->assertSame([null, [], ['sm']], $calls['before'][0]);
        $this->assertSame(2, $calls['after'][0][0]['processed']);
        $this->assertSame([[], ['sm']], array_slice($calls['after'][0], 1));
    }

    /**
     * 시간 예산을 넘으면 처리한 자리의 커서를 돌려주고, 그 커서로 이어 돌면 빠짐없이 끝난다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_time_budget_returns_resume_cursor
     *
     * @return void 반환값 없음
     */
    public function test_batch_time_budget_returns_cursor_at_the_stop_point(): void
    {
        $this->currentFingerprint('sm');
        $this->useFakeSources(3);
        $pregenerator = app(ThumbnailPregenerator::class);

        $first = $pregenerator->batch(null, [], ['sm'], 20, false, 0.0);

        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, $first['generated']);
        $this->assertIsString($first['next_cursor']);
        $this->assertSame(3, $first['total']);

        $second = $pregenerator->batch($first['next_cursor'], [], ['sm'], 20, false, 0.0);
        $this->assertSame(1, $second['generated']);

        $third = $pregenerator->batch($second['next_cursor'], [], ['sm'], 20, false, 0.0);
        $this->assertSame(1, $third['generated']);
        $this->assertNull($third['next_cursor']);
        $this->assertCount(3, Storage::disk('thumbnails')->allFiles(ThumbnailStore::ROOT));
    }

    /**
     * 명령은 시간 예산을 쓰지 않는다 — 설정이 0 이어도 배치 크기대로 끝까지 돈다.
     *
     * @scenario surface=pregenerate_command
     *
     * @effects pregenerate_time_budget_returns_resume_cursor
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_command_ignores_the_time_budget(): void
    {
        Config::set('core.thumbnail.pregenerate_time_budget', 0);
        $this->currentFingerprint('sm');
        $this->useFakeSources(3);

        $this->artisan('thumbnails:pregenerate', ['--preset' => ['sm']])
            ->expectsOutputToContain(__('thumbnail.console.pregenerate.done', ['processed' => 3, 'generated' => 3, 'total' => 3]))
            ->assertExitCode(0);
    }
}
