<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\HookManager;
use App\Jobs\PurgeRetiredThumbnailDiskJob;
use App\Jobs\PurgeThumbnailsOnReenableJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPresetSettings;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 업로드 탭 이미지 썸네일 설정 저장 테스트 (#869)
 *
 * 규격 표 검증(422 는 칸 하나를 가리키는 키로), 본문 규격과 저장 디스크 검증, 저장 변환(mergeForSave) 후
 * 저장본과 config 반영, 저장 응답의 표가 같은 요청 안에서 새 값인지를 잰다. 설정 반영은 기본값이 아닌 값으로
 * 뒤집어 측정한다(기본값으로 재면 반영되지 않아도 통과한다). 설정 디스크는 TestCase 가 페이크로 바꾼다.
 */
class ThumbnailSettingsSaveTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    /**
     * 관리자 토큰을 만들고 썸네일 관련 config 를 기본값으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.content_preset', 'lg');
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');
        Config::set('core.thumbnail.prune_enabled', true);
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        HookManager::clearFilter('core.thumbnail.filter_presets');
        app(ThumbnailPresetRegistry::class)->flush();

        $this->token = $this->createAdminUser()->createToken('test-token')->plainTextToken;
    }

    /**
     * 규격 필터와 레지스트리 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_presets');
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 설정 읽기·수정 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
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
            'identifier' => 'thumb_admin_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminBase = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminBase->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 화면이 처음 불러온 표 (고정 5행 + og)
     *
     * @return list<array<string, mixed>> 표 행
     */
    private function tableRows(): array
    {
        app(ThumbnailPresetRegistry::class)->flush();

        return app(ThumbnailPresetSettings::class)->presentRows();
    }

    /**
     * 운영자 규격 행 하나
     *
     * @param  string  $name  이름
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 행
     */
    private function customRow(string $name, array $overrides = []): array
    {
        return self::row($name, $overrides);
    }

    /**
     * 업로드 탭 저장 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $upload  업로드 탭 값 (기본 필수값 위에 덮는다)
     * @return TestResponse 응답
     */
    private function saveUpload(array $upload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/settings', [
            '_tab' => 'upload',
            'upload' => array_merge([
                'max_file_size' => 20,
                'allowed_extensions' => 'jpg,png,gif,webp',
            ], $upload),
        ]);
    }

    /**
     * 규격 표 오류 6종이 422 로 막히고, 오류 키가 그 칸 하나를 가리킵니다.
     *
     * 키가 `upload.thumbnail_presets` 하나로 뭉치면 화면이 어느 행의 어느 칸이 틀렸는지 표시할 수 없다.
     *
     * @return array<string, array{0: callable, 1: callable}> 표를 바꾸는 함수와 기대 오류 키를 만드는 함수
     */
    public static function invalidTables(): array
    {
        return [
            '이름 중복' => [
                fn (array $rows) => [...$rows, self::row('banner'), self::row('banner')],
                fn (int $base) => 'upload.thumbnail_presets.'.($base + 1).'.name',
            ],
            '예약어 xs' => [
                fn (array $rows) => [...$rows, self::row('xs')],
                fn (int $base) => "upload.thumbnail_presets.{$base}.name",
            ],
            '형식 a-b' => [
                fn (array $rows) => [...$rows, self::row('a-b')],
                fn (int $base) => "upload.thumbnail_presets.{$base}.name",
            ],
            '가로 0' => [
                fn (array $rows) => [...$rows, self::row('banner', ['width' => 0])],
                fn (int $base) => "upload.thumbnail_presets.{$base}.width",
            ],
            '가로 5000' => [
                fn (array $rows) => [...$rows, self::row('banner', ['width' => 5000])],
                fn (int $base) => "upload.thumbnail_presets.{$base}.width",
            ],
            '행 31개' => [
                fn (array $rows) => [...$rows, ...array_map(fn (int $i) => self::row('custom_'.$i), range(1, 31 - count($rows)))],
                fn (int $base) => 'upload.thumbnail_presets',
            ],
        ];
    }

    /**
     * 데이터 공급자용 운영자 행 (정적)
     *
     * @param  string  $name  이름
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 행
     */
    private static function row(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name, 'source' => 'custom', 'kind' => 'custom', 'width' => 1000, 'height' => 300,
            'fit' => 'cover', 'anchor' => 'center', 'quality' => null, 'format' => 'original',
            'upscale' => false, 'pregenerate' => false, 'numeric' => false, 'enabled' => true,
        ], $overrides);
    }

    /**
     * 잘못된 규격 표는 422 이고 그 칸의 키로 오류가 납니다.
     *
     * @param  callable  $build  처음 표를 받아 잘못된 표를 돌려주는 함수
     * @param  callable  $errorKey  처음 표 행 수를 받아 기대 오류 키를 돌려주는 함수
     * @return void 반환값 없음
     *
     * @scenario surface=save_validation
     *
     * @effects preset_table_errors_point_at_a_field
     */
    #[Test]
    #[DataProvider('invalidTables')]
    public function invalid_preset_table_is_rejected_with_a_field_key(callable $build, callable $errorKey): void
    {
        $rows = $this->tableRows();
        $base = count($rows);

        $response = $this->saveUpload(['thumbnail_presets' => $build($rows)]);

        $response->assertStatus(422)->assertJsonValidationErrors([$errorKey($base)]);

        // 문구가 다국어 키 문자열 그대로 나가지 않는다
        foreach ((array) ($response->json('errors')[$errorKey($base)] ?? []) as $message) {
            $this->assertStringNotContainsString('validation.', (string) $message);
        }
    }

    /**
     * 파이프 인자에서 리터럴로 바뀌는 낱말 네 개
     *
     * @return array<string, array{0: string}> 낱말
     */
    public static function pipeLiteralWords(): array
    {
        return ['true' => ['true'], 'false' => ['false'], 'null' => ['null'], 'undefined' => ['undefined']];
    }

    /**
     * 운영자 규격 이름이 `true`, `false`, `null`, `undefined` 이면 422 이고 그 행의 이름 칸을 가리킵니다.
     *
     * 이 네 이름은 형식(`^[a-z][a-z0-9_]{1,31}$`)은 맞지만 레이아웃 `{{url | thumb('true')}}` 에서 파이프 인자가
     * 불리언, null, undefined 로 바뀌어 규격이 붙지 않는다(원본 또는 기본 sm 이 나간다). 저장 단계에서 막는다.
     *
     * @param  string  $word  낱말
     * @return void 반환값 없음
     *
     * @scenario layer=save_request
     *
     * @effects literal_word_preset_name_rejected
     */
    #[Test]
    #[DataProvider('pipeLiteralWords')]
    public function pipe_literal_word_preset_name_is_rejected(string $word): void
    {
        $rows = $this->tableRows();
        $base = count($rows);

        $response = $this->saveUpload(['thumbnail_presets' => [...$rows, self::row($word)]]);

        $response->assertStatus(422)->assertJsonValidationErrors(["upload.thumbnail_presets.{$base}.name"]);
        $message = (string) ($response->json('errors')["upload.thumbnail_presets.{$base}.name"][0] ?? '');
        $this->assertStringContainsString("'{$word}'", $message, '어느 이름이 왜 안 되는지 문구에 이름이 실린다');
        $this->assertStringNotContainsString('validation.', $message);
    }

    /**
     * 경계값(가로 16, 4096)과 표 전체 30행은 통과합니다 — 상한이 한 칸 안쪽으로 어긋나지 않았는지.
     *
     * @scenario surface=save_validation
     *
     * @effects preset_boundaries_accepted
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function boundary_values_are_accepted(): void
    {
        $rows = $this->tableRows();
        $rows[] = $this->customRow('tiny', ['width' => 16, 'height' => 16]);
        $rows[] = $this->customRow('huge', ['width' => 4096, 'height' => 4096]);

        while (count($rows) < 30) {
            $rows[] = $this->customRow('extra_'.count($rows));
        }

        $this->saveUpload(['thumbnail_presets' => $rows])->assertStatus(200);
    }

    /**
     * 고정 행의 이름을 바꿔 보내면 잠금 오류가 납니다.
     *
     * @scenario surface=save_validation
     *
     * @effects locked_row_rename_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function renaming_a_locked_row_is_rejected(): void
    {
        $rows = $this->tableRows();
        $mdIndex = array_search('md', array_column($rows, 'name'), true);
        $rows[$mdIndex]['name'] = 'medium';

        $this->saveUpload(['thumbnail_presets' => $rows])
            ->assertStatus(422)
            ->assertJsonValidationErrors(["upload.thumbnail_presets.{$mdIndex}.name" => __('validation.settings.thumbnail_preset_name_locked')]);
    }

    /**
     * og 행은 SEO 탭 크기(8000px 까지)를 그대로 실어 보내도 막히지 않습니다.
     *
     * @scenario surface=save_validation
     *
     * @effects og_row_size_not_validated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function og_row_size_is_not_validated(): void
    {
        $rows = $this->tableRows();
        $ogIndex = array_search('og', array_column($rows, 'name'), true);
        $rows[$ogIndex]['width'] = 6000;

        $this->saveUpload(['thumbnail_presets' => $rows])->assertStatus(200);
    }

    /**
     * 본문 규격은 저장 뒤 켜져 있을 규격만 받습니다 — 같은 요청의 표 기준.
     *
     * @scenario surface=save_validation
     *
     * @effects content_preset_must_be_enabled_after_save
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function content_preset_must_be_an_enabled_preset_of_the_submitted_table(): void
    {
        $this->saveUpload(['thumbnail_content_preset' => 'nonexistent'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.thumbnail_content_preset']);

        // 같은 요청에서 추가한 규격은 바로 고를 수 있다
        $rows = [...$this->tableRows(), $this->customRow('article')];
        $this->saveUpload(['thumbnail_presets' => $rows, 'thumbnail_content_preset' => 'article'])->assertStatus(200);

        // 같은 요청에서 끈 규격은 고를 수 없다
        $rows = $this->tableRows();
        $rows[array_search('lg', array_column($rows, 'name'), true)]['enabled'] = false;
        $this->saveUpload(['thumbnail_presets' => $rows, 'thumbnail_content_preset' => 'lg'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.thumbnail_content_preset']);
    }

    /**
     * 권한이 걸린 이미지 썸네일은 공개 디스크에 둘 수 없고, 공개 쪽은 `none` 을 고를 수 없습니다.
     *
     * @scenario surface=save_validation
     *
     * @effects thumbnail_disks_limited_per_kind
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function thumbnail_disks_are_limited_per_kind(): void
    {
        $this->saveUpload(['thumbnail_disk_gated' => 'public'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.thumbnail_disk_gated']);

        $this->saveUpload(['thumbnail_disk_public' => 'none'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['upload.thumbnail_disk_public']);

        $this->saveUpload(['thumbnail_disk_public' => 'public', 'thumbnail_disk_gated' => 's3'])->assertStatus(200);
    }

    /**
     * 플러그인 디스크 넷을 카탈로그에 올립니다 — 비공개 첨부 저장소 디스크 `vault`(정의 있음)와 `ghost`(정의 없음),
     * 공개 자산 디스크이면서 비공개 카탈로그에도 올린 `cloudfront`, 비공개 카탈로그에 끼워 넣은 `public`.
     *
     * @return callable(): void 등록한 필터를 걷는 함수
     */
    private function registerPluginDisks(): callable
    {
        Config::set('filesystems.disks.vault', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/vault')]);
        Config::set('filesystems.disks.cloudfront', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/cloudfront'), 'url' => 'https://cdn.test']);
        Config::set('filesystems.disks.ghost', null);

        $private = function (array $drivers): array {
            foreach (['vault', 'ghost', 'cloudfront', 'public'] as $id) {
                $drivers[] = ['id' => $id, 'label' => ['ko' => "플러그인 {$id}", 'en' => "Plugin {$id}"], 'provider' => 'test-plugin'];
            }

            return $drivers;
        };
        $public = function (array $drivers): array {
            $drivers[] = ['id' => 'cloudfront', 'label' => ['ko' => 'CloudFront', 'en' => 'CloudFront'], 'provider' => 'test-plugin'];

            return $drivers;
        };

        HookManager::addFilter('core.settings.available_private_attachment_drivers', $private);
        HookManager::addFilter('core.settings.available_public_asset_drivers', $public);

        return function () use ($private, $public): void {
            HookManager::removeFilter('core.settings.available_private_attachment_drivers', $private);
            HookManager::removeFilter('core.settings.available_public_asset_drivers', $public);
        };
    }

    /**
     * 권한이 걸린 이미지 썸네일 선택지 = thumbnails, s3, 비공개 첨부 저장소 카탈로그 디스크.
     * 공개 자산 디스크(`public`, `cloudfront`)는 비공개 카탈로그에 함께 올라와도 빠지고, 정의 없는 디스크(`ghost`)도 빠진다.
     *
     * @scenario surface=admin_response
     *
     * @effects gated_thumbnail_disk_options_include_private_catalog
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function gated_thumbnail_disk_options_include_private_attachment_catalog(): void
    {
        $unregister = $this->registerPluginDisks();

        try {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ])->getJson('/api/admin/settings');

            $response->assertStatus(200);
            $options = array_column($response->json('data._meta.thumbnail_disk_options.gated'), null, 'id');

            $this->assertSame(['thumbnails', 's3', 'vault'], array_keys($options));
            $this->assertSame('플러그인 vault', $options['vault']['label']['ko'], '플러그인 디스크는 카탈로그 라벨을 쓴다');
            $this->assertSame(__('settings.thumbnail_disks.s3', [], 'ko'), $options['s3']['label']['ko'], '코어 s3 는 썸네일 전용 라벨을 쓴다');

            // 공개 쪽 선택지는 그대로 공개 자산 카탈로그다
            $this->assertContains('cloudfront', array_column($response->json('data._meta.thumbnail_disk_options.public'), 'id'));
            $this->assertNotContains('vault', array_column($response->json('data._meta.thumbnail_disk_options.public'), 'id'));
        } finally {
            $unregister();
        }
    }

    /**
     * 권한이 걸린 이미지 썸네일 저장은 선택지와 같은 목록으로 검증합니다 — 비공개 카탈로그 디스크는 저장되고
     * 공개 자산 디스크(`public`, 플러그인 `cloudfront`)와 정의 없는 디스크는 422 입니다.
     *
     * @scenario surface=save_validation
     *
     * @effects thumbnail_disks_limited_per_kind, gated_thumbnail_disk_options_include_private_catalog
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function gated_thumbnail_disk_save_follows_the_same_options(): void
    {
        $unregister = $this->registerPluginDisks();
        // 저장 위치를 바꾸면 옛 디스크 정리가 돈다 — 실제 디스크를 건드리지 않게 가짜로 둔다
        Storage::fake('thumbnails');
        Storage::fake('vault');

        try {
            foreach (['public', 'cloudfront', 'ghost'] as $disk) {
                $response = $this->saveUpload(['thumbnail_disk_gated' => $disk]);
                $response->assertStatus(422)->assertJsonValidationErrors(['upload.thumbnail_disk_gated' => __('validation.settings.thumbnail_disk_gated_invalid')]);
            }

            $this->saveUpload(['thumbnail_disk_gated' => 'vault'])->assertStatus(200);
            $this->assertSame('vault', config('core.thumbnail.disk_gated'));
            $this->assertSame('vault', app(ThumbnailStore::class)->diskFor(true));
        } finally {
            $unregister();
        }
    }

    /**
     * 공개 자산 디스크와 권한 썸네일 디스크가 둘 다 s3 이면 저장은 되고 응답 warnings 에 같은 버킷 경고가 실립니다.
     * 어느 한쪽만 s3 이면 경고가 없습니다.
     *
     * @scenario surface=save_storage
     *
     * @effects gated_thumbnail_same_bucket_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function gated_thumbnail_on_the_public_asset_bucket_returns_a_warning(): void
    {
        $warningsFor = fn (TestResponse $response) => array_values(array_filter(
            (array) $response->json('data.warnings'),
            fn ($warning) => ($warning['key'] ?? null) === 'upload.thumbnail_disk_gated'
        ));
        Storage::fake('thumbnails');

        app(ConfigRepositoryInterface::class)->set('drivers.public_asset_disk', 'none');
        $this->assertSame([], $warningsFor($this->saveUpload(['thumbnail_disk_gated' => 's3'])->assertStatus(200)));

        app(ConfigRepositoryInterface::class)->set('drivers.public_asset_disk', 's3');
        $this->assertSame([], $warningsFor($this->saveUpload(['thumbnail_disk_gated' => 'thumbnails'])->assertStatus(200)));

        $warnings = $warningsFor($this->saveUpload(['thumbnail_disk_gated' => 's3'])->assertStatus(200));

        $this->assertCount(1, $warnings);
        $this->assertSame(__('settings.warnings.gated_thumbnail_same_as_public_asset', ['disk' => 's3']), $warnings[0]['message']);
        $this->assertStringNotContainsString('settings.warnings', $warnings[0]['message']);
    }

    /**
     * 저장하면 저장본은 저장 행 형태가 되고, config 와 저장 응답의 표가 같은 요청 안에서 새 값을 보입니다.
     *
     * 모든 값을 기본값이 아닌 쪽으로 뒤집어 잰다. 응답 전용 값(`thumbnail_presets_rows`)을 실어 보내도 저장되지 않는다.
     *
     * @scenario surface=save_storage
     *
     * @effects saved_values_reach_storage_config_and_response
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function saved_values_reach_storage_config_and_response(): void
    {
        $rows = $this->tableRows();
        $rows[array_search('md', array_column($rows, 'name'), true)]['width'] = 700;
        $rows[] = $this->customRow('banner');

        $response = $this->saveUpload([
            'thumbnail_enabled' => false,
            'thumbnail_prune_enabled' => false,
            'thumbnail_animated_original' => false,
            'thumbnail_content_preset' => 'banner',
            'thumbnail_disk_public' => 'public',
            'thumbnail_disk_gated' => 's3',
            'thumbnail_presets' => $rows,
            'thumbnail_presets_rows' => [['name' => 'junk']],
            'thumbnail_url_patterns' => ['/junk/*'],
        ]);

        $response->assertStatus(200);

        // 저장본 — 고정 5행 + 운영자 행, og 와 응답 전용 값 없음
        $stored = (new JsonConfigRepository)->getCategory('upload');
        $this->assertSame([...ThumbnailPresetRegistry::FIXED_SLOTS, 'banner'], array_column($stored['thumbnail_presets'], 'name'));
        $this->assertSame(700, array_column($stored['thumbnail_presets'], null, 'name')['md']['width']);
        $this->assertArrayNotHasKey('thumbnail_presets_rows', $stored);
        $this->assertArrayNotHasKey('thumbnail_url_patterns', $stored);

        // 같은 요청 안의 config
        $this->assertFalse(config('core.thumbnail.enabled'));
        $this->assertFalse(config('core.thumbnail.prune_enabled'));
        $this->assertFalse(config('core.thumbnail.animated_original'));
        $this->assertFalse($stored['thumbnail_animated_original']);
        $this->assertSame('banner', config('core.thumbnail.content_preset'));
        $this->assertSame('public', config('core.thumbnail.disk_public'));
        $this->assertSame('s3', config('core.thumbnail.disk_gated'));

        // 저장 응답의 표가 새 값이다 (옛 표로 되돌아가면 화면이 저장 직후 값을 잃는다)
        $responseRows = array_column($response->json('data.settings.upload.thumbnail_presets_rows'), null, 'name');
        $this->assertSame(700, $responseRows['md']['width']);
        $this->assertSame('custom', $responseRows['banner']['kind']);
        $this->assertSame(
            $response->json('data.settings.upload.thumbnail_presets_rows'),
            $response->json('data.settings.upload.thumbnail_presets'),
        );

        // 다음 부팅의 주입(SettingsServiceProvider::applyUploadConfig)도 같은 값을 넣는다
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.animated_original', true);
        Config::set('core.thumbnail.content_preset', 'lg');
        Config::set('core.thumbnail.presets', null);

        $provider = new SettingsServiceProvider($this->app);
        (new ReflectionMethod($provider, 'applyUploadConfig'))->invoke($provider, new JsonConfigRepository);

        $this->assertFalse(config('core.thumbnail.enabled'));
        $this->assertFalse(config('core.thumbnail.animated_original'));
        $this->assertSame('banner', config('core.thumbnail.content_preset'));
        $this->assertSame('banner', config('core.thumbnail.presets')[5]['name']);
    }

    /**
     * 조회 응답에 표와 썸네일 메타가 실립니다 (화면 담당이 읽는 키).
     *
     * @scenario surface=admin_response
     *
     * @effects admin_response_carries_table_and_disk_options
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function index_response_carries_table_and_thumbnail_meta(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $this->assertSame(
            [...ThumbnailPresetRegistry::FIXED_SLOTS, 'og'],
            array_column($response->json('data.upload.thumbnail_presets_rows'), 'name')
        );
        $this->assertIsBool($response->json('data._meta.thumbnail_engine_available'));

        $public = array_column($response->json('data._meta.thumbnail_disk_options.public'), 'id');
        $gated = array_column($response->json('data._meta.thumbnail_disk_options.gated'), 'id');

        $this->assertSame('thumbnails', $public[0]);
        $this->assertContains('public', $public);
        $this->assertNotContains('none', $public);
        $this->assertSame(['thumbnails', 's3'], $gated);
        $this->assertArrayHasKey('ko', $response->json('data._meta.thumbnail_disk_options.public.0.label'));
    }

    /**
     * 규격 표는 단건 저장 경로로 쓰지 못합니다 — 행 검증·고정 슬롯 보충을 건너뛰기 때문입니다.
     *
     * @scenario surface=save_validation
     *
     * @effects single_key_save_of_preset_table_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function preset_table_cannot_be_saved_through_single_key_route(): void
    {
        $before = app(JsonConfigRepository::class)->getCategory('upload')['thumbnail_presets'] ?? null;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/settings/upload.thumbnail_presets', [
            'value' => [['name' => 'xs', 'width' => 0]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['value']);
        $this->assertSame($before, app(JsonConfigRepository::class)->getCategory('upload')['thumbnail_presets'] ?? null);
    }

    /**
     * 썸네일 저장 디스크·본문 규격·이미지 엔진도 단건 저장 경로로 쓰지 못합니다 — 탭 저장의 허용 목록 검사를 건너뛰기 때문입니다.
     *
     * @scenario surface=save_validation
     *
     * @effects single_key_save_of_thumbnail_allowlisted_keys_is_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function allowlisted_thumbnail_keys_cannot_be_saved_through_single_key_route(): void
    {
        foreach (['upload.thumbnail_disk_public' => 'settings', 'upload.thumbnail_disk_gated' => 'public', 'upload.thumbnail_content_preset' => 'nope', 'drivers.image_driver' => 'imagick'] as $key => $value) {
            $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ])->putJson('/api/admin/settings/'.$key, ['value' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['value']);
        }
    }

    /**
     * 저장 위치를 바꿔도 저장 요청은 옛 디스크를 훑지 않는다 — 큐가 있으면 작업으로 넘기고, 파일은 그 작업이 지운다.
     *
     * @scenario surface=save_storage
     *
     * @effects retired_disk_purge_leaves_the_save_request
     *
     * @return void 반환값 없음
     */
    public function test_retired_disk_purge_is_queued_outside_the_save_request(): void
    {
        Storage::fake('public');
        Storage::fake('thumbnails');
        Storage::disk('thumbnails')->put('thumbnails/attachments/a.jpg/sm_0123abcd.jpg', 'x');
        Config::set('queue.default', 'database');
        Queue::fake();

        $this->saveUpload(['thumbnail_disk_public' => 'public', 'thumbnail_disk_gated' => 's3'])->assertStatus(200);

        Storage::disk('thumbnails')->assertExists('thumbnails/attachments/a.jpg/sm_0123abcd.jpg');
        Queue::assertPushed(PurgeRetiredThumbnailDiskJob::class, fn (PurgeRetiredThumbnailDiskJob $job) => $job->disk === 'thumbnails');

        app()->call([new PurgeRetiredThumbnailDiskJob('thumbnails'), 'handle']);

        Storage::disk('thumbnails')->assertMissing('thumbnails/attachments/a.jpg/sm_0123abcd.jpg');
    }

    /**
     * 큐가 없는(sync) 사이트는 응답을 보낸 뒤 비운다 — 저장 응답은 파일 수와 무관하다.
     *
     * @scenario surface=save_storage
     *
     * @effects retired_disk_purge_leaves_the_save_request
     *
     * @return void 반환값 없음
     */
    public function test_retired_disk_purge_runs_after_the_response_without_a_queue(): void
    {
        Storage::fake('public');
        Storage::fake('thumbnails');
        Storage::disk('thumbnails')->put('thumbnails/attachments/a.jpg/sm_0123abcd.jpg', 'x');
        Config::set('queue.default', 'sync');

        $seen = null;
        $listener = function () use (&$seen) {
            $seen = Storage::disk('thumbnails')->exists('thumbnails/attachments/a.jpg/sm_0123abcd.jpg');
        };
        HookManager::addAction('core.settings.after_save', $listener);

        try {
            // 요청 시험 도우미는 응답 뒤 terminate 까지 돈다 — 훅 시점에는 남아 있고 끝나면 지워져 있어야 한다.
            $this->saveUpload(['thumbnail_disk_public' => 'public', 'thumbnail_disk_gated' => 's3'])->assertStatus(200);
        } finally {
            HookManager::removeAction('core.settings.after_save', $listener);
        }

        $this->assertTrue($seen, '저장 요청 안에서는 지우지 않는다');
        Storage::disk('thumbnails')->assertMissing('thumbnails/attachments/a.jpg/sm_0123abcd.jpg');
    }

    /**
     * 썸네일을 끈 동안에는 원본 put 이 파생본을 무효화하지 않으므로, 다시 켜면 지금 쓰는 썸네일 디스크를 비운다.
     * 비우기는 파일 수만큼 걸려 저장 요청 밖(큐)에서 돈다. 이미 켜진 채 저장하거나 끄는 저장은 비우지 않는다.
     *
     * @scenario surface=save_storage
     *
     * @effects reenable_purges_stale_derivatives
     *
     * @return void 반환값 없음
     */
    public function test_reenabling_thumbnails_purges_current_disks_outside_the_save_request(): void
    {
        Storage::fake('thumbnails');
        $derivative = 'thumbnails/attachments/a.jpg/sm_0123abcd.jpg';
        Storage::disk('thumbnails')->put($derivative, 'x');
        Config::set('queue.default', 'database');
        Queue::fake();

        $this->saveUpload(['thumbnail_enabled' => true])->assertStatus(200);
        $this->saveUpload(['thumbnail_enabled' => false])->assertStatus(200);
        Queue::assertNotPushed(PurgeThumbnailsOnReenableJob::class);

        $this->saveUpload(['thumbnail_enabled' => true])->assertStatus(200);

        Storage::disk('thumbnails')->assertExists($derivative);
        Queue::assertPushed(PurgeThumbnailsOnReenableJob::class, 1);

        app()->call([new PurgeThumbnailsOnReenableJob, 'handle']);

        Storage::disk('thumbnails')->assertMissing($derivative);
    }
}
