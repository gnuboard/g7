<?php

namespace Tests\Feature\Fields;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\FieldSetRegistry;
use App\Fields\FieldTypeRegistry;
use App\Models\FieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 교차 기여 — 한 확장이 가진 항목 집합에 다른 확장이 항목을 선언하는 경우의 규칙.
 *
 * 시험용 가짜 확장 둘을 쓴다: 집합을 가진 `fake-owner`(플러그인)와 항목을 기여하는 `fake-giver`(플러그인).
 */
class CrossExtensionFieldContributionTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'fake-owner';

    private const GIVER = 'fake-giver';

    private const SET = 'fake-owner.order_contact';

    /**
     * 두 가짜 확장을 켜고, 집합을 등록소에 올린다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setPluginStatus(self::OWNER, ExtensionStatus::Active);
        $this->setPluginStatus(self::GIVER, ExtensionStatus::Active);
        $this->registerOwnerSet();
    }

    /**
     * 가짜 확장의 필터 구독을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.field.filter_definitions');
        parent::tearDown();
    }

    /**
     * 집합을 가진 가짜 확장의 집합을 등록소에 올립니다.
     *
     * @param  array<string, mixed>  $overrides  선언에 덮어쓸 값
     * @return void 반환값 없음
     */
    private function registerOwnerSet(array $overrides = []): void
    {
        $sets = app(FieldSetRegistry::class);
        $sets->flush();
        $sets->register(FieldSet::fromArray(array_merge([
            'key' => self::SET,
            'label' => '주문서 항목',
            'owner_types' => ['fake_order'],
            'allowed_types' => ['text', 'boolean', 'choice'],
            'bundle_key' => 'extra_fields',
            'manage_permission' => 'fake-owner.settings.update',
        ], $overrides), 'plugin', self::OWNER));
    }

    /**
     * plugins 테이블에 가짜 확장 행을 지정한 상태로 준비합니다.
     *
     * @param  string  $identifier  확장 식별자
     * @param  ExtensionStatus  $status  상태
     * @return void 반환값 없음
     */
    private function setPluginStatus(string $identifier, ExtensionStatus $status): void
    {
        DB::table('plugins')->updateOrInsert(
            ['identifier' => $identifier],
            [
                'vendor' => 'fake',
                'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                'version' => '1.0.0',
                'status' => $status->value,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        PluginManager::invalidatePluginStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 기여 확장의 선언을 동기화합니다.
     *
     * @param  array<int, array<string, mixed>>  $fields  항목 선언
     * @return array{synced: array<int, string>, skipped: array<string, string>, removed: int} 동기화 결과
     */
    private function syncGiver(array $fields): array
    {
        return app(FieldDefinitionSyncHelper::class)->sync('plugin', self::GIVER, $fields);
    }

    /**
     * 기본 기여 선언(선물 포장 여부)을 반환합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 항목 선언
     */
    private function giftwrap(array $overrides = []): array
    {
        return array_merge([
            'field_set' => self::SET,
            'key' => 'fake_giver_giftwrap',
            'type' => 'boolean',
            'name' => ['ko' => '선물 포장', 'en' => 'Gift wrap'],
            'locks' => ['required' => 'fields.locks.default'],
        ], $overrides);
    }

    /**
     * 다른 확장의 집합에 선언하면 행이 만들어지고, 요청 키는 집합 소유자가 정한 묶음 아래에 놓인다.
     *
     * @scenario source=plugin
     *
     * @effects cross_contribution_creates_row_under_owner_bundle
     *
     * @return void 반환값 없음
     */
    public function test_contribution_creates_row_under_owner_bundle_key(): void
    {
        $result = $this->syncGiver([$this->giftwrap()]);

        $this->assertSame([self::SET.'|fake_giver_giftwrap'], $result['synced']);
        $row = FieldDefinition::where('field_set', self::SET)->where('key', 'fake_giver_giftwrap')->firstOrFail();
        $this->assertSame('plugin', $row->source_type);
        $this->assertSame(self::GIVER, $row->source_identifier);
        $this->assertSame('value', $row->storage);

        $registry = app(FieldRegistry::class);
        $listed = $registry->forSet(self::SET);
        $this->assertCount(1, $listed);
        $this->assertSame('extra_fields.fake_giver_giftwrap', $registry->attributeFor($listed->first()), '기여 항목의 요청 키가 집합 소유자의 묶음 아래가 아니다');
        $this->assertTrue($listed->first()->isLocked('required'), '기여 확장이 건 잠금이 실리지 않았다');
    }

    /**
     * 집합 소유 확장이 꺼지면 기여 항목이 빠지고, 다시 켜면 운영자가 바꿔 둔 값과 함께 돌아온다.
     *
     * @scenario source=plugin
     *
     * @effects owner_disabled_hides_contribution_and_restores_overrides
     *
     * @return void 반환값 없음
     */
    public function test_owner_disabled_hides_field_and_reenabling_restores_operator_overrides(): void
    {
        $this->syncGiver([$this->giftwrap()]);
        FieldDefinition::where('key', 'fake_giver_giftwrap')->firstOrFail()->update(['enabled' => false, 'sort_order' => 77]);

        // 소유 확장이 꺼지면 그 집합 자체가 등록소에서 빠진다.
        $this->setPluginStatus(self::OWNER, ExtensionStatus::Inactive);
        app(FieldSetRegistry::class)->flush();
        $this->assertCount(0, app(FieldRegistry::class)->forSet(self::SET), '소유 확장이 꺼졌는데 항목이 조회됐다');

        // 그 상태에서 기여 확장이 다시 동기화돼도 행은 지워지지 않는다(건너뛴 선언은 정리 대상이 아니다).
        $result = $this->syncGiver([$this->giftwrap()]);
        $this->assertArrayHasKey(self::SET.'|fake_giver_giftwrap', $result['skipped']);
        $this->assertSame(0, $result['removed']);
        $this->assertDatabaseHas('field_definitions', ['key' => 'fake_giver_giftwrap']);

        // 소유 확장을 다시 켠다.
        $this->setPluginStatus(self::OWNER, ExtensionStatus::Active);
        $this->registerOwnerSet();
        $this->syncGiver([$this->giftwrap()]);

        $restored = app(FieldRegistry::class)->forSet(self::SET)->first();
        $this->assertNotNull($restored);
        $this->assertFalse($restored->enabled, '운영자가 끈 사용 여부가 돌아오지 않았다');
        $this->assertSame(77, $restored->sort_order, '운영자가 바꾼 순서가 돌아오지 않았다');
    }

    /**
     * 기여 확장이 꺼지면 그 항목만 등록소에서 빠진다(행은 남는다).
     *
     * @scenario source=plugin
     *
     * @effects contributor_disabled_hides_only_its_fields
     *
     * @return void 반환값 없음
     */
    public function test_contributor_disabled_hides_only_its_fields(): void
    {
        $this->syncGiver([$this->giftwrap()]);
        app(FieldDefinitionSyncHelper::class)->sync('plugin', self::OWNER, [[
            'field_set' => self::SET, 'key' => 'memo', 'type' => 'text', 'name' => ['ko' => '배송 메모'],
        ]]);
        $this->assertCount(2, app(FieldRegistry::class)->forSet(self::SET));

        $this->setPluginStatus(self::GIVER, ExtensionStatus::Inactive);

        $this->assertSame(['memo'], app(FieldRegistry::class)->forSet(self::SET)->pluck('key')->all());
        $this->assertDatabaseHas('field_definitions', ['key' => 'fake_giver_giftwrap']);
    }

    /**
     * 집합이 허용하지 않는 유형, 기여를 받지 않는 집합, 컬럼 저장 선언, 이미 있는 키는 건너뛴다.
     *
     * @scenario source=plugin
     *
     * @effects cross_contribution_rejections
     *
     * @return void 반환값 없음
     */
    public function test_rejected_contributions_are_skipped_with_reason(): void
    {
        // 소유 확장이 먼저 같은 키를 선언해 두었다.
        app(FieldDefinitionSyncHelper::class)->sync('plugin', self::OWNER, [[
            'field_set' => self::SET, 'key' => 'memo', 'type' => 'text', 'name' => ['ko' => '배송 메모'],
        ]]);

        $result = $this->syncGiver([
            $this->giftwrap(['key' => 'fake_giver_file', 'type' => 'file']),            // 허용하지 않는 유형
            $this->giftwrap(['key' => 'fake_giver_col', 'storage' => 'column']),         // 컬럼 저장은 소유자만
            $this->giftwrap(['key' => 'memo', 'type' => 'text']),                         // 키 충돌
            $this->giftwrap(['key' => 'fake_giver_handler', 'storage' => 'handler']),    // 핸들러 클래스 없음
        ]);

        $this->assertSame([], $result['synced']);
        $this->assertCount(4, $result['skipped']);
        $this->assertSame(self::OWNER, FieldDefinition::where('key', 'memo')->firstOrFail()->source_identifier, '먼저 등록된 쪽이 유지되지 않았다');
        $this->assertSame(1, FieldDefinition::where('field_set', self::SET)->count());

        // 기여를 받지 않는 집합.
        $this->registerOwnerSet(['accepts_contributions' => false]);
        $closed = $this->syncGiver([$this->giftwrap()]);
        $this->assertArrayHasKey(self::SET.'|fake_giver_giftwrap', $closed['skipped']);
        $this->assertDatabaseMissing('field_definitions', ['key' => 'fake_giver_giftwrap']);
    }

    /**
     * 기여 확장을 삭제하면 그 확장의 행만 정리된다.
     *
     * @scenario source=plugin
     *
     * @effects contributor_removal_cleans_only_its_rows
     *
     * @return void 반환값 없음
     */
    public function test_removing_contributor_cleans_only_its_rows(): void
    {
        $this->syncGiver([$this->giftwrap()]);
        app(FieldDefinitionSyncHelper::class)->sync('plugin', self::OWNER, [[
            'field_set' => self::SET, 'key' => 'memo', 'type' => 'text', 'name' => ['ko' => '배송 메모'],
        ]]);
        $custom = FieldDefinition::create([
            'field_set' => self::SET, 'key' => 'door_code', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '현관 비밀번호'],
        ]);

        $removed = app(FieldDefinitionSyncHelper::class)->removeSource('plugin', self::GIVER);

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('field_definitions', ['key' => 'fake_giver_giftwrap']);
        $this->assertDatabaseHas('field_definitions', ['key' => 'memo']);
        $this->assertDatabaseHas('field_definitions', ['id' => $custom->id]);
    }

    /**
     * 필터로 요청 시점에 항목을 더하거나 뺄 수 있다.
     *
     * @scenario source=plugin
     *
     * @effects filter_definitions_adjusts_fields_per_request
     *
     * @return void 반환값 없음
     */
    public function test_filter_definitions_can_drop_fields_per_request(): void
    {
        $this->syncGiver([$this->giftwrap()]);

        HookManager::addFilter('core.field.filter_definitions', function (Collection $definitions, string $set, ?string $scopeKey) {
            return $set === self::SET && $scopeKey === 'no-gift'
                ? $definitions->reject(fn (FieldDefinition $definition) => $definition->key === 'fake_giver_giftwrap')
                : $definitions;
        });

        $registry = app(FieldRegistry::class);
        $this->assertCount(1, $registry->forSet(self::SET, 'normal'));
        $this->assertCount(0, $registry->forSet(self::SET, 'no-gift'), '필터가 뺀 항목이 조회됐다');
    }

    /**
     * 필터로 확장이 더한 유형도 항목에 쓸 수 있고, 빠지면 그 유형의 항목은 사용 불가가 된다.
     *
     * @scenario source=plugin
     *
     * @effects extension_type_availability_follows_registry
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_type_marks_field_unavailable(): void
    {
        $row = FieldDefinition::create([
            'field_set' => self::SET, 'key' => 'fav_color', 'type' => 'color', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '색상'], 'enabled' => true,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $registry = app(FieldRegistry::class);
        $this->assertFalse(app(FieldTypeRegistry::class)->has('color'));
        $this->assertCount(1, $registry->forSet(self::SET), '사용 불가 유형의 항목이 운영자 표에서 사라졌다');
        $this->assertFalse($registry->isAvailable($registry->forSet(self::SET)->first()));
        $this->assertCount(0, $registry->active(self::SET), '사용 불가 유형의 항목이 사용자 화면 대상에 남았다');
        $this->assertSame($row->id, $registry->forSet(self::SET)->first()->id);
    }
}
