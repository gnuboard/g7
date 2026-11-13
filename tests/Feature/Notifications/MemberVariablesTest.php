<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Listeners\NotificationHookListener;
use App\Models\FieldDefinition;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GuestNotifiable;
use App\Notifications\MemberVariables;
use Database\Seeders\CoreFieldDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Helpers\MocksChannelReadiness;
use Tests\TestCase;

/**
 * 알림 템플릿의 회원 항목 변수(`{member.<키>}`, `{member.custom.<키>}`, `{member.display_name}`)를 잰다.
 *
 * 템플릿이 참조한 변수만 채우고, 수신자 묶음마다 값을 한 번에 읽고, 「관리자만 열람」 항목은 넣지 않는다.
 * 회원이 아닌 수신자는 이름만 채우고, 자기 템플릿을 코어가 읽지 못하는 확장 채널은 필터로 변수 이름을 보탠다.
 * 없는 변수를 쓴 템플릿은 저장되되 응답의 `warnings` 로 알려 준다.
 */
class MemberVariablesTest extends TestCase
{
    use MocksChannelReadiness;
    use RefreshDatabase;

    private const HOOK_PREFIX = 'core.test490vars';

    /**
     * 실제로 나간 알림 데이터 — 회원 ID(비회원은 이메일) => 데이터.
     *
     * @var array<int|string, array<string, mixed>>
     */
    private array $delivered = [];

    /**
     * 역할과 코어 회원 항목을 시딩하고, 실제로 나간 알림 데이터를 모으는 구독자를 건다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(CoreFieldDefinitionSeeder::class);

        Notification::fake();
        $this->mockAllChannelsReady();

        HookManager::addFilter(self::HOOK_PREFIX.'.notification.extract_data', fn ($default) => array_merge($default, [
            'data' => ['name' => '{recipient_name}', 'app_name' => 'G7'],
        ]), priority: 20);

        $this->delivered = [];
        HookManager::addAction('core.notification.after_send', function ($notifiable, $definition, $data) {
            $this->delivered[$notifiable instanceof User ? $notifiable->id : $notifiable->email] = $data;
        }, priority: 20);
    }

    /**
     * 테스트가 건 구독자를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(self::HOOK_PREFIX.'.notification.extract_data');
        HookManager::clearFilter('core.notification.filter_member_variable_keys');
        HookManager::clearFilter('core.notification.filter_template_variables');
        HookManager::clearAction('core.notification.after_send');
        parent::tearDown();
    }

    /**
     * 운영자 추가 항목을 만듭니다.
     *
     * @param  string  $key  항목 키
     * @param  string  $label  항목 이름
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return FieldDefinition 만든 항목
     */
    private function customField(string $key, string $label, array $attributes = []): FieldDefinition
    {
        $definition = FieldDefinition::create(array_merge([
            'field_set' => FieldSet::CORE_USER, 'key' => $key, 'type' => 'text', 'source_type' => 'custom', 'source_identifier' => 'custom',
            'storage' => 'value', 'name' => ['ko' => $label, 'en' => $label], 'enabled' => true, 'sort_order' => 900,
        ], $attributes));
        $this->forgetFieldMemo();

        return $definition;
    }

    /**
     * 항목 목록 메모를 비웁니다 (항목을 만들거나 고친 뒤).
     *
     * @return void 반환값 없음
     */
    private function forgetFieldMemo(): void
    {
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        app(MemberVariables::class)->flush();
    }

    /**
     * 회원의 추가 항목 값을 저장합니다.
     *
     * @param  User  $user  회원
     * @param  array<string, string>  $values  항목 키 => 값
     * @return void 반환값 없음
     */
    private function setValues(User $user, array $values): void
    {
        app(FieldValueService::class)->write($user, FieldSet::CORE_USER, $values, FieldContext::for(FieldContext::SURFACE_ADMIN_UPDATE, $user));
    }

    /**
     * 템플릿 하나를 가진 알림 정의를 만듭니다.
     *
     * @param  string  $body  본문
     * @param  array<int, array<string, mixed>>  $recipients  수신자 규칙
     * @param  string  $channel  채널
     * @param  string  $type  알림 종류 (생략하면 새 이름)
     * @return NotificationDefinition 알림 정의
     */
    private function definition(string $body, array $recipients = [], string $channel = 'database', ?string $type = null): NotificationDefinition
    {
        $definition = NotificationDefinition::create([
            'type' => $type ?? 'issue490_vars_'.uniqid(), 'hook_prefix' => self::HOOK_PREFIX, 'extension_type' => 'core', 'extension_identifier' => 'core',
            'name' => ['ko' => '회원 변수'], 'variables' => [['key' => 'name', 'description' => '수신자 이름']],
            'channels' => [$channel], 'hooks' => [], 'is_active' => true, 'is_default' => true,
        ]);

        NotificationTemplate::create([
            'definition_id' => $definition->id, 'channel' => $channel, 'subject' => ['ko' => '안내'], 'body' => ['ko' => $body],
            'recipients' => $recipients, 'is_active' => true, 'is_default' => true,
        ]);

        return $definition;
    }

    /**
     * 템플릿이 참조한 회원 변수만 알림 데이터에 들어가고, 수신자가 여럿이어도 항목 값은 한 번에 읽는다.
     *
     * @scenario case=referenced_keys_only
     *
     * @effects only_referenced_member_variables_are_injected, member_values_are_read_once_per_recipient_batch, core_and_custom_and_display_name_variables_resolve, template_without_member_variables_adds_nothing
     *
     * @return void 반환값 없음
     */
    public function test_only_referenced_variables_are_loaded_once_per_batch(): void
    {
        $this->customField('job', '직업');
        $this->customField('hobby', '취미');

        $users = collect(range(1, 4))->map(function (int $i) {
            $user = User::factory()->create(['name' => "회원{$i}", 'nickname' => "닉{$i}"]);
            $this->setValues($user, ['job' => "직업{$i}", 'hobby' => "취미{$i}"]);

            return $user;
        });
        $recipients = [['type' => 'specific_users', 'value' => $users->pluck('uuid')->all()]];
        $definition = $this->definition('{member.display_name}님({member.nickname})의 직업은 {member.custom.job}', $recipients);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(NotificationHookListener::class)->dispatchForDefinition($definition);
        $valueQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();

        $this->assertCount(1, $valueQueries, '수신자마다 항목 값을 읽었다');

        foreach ($users as $index => $user) {
            $data = $this->delivered[$user->id] ?? [];
            $n = $index + 1;
            $this->assertSame("직업{$n}", $data['member.custom.job'] ?? null);
            $this->assertSame("닉{$n}", $data['member.nickname'] ?? null);
            $this->assertSame("회원{$n}", $data['member.display_name'] ?? null);
            $this->assertArrayNotHasKey('member.custom.hobby', $data, '참조하지 않은 변수가 들어갔다');
            $this->assertArrayNotHasKey('member.name', $data);
        }

        // 템플릿이 실제로 치환된다
        $template = $definition->templates()->firstOrFail();
        $this->assertSame(
            '회원1님(닉1)의 직업은 직업1',
            $template->replaceVariables($this->delivered[$users[0]->id], 'ko')['body'],
        );

        // 회원 변수를 쓰지 않는 템플릿은 아무것도 더하지 않고 항목 값도 읽지 않는다
        $this->delivered = [];
        $plain = $this->definition('{name}님 안녕하세요', $recipients);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(NotificationHookListener::class)->dispatchForDefinition($plain);
        $valueQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'field_values'));
        DB::disableQueryLog();

        $this->assertCount(0, $valueQueries);
        $this->assertSame(['name' => '회원1', 'app_name' => 'G7'], $this->delivered[$users[0]->id]);
    }

    /**
     * 「관리자만 열람」 항목과 꺼진 항목의 변수는 값이 들어가지 않는다(빈 글자). 편집 화면 목록에도 없다.
     *
     * @scenario case=admin_only_not_injected
     *
     * @effects admin_only_field_value_is_never_injected, disabled_field_variable_is_blank, admin_only_field_is_not_listed_as_variable
     *
     * @return void 반환값 없음
     */
    public function test_admin_only_fields_are_not_injected(): void
    {
        $this->customField('memo', '관리 메모', ['edit_scope' => FieldDefinition::SCOPE_ADMIN_ONLY]);
        $off = $this->customField('legacy', '옛 항목');
        $this->customField('job', '직업');

        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);
        $this->setValues($user, ['memo' => '블랙리스트 검토 중', 'legacy' => '옛 값', 'job' => '목수']);
        $off->update(['enabled' => false]);
        $this->forgetFieldMemo();

        $definition = $this->definition(
            '메모:{member.custom.memo} 옛:{member.custom.legacy} 직업:{member.custom.job}',
            [['type' => 'specific_users', 'value' => [$user->uuid]]],
        );
        app(NotificationHookListener::class)->dispatchForDefinition($definition);

        $data = $this->delivered[$user->id];
        $this->assertSame('', $data['member.custom.memo'], '관리자만 열람 항목의 값이 알림에 들어갔다');
        $this->assertSame('', $data['member.custom.legacy']);
        $this->assertSame('목수', $data['member.custom.job']);
        $this->assertStringNotContainsString('블랙리스트', json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertSame(
            '메모: 옛: 직업:목수',
            $definition->templates()->firstOrFail()->replaceVariables($data, 'ko')['body'],
        );

        $keys = array_column(app(MemberVariables::class)->catalog('ko'), 'key');
        $this->assertContains('member.custom.job', $keys);
        $this->assertNotContains('member.custom.memo', $keys);
        $this->assertNotContains('member.custom.legacy', $keys);
    }

    /**
     * 회원이 아닌 수신자는 표시 이름 변수에 받은 이름이 들어가고 나머지 회원 변수는 빈 글자다.
     *
     * @scenario case=guest_recipient
     *
     * @effects guest_display_name_variable_uses_given_name, guest_member_variables_are_blank
     *
     * @return void 반환값 없음
     */
    public function test_guest_recipient_gets_name_only(): void
    {
        $this->customField('job', '직업');
        $definition = $this->definition('{member.display_name}님 직업:{member.custom.job} 닉:{member.nickname}', [], 'mail');

        HookManager::clearFilter(self::HOOK_PREFIX.'.notification.extract_data');
        HookManager::addFilter(self::HOOK_PREFIX.'.notification.extract_data', fn ($default) => array_merge($default, [
            'notifiables' => [new GuestNotifiable('guest@example.com', '비회원 주문자')],
            'data' => ['name' => '{recipient_name}'],
        ]), priority: 20);

        app(NotificationHookListener::class)->dispatchForDefinition($definition);

        $data = $this->delivered['guest@example.com'] ?? null;
        $this->assertNotNull($data, '비회원 수신자에게 알림이 나가지 않았다');
        $this->assertSame('비회원 주문자', $data['name']);
        $this->assertSame('비회원 주문자', $data['member.display_name']);
        $this->assertSame('', $data['member.custom.job']);
        $this->assertSame('', $data['member.nickname']);
    }

    /**
     * 자기 템플릿을 코어가 읽지 못하는 확장 채널은 필터로 변수 이름을 보탠다. 필터는 알림 종류와 채널을 받는다.
     *
     * @scenario case=extension_channel_filter
     *
     * @effects extension_channel_adds_member_variable_keys, member_variable_key_filter_receives_type_and_channel, non_member_keys_from_filter_are_ignored
     *
     * @return void 반환값 없음
     */
    public function test_extension_channel_adds_keys_through_filter(): void
    {
        $this->customField('job', '직업');
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이']);
        $this->setValues($user, ['job' => '목수']);

        // 템플릿 본문에는 회원 변수가 없다 — 확장 채널이 바깥 서비스에 등록해 둔 문구가 변수를 쓴다고 가정한다.
        $definition = $this->definition('{name}님', [['type' => 'specific_users', 'value' => [$user->uuid]]], 'vendor_push');

        $seen = [];
        HookManager::addFilter('core.notification.filter_member_variable_keys', function (array $keys, string $type, string $channel) use (&$seen) {
            $seen[] = [$keys, $type, $channel];

            return $channel === 'vendor_push' ? [...$keys, 'member.custom.job', 'member.nickname', 'order_number'] : $keys;
        }, priority: 20);

        app(NotificationHookListener::class)->dispatchForDefinition($definition);

        $this->assertSame([[[], $definition->type, 'vendor_push']], $seen);
        $data = $this->delivered[$user->id];
        $this->assertSame('목수', $data['member.custom.job']);
        $this->assertSame('길동이', $data['member.nickname']);
        $this->assertArrayNotHasKey('order_number', $data, '회원 변수가 아닌 이름이 필터로 들어왔다');
    }

    /**
     * 편집 화면의 변수 목록에 회원 공통 변수 묶음이 덧붙고 확장이 필터로 더할 수 있다. 본인인증 알림에는 덧붙지 않는다.
     *
     * @scenario case=template_variable_list
     *
     * @effects member_variables_are_appended_to_template_variable_list, template_variable_filter_can_add_variables, identity_notifications_get_no_member_variables, preview_shows_member_variable_descriptions
     *
     * @return void 반환값 없음
     */
    public function test_template_variable_list_includes_member_variables(): void
    {
        $this->customField('job', '직업');
        $definition = $this->definition('본문');

        $variables = collect($definition->localizedVariables())->keyBy('key');
        $this->assertSame(['name', 'member.display_name'], $variables->keys()->take(2)->all(), '선언한 변수 뒤에 회원 변수가 와야 한다');
        $this->assertSame('member', $variables['member.display_name']['group']);
        $this->assertSame(__('notification.member_variables.field', ['name' => '직업']), $variables['member.custom.job']['description']);
        $this->assertTrue($variables->has('member.nickname'));
        $this->assertArrayNotHasKey('group', $variables['name']);

        HookManager::addFilter('core.notification.filter_template_variables', function (array $list, NotificationDefinition $subject) use ($definition) {
            return $subject->is($definition) ? [...$list, ['key' => 'member.vendor_grade', 'description' => '등급', 'group' => 'member']] : $list;
        }, priority: 20);
        $this->assertContains('member.vendor_grade', array_column($definition->localizedVariables(), 'key'));
        HookManager::clearFilter('core.notification.filter_template_variables');

        $identity = $this->definition('본문', [], 'mail', 'identity.signup');
        $this->assertSame(['name'], array_column($identity->localizedVariables(), 'key'));

        $admin = $this->superAdmin();
        $preview = $this->postJson('/api/admin/notification-templates/preview', [
            'definition_id' => $definition->id, 'locale' => 'ko',
            'subject' => ['ko' => '{member.display_name}님'], 'body' => ['ko' => '직업 {member.custom.job}'],
        ], ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'])->assertOk();
        $this->assertSame('직업 ['.__('notification.member_variables.field', ['name' => '직업']).']', $preview->json('data.body'));
    }

    /**
     * 없는 회원 변수를 쓴 템플릿은 저장되고, 응답의 `warnings` 가 없는 변수 이름을 알려 준다.
     *
     * @scenario case=unknown_variable_warning
     *
     * @effects template_with_unknown_member_variable_is_saved, save_response_lists_unknown_member_variables, save_response_has_empty_warnings_when_all_known
     *
     * @return void 반환값 없음
     */
    public function test_saving_template_with_unknown_variable_returns_warnings(): void
    {
        $job = $this->customField('job', '직업');
        $this->customField('memo', '관리 메모', ['edit_scope' => FieldDefinition::SCOPE_ADMIN_ONLY]);
        $definition = $this->definition('본문');
        $template = $definition->templates()->firstOrFail();
        $admin = $this->superAdmin();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken, 'Accept' => 'application/json'];

        $body = '<p>{name}님 직업 {member.custom.job}, 닉네임 {member.nickname}</p>';
        $response = $this->putJson("/api/admin/notification-templates/{$template->id}", ['body' => ['ko' => $body]], $headers)->assertOk();
        $this->assertSame([], $response->json('data.warnings'));

        // 항목을 지운 뒤 같은 본문을 다시 저장한다
        $job->delete();
        $this->forgetFieldMemo();

        $body = '<p>직업 {member.custom.job}, 메모 {member.custom.memo}, 오타 {member.nicknam}, 이름 {member.display_name}</p>';
        $response = $this->putJson("/api/admin/notification-templates/{$template->id}", ['subject' => ['ko' => '{member.custom.job} 안내'], 'body' => ['ko' => $body]], $headers)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($body, $template->fresh()->body['ko'], '경고가 있어도 저장은 되어야 한다');

        $warnings = $response->json('data.warnings');
        $this->assertSame(['member.custom.job', 'member.custom.memo', 'member.nicknam'], array_column($warnings, 'target'));
        $this->assertSame(['template_variable'], array_values(array_unique(array_column($warnings, 'step'))));
        $this->assertSame(['warning'], array_values(array_unique(array_column($warnings, 'level'))));
        $this->assertStringContainsString('{member.custom.job}', $warnings[0]['message']);
    }

    /**
     * 최고관리자를 만듭니다.
     *
     * @return User 최고관리자
     */
    private function superAdmin(): User
    {
        $admin = User::factory()->create(['is_super' => true]);
        $role = Role::where('identifier', 'admin')->first();
        if ($role) {
            $admin->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $admin->fresh();
    }
}
