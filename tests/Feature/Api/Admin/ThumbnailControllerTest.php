<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ThumbnailAdminService;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Thumbnails\Support\FakeThumbnailSourceProvider;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * 썸네일 캐시 관리 API 테스트 — stats·purge·pregenerate
 *
 * 저장 위치는 원본 분류(공개·권한)별 두 줄이다. 두 분류가 같은 디스크를 써도 따로 세고 따로 지워야 한다 —
 * 공개만 비웠는데 권한 이미지 썸네일이 사라지면 안 된다. 분류는 원본 제공자 판정을 따른다:
 * 코어 첨부(`attachments` 디스크의 `Y/m/d/파일`)는 권한, 레이아웃 첨부(`template-layout-attachments/…`)는 공개.
 */
class ThumbnailControllerTest extends TestCase
{
    use DatabaseTransactions;

    /** 권한 원본(코어 첨부) 파생본 디렉터리 */
    private const GATED_DIR = 'thumbnails/attachments/2026/11/05/gated.png';

    /** 공개 원본(레이아웃 첨부) 파생본 디렉터리 */
    private const PUBLIC_DIR = 'thumbnails/attachments/template-layout-attachments/sirsoft-basic/hero.png';

    /** @var list<list<array<string, mixed>>> 퍼지 훅이 받은 묶음 */
    private array $purges = [];

    /** @var \Closure|null 퍼지 훅 기록기 */
    private ?\Closure $purgeListener = null;

    /** @var \Closure|null 시험용 원본 제공자 필터 */
    private ?\Closure $providerFilter = null;

    /**
     * 디스크를 가짜로 바꾸고 두 저장 위치가 같은 디스크를 쓰게 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        foreach (['attachments', 'thumbnails', 'public'] as $disk) {
            Storage::fake($disk);
        }

        Config::set('attachment.disk', 'attachments');
        Config::set('core.image.driver', 'gd');
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
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
     * 관리자를 만듭니다 (admin 역할 + 지정 권한).
     *
     * @param  list<string>  $permissions  권한 식별자
     * @return User 관리자
     */
    private function admin(array $permissions = ['core.settings.read', 'core.settings.update']): User
    {
        $user = User::factory()->create();
        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'thumbnail_test_'.uniqid(),
            'name' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'description' => json_encode(['ko' => '시험 관리자', 'en' => 'Test admin']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 관리자 토큰을 단 요청 헤더
     *
     * @param  User  $user  관리자
     * @return array<string, string> 헤더
     */
    private function headers(User $user): array
    {
        // 한 테스트에서 다른 관리자로 다시 부를 때 앞 요청의 인증 사용자가 가드에 남지 않게 한다.
        app('auth')->forgetGuards();

        return [
            'Authorization' => 'Bearer '.$user->createToken('thumbnail-test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 파생본 파일 하나를 심습니다.
     *
     * @param  string  $disk  썸네일 디스크
     * @param  string  $directory  원본의 파생본 디렉터리
     * @param  string  $preset  규격 이름
     * @return string 키
     */
    private function plant(string $disk, string $directory, string $preset = 'sm'): string
    {
        $key = "{$directory}/{$preset}_0123abcd.png";
        Storage::disk($disk)->put($key, str_repeat('x', 10));

        return $key;
    }

    /**
     * stats 는 저장 위치별 두 항목을 낸다 — 같은 디스크를 써도 분류별로 따로 센다.
     *
     * @scenario surface=stats_api
     *
     * @effects stats_returns_two_locations_by_classification, stats_labels_are_translated
     *
     * @return void 반환값 없음
     */
    public function test_stats_returns_two_locations_counted_by_classification(): void
    {
        $this->plant('thumbnails', self::PUBLIC_DIR);
        $this->plant('thumbnails', self::GATED_DIR);
        $this->plant('thumbnails', self::GATED_DIR, 'md');

        $response = $this->getJson('/api/admin/thumbnails/stats', $this->headers($this->admin()));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.locations')
            ->assertJsonPath('data.locations.0.kind', 'public')
            ->assertJsonPath('data.locations.0.disk', 'thumbnails')
            ->assertJsonPath('data.locations.0.disk_label', __('thumbnail.disks.thumbnails'))
            ->assertJsonPath('data.locations.0.remote', false)
            ->assertJsonPath('data.locations.0.count', 1)
            ->assertJsonPath('data.locations.0.bytes', 10)
            ->assertJsonPath('data.locations.0.by_preset.sm', 1)
            ->assertJsonPath('data.locations.1.kind', 'gated')
            ->assertJsonPath('data.locations.1.count', 2)
            ->assertJsonPath('data.locations.1.bytes', 20)
            ->assertJsonPath('data.locations.1.by_preset.md', 1);

        $presets = $response->json('data.presets');
        foreach (ThumbnailPresetRegistry::FIXED_SLOTS as $slot) {
            $this->assertContains($slot, $presets);
        }

        $sources = collect($response->json('data.sources'))->keyBy('key');
        $this->assertSame(__('thumbnail.sources.core_attachment'), $sources['core.attachment']['label'] ?? null, '라벨은 번역된 문자열');
        $this->assertSame(__('thumbnail.sources.layout_attachment'), $sources['core.layout_attachment']['label'] ?? null);
    }

    /**
     * 원격 디스크(S3)는 목록을 세지 않고 `remote: true` 에 count·bytes null 이다.
     *
     * @scenario surface=stats_api
     *
     * @effects stats_remote_disk_not_counted
     *
     * @return void 반환값 없음
     */
    public function test_stats_does_not_count_remote_disk(): void
    {
        Config::set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'test', 'region' => 'ap-northeast-2', 'key' => 'k', 'secret' => 's']);
        Config::set('core.thumbnail.disk_public', 's3');

        $this->getJson('/api/admin/thumbnails/stats', $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.locations.0.disk', 's3')
            ->assertJsonPath('data.locations.0.remote', true)
            ->assertJsonPath('data.locations.0.count', null)
            ->assertJsonPath('data.locations.0.bytes', null)
            ->assertJsonPath('data.locations.1.remote', false);
    }

    /**
     * 두 위치가 같은 디스크를 쓸 때 공개만 비우면 권한 이미지 썸네일은 남는다. 권한만 비우면 그 반대다.
     *
     * @scenario surface=purge_api
     *
     * @effects purge_shared_disk_keeps_other_classification
     *
     * @return void 반환값 없음
     */
    public function test_purge_on_shared_disk_deletes_only_that_classification(): void
    {
        $public = $this->plant('thumbnails', self::PUBLIC_DIR);
        $gated = $this->plant('thumbnails', self::GATED_DIR);
        $headers = $this->headers($this->admin());

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'public'], $headers)
            ->assertOk()
            ->assertJsonPath('data.deleted.public', 1)
            ->assertJsonMissingPath('data.deleted.gated');

        Storage::disk('thumbnails')->assertMissing($public);
        Storage::disk('thumbnails')->assertExists($gated);

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'gated'], $headers)
            ->assertOk()
            ->assertJsonPath('data.deleted.gated', 1);

        Storage::disk('thumbnails')->assertMissing($gated);
    }

    /**
     * `preset` 을 주면 그 규격 파일만 지운다.
     *
     * @scenario surface=purge_api
     *
     * @effects purge_preset_deletes_only_that_preset
     *
     * @return void 반환값 없음
     */
    public function test_purge_with_preset_deletes_only_that_preset(): void
    {
        $sm = $this->plant('thumbnails', self::GATED_DIR, 'sm');
        $md = $this->plant('thumbnails', self::GATED_DIR, 'md');

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'gated', 'preset' => 'sm'], $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.deleted.gated', 1);

        Storage::disk('thumbnails')->assertMissing($sm);
        Storage::disk('thumbnails')->assertExists($md);
    }

    /**
     * `all` 은 두 위치를 모두 비우고 위치별 수를 낸다. 퍼지 훅은 요청 하나에 한 번이고, CDN 대상 경로는
     * 공개 자산 디스크(public)에 둔 공개 위치에만 실린다(비공개 디스크의 권한 위치는 null).
     *
     * @scenario surface=purge_api
     *
     * @effects purge_all_reports_both_locations, purge_keeps_originals_on_shared_public_disk, purge_hook_fires_once_with_cdn_prefix_only_on_public_asset_disk
     *
     * @return void 반환값 없음
     */
    public function test_purge_all_on_separate_disks_reports_both_and_fires_once(): void
    {
        Config::set('core.thumbnail.disk_public', 'public');
        $public = $this->plant('public', self::PUBLIC_DIR);
        $gated = $this->plant('thumbnails', self::GATED_DIR);
        $original = 'template-layout-attachments/sirsoft-basic/hero.png';
        Storage::disk('public')->put($original, 'original');

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'all'], $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.deleted.public', -1)
            ->assertJsonPath('data.deleted.gated', -1);

        Storage::disk('public')->assertMissing($public);
        // 공용 디스크를 골라도 원본은 남는다 (고정 루트 thumbnails/ 만 지움)
        Storage::disk('public')->assertExists($original);
        Storage::disk('thumbnails')->assertMissing($gated);

        $this->assertCount(1, $this->purges, '퍼지 훅은 요청 하나에 한 번');
        $byDisk = collect($this->purges[0])->keyBy('thumbnail_disk');
        $this->assertNotNull($byDisk['public']['public_url_prefix'] ?? null, '공개 자산 디스크는 CDN 대상 경로를 싣는다');
        $this->assertArrayHasKey('thumbnails', $byDisk->all(), '권한 위치 삭제도 퍼지 묶음에 들어간다');
        $this->assertNull($byDisk['thumbnails']['public_url_prefix'], '비공개 디스크는 CDN 대상 경로가 없다');
    }

    /**
     * 잘못된 저장 위치·없는 규격은 422.
     *
     * @scenario surface=purge_api
     *
     * @effects purge_validates_location_and_preset
     *
     * @return void 반환값 없음
     */
    public function test_purge_validates_location_and_preset(): void
    {
        $headers = $this->headers($this->admin());

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'everything'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'public', 'preset' => 'no_such_preset'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preset']);

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'public', 'cursor' => '2:abc'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cursor']);
    }

    /**
     * 같은 디스크를 쓰는 위치의 비우기는 파일 전체를 한 요청에서 훑지 않고 시간 예산만큼 걸어 커서를 돌려준다.
     * 예산 0 이면 한 번에 파일 하나만 보고 멈춘다. 커서로 끝까지 이어 부르면 그 분류 파일만 모두 지워지고 다른 분류는
     * 남는다. 직전 걸음에서 지운 파일이 사라져도 커서(마지막으로 본 경로)는 그 뒤부터 이어 걷는다.
     * 시작 훅 `core.thumbnail.before_purge` 는 첫 걸음에만 발화한다.
     *
     * @scenario surface=purge_api
     *
     * @effects purge_walks_in_bounded_steps, purge_shared_disk_keeps_other_classification
     *
     * @return void 반환값 없음
     */
    public function test_shared_disk_purge_walks_in_bounded_steps_with_a_cursor(): void
    {
        $public = [];
        $gated = [];

        foreach (['a', 'b', 'c'] as $name) {
            $public[] = $this->plant('thumbnails', "thumbnails/attachments/template-layout-attachments/sirsoft-basic/{$name}.png");
            $public[] = $this->plant('thumbnails', "thumbnails/attachments/template-layout-attachments/sirsoft-basic/{$name}.png", 'md');
            $gated[] = $this->plant('thumbnails', "thumbnails/attachments/2026/11/05/{$name}.png");
        }

        $started = 0;
        $listener = function () use (&$started) {
            $started++;
        };
        HookManager::addAction('core.thumbnail.before_purge', $listener);

        try {
            $service = app(ThumbnailAdminService::class);
            $cursor = null;
            $steps = 0;
            $deleted = 0;

            do {
                $result = $service->purge('public', null, $cursor, 0.0);
                $deleted += $result['deleted']['public'] ?? 0;
                $cursor = $result['next_cursor'];
                $steps++;
            } while ($cursor !== null && $steps < 50);
        } finally {
            HookManager::removeAction('core.thumbnail.before_purge', $listener);
        }

        $this->assertGreaterThanOrEqual(9, $steps, '예산 0 이면 걸음마다 파일 하나만 본다(파일 9개)');
        $this->assertSame(6, $deleted);
        $this->assertSame(1, $started, '시작 훅은 첫 걸음에만');

        foreach ($public as $file) {
            Storage::disk('thumbnails')->assertMissing($file);
        }

        foreach ($gated as $file) {
            Storage::disk('thumbnails')->assertExists($file);
        }
    }

    /**
     * 비우기 한 걸음의 시간 예산은 업로드 탭 「고급」 칸 값(`core.thumbnail.purge_time_budget`)을 따른다.
     * 0 으로 좁히면 API 한 번이 파일 하나만 보고 커서를 돌려준다(화면 칸 범위는 1~60, 시험만 0 으로 잰다).
     *
     * @scenario surface=purge_api
     *
     * @effects purge_walks_in_bounded_steps, purge_time_budget_follows_setting
     *
     * @return void 반환값 없음
     */
    public function test_purge_step_budget_follows_the_setting(): void
    {
        Config::set('core.thumbnail.purge_time_budget', 0);
        $this->plant('thumbnails', 'thumbnails/attachments/template-layout-attachments/sirsoft-basic/a.png');
        $this->plant('thumbnails', 'thumbnails/attachments/template-layout-attachments/sirsoft-basic/b.png');

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'public'], $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.deleted.public', 1)
            ->assertJsonPath('data.next_cursor', fn ($cursor) => is_string($cursor) && str_starts_with($cursor, '0:'));
    }

    /**
     * API 는 걸음 결과로 `next_cursor` 를 돌려주고, 받은 커서를 넘기면 그 자리부터 이어 지운다.
     * 두 위치(all)의 커서는 위치 순번을 앞에 달아 다음 걸음이 어느 위치부터 걸을지 안다.
     *
     * @scenario surface=purge_api
     *
     * @effects purge_walks_in_bounded_steps, purge_all_reports_both_locations
     *
     * @return void 반환값 없음
     */
    public function test_purge_api_continues_from_the_returned_cursor(): void
    {
        $publicA = $this->plant('thumbnails', 'thumbnails/attachments/template-layout-attachments/sirsoft-basic/a.png');
        $publicB = $this->plant('thumbnails', 'thumbnails/attachments/template-layout-attachments/sirsoft-basic/b.png');
        $gated = $this->plant('thumbnails', self::GATED_DIR);

        $first = app(ThumbnailAdminService::class)->purge('all', null, null, 0.0);
        $this->assertNotNull($first['next_cursor']);
        $this->assertMatchesRegularExpression('/^[01]:[A-Za-z0-9_-]*$/', $first['next_cursor']);

        $response = $this->postJson('/api/admin/thumbnails/purge', ['location' => 'all', 'cursor' => $first['next_cursor']], $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.next_cursor', null);

        $this->assertSame(2, ($first['deleted']['public'] ?? 0) + ($response->json('data.deleted.public') ?? 0));
        $this->assertSame(1, $response->json('data.deleted.gated'));

        Storage::disk('thumbnails')->assertMissing($publicA);
        Storage::disk('thumbnails')->assertMissing($publicB);
        Storage::disk('thumbnails')->assertMissing($gated);
    }

    /**
     * 설정 권한이 없는 관리자는 조회 403, 조회 권한만 있으면 비우기·미리 만들기 403.
     *
     * @scenario surface=stats_api
     *
     * @effects settings_permissions_gate_every_endpoint
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_settings_permission_is_forbidden(): void
    {
        $gated = $this->plant('thumbnails', self::GATED_DIR);

        $this->getJson('/api/admin/thumbnails/stats', $this->headers($this->admin([])))->assertForbidden();

        $readOnly = $this->headers($this->admin(['core.settings.read']));
        $this->getJson('/api/admin/thumbnails/stats', $readOnly)->assertOk();
        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'all'], $readOnly)->assertForbidden();
        $this->postJson('/api/admin/thumbnails/pregenerate', [], $readOnly)->assertForbidden();

        Storage::disk('thumbnails')->assertExists($gated);
    }

    /**
     * 미리 만들기는 한 번에 배치 크기(2)만 처리하고 next_cursor 로 이어 간다. 마지막 호출에서 null.
     *
     * @scenario surface=pregenerate_api
     *
     * @effects pregenerate_api_batches_with_cursor
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_processes_one_batch_and_resumes_with_cursor(): void
    {
        $engine = app(ImageProcessorResolver::class)->resolve();

        if ($engine === null) {
            $this->markTestSkipped('이미지 처리 엔진(GD)이 없는 환경');
        }

        $sources = [];
        for ($i = 1; $i <= 3; $i++) {
            $path = "fake/api-{$i}.png";
            Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::halves(400, 300, 'vertical', [255, 0, 0], [0, 0, 255])));
            $sources[] = new ThumbnailSource('attachments', $path, 'image/png', false, 400, 300);
        }
        $provider = new FakeThumbnailSourceProvider($sources);
        $this->providerFilter = fn () => [$provider];
        HookManager::addFilter('core.thumbnail.filter_source_providers', $this->providerFilter, 999);

        $headers = $this->headers($this->admin());

        $first = $this->postJson('/api/admin/thumbnails/pregenerate', ['presets' => ['sm'], 'sources' => ['test.fake']], $headers)
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.generated', 2)
            ->assertJsonPath('data.total', 3);

        $cursor = $first->json('data.next_cursor');
        $this->assertIsString($cursor);

        $this->postJson('/api/admin/thumbnails/pregenerate', ['presets' => ['sm'], 'cursor' => $cursor], $headers)
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.generated', 1)
            ->assertJsonPath('data.next_cursor', null);

        $fingerprint = app(ThumbnailPresetRegistry::class)->find('sm')->fingerprint($engine->id());
        foreach ($sources as $source) {
            Storage::disk('thumbnails')->assertExists(ThumbnailStore::ROOT."/attachments/{$source->path}/sm_{$fingerprint}.png");
        }
    }

    /**
     * 모르는 원본 종류·규격·형식이 틀린 커서는 422 — 「전부」로 해석하지 않는다.
     *
     * @scenario surface=pregenerate_api
     *
     * @effects pregenerate_api_validates_inputs
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_validates_inputs(): void
    {
        $headers = $this->headers($this->admin());

        $this->postJson('/api/admin/thumbnails/pregenerate', ['sources' => ['nope']], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sources.0']);

        $this->postJson('/api/admin/thumbnails/pregenerate', ['presets' => ['nope']], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['presets.0']);

        $this->postJson('/api/admin/thumbnails/pregenerate', ['cursor' => 'not a cursor'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cursor']);
    }

    /**
     * 사용량은 짧게 캐시해 탭을 열 때마다 디스크를 훑지 않고, 비우기·미리 만들기 뒤에는 새로 센다.
     *
     * @scenario surface=stats_api
     *
     * @effects stats_cached_until_purge_or_pregenerate
     *
     * @return void 반환값 없음
     */
    public function test_stats_are_cached_until_purge_or_pregenerate(): void
    {
        $headers = $this->headers($this->admin());
        $this->plant('thumbnails', self::PUBLIC_DIR, 'sm');

        $this->getJson('/api/admin/thumbnails/stats', $headers)->assertOk()->assertJsonPath('data.locations.0.count', 1);

        $this->plant('thumbnails', self::PUBLIC_DIR, 'md');
        $this->getJson('/api/admin/thumbnails/stats', $headers)->assertOk()->assertJsonPath('data.locations.0.count', 1);

        $provider = new FakeThumbnailSourceProvider([]);
        $filter = fn () => [$provider];
        HookManager::addFilter('core.thumbnail.filter_source_providers', $filter, 999);
        $this->postJson('/api/admin/thumbnails/pregenerate', [], $headers)->assertOk();
        HookManager::removeFilter('core.thumbnail.filter_source_providers', $filter);

        $this->getJson('/api/admin/thumbnails/stats', $headers)->assertOk()->assertJsonPath('data.locations.0.count', 2);

        $this->postJson('/api/admin/thumbnails/purge', ['location' => 'public'], $headers)->assertOk();
        $this->getJson('/api/admin/thumbnails/stats', $headers)->assertOk()->assertJsonPath('data.locations.0.count', 0);
    }

    /**
     * 관리 화면 진입점도 미리 만들기 앞뒤 훅을 정확히 한 번씩 발화한다 — 발화 지점을 옮겨도 두 번 나가지 않는다.
     *
     * @scenario surface=pregenerate_api
     *
     * @effects pregenerate_hooks_fire_once_per_batch
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_api_fires_hooks_once(): void
    {
        $provider = new FakeThumbnailSourceProvider([]);
        $this->providerFilter = fn () => [$provider];
        HookManager::addFilter('core.thumbnail.filter_source_providers', $this->providerFilter, 999);
        $counts = ['before' => 0, 'after' => 0];
        $before = function () use (&$counts) {
            $counts['before']++;
        };
        $after = function () use (&$counts) {
            $counts['after']++;
        };
        HookManager::addAction('core.thumbnail.before_pregenerate', $before);
        HookManager::addAction('core.thumbnail.after_pregenerate', $after);

        try {
            $this->postJson('/api/admin/thumbnails/pregenerate', [], $this->headers($this->admin()))->assertOk();
        } finally {
            HookManager::removeAction('core.thumbnail.before_pregenerate', $before);
            HookManager::removeAction('core.thumbnail.after_pregenerate', $after);
        }

        $this->assertSame(['before' => 1, 'after' => 1], $counts);
    }

    /**
     * 관리 화면 한 번분은 시간 예산을 넘으면 배치 크기보다 적게 처리하고 그 자리 커서를 돌려준다.
     *
     * @scenario surface=pregenerate_api
     *
     * @effects pregenerate_time_budget_returns_resume_cursor
     *
     * @return void 반환값 없음
     */
    public function test_pregenerate_api_stops_at_the_time_budget(): void
    {
        Config::set('core.thumbnail.pregenerate_time_budget', 0);
        $sources = [];

        for ($i = 1; $i <= 3; $i++) {
            $path = "fake/budget-{$i}.png";
            Storage::disk('attachments')->put($path, (string) file_get_contents(TestImages::halves(400, 300, 'vertical', [255, 0, 0], [0, 0, 255])));
            $sources[] = new ThumbnailSource('attachments', $path, 'image/png', false, 400, 300);
        }

        $provider = new FakeThumbnailSourceProvider($sources);
        $this->providerFilter = fn () => [$provider];
        HookManager::addFilter('core.thumbnail.filter_source_providers', $this->providerFilter, 999);

        $response = $this->postJson('/api/admin/thumbnails/pregenerate', ['presets' => ['sm']], $this->headers($this->admin()))
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->assertIsString($response->json('data.next_cursor'));
    }
}
