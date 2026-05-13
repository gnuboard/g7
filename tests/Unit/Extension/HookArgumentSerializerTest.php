<?php

namespace Tests\Unit\Extension;

use App\Extension\HookArgumentSerializer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * HookArgumentSerializer 테스트
 *
 * 훅 인자의 직렬화/역직렬화를 검증합니다.
 */
class HookArgumentSerializerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 스칼라 값이 그대로 직렬화/역직렬화되는지 검증합니다.
     */
    public function test_scalar_values_pass_through(): void
    {
        $args = ['hello', 123, 45.6, true, null];
        $serialized = HookArgumentSerializer::serialize($args);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertEquals($args, $deserialized);
    }

    /**
     * 배열이 재귀적으로 직렬화/역직렬화되는지 검증합니다.
     */
    public function test_nested_arrays_are_serialized_recursively(): void
    {
        $args = [['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]]];
        $serialized = HookArgumentSerializer::serialize($args);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertEquals($args, $deserialized);
    }

    /**
     * Eloquent Model이 클래스명 + PK로 직렬화되고 DB에서 복원되는지 검증합니다.
     */
    public function test_eloquent_model_serialization(): void
    {
        $user = User::factory()->create(['name' => 'TestUser']);

        $serialized = HookArgumentSerializer::serialize([$user]);

        // 직렬화 결과에 마커와 클래스명, ID 포함
        $this->assertTrue($serialized[0]['__hook_model__']);
        $this->assertEquals(User::class, $serialized[0]['class']);
        $this->assertEquals($user->id, $serialized[0]['id']);

        // 역직렬화 시 DB에서 조회하여 복원
        $deserialized = HookArgumentSerializer::deserialize($serialized);
        $this->assertInstanceOf(User::class, $deserialized[0]);
        $this->assertEquals($user->id, $deserialized[0]->id);
    }

    /**
     * Collection이 직렬화/역직렬화되는지 검증합니다.
     */
    public function test_collection_serialization(): void
    {
        $collection = collect(['a', 'b', 'c']);
        $serialized = HookArgumentSerializer::serialize([$collection]);

        $this->assertTrue($serialized[0]['__hook_collection__']);

        $deserialized = HookArgumentSerializer::deserialize($serialized);
        $this->assertInstanceOf(Collection::class, $deserialized[0]);
        $this->assertEquals(['a', 'b', 'c'], $deserialized[0]->all());
    }

    /**
     * Closure 등 직렬화 불가 객체가 null로 대체되는지 검증합니다.
     */
    public function test_non_serializable_objects_become_null(): void
    {
        $closure = function () { return 'test'; };
        $serialized = HookArgumentSerializer::serialize([$closure]);

        $this->assertNull($serialized[0]);
    }

    /**
     * 혼합 인자 배열이 올바르게 처리되는지 검증합니다.
     */
    public function test_mixed_arguments(): void
    {
        $user = User::factory()->create();

        $args = ['action_name', $user, ['extra' => 'data'], 42];
        $serialized = HookArgumentSerializer::serialize($args);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertEquals('action_name', $deserialized[0]);
        $this->assertInstanceOf(User::class, $deserialized[1]);
        $this->assertEquals($user->id, $deserialized[1]->id);
        $this->assertEquals(['extra' => 'data'], $deserialized[2]);
        $this->assertEquals(42, $deserialized[3]);
    }

    /**
     * Backed Enum (string) 이 직렬화/역직렬화되는지 검증합니다.
     *
     * 회귀 차단 (이슈 #204):
     * 큐 디스패치된 hook listener 가 enum 인자를 받을 때 deserialize 가 null 로 떨어져
     * TypeError 발생 (예: OrderActivityLogListener::handleOrderOptionAfterStatusChange 의
     * OrderStatusEnum 인자). HookArgumentSerializer 가 BackedEnum 을 처리하지 않는 사각지대.
     */
    public function test_backed_string_enum_serialization(): void
    {
        $enum = HookSerializerStringBackedEnumFixture::ACTIVE;

        $serialized = HookArgumentSerializer::serialize([$enum]);

        $this->assertTrue($serialized[0]['__hook_enum__']);
        $this->assertSame(HookSerializerStringBackedEnumFixture::class, $serialized[0]['class']);
        $this->assertSame('active', $serialized[0]['value']);

        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertInstanceOf(HookSerializerStringBackedEnumFixture::class, $deserialized[0]);
        $this->assertSame(HookSerializerStringBackedEnumFixture::ACTIVE, $deserialized[0]);
    }

    /**
     * Backed Enum (int) 이 직렬화/역직렬화되는지 검증합니다.
     */
    public function test_backed_int_enum_serialization(): void
    {
        $enum = HookSerializerIntBackedEnumFixture::HIGH;

        $serialized = HookArgumentSerializer::serialize([$enum]);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertSame(HookSerializerIntBackedEnumFixture::HIGH, $deserialized[0]);
    }

    /**
     * Pure (Unit) Enum 이 직렬화/역직렬화되는지 검증합니다.
     */
    public function test_unit_enum_serialization(): void
    {
        $enum = HookSerializerUnitEnumFixture::FIRST;

        $serialized = HookArgumentSerializer::serialize([$enum]);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertSame(HookSerializerUnitEnumFixture::FIRST, $deserialized[0]);
    }

    /**
     * Hard delete 된 모델도 deserialize 시 attributes 스냅샷으로 in-memory 복원되는지 검증.
     *
     * 회귀 차단 (이슈 #204):
     * Order/Product 등 hard-delete 후 after_delete 훅이 큐 워커에서 dispatch 될 때
     * find($id) 가 null 반환 → listener TypeError. SoftDeletes 처리(40d98e2a7) 의 사각지대.
     * 직렬화 시 attributes 도 함께 저장하고, find() 가 null 이면 attributes 로 모델 재생성.
     */
    public function test_hard_deleted_model_restores_via_attributes_snapshot(): void
    {
        $user = User::factory()->create(['name' => 'TestUser', 'email' => 'snap@test.local']);

        // 직렬화 시 user 가 살아있는 상태에서 attributes 캡처
        $serialized = HookArgumentSerializer::serialize([$user]);

        // attributes 가 직렬화 결과에 포함되어 있는지 확인
        $this->assertArrayHasKey('attributes', $serialized[0]);
        $this->assertSame('TestUser', $serialized[0]['attributes']['name']);

        // 모델을 hard delete 하여 DB 에서 제거
        $userId = $user->id;
        $user->forceDelete();

        $this->assertNull(User::find($userId), '실제로 삭제되어 find 결과 null');

        // 역직렬화: find 가 null 이지만 attributes snapshot 으로 복원되어야 함
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertInstanceOf(User::class, $deserialized[0], 'hard-deleted 모델도 in-memory 복원');
        $this->assertSame('TestUser', $deserialized[0]->name);
        $this->assertSame('snap@test.local', $deserialized[0]->email);
        $this->assertSame($userId, $deserialized[0]->id);
        $this->assertFalse($deserialized[0]->exists, 'in-memory 복원이므로 exists=false');
    }

    /**
     * 혼합 인자 배열에 enum 이 포함되어 정상 통과하는지 검증.
     */
    public function test_mixed_arguments_including_enum(): void
    {
        $user = User::factory()->create();

        $args = [$user, HookSerializerStringBackedEnumFixture::ACTIVE, 'note'];

        $serialized = HookArgumentSerializer::serialize($args);
        $deserialized = HookArgumentSerializer::deserialize($serialized);

        $this->assertInstanceOf(User::class, $deserialized[0]);
        $this->assertSame(HookSerializerStringBackedEnumFixture::ACTIVE, $deserialized[1]);
        $this->assertSame('note', $deserialized[2]);
    }
}

enum HookSerializerStringBackedEnumFixture: string
{
    case ACTIVE = 'active';
    case PENDING = 'pending';
}

enum HookSerializerIntBackedEnumFixture: int
{
    case LOW = 1;
    case HIGH = 9;
}

enum HookSerializerUnitEnumFixture
{
    case FIRST;
    case SECOND;
}
