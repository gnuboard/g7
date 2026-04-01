<?php

namespace Tests\Unit\Models;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ActivityLog 모델 테스트
 *
 * 모델의 fillable, 캐스팅, 접근자(accessor), 스코프를 검증합니다.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // fillable
    // ========================================================================

    /**
     * description_key, description_params, changes가 fillable에 포함
     */
    public function test_i18n_fields_are_fillable(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
            'description_params' => ['user_id' => 'abc-123'],
            'changes' => [
                ['field' => 'name', 'old' => null, 'new' => 'John'],
            ],
        ]);

        $this->assertNotNull($log->id);
        $this->assertEquals('activity_log.description.user_create', $log->description_key);
        $this->assertEquals(['user_id' => 'abc-123'], $log->description_params);
        $this->assertEquals([['field' => 'name', 'old' => null, 'new' => 'John']], $log->changes);
    }

    /**
     * 모든 fillable 필드가 정의되어 있는지 확인
     */
    public function test_fillable_includes_all_expected_fields(): void
    {
        $log = new ActivityLog;
        $expected = [
            'log_type',
            'loggable_type',
            'loggable_id',
            'user_id',
            'action',
            'description_key',
            'description_params',
            'properties',
            'changes',
            'ip_address',
            'user_agent',
            'created_at',
        ];

        $this->assertEquals($expected, $log->getFillable());
    }

    // ========================================================================
    // casts
    // ========================================================================

    /**
     * description_params가 배열로 캐스팅됨
     */
    public function test_description_params_is_cast_to_array(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_params' => ['user_id' => 'abc-123', 'name' => 'John'],
        ]);

        $log->refresh();

        $this->assertIsArray($log->description_params);
        $this->assertEquals('abc-123', $log->description_params['user_id']);
        $this->assertEquals('John', $log->description_params['name']);
    }

    /**
     * changes가 배열로 캐스팅됨
     */
    public function test_changes_is_cast_to_array(): void
    {
        $changes = [
            ['field' => 'email', 'old' => 'old@example.com', 'new' => 'new@example.com'],
        ];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'changes' => $changes,
        ]);

        $log->refresh();

        $this->assertIsArray($log->changes);
        $this->assertEquals($changes, $log->changes);
    }

    /**
     * properties가 배열로 캐스팅됨
     */
    public function test_properties_is_cast_to_array(): void
    {
        $props = ['key' => 'value', 'nested' => ['a' => 1]];

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'config.update',
            'properties' => $props,
        ]);

        $log->refresh();

        $this->assertIsArray($log->properties);
        $this->assertEquals($props, $log->properties);
    }

    /**
     * log_type이 ActivityLogType Enum으로 캐스팅됨
     */
    public function test_log_type_is_cast_to_enum(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::User,
            'action' => 'login',
        ]);

        $log->refresh();

        $this->assertInstanceOf(ActivityLogType::class, $log->log_type);
        $this->assertEquals(ActivityLogType::User, $log->log_type);
    }

    /**
     * created_at이 datetime으로 캐스팅됨
     */
    public function test_created_at_is_cast_to_datetime(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
        ]);

        $log->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $log->created_at);
    }

    // ========================================================================
    // accessors
    // ========================================================================

    /**
     * localized_description: description_key가 있으면 번역된 문자열 반환
     */
    public function test_localized_description_returns_translated_string(): void
    {
        $locale = app()->getLocale();

        // 번역 키를 임시 등록
        app('translator')->addLines([
            'activity_log.description.user_create' => ':user_id 사용자가 생성되었습니다',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => 'activity_log.description.user_create',
            'description_params' => ['user_id' => 'abc-123'],
        ]);

        $this->assertEquals('abc-123 사용자가 생성되었습니다', $log->localized_description);
    }

    /**
     * localized_description: description_key가 null이면 빈 문자열 반환
     */
    public function test_localized_description_returns_empty_string_when_key_is_null(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'description_key' => null,
        ]);

        $this->assertEquals('', $log->localized_description);
    }

    /**
     * localized_description: description_params가 null이어도 동작
     */
    public function test_localized_description_works_with_null_params(): void
    {
        $locale = app()->getLocale();

        app('translator')->addLines([
            'activity_log.description.simple' => '단순 작업',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'simple',
            'description_key' => 'activity_log.description.simple',
            'description_params' => null,
        ]);

        $this->assertEquals('단순 작업', $log->localized_description);
    }

    /**
     * action_label: 1단계 - 전체 키로 번역 성공 시 번역문 반환
     */
    public function test_action_label_returns_full_key_translation(): void
    {
        $locale = app()->getLocale();

        app('translator')->addLines([
            'activity_log.action.user.create' => '사용자 생성',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
        ]);

        $this->assertEquals('사용자 생성', $log->action_label);
    }

    /**
     * action_label: 2단계 - 전체 키 실패 시 마지막 세그먼트로 조회
     */
    public function test_action_label_falls_back_to_last_segment(): void
    {
        $locale = app()->getLocale();

        // 전체 키 'activity_log.action.user.create'는 등록하지 않음
        app('translator')->addLines([
            'activity_log.action.create' => '생성',
        ], $locale);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
        ]);

        $this->assertEquals('생성', $log->action_label);
    }

    /**
     * action_label: 3단계 - 모든 번역 실패 시 raw action 문자열 반환
     */
    public function test_action_label_falls_back_to_raw_action(): void
    {
        // 번역 키 미등록 → fallback to raw
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'some.unknown.action',
        ]);

        $this->assertEquals('some.unknown.action', $log->action_label);
    }

    /**
     * actor_name: user 관계가 있으면 사용자 이름 반환
     */
    public function test_actor_name_returns_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'user_id' => $user->id,
        ]);

        $log->load('user');

        $this->assertEquals('Alice', $log->actor_name);
    }

    /**
     * actor_name: user가 없으면 시스템 라벨 반환
     */
    public function test_actor_name_returns_system_label_when_no_user(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'maintenance',
            'user_id' => null,
        ]);

        // __('common.system')의 번역 결과를 그대로 비교
        $this->assertEquals(__('common.system'), $log->actor_name);
    }

    /**
     * log_type_label: Enum의 label() 메서드를 통해 번역된 라벨 반환
     */
    public function test_log_type_label_returns_translated_label(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'test',
        ]);

        // Enum의 label() 결과와 일치하는지 확인
        $this->assertEquals(ActivityLogType::Admin->label(), $log->log_type_label);
    }

    // ========================================================================
    // relationships
    // ========================================================================

    /**
     * user 관계: BelongsTo User
     */
    public function test_user_relationship(): void
    {
        $user = User::factory()->create();

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'login',
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(User::class, $log->user);
        $this->assertEquals($user->id, $log->user->id);
    }

    // ========================================================================
    // scopes
    // ========================================================================

    /**
     * admin 스코프: 관리자 로그만 반환
     */
    public function test_admin_scope(): void
    {
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'a']);
        ActivityLog::create(['log_type' => ActivityLogType::User, 'action' => 'b']);
        ActivityLog::create(['log_type' => ActivityLogType::System, 'action' => 'c']);

        $adminLogs = ActivityLog::admin()->get();

        $this->assertCount(1, $adminLogs);
        $this->assertEquals(ActivityLogType::Admin, $adminLogs->first()->log_type);
    }

    /**
     * userLog 스코프: 사용자 로그만 반환
     */
    public function test_user_log_scope(): void
    {
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'a']);
        ActivityLog::create(['log_type' => ActivityLogType::User, 'action' => 'b']);

        $userLogs = ActivityLog::userLog()->get();

        $this->assertCount(1, $userLogs);
        $this->assertEquals(ActivityLogType::User, $userLogs->first()->log_type);
    }

    /**
     * system 스코프: 시스템 로그만 반환
     */
    public function test_system_scope(): void
    {
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'a']);
        ActivityLog::create(['log_type' => ActivityLogType::System, 'action' => 'c']);

        $systemLogs = ActivityLog::system()->get();

        $this->assertCount(1, $systemLogs);
        $this->assertEquals(ActivityLogType::System, $systemLogs->first()->log_type);
    }

    /**
     * action 스코프: 특정 액션의 로그만 반환
     */
    public function test_action_scope(): void
    {
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'login']);
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'logout']);

        $loginLogs = ActivityLog::action('login')->get();

        $this->assertCount(1, $loginLogs);
        $this->assertEquals('login', $loginLogs->first()->action);
    }

    /**
     * byUser 스코프: 특정 사용자의 로그만 반환
     */
    public function test_by_user_scope(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'a', 'user_id' => $user1->id]);
        ActivityLog::create(['log_type' => ActivityLogType::Admin, 'action' => 'b', 'user_id' => $user2->id]);

        $logs = ActivityLog::byUser($user1->id)->get();

        $this->assertCount(1, $logs);
        $this->assertEquals($user1->id, $logs->first()->user_id);
    }

    // ========================================================================
    // boot (created_at 자동 설정)
    // ========================================================================

    /**
     * created_at이 미지정이면 자동 설정됨
     */
    public function test_created_at_is_auto_set_on_create(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'test',
        ]);

        $this->assertNotNull($log->created_at);
    }

    // ========================================================================
    // loggable_type_display 접근자
    // ========================================================================

    /**
     * loggable_type_display: FQCN에서 짧은 클래스명 추출
     */
    public function test_loggable_type_display_extracts_short_class_name(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'product.update',
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Product',
            'loggable_id' => 1,
        ]);

        $this->assertEquals('Product', $log->loggable_type_display);
    }

    /**
     * loggable_type_display: 코어 모델 FQCN도 정상 추출
     */
    public function test_loggable_type_display_handles_core_model(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        $this->assertEquals('User', $log->loggable_type_display);
    }

    /**
     * loggable_type_display: null이면 null 반환
     */
    public function test_loggable_type_display_returns_null_when_loggable_type_is_null(): void
    {
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::System,
            'action' => 'maintenance',
            'loggable_type' => null,
        ]);

        $this->assertNull($log->loggable_type_display);
    }
}
