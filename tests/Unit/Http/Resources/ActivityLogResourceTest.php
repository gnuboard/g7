<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\ActivityLogType;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ActivityLogResource API 리소스 테스트
 *
 * 리소스 변환 결과에 i18n 필드 및 변경 이력이 올바르게 포함되는지 검증합니다.
 */
class ActivityLogResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트용 Request 인스턴스를 생성합니다.
     *
     * @return Request
     */
    private function makeRequest(): Request
    {
        return Request::create('/api/admin/activity-logs', 'GET');
    }

    // ========================================================================
    // 기본 필드 검증
    // ========================================================================

    /**
     * 리소스에 모든 표준 필드가 포함됨
     */
    public function test_resource_includes_standard_fields(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
            'description_params' => ['user_id' => 'abc-123'],
            'properties' => ['key' => 'value'],
            'changes' => [['field' => 'name', 'old' => null, 'new' => 'John']],
            'ip_address' => '127.0.0.1',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertArrayHasKey('id', $resource);
        $this->assertArrayHasKey('log_type', $resource);
        $this->assertArrayHasKey('log_type_label', $resource);
        $this->assertArrayHasKey('loggable_type', $resource);
        $this->assertArrayHasKey('loggable_id', $resource);
        $this->assertArrayHasKey('action', $resource);
        $this->assertArrayHasKey('action_label', $resource);
        $this->assertArrayHasKey('localized_description', $resource);
        $this->assertArrayHasKey('description_key', $resource);
        $this->assertArrayHasKey('properties', $resource);
        $this->assertArrayHasKey('changes', $resource);
        $this->assertArrayHasKey('bulk_changes', $resource);
        $this->assertArrayHasKey('actor_name', $resource);
        $this->assertArrayHasKey('user', $resource);
        $this->assertArrayHasKey('ip_address', $resource);
        $this->assertArrayHasKey('created_at', $resource);
    }

    /**
     * 리소스에 description 키가 아닌 localized_description이 포함됨
     */
    public function test_resource_uses_localized_description_not_description(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'test',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertArrayHasKey('localized_description', $resource);
        $this->assertArrayNotHasKey('description', $resource);
    }

    // ========================================================================
    // i18n 필드 검증
    // ========================================================================

    /**
     * localized_description에 번역된 문자열이 반환됨
     */
    public function test_resource_returns_translated_description(): void
    {
        $locale = app()->getLocale();

        app('translator')->addLines([
            'activity_log.description.user_create' => ':user_id 사용자가 생성되었습니다',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
            'description_params' => ['user_id' => 'test-user'],
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('test-user 사용자가 생성되었습니다', $resource['localized_description']);
    }

    /**
     * description_key에 raw 키가 그대로 반환됨
     */
    public function test_resource_returns_raw_description_key(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('activity_log.description.user_create', $resource['description_key']);
    }

    /**
     * action_label에 번역된 액션 라벨이 반환됨
     */
    public function test_resource_returns_action_label(): void
    {
        $locale = app()->getLocale();

        app('translator')->addLines([
            'activity_log.action.user.create' => '사용자 생성',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('사용자 생성', $resource['action_label']);
    }

    // ========================================================================
    // changes 필드 검증
    // ========================================================================

    /**
     * changes에 구조화된 변경 이력이 포함되고 label이 번역됨
     */
    public function test_resource_includes_changes_with_translated_label(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.email' => '이메일',
            'activity_log.fields.name' => '이름',
        ], $locale);

        $changes = [
            ['field' => 'email', 'label_key' => 'activity_log.fields.email', 'old' => 'old@test.com', 'new' => 'new@test.com', 'type' => 'text'],
            ['field' => 'name', 'label_key' => 'activity_log.fields.name', 'old' => 'Old Name', 'new' => 'New Name', 'type' => 'text'],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertCount(2, $resource['changes']);
        $this->assertEquals('이메일', $resource['changes'][0]['label']);
        $this->assertEquals('이름', $resource['changes'][1]['label']);
        $this->assertEquals('old@test.com', $resource['changes'][0]['old']);
        $this->assertEquals('new@test.com', $resource['changes'][0]['new']);
    }

    /**
     * changes에 enum 타입 필드의 old_label/new_label이 번역됨
     */
    public function test_resource_translates_enum_labels_in_changes(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.status' => '상태',
            'enums.status.active' => '활성',
            'enums.status.inactive' => '비활성',
        ], $locale);

        $changes = [
            [
                'field' => 'status',
                'label_key' => 'activity_log.fields.status',
                'old' => 'inactive',
                'new' => 'active',
                'type' => 'enum',
                'old_label_key' => 'enums.status.inactive',
                'new_label_key' => 'enums.status.active',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('상태', $resource['changes'][0]['label']);
        $this->assertEquals('비활성', $resource['changes'][0]['old_label']);
        $this->assertEquals('활성', $resource['changes'][0]['new_label']);
    }

    /**
     * changes에 boolean 타입 필드의 old_label/new_label이 예/아니오로 변환됨
     */
    public function test_resource_translates_boolean_labels_in_changes(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.is_active' => '활성 여부',
        ], $locale);

        $changes = [
            [
                'field' => 'is_active',
                'label_key' => 'activity_log.fields.is_active',
                'old' => false,
                'new' => true,
                'type' => 'boolean',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('활성 여부', $resource['changes'][0]['label']);
        $this->assertEquals(__('common.no'), $resource['changes'][0]['old_label']);
        $this->assertEquals(__('common.yes'), $resource['changes'][0]['new_label']);
    }

    /**
     * changes에 json 타입 필드(다국어)가 현재 로케일 값으로 변환됨
     */
    public function test_resource_extracts_locale_value_from_json_changes(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.option_name' => '옵션명',
        ], $locale);

        $changes = [
            [
                'field' => 'option_name',
                'label_key' => 'activity_log.fields.option_name',
                'old' => ['en' => 'Brown', 'ko' => '하나하나'],
                'new' => ['en' => 'Brown', 'ko' => '원'],
                'type' => 'json',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'product_option.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('옵션명', $resource['changes'][0]['label']);
        // 현재 로케일(ko)에 맞는 값 추출
        $this->assertEquals('하나하나', $resource['changes'][0]['old']);
        $this->assertEquals('원', $resource['changes'][0]['new']);
    }

    /**
     * changes에 json 타입 필드가 한국어 로케일로 변환됨
     */
    public function test_resource_extracts_korean_locale_value_from_json_changes(): void
    {
        app()->setLocale('ko');

        app('translator')->addLines([
            'activity_log.fields.option_name' => '옵션명',
        ], 'ko');

        $changes = [
            [
                'field' => 'option_name',
                'label_key' => 'activity_log.fields.option_name',
                'old' => ['en' => 'Brown', 'ko' => '하나하나'],
                'new' => ['en' => 'Brown', 'ko' => '원'],
                'type' => 'json',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'product_option.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('하나하나', $resource['changes'][0]['old']);
        $this->assertEquals('원', $resource['changes'][0]['new']);

        // 로케일 복원
        app()->setLocale(config('app.locale'));
    }

    /**
     * changes에 json 타입 필드의 old가 null이면 null 그대로 반환
     */
    public function test_resource_handles_null_json_change_values(): void
    {
        $changes = [
            [
                'field' => 'option_name',
                'label_key' => 'activity_log.fields.option_name',
                'old' => null,
                'new' => ['en' => 'New', 'ko' => '새로운'],
                'type' => 'json',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'product_option.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertNull($resource['changes'][0]['old']);
        $this->assertIsString($resource['changes'][0]['new']);
    }

    /**
     * bulk_changes에서도 json 타입 필드가 로케일 변환됨
     */
    public function test_resource_extracts_locale_value_from_bulk_json_changes(): void
    {
        app()->setLocale('ko');

        $bulkChanges = [
            503 => [
                [
                    'field' => 'option_name',
                    'label_key' => 'activity_log.fields.option_name',
                    'old' => ['en' => 'Brown', 'ko' => '하나하나'],
                    'new' => ['en' => 'Brown', 'ko' => '원'],
                    'type' => 'json',
                ],
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'product_option.bulk_update',
            'changes' => $bulkChanges,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertNotNull($resource['bulk_changes']);
        $this->assertEquals('하나하나', $resource['bulk_changes'][0]['changes'][0]['old']);
        $this->assertEquals('원', $resource['bulk_changes'][0]['changes'][0]['new']);

        app()->setLocale(config('app.locale'));
    }

    /**
     * changes가 null이면 changes, bulk_changes 모두 null로 반환됨
     */
    public function test_resource_returns_null_changes_when_empty(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'login',
            'changes' => null,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertNull($resource['changes']);
        $this->assertNull($resource['bulk_changes']);
    }

    /**
     * 단일 수정 changes는 changes에 반환, bulk_changes는 null
     */
    public function test_resource_returns_single_changes_in_changes_field(): void
    {
        $changes = [
            ['field' => 'name', 'label_key' => 'activity_log.fields.name', 'old' => 'Old', 'new' => 'New', 'type' => 'text'],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertNotNull($resource['changes']);
        $this->assertNull($resource['bulk_changes']);
        $this->assertCount(1, $resource['changes']);
    }

    /**
     * 일괄 수정 changes (모델ID 기준 그룹핑)는 bulk_changes에 반환, changes는 null
     */
    public function test_resource_returns_bulk_changes_in_bulk_changes_field(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.status' => '상태',
        ], $locale);

        $bulkChanges = [
            1 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'active', 'new' => 'inactive', 'type' => 'text'],
            ],
            2 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'active', 'new' => 'inactive', 'type' => 'text'],
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.bulk_update',
            'changes' => $bulkChanges,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertNull($resource['changes']);
        $this->assertNotNull($resource['bulk_changes']);
        $this->assertCount(2, $resource['bulk_changes']);

        // 각 그룹에 model_id와 번역된 changes 포함
        $this->assertEquals(1, $resource['bulk_changes'][0]['model_id']);
        $this->assertEquals(2, $resource['bulk_changes'][1]['model_id']);
        $this->assertEquals('상태', $resource['bulk_changes'][0]['changes'][0]['label']);
    }

    // ========================================================================
    // 하위 호환: 기존 DB 레코드 처리
    // ========================================================================

    /**
     * 기존 DB 레코드에서 type이 'text'로 저장되었지만 모델의 현재
     * $activityLogFields에서 'enum'으로 정의된 필드는 동적으로 enum 라벨을 해석함
     */
    public function test_resource_resolves_enum_labels_from_legacy_text_type(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.fields.status' => '상태',
            'user.status.active' => '활성',
            'user.status.blocked' => '차단',
        ], $locale);

        // 기존 DB 레코드: type이 'text'로 저장됨 (old_label_key/new_label_key 없음)
        $changes = [
            [
                'field' => 'status',
                'label_key' => 'activity_log.fields.status',
                'old' => 'active',
                'new' => 'blocked',
                'type' => 'text',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'loggable_type' => User::class,
            'loggable_id' => 1,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        // type이 동적으로 'enum'으로 보정됨
        $this->assertEquals('enum', $resource['changes'][0]['type']);
        // enum 라벨이 동적으로 해석됨
        $this->assertEquals('활성', $resource['changes'][0]['old_label']);
        $this->assertEquals('차단', $resource['changes'][0]['new_label']);
    }

    /**
     * 기존 DB 레코드에서 type이 'text'이고 모델에서도 'text'인 필드는 변경 없이 유지됨
     */
    public function test_resource_does_not_alter_genuine_text_type_fields(): void
    {
        $changes = [
            [
                'field' => 'name',
                'label_key' => 'activity_log.fields.name',
                'old' => 'Old Name',
                'new' => 'New Name',
                'type' => 'text',
            ],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'loggable_type' => User::class,
            'loggable_id' => 1,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('text', $resource['changes'][0]['type']);
        $this->assertArrayNotHasKey('old_label', $resource['changes'][0]);
        $this->assertArrayNotHasKey('new_label', $resource['changes'][0]);
    }

    /**
     * 일괄 변경 로그에서 description_params에 count가 누락된 경우
     * bulk changes 개수로 :count 플레이스홀더를 보정함
     */
    public function test_resource_resolves_unreplaced_count_from_bulk_changes(): void
    {
        $locale = app()->getLocale();
        // namespaced key ('vendor::group.key') 는 addLines 의 3번째 인자 namespace 로 전달해야 함
        app('translator')->addLines([
            'activity_log.description.order_bulk_status_update' => '주문 일괄 상태 변경 (:count건)',
        ], $locale, 'sirsoft-ecommerce');

        $bulkChanges = [
            101 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'pending', 'new' => 'shipping', 'type' => 'text'],
            ],
            102 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'pending', 'new' => 'shipping', 'type' => 'text'],
            ],
            103 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'pending', 'new' => 'shipping', 'type' => 'text'],
            ],
        ];

        // 기존 DB 레코드: description_params에 count 없음
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'order.bulk_status_update',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_bulk_status_update',
            'description_params' => ['order_id' => 'ORD-001'],
            'changes' => $bulkChanges,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('주문 일괄 상태 변경 (3건)', $resource['localized_description']);
    }

    /**
     * description_params에 count가 이미 있으면 그대로 사용됨 (이중 치환 없음)
     */
    public function test_resource_does_not_double_replace_count(): void
    {
        $locale = app()->getLocale();
        app('translator')->addLines([
            'activity_log.description.order_bulk_status_update' => '주문 일괄 상태 변경 (:count건)',
        ], $locale, 'sirsoft-ecommerce');

        $bulkChanges = [
            101 => [
                ['field' => 'status', 'label_key' => 'activity_log.fields.status', 'old' => 'pending', 'new' => 'shipping', 'type' => 'text'],
            ],
        ];

        // 새 DB 레코드: description_params에 count가 이미 있음
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'order.bulk_status_update',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.order_bulk_status_update',
            'description_params' => ['order_id' => 'ORD-001', 'count' => 5],
            'changes' => $bulkChanges,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        // count가 이미 치환되어 5건으로 표시 (bulk_changes 개수 1이 아닌 5)
        $this->assertEquals('주문 일괄 상태 변경 (5건)', $resource['localized_description']);
    }

    // ========================================================================
    // user 필드 검증
    // ========================================================================

    /**
     * user가 있으면 uuid, name, email이 포함된 객체 반환
     */
    public function test_resource_includes_user_info_when_user_exists(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'user_id' => $user->id,
        ]);

        $log->load('user');

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertIsArray($resource['user']);
        $this->assertEquals($user->uuid, $resource['user']['uuid']);
        $this->assertEquals('Test User', $resource['user']['name']);
        $this->assertEquals('test@example.com', $resource['user']['email']);
    }

    /**
     * user가 없으면 시스템 라벨만 포함된 객체 반환
     */
    public function test_resource_returns_system_user_when_no_user(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'maintenance',
            'user_id' => null,
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertIsArray($resource['user']);
        $this->assertEquals(__('common.system'), $resource['user']['name']);
        $this->assertArrayNotHasKey('uuid', $resource['user']);
        $this->assertArrayNotHasKey('email', $resource['user']);
    }

    // ========================================================================
    // 기타 필드
    // ========================================================================

    /**
     * log_type에 enum value 문자열이 반환됨
     */
    public function test_resource_returns_log_type_as_string_value(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'test',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('admin', $resource['log_type']);
    }

    /**
     * ip_address가 올바르게 반환됨
     */
    public function test_resource_returns_ip_address(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'login',
            'ip_address' => '192.168.1.100',
        ]);

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('192.168.1.100', $resource['ip_address']);
    }

    /**
     * actor_name이 리소스에 포함됨
     */
    public function test_resource_includes_actor_name(): void
    {
        $user = User::factory()->create(['name' => 'Admin User']);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'test',
            'user_id' => $user->id,
        ]);

        $log->load('user');

        $resource = (new ActivityLogResource($log))->toArray($this->makeRequest());

        $this->assertEquals('Admin User', $resource['actor_name']);
    }
}
