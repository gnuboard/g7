<?php

namespace Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Notifications\Identity\IdentityNotificationType;
use App\Services\NotificationDefinitionService;
use App\Upgrades\Data\V7_1_0\Migrations\MigrateIdentityMessagesToNotifications;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * 7.1.0 업그레이드 스텝 07 — 본인인증 메시지 → 알림 정의 이관 테스트 (#699)
 *
 * 옛 표는 7.1.0 신규 설치에 없으므로 7.0.x 스키마를 테스트가 직접 만든다. MySQL 은 DDL 에서 트랜잭션을
 * 암묵 커밋해 트랜잭션 격리가 성립하지 않으므로(스텝 자신도 DROP 한다), 이 테스트는 시작 전 상태를
 * 기록해 두고 tearDown 에서 만든 행·표를 직접 되돌린다.
 */
class MigrateIdentityMessagesToNotificationsTest extends TestCase
{
    /** @var array<string, int> 테스트 시작 시 각 표의 최대 id */
    private array $maxIds = [];

    /** @var array<int, object> 시작 시 본인인증 알림 정의 행 (복원용) */
    private array $definitionSnapshot = [];

    /** @var array<int, object> 시작 시 본인인증 알림 템플릿 행 (복원용) */
    private array $templateSnapshot = [];

    /**
     * 스텝 클래스를 불러오고, 코어 본인인증 선언 행을 시딩한 뒤 시작 상태를 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('upgrades/data/7.1.0/migrations/07_MigrateIdentityMessagesToNotifications.php');

        // 시작 상태는 시딩 **전에** 잰다 — 이 클래스는 DDL(옛 표 생성·드랍)이 트랜잭션을 암묵 커밋해 RefreshDatabase 를
        // 쓰지 못하므로, 시딩한 행까지 tearDown 이 지워야 같은 프로세스의 뒤 테스트가 빈 표에서 시작한다
        // (시딩 뒤에 재면 시딩 행이 기준 아래로 들어가 남는다 — 뒤 테스트가 같은 type 으로 넣다 중복 키로 깨졌다).
        foreach (['notification_definitions', 'notification_templates', 'permissions', 'role_permissions', 'users'] as $table) {
            $this->maxIds[$table] = (int) DB::table($table)->max('id');
        }

        // 업데이트 순서: 마이그레이션 → 재시딩(코어 identity.* 선언 행) → 스텝
        $this->seed(NotificationDefinitionSeeder::class);
        $this->definitionSnapshot = DB::table('notification_definitions')->where('category', 'identity')->get()->all();
        $this->templateSnapshot = DB::table('notification_templates')
            ->whereIn('definition_id', array_column($this->definitionSnapshot, 'id'))->get()->all();

        $this->dropLegacyTables();
    }

    /**
     * 만든 행·표를 지우고 기존 본인인증 행을 시작 상태로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->dropLegacyTables();

        DB::table('notification_templates')->where('id', '>', $this->maxIds['notification_templates'])->delete();
        DB::table('notification_definitions')->where('id', '>', $this->maxIds['notification_definitions'])->delete();
        DB::table('role_permissions')->where('id', '>', $this->maxIds['role_permissions'])->delete();
        DB::table('permissions')->where('id', '>', $this->maxIds['permissions'])->delete();
        DB::table('users')->where('id', '>', $this->maxIds['users'])->delete();

        foreach ($this->definitionSnapshot as $row) {
            DB::table('notification_definitions')->where('id', $row->id)->update((array) $row);
        }
        foreach ($this->templateSnapshot as $row) {
            DB::table('notification_templates')->where('id', $row->id)->update((array) $row);
        }
        app(NotificationDefinitionService::class)->invalidateAllCache();

        parent::tearDown();
    }

    /**
     * 코어 5 + 이커머스 1 + 운영자 정책 1 + 메일 외 provider 1 을 옮긴다 — 건수·수정분·소유·권한·드랍.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_migrates_and_drops_legacy_tables, upgrade_preserves_operator_edits, upgrade_removes_message_permissions
     *
     * @return void 반환값 없음
     */
    public function test_migrates_legacy_messages_and_drops_tables(): void
    {
        $editorId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => '편집자', 'email' => 'idv699-editor@example.test',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createLegacyTables();

        // 코어 선언 5건 — signup 템플릿은 운영자가 고침, self_update 는 이름을 고침
        foreach (['' => 'provider_default', 'signup' => 'purpose', 'password_reset' => 'purpose', 'self_update' => 'purpose', 'sensitive_action' => 'purpose'] as $value => $scope) {
            $overrides = $value === 'self_update' ? ['name.ko'] : null;
            $name = $value === 'self_update' ? ['ko' => '운영자 이름', 'en' => 'Edited'] : ['ko' => '원본', 'en' => 'Original'];
            $id = $this->legacyDefinition('g7:core.mail', $scope, $value, 'core', 'core', $name, $overrides);
            $edited = $value === 'signup';
            $this->legacyTemplate(
                $id,
                $edited ? '[운영자] 가입 코드 {code}' : '원본 제목',
                $edited ? ['subject.ko', 'body.ko'] : null,
                $edited ? $editorId : null,
                true,
                $edited ? '<p>[운영자] 가입 본문 {code}</p>' : null
            );
        }
        // 이커머스(확장) 목적 정의 — 알림 정의 쪽에 아직 없다
        $ecom = $this->legacyDefinition('g7:core.mail', 'purpose', 'checkout_verification', 'module', 'sirsoft-ecommerce', ['ko' => '결제 시 본인 확인'], null);
        $this->legacyTemplate($ecom, '[{app_name}] 결제 본인 확인 인증 코드', null, null);
        // 운영자 정책 정의
        $policy = $this->legacyDefinition('g7:core.mail', 'policy', 'admin.board.delete', 'core', 'admin', ['ko' => '게시판 삭제 확인'], ['name.ko'], false);
        $this->legacyTemplate($policy, '[정책] 삭제 확인 {code}', ['subject.ko', 'body.ko'], $editorId, false);
        // 메일 외 provider — 발송에 쓰인 적이 없어 옮기지 않는다
        $kcp = $this->legacyDefinition('nhnkcp', 'purpose', 'kcp_only', 'plugin', 'sirsoft-verification_nhnkcp', ['ko' => 'KCP'], null);
        $this->legacyTemplate($kcp, 'KCP 제목', null, null);

        $roleId = (int) DB::table('roles')->value('id');
        foreach (['core.admin.identity.messages.read', 'core.admin.identity.messages.update'] as $identifier) {
            $permId = DB::table('permissions')->where('identifier', $identifier)->value('id')
                ?? DB::table('permissions')->insertGetId(['identifier' => $identifier, 'name' => '{"ko":"x"}', 'type' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permId]);
        }

        $this->runStep();

        $this->assertFalse(Schema::hasTable('identity_message_definitions'));
        $this->assertFalse(Schema::hasTable('identity_message_templates'));

        $signup = $this->templateOf('identity.purpose.signup');
        $this->assertSame('[운영자] 가입 코드 {code}', json_decode($signup->subject, true)['ko']);
        $this->assertSame('<p>[운영자] 가입 본문 {code}</p>', json_decode($signup->body, true)['ko'], '운영자가 고친 본문도 옮겨진다');
        $this->assertSame(['subject.ko', 'body.ko'], json_decode($signup->user_overrides, true));
        $this->assertSame($editorId, (int) $signup->updated_by);
        $this->assertSame(0, (int) $signup->is_default);

        $password = $this->templateOf('identity.purpose.password_reset');
        $this->assertStringNotContainsString('원본 제목', $password->subject, '고치지 않은 템플릿은 선언 기본값을 유지');

        $selfUpdate = DB::table('notification_definitions')->where('type', 'identity.purpose.self_update')->first();
        $this->assertSame('운영자 이름', json_decode($selfUpdate->name, true)['ko']);
        $this->assertContains('name.ko', json_decode((string) $selfUpdate->user_overrides, true));

        $policyDef = DB::table('notification_definitions')->where('type', 'identity.policy.admin.board.delete')->first();
        $this->assertNotNull($policyDef);
        $this->assertSame(['core', 'admin', 'identity', 'core.identity', 0], [
            $policyDef->extension_type, $policyDef->extension_identifier, $policyDef->category, $policyDef->hook_prefix, (int) $policyDef->is_default,
        ]);
        $this->assertSame('[정책] 삭제 확인 {code}', json_decode($this->templateOf('identity.policy.admin.board.delete')->subject, true)['ko']);

        $checkout = DB::table('notification_definitions')->where('type', 'identity.purpose.checkout_verification')->first();
        $this->assertSame(['module', 'sirsoft-ecommerce', 'sirsoft-ecommerce'], [$checkout->extension_type, $checkout->extension_identifier, $checkout->hook_prefix]);

        $this->assertNull(DB::table('notification_definitions')->where('type', 'identity.purpose.kcp_only')->first());

        $this->assertSame(0, DB::table('permissions')->whereIn('identifier', ['core.admin.identity.messages.read', 'core.admin.identity.messages.update'])->count());
    }

    /**
     * 표식만 있고 값이 선언 기본값과 같은 템플릿·이름은 옮기지 않는다 — 고친 적 없는 문구가 「수정됨」 으로 굳지 않는다.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_ignores_markers_with_unchanged_values
     *
     * @return void 반환값 없음
     */
    public function test_markers_with_unchanged_values_are_not_moved(): void
    {
        $this->createLegacyTables();
        $declared = $this->templateOf('identity.purpose.password_reset');
        $declaredDef = DB::table('notification_definitions')->where('type', 'identity.purpose.password_reset')->first();

        $id = DB::table('identity_message_definitions')->insertGetId([
            'provider_id' => 'g7:core.mail', 'scope_type' => 'purpose', 'scope_value' => 'password_reset',
            'name' => $declaredDef->name, 'description' => null, 'channels' => '["mail"]', 'variables' => null,
            'extension_type' => 'core', 'extension_identifier' => 'core', 'is_active' => true, 'is_default' => true,
            'user_overrides' => '["name.ko"]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('identity_message_templates')->insert([
            'definition_id' => $id, 'channel' => 'mail', 'subject' => $declared->subject, 'body' => $declared->body,
            'is_active' => true, 'is_default' => false, 'user_overrides' => '["subject","body"]',
            'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runStep();

        $after = $this->templateOf('identity.purpose.password_reset');
        $this->assertNull($after->user_overrides, '값이 같으면 표식을 옮기지 않는다');
        $this->assertSame(1, (int) $after->is_default);
        $this->assertNull(DB::table('notification_definitions')->where('id', $declaredDef->id)->value('user_overrides'));
    }

    /**
     * 긴 정책 키는 해시 type 으로 옮긴다 (IdentityNotificationType 규칙과 같은 결과).
     *
     * @scenario surface=upgrade
     *
     * @effects long_policy_key_hashed_within_column
     *
     * @return void 반환값 없음
     */
    public function test_long_policy_key_is_hashed(): void
    {
        $this->createLegacyTables();
        $key = 'admin.'.str_repeat('y', 100);
        $id = $this->legacyDefinition('g7:core.mail', 'policy', $key, 'core', 'admin', ['ko' => '긴 키'], null, false);
        $this->legacyTemplate($id, '긴 키 제목', null, null, false);

        $this->runStep();

        $this->assertNotNull(DB::table('notification_definitions')->where('type', IdentityNotificationType::forPolicy($key))->first());
    }

    /**
     * 건수 대조가 어긋나면 예외를 던지고 옛 표를 드랍하지 않는다 (데이터 보존).
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_count_mismatch_keeps_tables
     *
     * @return void 반환값 없음
     */
    public function test_count_mismatch_throws_and_keeps_tables(): void
    {
        $this->createLegacyTables();
        $id = $this->legacyDefinition('g7:core.mail', 'purpose', 'signup', 'core', 'core', ['ko' => '원본'], null);
        $this->legacyTemplate($id, '제목', null, null);
        // 정의 없는 템플릿(고아) — 어느 정의로도 옮겨지지 않아 대조가 어긋난다
        $this->legacyTemplate(999999, '고아 제목', null, null);

        try {
            $this->runStep();
            $this->fail('대조 불일치인데 예외가 나지 않았다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('드랍하지 않았습니다', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('identity_message_definitions'));
        $this->assertSame(2, DB::table('identity_message_templates')->count());
    }

    /**
     * 옛 표가 없으면(신규 설치·재실행) 이관을 건너뛰고 오류 없이 끝난다 — 멱등.
     *
     * @scenario surface=upgrade
     *
     * @effects upgrade_rerun_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_rerun_without_tables_is_noop(): void
    {
        $before = DB::table('notification_definitions')->count();

        $this->runStep();
        $this->runStep();

        $this->assertSame($before, DB::table('notification_definitions')->count());
    }

    /**
     * 스텝을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runStep(): void
    {
        (new MigrateIdentityMessagesToNotifications)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
    }

    /**
     * 7.0.x 의 옛 표를 만듭니다 (고아 템플릿 검증을 위해 FK 는 걸지 않는다).
     *
     * @return void 반환값 없음
     */
    private function createLegacyTables(): void
    {
        Schema::create('identity_message_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id', 64);
            $table->string('scope_type', 32);
            $table->string('scope_value', 120)->default('');
            $table->text('name');
            $table->mediumText('description')->nullable();
            $table->text('channels');
            $table->text('variables')->nullable();
            $table->string('extension_type', 20);
            $table->string('extension_identifier', 100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(true);
            $table->text('user_overrides')->nullable();
            $table->timestamps();
        });
        Schema::create('identity_message_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('definition_id');
            $table->string('channel', 20);
            $table->text('subject')->nullable();
            $table->mediumText('body');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(true);
            $table->text('user_overrides')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * 옛 표를 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function dropLegacyTables(): void
    {
        Schema::dropIfExists('identity_message_templates');
        Schema::dropIfExists('identity_message_definitions');
    }

    /**
     * 옛 정의 행을 넣습니다.
     *
     * @param  string  $provider  프로바이더 ID
     * @param  string  $scopeType  범위
     * @param  string  $scopeValue  범위 값
     * @param  string  $extType  소유 확장 유형
     * @param  string  $extId  소유 확장 식별자
     * @param  array<string, string>  $name  이름
     * @param  array<int, string>|null  $overrides  운영자 수정 표식
     * @param  bool  $isDefault  선언 기본값 여부
     * @return int 행 id
     */
    private function legacyDefinition(string $provider, string $scopeType, string $scopeValue, string $extType, string $extId, array $name, ?array $overrides, bool $isDefault = true): int
    {
        return DB::table('identity_message_definitions')->insertGetId([
            'provider_id' => $provider, 'scope_type' => $scopeType, 'scope_value' => $scopeValue,
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE), 'description' => null, 'channels' => '["mail"]',
            'variables' => null, 'extension_type' => $extType, 'extension_identifier' => $extId,
            'is_active' => true, 'is_default' => $isDefault,
            'user_overrides' => $overrides === null ? null : json_encode($overrides),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 옛 템플릿 행을 넣습니다.
     *
     * @param  int  $definitionId  정의 id
     * @param  string  $subject  제목 (ko)
     * @param  array<int, string>|null  $overrides  운영자 수정 표식
     * @param  int|null  $updatedBy  수정자
     * @param  bool  $isDefault  선언 기본값 여부
     * @param  string|null  $body  본문 (ko) — null 이면 공통 기본 본문
     * @return void 반환값 없음
     */
    private function legacyTemplate(int $definitionId, string $subject, ?array $overrides, ?int $updatedBy, bool $isDefault = true, ?string $body = null): void
    {
        DB::table('identity_message_templates')->insert([
            'definition_id' => $definitionId, 'channel' => 'mail',
            'subject' => json_encode(['ko' => $subject, 'en' => $subject], JSON_UNESCAPED_UNICODE),
            'body' => json_encode(['ko' => $body ?? '<p>{code}</p>', 'en' => '<p>{code}</p>'], JSON_UNESCAPED_UNICODE),
            'is_active' => true, 'is_default' => $overrides === null && $isDefault,
            'user_overrides' => $overrides === null ? null : json_encode($overrides),
            'updated_by' => $updatedBy, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 알림 정의 type 의 메일 템플릿 행을 반환합니다.
     *
     * @param  string  $type  알림 type
     * @return object 템플릿 행
     */
    private function templateOf(string $type): object
    {
        $definitionId = DB::table('notification_definitions')->where('type', $type)->value('id');

        return DB::table('notification_templates')->where('definition_id', $definitionId)->where('channel', 'mail')->first();
    }
}
