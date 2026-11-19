<?php

namespace Tests\Feature\Services;

use App\Exceptions\FieldDefinitionConflictException;
use App\Extension\HookManager;
use App\Fields\FieldSetRegistry;
use App\Models\FieldDefinition;
use App\Services\FieldDefinitionService;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 항목 관리 Service — HTTP 를 거치지 않는 호출의 계약(훅 발화, 버전 대조, 집합 경계).
 *
 * 요청 검증과 응답 모양은 `FieldDefinitionControllerTest` 가 잰다.
 */
class FieldDefinitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FieldDefinitionService $service;

    /** @var array<int, string> 발화된 액션 이름 */
    private array $fired = [];

    /**
     * 코어 회원 항목을 시딩하고 액션 발화를 기록할 구독자를 건다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreFieldDefinitionSeeder::class);
        $this->service = app(FieldDefinitionService::class);

        foreach (['after_create', 'after_update', 'after_delete'] as $action) {
            HookManager::addAction('core.field_definition.'.$action, function () use ($action) {
                $this->fired[] = $action;
            });
        }
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (['after_create', 'after_update', 'after_delete'] as $action) {
            HookManager::clearAction('core.field_definition.'.$action);
        }
        parent::tearDown();
    }

    /**
     * 추가, 수정, 삭제가 각각 액션 훅을 발화한다.
     *
     * @scenario action=store
     *
     * @effects admin_field_lifecycle_actions_fire
     *
     * @return void 반환값 없음
     */
    public function test_create_update_delete_fire_actions(): void
    {
        $set = app(FieldSetRegistry::class)->get('core.user');

        $definition = $this->service->create($set, ['key' => 'job', 'type' => 'text', 'name' => ['ko' => '직업']]);
        $this->service->update($definition, ['required' => true]);
        $this->service->delete($definition);

        $this->assertSame(['after_create', 'after_update', 'after_delete'], $this->fired);
        $this->assertDatabaseMissing('field_definitions', ['key' => 'job']);
    }

    /**
     * 버전을 싣지 않은 호출은 통과하고, 다른 버전은 지금 버전을 실은 충돌 예외를 던진다.
     *
     * @scenario action=update
     *
     * @effects admin_field_stale_version_rejected
     *
     * @return void 반환값 없음
     */
    public function test_assert_version(): void
    {
        $current = $this->service->version('core.user');

        $this->service->assertVersion('core.user', null);
        $this->service->assertVersion('core.user', '');
        $this->service->assertVersion('core.user', $current);

        try {
            $this->service->assertVersion('core.user', 'stale-version');
            $this->fail('낡은 버전이 통과했다');
        } catch (FieldDefinitionConflictException $e) {
            $this->assertSame(FieldDefinitionConflictException::FIELDS_CHANGED, $e->errorCode);
            $this->assertSame($current, $e->data['fields_version']);
        }
    }

    /**
     * 다른 집합의 항목 ID 로는 찾지 못한다(집합 경계).
     *
     * @scenario action=show
     *
     * @effects admin_field_lookup_is_scoped_to_set
     *
     * @return void 반환값 없음
     */
    public function test_find_is_scoped_to_the_set(): void
    {
        $name = FieldDefinition::where('field_set', 'core.user')->where('key', 'name')->firstOrFail();

        $this->assertNotNull($this->service->find('core.user', $name->id));
        $this->assertNull($this->service->find('sirsoft-board.guest_author', $name->id), '다른 집합 이름으로 항목이 조회됐다');
    }

    /**
     * 회원 집합이 아닌 항목과 컬럼·값 테이블이 아닌 저장 방식의 미입력 회원 수는 0 이다.
     *
     * @scenario action=missing_count
     *
     * @effects admin_missing_count_before_requiring
     *
     * @return void 반환값 없음
     */
    public function test_missing_count_is_zero_outside_member_set(): void
    {
        $other = new FieldDefinition(['field_set' => 'other.set', 'key' => 'x', 'type' => 'text', 'storage' => 'value']);
        $handler = new FieldDefinition(['field_set' => 'core.user', 'key' => 'y', 'type' => 'text', 'storage' => 'handler']);

        $this->assertSame(0, $this->service->missingCount($other));
        $this->assertSame(0, $this->service->missingCount($handler));
    }
}
