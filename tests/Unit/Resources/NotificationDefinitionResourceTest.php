<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\NotificationDefinitionResource;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * NotificationDefinitionResource 단위 테스트
 */
class NotificationDefinitionResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * templates 관계 미로드 시 null 반환 (json 직렬화 에러 없음)
     */
    public function test_templates_returns_null_when_relation_not_loaded(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        $request = Request::create('/test');
        $resource = new NotificationDefinitionResource($definition);
        $array = $resource->toArray($request);

        $this->assertNull($array['templates']);

        // json_encode 시 에러 없음 검증
        $json = json_encode($array);
        $this->assertNotFalse($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * templates 관계 로드 시 배열 반환
     */
    public function test_templates_returns_array_when_relation_loaded(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영'],
            'channels' => ['mail'],
            'hooks' => [],
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목'],
            'body' => ['ko' => '본문'],
        ]);

        $definition->load('templates');

        $request = Request::create('/test');
        $resource = new NotificationDefinitionResource($definition);
        $array = $resource->toArray($request);

        $this->assertNotNull($array['templates']);
        // Resource::collection()은 AnonymousResourceCollection을 반환
        // json_encode 시 배열로 직렬화됨
        $json = json_encode($array);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded['templates']);
        $this->assertCount(1, $decoded['templates']);

        // json_encode 시 에러 없음 검증
        $json = json_encode($array);
        $this->assertNotFalse($json);
    }

    /**
     * 기본 필드가 올바르게 반환됨
     */
    public function test_basic_fields_returned(): void
    {
        $definition = NotificationDefinition::create([
            'type' => 'order_confirmed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
            'name' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_confirm'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'is_active' => true,
        ]);

        $request = Request::create('/test');
        $array = (new NotificationDefinitionResource($definition))->toArray($request);

        $this->assertEquals('order_confirmed', $array['type']);
        $this->assertEquals('sirsoft-ecommerce', $array['hook_prefix']);
        $this->assertEquals('module', $array['extension_type']);
        $this->assertEquals(['mail', 'database'], $array['channels']);
        $this->assertTrue($array['is_active']);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('variables', $array);
    }
}
