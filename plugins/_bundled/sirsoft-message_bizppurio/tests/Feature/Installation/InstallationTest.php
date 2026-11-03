<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Installation;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 비즈뿌리오 메시징 플러그인 설치 스모크 테스트.
 *
 * plugin.php 가 선언하는 권한/알림 정의/설정 스키마 구조와
 * 관리자 메뉴 미생성(⚑ 결정 3) 을 검증한다.
 *
 * @scenario credential_state=empty
 */
class InstallationTest extends PluginTestCase
{
    private Plugin $plugin;

    /**
     * 테스트마다 검증 대상 Plugin 인스턴스를 새로 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new Plugin;
    }

    /**
     * 플러그인 식별자가 디렉터리명 sirsoft-message_bizppurio 와 같고 벤더가 sirsoft 인지 확인한다.
     *
     * @effects plugin_identifier_and_vendor_match_directory_name
     *
     * @return void 반환값 없음
     */
    public function test_식별자가_디렉토리명과_일치한다(): void
    {
        $this->assertSame('sirsoft-message_bizppurio', $this->plugin->getIdentifier());
        $this->assertSame('sirsoft', $this->plugin->getVendor());
    }

    /**
     * 권한 선언이 messaging 카테고리 하나에 view 와 manage 두 admin 권한만 두고, 둘 다 admin 역할에 기본 부여되는지 확인한다.
     *
     * @effects install_creates_view_and_manage_permissions
     *
     * @return void 반환값 없음
     */
    public function test_권한이_view_manage_2개로_선언된다(): void
    {
        $permissions = $this->plugin->getPermissions();

        $this->assertArrayHasKey('categories', $permissions);
        $this->assertCount(1, $permissions['categories']);

        $category = $permissions['categories'][0];
        $this->assertSame('messaging', $category['identifier']);

        $actions = array_column($category['permissions'], 'action');
        $this->assertEqualsCanonicalizing(['view', 'manage'], $actions);

        foreach ($category['permissions'] as $permission) {
            $this->assertContains('admin', $permission['roles']);
            $this->assertSame('admin', $permission['type']);
        }
    }

    /**
     * 설정 스키마가 password, api_key, sender_key 를 sensitive 로 표시하고, 일반 필드와 기본값 true 인 is_test_mode 불리언을 함께 선언하는지 확인한다.
     *
     * @scenario field_kind=credential
     *
     * @effects settings_schema_marks_credentials_sensitive, settings_defaults_load_with_test_mode_on
     *
     * @return void 반환값 없음
     */
    public function test_설정_스키마의_크리덴셜은_sensitive다(): void
    {
        $schema = $this->plugin->getSettingsSchema();

        foreach (['password', 'api_key', 'sender_key'] as $credential) {
            $this->assertArrayHasKey($credential, $schema);
            $this->assertTrue(
                $schema[$credential]['sensitive'] ?? false,
                "{$credential} 는 sensitive 로 마킹되어야 한다."
            );
        }

        // 비크리덴셜 필드는 존재하되 sensitive 아님
        $this->assertArrayHasKey('bizppurio_id', $schema);
        $this->assertArrayHasKey('sender_number', $schema);
        $this->assertArrayHasKey('is_test_mode', $schema);
        $this->assertSame('boolean', $schema['is_test_mode']['type']);
        $this->assertTrue($schema['is_test_mode']['default']);
    }

    /**
     * config/settings/defaults.json 의 frontend_schema 가 모든 필드를 expose false 로 두고 크리덴셜 세 필드를 sensitive 로 표시하는지 확인한다.
     *
     * @scenario field_kind=credential
     *
     * @effects defaults_json_frontend_schema_exposes_no_fields
     *
     * @return void 반환값 없음
     */
    public function test_defaults_json의_크리덴셜은_expose_false다(): void
    {
        $path = dirname(__DIR__, 3).'/config/settings/defaults.json';
        $this->assertFileExists($path);

        $defaults = json_decode(file_get_contents($path), true);
        $schema = $defaults['frontend_schema'];

        foreach ($schema as $field => $rule) {
            $this->assertFalse(
                $rule['expose'] ?? true,
                "{$field} 는 프론트에 노출(expose:true)되면 안 된다."
            );
        }

        foreach (['password', 'api_key', 'sender_key'] as $credential) {
            $this->assertTrue($schema[$credential]['sensitive'] ?? false);
        }
    }

    /**
     * 잔액부족 알림 정의가 bizppurio_balance_low 타입 하나로 mail 과 database 채널에 선언되고, 모든 템플릿 수신자가 admin 역할인지 확인한다.
     *
     * @effects balance_low_notification_targets_admin
     *
     * @return void 반환값 없음
     */
    public function test_잔액부족_알림이_관리자_대상으로_정의된다(): void
    {
        $definitions = $this->plugin->getNotificationDefinitions();

        $this->assertCount(1, $definitions);
        $definition = $definitions[0];

        $this->assertSame('bizppurio_balance_low', $definition['type']);
        $this->assertSame('sirsoft-message_bizppurio', $definition['hook_prefix']);
        $this->assertEqualsCanonicalizing(['mail', 'database'], $definition['channels']);

        foreach ($definition['templates'] as $template) {
            $recipients = $template['recipients'];
            $this->assertSame('role', $recipients[0]['type']);
            $this->assertSame('admin', $recipients[0]['value']);
        }
    }

    /**
     * 관리자 메뉴를 추가하지 않는다 (화면 배치 결정 2026-07-14, ⚑ 결정 3).
     *
     * 플러그인 메뉴 추가는 정식 기능이 아니므로 getAdminMenus()·메뉴 sync 를
     * 폐기했다. 진입은 코어 소유 설정 페이지(/admin/plugins/{id}/settings) 하나로
     * 통일한다. 활성화해도 플러그인 소속 메뉴 row 가 생성되지 않아야 한다.
     *
     * @effects activate_does_not_create_admin_menus
     *
     * @return void 반환값 없음
     */
    public function test_activate_시_관리자_메뉴를_생성하지_않는다(): void
    {
        $this->plugin->activate();

        $this->assertSame(
            0,
            Menu::where('extension_identifier', 'sirsoft-message_bizppurio')->count(),
            '메뉴 폐기 결정에 따라 플러그인 소속 메뉴가 생성되면 안 된다.'
        );

        $this->assertNull(
            Menu::where('slug', 'sirsoft-message_bizppurio')
                ->where('extension_type', ExtensionOwnerType::Plugin->value)
                ->first(),
            '최상위 메시지 발송 관리 메뉴가 남아 있으면 안 된다.'
        );
    }

    /**
     * Phase 4 발송 이력·알림 템플릿 테이블이 마이그레이션으로 생성된다.
     *
     * bizppurio_notification_bindings 는 #597 개편으로 bizppurio_templates(알림톡 등록
     * 페이로드·승인 스냅샷·검수 상태 + SMS 본문)로 대체되었다.
     *
     * @effects phase4_tables_created_with_expected_columns
     *
     * @return void 반환값 없음
     */
    public function test_phase4_테이블이_생성된다(): void
    {
        $this->assertTrue(Schema::hasTable('bizppurio_dispatches'));
        $this->assertTrue(Schema::hasTable('bizppurio_templates'));

        $this->assertTrue(Schema::hasColumns('bizppurio_dispatches', [
            'refkey', 'messagekey', 'channel', 'to_number', 'to_user_id',
            'status', 'result_code', 'reported_at', 'raw_payload',
        ]));

        $this->assertTrue(Schema::hasColumns('bizppurio_templates', [
            'notification_type', 'alimtalk_enabled', 'template_code', 'sender_key',
            'content', 'approved_content', 'status', 'inspection_detail',
            'fallback_sms_enabled', 'sms_body', 'sms_only', 'is_active',
        ]));
    }

    /**
     * 마이그레이션 up→down→up 왕복 후에도 리포지토리 호출 1회전이 동작한다.
     *
     * @effects migration_roundtrip_up_down_up_preserves_repository_ops
     *
     * @return void 반환값 없음
     */
    public function test_마이그레이션_왕복_후_리포지토리가_동작한다(): void
    {
        $migrations = base_path('plugins/_bundled/sirsoft-message_bizppurio/database/migrations');

        // down
        $this->artisan('migrate:rollback', ['--path' => $migrations, '--realpath' => true])->run();
        $this->assertFalse(Schema::hasTable('bizppurio_dispatches'));
        $this->assertFalse(Schema::hasTable('bizppurio_templates'), '롤백 시 bizppurio_templates 도 함께 제거되어야 한다.');

        // up
        $this->artisan('migrate', ['--path' => $migrations, '--realpath' => true])->run();
        $this->assertTrue(Schema::hasTable('bizppurio_dispatches'));
        $this->assertTrue(Schema::hasTable('bizppurio_templates'), '재실행 시 bizppurio_templates 가 다시 생성되어야 한다.');

        // 왕복 후 Repository 호출 1회전 (create → 조회)
        $dispatch = BizppurioDispatch::create([
            'refkey' => 'roundtrip',
            'channel' => 'sms',
            'to_number' => '01011112222',
            'content' => 'x',
            'status' => 'sent',
            'source' => 'auto',
        ]);
        $this->assertNotNull(BizppurioDispatch::query()->byRefkey('roundtrip')->first());
        $this->assertSame('roundtrip', $dispatch->refkey);

        // 왕복 후 템플릿 모델 1회전 — unique(notification_type)·JSON 캐스팅이 살아 있는지 확인
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'alimtalk_enabled' => true,
            'content' => ['templateName' => '가입 환영'],
            'sms_body' => ['ko' => '가입을 환영합니다.', 'en' => 'Welcome aboard.'],
        ]);
        $this->assertNotNull(BizppurioTemplate::query()->where('notification_type', 'welcome')->first());
        $this->assertSame(['templateName' => '가입 환영'], $template->content);
    }
}
