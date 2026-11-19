<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 운영자가 발송 수단을 켜서 코어가 만드는 빈 템플릿(`is_user_created`) 시험.
 *
 * 저장 버튼으로 모아 보내는 화면과 스위치마다 즉시 보내는 화면이 모두 정의 수정 API(PUT channels)를 지나므로 이 API 에서 잰다.
 * 스키마를 새로 만들지 않고(로컬 MySQL migrate:fresh OOM 회피) 트랜잭션으로 격리하며, 알림 유형에 실행 고유 접미어를 붙인다.
 */
class NotificationUserCreatedTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private NotificationDefinition $definition;

    private NotificationTemplate $mail;

    /** 본문을 확장이 따로 받는 채널(편집기 none) — 시험 안에서만 등록한다 */
    private const EXTERNAL_CHANNEL = 'test_external';

    /** @var callable 채널 목록 필터 */
    private $channelFilter;

    /**
     * 설정 권한 관리자와 메일 템플릿 하나를 가진 정의를 만들고, 편집기 none 채널을 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdmin()->createToken('test-token')->plainTextToken;

        $this->definition = NotificationDefinition::create([
            'type' => 'user_created_tpl_'.Str::lower(Str::random(8)),
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '빈 템플릿 시험', 'en' => 'Empty template test'],
            'channels' => ['mail'],
            'hooks' => ['core.test.happened'],
        ]);
        $this->mail = NotificationTemplate::create([
            'definition_id' => $this->definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '<p>본문</p>', 'en' => '<p>Body</p>'],
            'recipients' => [['type' => 'role', 'value' => 'admin']],
            'is_active' => true,
        ]);

        $this->channelFilter = function (array $channels): array {
            $channels[] = ['id' => self::EXTERNAL_CHANNEL, 'name' => 'External', 'source' => 'test', 'template_editor' => 'none'];

            return $channels;
        };
        HookManager::addFilter('core.notification.filter_available_channels', $this->channelFilter);
    }

    /**
     * 시험용 채널 필터를 뗍니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::removeFilter('core.notification.filter_available_channels', $this->channelFilter);
        parent::tearDown();
    }

    /**
     * 템플릿이 없는 수단을 켜면 꺼진 빈 템플릿이 운영자 생성 표식과 함께 생기고, 이미 있는 템플릿은 그대로다.
     *
     * @scenario action=enable_channel, template=missing|existing
     *
     * @effects empty_template_created_on_enable, existing_template_untouched
     *
     * @return void 반환값 없음
     */
    public function test_enabling_channel_without_template_creates_empty_template(): void
    {
        $this->putChannels(['mail', 'database'])->assertOk();

        $created = $this->template('database');
        $this->assertNotNull($created);
        $this->assertTrue($created->is_user_created);
        $this->assertFalse((bool) $created->is_active);
        $this->assertNull($created->subject);
        $this->assertSame([], $created->body);
        $this->assertSame([], $created->recipients);
        $this->assertSame(['ko' => '제목', 'en' => 'Subject'], $this->mail->fresh()->subject);
        $this->assertFalse($this->mail->fresh()->is_user_created);

        // 다시 저장해도 두 번 만들지 않는다
        $this->putChannels(['mail', 'database'])->assertOk();
        $this->assertSame(1, NotificationTemplate::where('definition_id', $this->definition->id)->where('channel', 'database')->count());
    }

    /**
     * 수단을 다시 끄면 운영자가 만든 템플릿은 남는다 — 다시 켜면 써 둔 내용이 그대로다.
     *
     * @scenario action=disable_channel, template=user_created
     *
     * @effects user_template_kept_on_disable
     *
     * @return void 반환값 없음
     */
    public function test_disabling_channel_keeps_user_created_template(): void
    {
        $this->putChannels(['mail', 'database'])->assertOk();
        $this->putChannels(['mail'])->assertOk();

        $this->assertNotNull($this->template('database'));
    }

    /**
     * 비어 있는 운영자 템플릿은 켜지 못한다(받는 사람·본문 안내 422). 채운 뒤에는 켜진다.
     *
     * @scenario action=toggle_on, template=user_created_empty|user_created_filled
     *
     * @effects empty_user_template_toggle_rejected, filled_user_template_toggle_allowed
     *
     * @return void 반환값 없음
     */
    public function test_empty_user_created_template_cannot_be_turned_on(): void
    {
        $this->putChannels(['mail', 'database'])->assertOk();
        $created = $this->template('database');

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$created->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients', 'body']);
        $this->assertFalse((bool) $created->fresh()->is_active);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$created->id}", [
            'subject' => null,
            'body' => ['ko' => '가입 {count}건'],
            'recipients' => [['type' => 'role', 'value' => ['admin']]],
        ])->assertOk();

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$created->id}/toggle-active")->assertOk();
        $this->assertTrue((bool) $created->fresh()->is_active);
    }

    /**
     * 본문을 확장이 따로 받는 채널(편집기 none)은 코어 본문이 비어도 저장·켜기가 된다 — 받는 사람만 본다.
     *
     * @scenario action=toggle_on, channel=template_editor_none
     *
     * @effects extension_edited_channel_needs_only_recipients
     *
     * @return void 반환값 없음
     */
    public function test_extension_edited_channel_only_requires_recipients(): void
    {
        $this->putChannels(['mail', self::EXTERNAL_CHANNEL])->assertOk();
        $created = $this->template(self::EXTERNAL_CHANNEL);
        $this->assertNotNull($created);

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$created->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients'])
            ->assertJsonMissingValidationErrors(['body']);

        // 확장의 저장 버튼은 받는 사람을 저장하려고 빈 본문 그대로 코어 저장 API 를 부른다
        $this->authRequest()->putJson("/api/admin/notification-templates/{$created->id}", [
            'subject' => null,
            'body' => [],
            'recipients' => [['type' => 'trigger_user']],
        ])->assertOk();

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$created->id}/toggle-active")->assertOk();
    }

    /**
     * 확장이 선언한 템플릿은 받는 사람이 비어 있어도 켜기를 막지 않는다(받는 사람 없이 쓰는 선언이 있다).
     *
     * @scenario action=toggle_on, template=declared_without_recipients
     *
     * @effects declared_template_toggle_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_declared_template_without_recipients_still_toggles(): void
    {
        $this->mail->update(['recipients' => null, 'is_active' => false]);

        $this->authRequest()->patchJson("/api/admin/notification-templates/{$this->mail->id}/toggle-active")->assertOk();
        $this->assertTrue((bool) $this->mail->fresh()->is_active);
    }

    /**
     * 정의의 [기본값으로 되돌리기]·템플릿 되돌리기는 운영자가 만든 템플릿을 만들었을 때의 빈 상태(꺼짐)로 돌린다.
     *
     * @scenario action=reset, scope=definition|template
     *
     * @effects user_template_reset_to_empty
     *
     * @return void 반환값 없음
     */
    public function test_reset_returns_user_created_template_to_empty_state(): void
    {
        $this->putChannels(['mail', 'database'])->assertOk();
        $created = $this->template('database');
        $this->authRequest()->putJson("/api/admin/notification-templates/{$created->id}", [
            'body' => ['ko' => '내용'],
            'recipients' => [['type' => 'trigger_user']],
        ])->assertOk();
        $this->authRequest()->patchJson("/api/admin/notification-templates/{$created->id}/toggle-active")->assertOk();

        $this->authRequest()->postJson("/api/admin/notification-definitions/{$this->definition->id}/reset")->assertOk();

        $reset = $created->fresh();
        $this->assertNotNull($reset);
        $this->assertFalse((bool) $reset->is_active);
        $this->assertSame([], $reset->body);
        $this->assertSame([], $reset->recipients);
        $this->assertNull($reset->user_overrides);

        $this->authRequest()->putJson("/api/admin/notification-templates/{$created->id}", [
            'body' => ['ko' => '다시'],
        ])->assertOk();
        $this->authRequest()->postJson("/api/admin/notification-templates/{$created->id}/reset")->assertOk();
        $this->assertSame([], $created->fresh()->body);
    }

    /**
     * 확장 업데이트의 재동기화는 운영자가 켠 수단과 운영자가 만든 템플릿을 지우지 않는다.
     * 그 뒤 확장이 같은 수단을 선언하면 그 행은 선언 행이 된다.
     *
     * @scenario action=extension_resync, template=user_created
     *
     * @effects user_channels_survive_resync, user_template_survives_cleanup, declared_channel_takes_over
     *
     * @return void 반환값 없음
     */
    public function test_resync_keeps_operator_channels_and_templates(): void
    {
        $this->putChannels(['mail', 'database'])->assertOk();
        $helper = app(NotificationSyncHelper::class);

        $definition = $helper->syncDefinition([
            'type' => $this->definition->type,
            'hook_prefix' => 'core.test',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '빈 템플릿 시험', 'en' => 'Empty template test'],
            'channels' => ['mail'],
            'hooks' => ['core.test.happened'],
        ]);
        $helper->cleanupStaleTemplates((int) $definition->id, ['mail']);

        $this->assertEqualsCanonicalizing(['mail', 'database'], $definition->fresh()->channels);
        $this->assertNotNull($this->template('database'));

        $helper->syncTemplate((int) $definition->id, ['channel' => 'database', 'body' => ['ko' => '선언'], 'recipients' => []]);
        $this->assertFalse($this->template('database')->is_user_created);
    }

    /**
     * 발송 수단 순서만 바꿔 저장한 것은 수정으로 기록하지 않는다.
     *
     * @scenario action=enable_channel, input=reordered
     *
     * @effects reordered_channels_not_override
     *
     * @return void 반환값 없음
     */
    public function test_reordering_channels_is_not_recorded_as_override(): void
    {
        // 선언값으로 맞추는 준비는 동기화 문맥에서 한다 — 운영자 수정이 아니다
        app()->instance('user_overrides.seeding', true);
        $this->definition->update(['channels' => ['mail', 'database'], 'user_overrides' => null]);
        app()->forgetInstance('user_overrides.seeding');

        $this->definition->fresh()->update(['channels' => ['database', 'mail']]);

        $this->assertEmpty($this->definition->fresh()->user_overrides ?? []);
    }

    /**
     * 정의 발송 수단을 저장합니다.
     *
     * @param  array<int, string>  $channels  발송 수단
     * @return \Illuminate\Testing\TestResponse 응답
     */
    private function putChannels(array $channels)
    {
        return $this->authRequest()->putJson("/api/admin/notification-definitions/{$this->definition->id}", ['channels' => $channels]);
    }

    /**
     * 이 정의의 채널 템플릿을 찾습니다.
     *
     * @param  string  $channel  채널
     * @return NotificationTemplate|null 템플릿
     */
    private function template(string $channel): ?NotificationTemplate
    {
        return NotificationTemplate::where('definition_id', $this->definition->id)->where('channel', $channel)->first();
    }

    /**
     * 인증 헤더를 붙인 요청.
     *
     * @return static 요청 빌더
     */
    private function authRequest(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 설정 조회·수정 권한 관리자를 만듭니다(역할 식별자에 실행 고유 접미어).
     *
     * @return User 관리자
     */
    private function createAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => ['ko' => '시험 관리자'],
            'identifier' => 'user_tpl_admin_'.Str::lower(Str::random(6)),
            'is_admin' => true,
        ]);
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
