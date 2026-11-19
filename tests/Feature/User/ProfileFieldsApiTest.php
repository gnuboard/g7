<?php

namespace Tests\Feature\User;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldValueService;
use App\Models\FieldDefinition;
use App\Models\User;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 내 정보 화면 항목 조회(`GET /api/me/fields`)가 본인에게 맞는 항목 계약을 주는지.
 *
 * 화면은 이 응답으로 칸을 그린다 — 고칠 수 있는 항목, 볼 수만 있는 항목, 잠긴 칸과 그 사유, 보기용 문자열이 계약이다.
 */
class ProfileFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    /**
     * 역할과 코어 회원 항목을 시딩하고 회원 한 명을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        $this->member = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'mobile' => '010-1111-2222']);
    }

    /**
     * 테스트가 건 필터를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.user.locked_fields');
        HookManager::clearFilter('core.user.locked_field_reasons');
        parent::tearDown();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  string  $type  유형 키
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function custom(string $key, string $type = 'text', array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => 'core.user', 'key' => $key, 'type' => $type, 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => $key], 'sort_order' => 500,
            'enabled' => true,
        ], $attributes));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        return $definition->refresh();
    }

    /**
     * 내 정보 항목을 조회합니다.
     *
     * @return array<string, array<string, mixed>> 항목 키 => 항목 계약
     */
    private function fields(): array
    {
        $this->app['auth']->forgetGuards();
        $fields = $this->actingAs($this->member, 'sanctum')->getJson('/api/me/fields')->assertOk()->json('data.fields');

        return array_column($fields, null, 'key');
    }

    /**
     * 설정을 바꾸지 않은 사이트는 본인이 고칠 수 있는 기본 항목 11개를 순서대로 주고, 잠긴 칸이 없다. 비로그인은 401 이다.
     *
     * @scenario case=defaults
     *
     * @effects profile_schema_lists_self_editable_core_fields
     *
     * @return void 반환값 없음
     */
    public function test_default_schema_lists_self_editable_core_fields(): void
    {
        $this->getJson('/api/me/fields')->assertUnauthorized();

        $fields = $this->fields();

        $this->assertSame(
            ['name', 'nickname', 'mobile', 'phone', 'language', 'country', 'timezone', 'homepage', 'address', 'signature', 'bio'],
            array_keys($fields),
        );
        $this->assertTrue($fields['name']['required']);
        $this->assertSame([], array_keys(array_filter($fields, fn (array $field) => $field['readonly'])));
        $this->assertSame('홍길동', $fields['name']['display_value']);
        $this->assertSame(['zipcode' => 'zipcode', 'address' => 'address', 'address_detail' => 'address_detail'], $fields['address']['parts']);
    }

    /**
     * 관리자만 수정 항목은 읽기 전용(사유 admin_only)으로 값과 함께 나오고, 관리자만 열람 항목은 나오지 않는다.
     *
     * @scenario case=edit_scope
     *
     * @effects profile_schema_marks_admin_edit_readonly_and_hides_admin_only
     *
     * @return void 반환값 없음
     */
    public function test_admin_edit_field_is_readonly_and_admin_only_field_is_hidden(): void
    {
        $grade = $this->custom('t490_grade', 'choice', [
            'edit_scope' => 'admin_edit', 'required' => true,
            'options' => [['value' => 'a', 'label' => ['ko' => '에이 등급']]],
        ]);
        $this->custom('t490_memo', 'text', ['edit_scope' => 'admin_only']);
        $this->custom('t490_job', 'text', ['edit_scope' => 'self', 'required' => true]);

        app(FieldValueService::class)->write($this->member, 'core.user', ['t490_grade' => 'a'], FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $this->member));

        $fields = $this->fields();

        $this->assertArrayNotHasKey('t490_memo', $fields);
        $this->assertTrue($fields['t490_grade']['readonly']);
        $this->assertSame('admin_only', $fields['t490_grade']['locked_reason']);
        $this->assertFalse($fields['t490_grade']['required'], '본인이 넣을 수 없는 항목이 필수로 표시됐다');
        $this->assertSame('에이 등급', $fields['t490_grade']['display_value']);
        $this->assertSame($grade->key, $fields['t490_grade']['key']);

        $this->assertFalse($fields['t490_job']['readonly']);
        $this->assertTrue($fields['t490_job']['required']);
        $this->assertSame('custom_fields.t490_job', $fields['t490_job']['name']);
    }

    /**
     * 확장이 잠근 칸은 읽기 전용이고 확장이 준 사유가 실린다. 사유를 주지 않은 칸은 일반 사유(locked)다. 내 정보 응답에도 같은 사유가 실린다.
     *
     * @scenario case=extension_lock
     *
     * @effects profile_schema_carries_lock_reasons
     *
     * @return void 반환값 없음
     */
    public function test_extension_locked_fields_carry_reasons(): void
    {
        HookManager::addFilter('core.user.locked_fields', fn ($fields) => [...(array) $fields, 'name', 'mobile']);
        HookManager::addFilter('core.user.locked_field_reasons', fn ($reasons) => [...(array) $reasons, 'name' => 'sso', 'phone' => 'sso']);

        $fields = $this->fields();

        $this->assertTrue($fields['name']['readonly']);
        $this->assertSame('sso', $fields['name']['locked_reason']);
        $this->assertTrue($fields['mobile']['readonly']);
        $this->assertSame('locked', $fields['mobile']['locked_reason']);
        $this->assertFalse($fields['phone']['readonly'], '잠기지 않은 칸에 사유만 온 경우 잠그면 안 된다');

        $this->app['auth']->forgetGuards();
        $profile = $this->actingAs($this->member, 'sanctum')->getJson('/api/me')->assertOk()->json('data');
        $this->assertSame(['name', 'mobile'], $profile['locked_fields']);
        $this->assertSame(['name' => 'sso', 'mobile' => 'locked'], $profile['locked_reasons']);
    }

    /**
     * 닉네임 변경 주기가 아직 지나지 않았으면 닉네임 칸이 읽기 전용(사유 nickname_interval)이고 다시 바꿀 수 있는 시각이 실린다.
     *
     * @scenario case=nickname_interval
     *
     * @effects profile_schema_locks_nickname_within_change_interval
     *
     * @return void 반환값 없음
     */
    public function test_nickname_is_locked_within_change_interval(): void
    {
        config(['g7_settings.core.member.nickname_change_interval_days' => 30]);

        $this->assertFalse($this->fields()['nickname']['readonly'], '바꾼 적 없는 닉네임이 잠겼다');

        $this->member->forceFill(['nickname_changed_at' => now()->subDays(3)])->save();
        $field = $this->fields()['nickname'];

        $this->assertTrue($field['readonly']);
        $this->assertSame('nickname_interval', $field['locked_reason']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) $field['locked_until']);

        $this->member->forceFill(['nickname_changed_at' => now()->subDays(31)])->save();
        $this->assertFalse($this->fields()['nickname']['readonly'], '주기가 지난 닉네임이 잠겼다');
    }

    /**
     * 사용을 끈 항목은 나오지 않는다.
     *
     * @scenario case=disabled
     *
     * @effects profile_schema_omits_disabled_fields
     *
     * @return void 반환값 없음
     */
    public function test_disabled_field_is_omitted(): void
    {
        FieldDefinition::where('field_set', 'core.user')->where('key', 'homepage')->firstOrFail()->update(['enabled' => false]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $this->assertArrayNotHasKey('homepage', $this->fields());
    }
}
