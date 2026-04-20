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
}
