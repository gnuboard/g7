<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Attachment;
use App\Models\IdentityPolicy;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\User;
use App\Repositories\IdentityPolicyRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Thumbnails\Support\TestImages;

/**
 * hook 범위 본인인증 정책 색인(`IdentityPolicyRepository::getHookScopeIndex()`)의 무효화.
 *
 * 리스너는 이 색인에 없는 훅을 정책 표 조회 없이 지나간다. 색인이 낡으면 켜진 정책이 건너뛰어진다(열린 채 통과).
 * 정책 저장, 확장 활성화·비활성화, 업데이트 뒤 상태 복원(성공·롤백)이 모두 색인을 비워야 한다. 업데이트 중
 * (`updating`) 확장의 정책은 색인에서 빠지므로, 그 창에 색인이 다시 만들어진 뒤 상태 복원이 비우지 않으면 캐시 수명
 * 동안 그 확장의 정책이 걸리지 않는다. 색인 조회가 실패하면 리스너는 종전처럼 정책 표로 판정한다.
 */
class IdentityHookScopeIndexInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    /** 시험 확장 식별자 */
    private const EXTENSION = 'g7test-idvindex';

    /** 시험 대상 훅 (코어 첨부 이미지 경로에서 발화) */
    private const HOOK = 'core.attachment.before_download_action';

    /**
     * 디스크를 가짜로 바꾸고 정책 색인 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Config::set('attachment.disk', 'attachments');
        app(CacheInterface::class)->flush();
    }

    /**
     * 임시 이미지를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        TestImages::cleanup();

        parent::tearDown();
    }

    /**
     * 코어 첨부 이미지 하나를 심고 서빙 주소를 돌려줍니다.
     *
     * @return string 서빙 주소
     */
    private function image(): string
    {
        $bytes = (string) file_get_contents(TestImages::solid(40, 30, 'jpeg'));
        $path = '2026/11/05/'.Str::uuid().'.jpg';
        Storage::disk('attachments')->put($path, $bytes);

        $row = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => $path,
            'stored_filename' => basename($path),
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
        ]);

        return '/api/attachment/'.$row->hash;
    }

    /**
     * hook 범위 정책을 만듭니다.
     *
     * @param  string  $sourceType  출처 (core·module·plugin)
     * @param  bool  $enabled  켜짐 여부
     * @return IdentityPolicy 정책
     */
    private function policy(string $sourceType = 'core', bool $enabled = true): IdentityPolicy
    {
        return IdentityPolicy::create([
            'key' => 'g7test.idvindex.'.Str::random(6),
            'scope' => 'hook',
            'target' => self::HOOK,
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => $enabled,
            'priority' => 100,
            'conditions' => [],
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'source_type' => $sourceType,
            'source_identifier' => $sourceType === 'core' ? 'core' : self::EXTENSION,
        ]);
    }

    /**
     * 시험 확장 행을 만듭니다.
     *
     * @param  string  $type  module 또는 plugin
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function extension(string $type, ExtensionStatus $status): void
    {
        $model = $type === 'module' ? Module::class : Plugin::class;
        $model::create([
            'identifier' => self::EXTENSION,
            'vendor' => 'g7test',
            'name' => ['ko' => '시험', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => $status->value,
        ]);
    }

    /**
     * 확장 관리자가 상태를 쓰고 상태 캐시를 비우는 두 문장을 그대로 따라 합니다 (업데이트 뒤 상태 복원·롤백, 활성화, 비활성화 공통).
     *
     * @param  string  $type  module 또는 plugin
     * @param  ExtensionStatus  $status  새 상태
     * @return void 반환값 없음
     */
    private function transition(string $type, ExtensionStatus $status): void
    {
        if ($type === 'module') {
            app(ModuleRepositoryInterface::class)->updateByIdentifier(self::EXTENSION, ['status' => $status->value, 'updated_at' => now()]);
            ModuleManager::invalidateModuleStatusCache();
        } else {
            app(PluginRepositoryInterface::class)->updateByIdentifier(self::EXTENSION, ['status' => $status->value, 'updated_at' => now()]);
            PluginManager::invalidatePluginStatusCache();
        }
    }

    /**
     * 색인이 그 훅에 정책을 갖고 있는지
     *
     * @return bool 있으면 true
     */
    private function indexed(): bool
    {
        return isset(app(IdentityPolicyRepositoryInterface::class)->getHookScopeIndex()[self::HOOK]);
    }

    /**
     * 확장 종류 표
     *
     * @return array<string, array{0:string}> 종류
     */
    public static function extensionProvider(): array
    {
        return ['module' => ['module'], 'plugin' => ['plugin']];
    }

    /**
     * 색인을 데운 뒤 정책을 켜면(모델 저장) 색인이 비워져 다음 요청부터 정책이 걸린다.
     *
     * @scenario surface=hook_index
     *
     * @effects hook_index_invalidated_on_policy_and_extension_changes
     *
     * @return void 반환값 없음
     */
    public function test_enabling_a_policy_after_the_index_is_warm_takes_effect(): void
    {
        $policy = $this->policy(enabled: false);
        $this->assertFalse($this->indexed());

        $policy->update(['enabled' => true]);

        $this->assertTrue($this->indexed());
        $this->actingAs(User::factory()->create())->get($this->image())->assertStatus(428);
    }

    /**
     * 업데이트 중(`updating`)에 색인이 다시 만들어져도 상태 복원(성공·롤백 공통)이 색인을 비워 그 확장의 정책이 다시 걸린다.
     *
     * @scenario surface=hook_index
     *
     * @effects hook_index_invalidated_on_policy_and_extension_changes
     *
     * @param  string  $type  module 또는 plugin
     * @return void 반환값 없음
     */
    #[DataProvider('extensionProvider')]
    public function test_restoring_status_after_update_rejoins_the_index(string $type): void
    {
        $this->extension($type, ExtensionStatus::Updating);
        $this->policy($type);
        $this->assertFalse($this->indexed(), '업데이트 중 확장의 정책이 색인에 들었다');

        $this->transition($type, ExtensionStatus::Active);

        $this->assertTrue($this->indexed(), '상태를 복원했는데 색인이 낡은 채다');
        $this->actingAs(User::factory()->create())->get($this->image())->assertStatus(428);
    }

    /**
     * 활성 확장을 비활성화하면 색인에서 빠지고, 다시 켜면 들어온다.
     *
     * @scenario surface=hook_index
     *
     * @effects hook_index_invalidated_on_policy_and_extension_changes
     *
     * @param  string  $type  module 또는 plugin
     * @return void 반환값 없음
     */
    #[DataProvider('extensionProvider')]
    public function test_deactivating_and_reactivating_an_extension_refreshes_the_index(string $type): void
    {
        $this->extension($type, ExtensionStatus::Active);
        $this->policy($type);
        $this->assertTrue($this->indexed());

        $this->transition($type, ExtensionStatus::Inactive);
        $this->assertFalse($this->indexed(), '비활성 확장의 정책이 색인에 남았다');

        $this->transition($type, ExtensionStatus::Active);
        $this->assertTrue($this->indexed(), '다시 켠 확장의 정책이 색인에 없다');
    }

    /**
     * 태그를 지원하지 않는 캐시에서는 route 색인 키와 hook 색인 키, hook 대상 목록 키를 따로 지운다.
     *
     * hook 쪽 키는 둘이다 — 리스너가 정책 표 조회를 건너뛰는 근거인 색인(`HOOK_SCOPE_CACHE_KEY`)과 부팅 때 훅 구독 범위를
     * 정하는 대상 목록(`HOOK_TARGETS_CACHE_KEY`). 하나라도 남으면 켠 정책이 캐시 수명 동안 걸리지 않는다.
     *
     * @scenario surface=hook_index
     *
     * @effects hook_index_invalidated_on_policy_and_extension_changes
     *
     * @return void 반환값 없음
     */
    public function test_tagless_cache_forgets_both_index_keys(): void
    {
        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('supportsTags')->andReturn(false);
        $cache->shouldReceive('forget')->with(IdentityPolicy::ROUTE_SCOPE_CACHE_KEY)->once();
        $cache->shouldReceive('forget')->with(IdentityPolicy::HOOK_SCOPE_CACHE_KEY)->once();
        $cache->shouldReceive('forget')->with(IdentityPolicy::HOOK_TARGETS_CACHE_KEY)->once();
        $this->app->instance(CacheInterface::class, $cache);

        IdentityPolicy::flushRouteScopeCache();
    }

    /**
     * 색인 조회가 실패해도 리스너는 정책 표로 판정한다 — 색인 장애가 before_* 훅 전체의 오류(500)로 번지거나 정책을 건너뛰지 않는다.
     *
     * @scenario surface=hook_index
     *
     * @effects hook_index_failure_falls_back_to_table_lookup
     *
     * @return void 반환값 없음
     */
    public function test_listener_falls_back_to_the_table_when_the_index_fails(): void
    {
        $this->policy();

        $repository = Mockery::mock(IdentityPolicyRepository::class, [app(CacheInterface::class)])->makePartial();
        $repository->shouldReceive('getHookScopeIndex')->andThrow(new RuntimeException('cache down'));
        $this->app->instance(IdentityPolicyRepositoryInterface::class, $repository);

        $this->actingAs(User::factory()->create())->get($this->image())->assertStatus(428)
            ->assertJsonPath('error_code', 'identity_verification_required');
    }
}
