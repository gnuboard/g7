<?php

namespace Tests\Unit\Listeners\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\LanguagePack;
use App\Models\Menu;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * SyncDatabaseTranslations 단위 테스트.
 *
 * 활성/비활성 훅 페이로드를 받아 DB JSON 다국어 컬럼을 갱신/제거하는 핵심 로직과
 * user_overrides 보존 정책(§6.6 Case A/B/C)을 검증합니다.
 */
class SyncDatabaseTranslationsTest extends TestCase
{
    use RefreshDatabase;

    private SyncDatabaseTranslations $listener;

    private string $packRoot;

    private string $packIdentifier = 'test-core-ja';

    /**
     * 리스너를 해석하고 테스트 언어팩의 seed 디렉터리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->app->make(SyncDatabaseTranslations::class);

        $this->packRoot = base_path('lang-packs/'.$this->packIdentifier);
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    /**
     * 테스트 언어팩 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    /**
     * 활성 코어 ja 언어팩과 permissions seed 파일을 생성합니다.
     *
     * @param  array<string, mixed>  $seedData  permissions seed 데이터
     * @return LanguagePack 생성된 언어팩
     */
    private function setupPackWithPermissionsSeed(array $seedData): LanguagePack
    {
        File::put($this->packRoot.'/seed/permissions.json', json_encode($seedData));
        File::put($this->packRoot.'/seed/roles.json', json_encode($seedData));

        return LanguagePack::query()->create([
            'identifier' => $this->packIdentifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);
    }

    /**
     * (Case A) 행에 없는 로케일은 활성화 훅이 seed 값으로 추가하고 기존 ko 값은 그대로 둔다.
     *
     * @effects activation_adds_absent_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_case_a_adds_locale_when_absent(): void
    {
        $perm = Permission::query()->create([
            'identifier' => 'test.foo',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'order' => 1,
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test.foo' => ['name' => 'テスト', 'description' => '説明'],
        ]);

        $this->listener->handleActivated($pack);

        $perm->refresh();
        $this->assertSame('テスト', $perm->name['ja']);
        $this->assertSame('설명', $perm->description['ko']);
    }

    /**
     * (Case B) user_overrides 에 dot-path(name.ja)로 등록된 필드는 활성화 훅이 덮지 않고, 등록되지 않은 필드(description)는 seed 값을 받는다.
     *
     * @effects activation_preserves_user_overridden_locale_field
     *
     * @return void 반환값 없음
     */
    public function test_case_b_skips_locale_when_user_overrides_registered(): void
    {
        // Permission 은 HasUserOverrides 미사용이므로 Role 로 검증 (Role/Menu/Notification 모두 동일 정책)
        // user_overrides 는 실제 생산 형식(운영자 수정 시 updating 이벤트가 기록하는
        // dot-path 리스트 "{column}.{locale}")으로 둔다 — 과거 픽스처는 어떤 생산자도
        // 쓰지 않는 연관 형식이라 보존 판정 결함(isset 사문)을 green 으로 가렸다.
        $role = Role::query()->create([
            'identifier' => 'test_role_b',
            'name' => ['ko' => '바', 'en' => 'Bar', 'ja' => '사용자수정'],
            'description' => ['ko' => 'D', 'en' => 'D'],
            'is_active' => true,
            'user_overrides' => ['name.ja'],
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test_role_b' => ['name' => 'バー', 'description' => '説明'],
        ]);

        $this->listener->handleActivated($pack);

        $role->refresh();
        // 사용자 수정 보존 — 시드 값으로 덮어쓰지 않음
        $this->assertSame('사용자수정', $role->name['ja']);
        // description 은 user_overrides 미등록이므로 시드 값 적용 가능
        $this->assertSame('説明', $role->description['ja']);
    }

    /**
     * (Case C) user_overrides 가 없으면 기존 로케일 값을 seed 값으로 덮어쓴다.
     *
     * @effects activation_overwrites_locale_without_overrides
     *
     * @return void 반환값 없음
     */
    public function test_case_c_overwrites_locale_when_no_overrides(): void
    {
        $perm = Permission::query()->create([
            'identifier' => 'test.baz',
            'name' => ['ko' => '바즈', 'en' => 'Baz', 'ja' => '구버전'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'order' => 1,
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test.baz' => ['name' => '신버전', 'description' => '説明'],
        ]);

        $this->listener->handleActivated($pack);

        $perm->refresh();
        // user_overrides 미등록 → 시드 값으로 덮어쓰기
        $this->assertSame('신버전', $perm->name['ja']);
    }

    /**
     * 비활성화 훅은 팩 로케일(ja) 키를 지우고 ko·en 키는 남긴다.
     *
     * @effects deactivation_strips_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_strips_locale(): void
    {
        $perm = Permission::query()->create([
            'identifier' => 'test.qux',
            'name' => ['ko' => '쿡스', 'en' => 'Qux', 'ja' => 'クックス'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'order' => 1,
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test.qux' => ['name' => 'クックス', 'description' => ''],
        ]);

        $this->listener->handleDeactivated($pack);

        $perm->refresh();
        // 비활성화 시 ja 키 제거
        $this->assertArrayNotHasKey('ja', $perm->name);
        $this->assertArrayHasKey('ko', $perm->name);
        $this->assertArrayHasKey('en', $perm->name);
    }

    /**
     * 비활성화 훅은 user_overrides 에 등록된 로케일 필드(name.ja)는 지우지 않는다.
     *
     * @effects deactivation_preserves_user_overridden_locale_field
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_preserves_locale_when_user_overrides_registered(): void
    {
        // dot-path 리스트 = 실제 생산 형식 (test_case_b 주석 참조)
        $role = Role::query()->create([
            'identifier' => 'test_role_zed',
            'name' => ['ko' => '제드', 'en' => 'Zed', 'ja' => '사용자수정ja'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
            'user_overrides' => ['name.ja'],
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test_role_zed' => ['name' => 'ゼド', 'description' => ''],
        ]);

        $this->listener->handleDeactivated($pack);

        $role->refresh();
        // 사용자 보존 — 비활성화해도 ja 키 유지
        $this->assertSame('사용자수정ja', $role->name['ja']);
    }

    /**
     * 본인인증 알림 정의(category=identity)도 seed/notifications.json 의 type 키로 정의 name·description 과 메일 템플릿 subject·body 에 ja 를 병합하고 ko 는 보존한다.
     *
     * @effects activation_merges_identity_notification_locale
     *
     * @return void 반환값 없음
     */
    public function test_activate_merges_identity_message_locale_into_definition_and_template(): void
    {
        // 본인인증 알림(7.1.0~)은 알림 정의 type 키로 seed/notifications.json 에서 번역을 받는다 (#699).
        $def = NotificationDefinition::query()->create([
            'type' => 'identity.purpose.signup',
            'hook_prefix' => 'core.identity',
            'category' => 'identity',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
            'description' => ['ko' => '회원가입 단계 인증', 'en' => 'Signup-stage verification'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        $tpl = NotificationTemplate::query()->create([
            'definition_id' => $def->id,
            'channel' => 'mail',
            'subject' => ['ko' => '인증 코드', 'en' => 'Verification code'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'is_active' => true,
            'is_default' => true,
        ]);

        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'identity.purpose.signup' => [
                'definition' => [
                    'name' => '会員登録認証',
                    'description' => '会員登録段階の認証',
                ],
                'templates' => [
                    'mail' => [
                        'subject' => '認証コード',
                        'body' => '<p>こんにちは</p>',
                    ],
                ],
            ],
        ]));

        $pack = LanguagePack::query()->create([
            'identifier' => $this->packIdentifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->listener->handleActivated($pack);

        $def->refresh();
        $tpl->refresh();
        $this->assertSame('会員登録認証', $def->name['ja']);
        $this->assertSame('会員登録段階の認証', $def->description['ja']);
        $this->assertSame('認証コード', $tpl->subject['ja']);
        $this->assertSame('<p>こんにちは</p>', $tpl->body['ja']);
        // 기존 ko 키는 보존
        $this->assertSame('회원가입 인증', $def->name['ko']);
    }

    /**
     * 비활성화 훅은 본인인증 알림 정의 name 의 ja 키를 지우고 ko 값은 남긴다.
     *
     * @effects deactivation_strips_identity_notification_locale
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_strips_identity_message_locale(): void
    {
        $def = NotificationDefinition::query()->create([
            'type' => 'identity.purpose.signup',
            'hook_prefix' => 'core.identity',
            'category' => 'identity',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup', 'ja' => '会員登録認証'],
            'description' => ['ko' => '설명', 'en' => 'Desc'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        File::put($this->packRoot.'/seed/notifications.json', json_encode([]));
        $pack = LanguagePack::query()->create([
            'identifier' => $this->packIdentifier,
            'vendor' => 'test',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Inactive->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => 'zip',
        ]);

        $this->listener->handleDeactivated($pack);

        $def->refresh();
        $this->assertArrayNotHasKey('ja', $def->name);
        $this->assertSame('회원가입 인증', $def->name['ko']);
    }

    /**
     * 플러그인 범위 ja 팩을 만들고 seed 파일을 적습니다.
     *
     * @param  string  $sourceType  팩 소스 유형
     * @return LanguagePack 생성된 언어팩
     */
    private function createPluginPack(string $sourceType = 'bundled'): LanguagePack
    {
        $root = base_path('lang-packs/test-plugin-qa-ja');
        File::ensureDirectoryExists($root.'/seed');
        File::put($root.'/seed/permissions.json', json_encode([
            'qa-plugin.view' => ['name' => '閲覧', 'description' => '閲覧の説明'],
        ]));
        File::put($root.'/seed/roles.json', json_encode([
            'qa-plugin.manager' => ['name' => '運営者', 'description' => '運営者の説明'],
            'qa-plugin.edited' => ['name' => 'パック値', 'description' => '説明'],
        ]));
        File::put($root.'/seed/menus.json', json_encode([
            'qa-plugin-log' => ['name' => '履歴'],
        ]));
        File::put($root.'/seed/notifications.json', json_encode([
            'qa_plugin_notice' => [
                'definition' => ['name' => 'お知らせ', 'description' => 'お知らせの説明'],
                'templates' => ['mail' => ['subject' => '件名', 'body' => '<p>本文</p>']],
            ],
        ]));

        return LanguagePack::query()->create([
            'identifier' => 'test-plugin-qa-ja',
            'vendor' => 'test',
            'scope' => LanguagePackScope::Plugin->value,
            'target_identifier' => 'qa-plugin',
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'version' => '1.0.0',
            'status' => LanguagePackStatus::Active->value,
            'is_protected' => false,
            'manifest' => [],
            'source_type' => $sourceType,
        ]);
    }

    /**
     * 플러그인이 소유한 권한·역할·메뉴·알림 정의·알림 템플릿 행과 코어 소유 권한 행을 만듭니다.
     *
     * @return array<string, Model> 이름별 행
     */
    private function createPluginOwnedRows(): array
    {
        $owner = ['extension_type' => 'plugin', 'extension_identifier' => 'qa-plugin'];

        $perm = Permission::query()->create($owner + [
            'identifier' => 'qa-plugin.view',
            'name' => ['ko' => '보기', 'en' => 'View'],
            'description' => ['ko' => '보기 설명', 'en' => 'View desc'],
            'order' => 1,
        ]);
        $role = Role::query()->create($owner + [
            'identifier' => 'qa-plugin.manager',
            'name' => ['ko' => '운영자', 'en' => 'Manager'],
            'description' => ['ko' => '운영자 설명', 'en' => 'Manager desc'],
            'is_active' => true,
        ]);
        $edited = Role::query()->create($owner + [
            'identifier' => 'qa-plugin.edited',
            'name' => ['ko' => '수정됨', 'en' => 'Edited'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
        ]);
        $menu = Menu::query()->create($owner + [
            'name' => ['ko' => '이력', 'en' => 'Log'],
            'slug' => 'qa-plugin-log',
            'url' => '/admin/qa-plugin/log',
            'order' => 1,
            'is_active' => true,
        ]);
        $def = NotificationDefinition::query()->create($owner + [
            'type' => 'qa_plugin_notice',
            'hook_prefix' => 'qa-plugin',
            'category' => 'qa',
            'name' => ['ko' => '안내', 'en' => 'Notice'],
            'description' => ['ko' => '안내 설명', 'en' => 'Notice desc'],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        $tpl = NotificationTemplate::query()->create([
            'definition_id' => $def->id,
            'channel' => 'mail',
            'subject' => ['ko' => '제목', 'en' => 'Subject'],
            'body' => ['ko' => '<p>본문</p>', 'en' => '<p>Body</p>'],
            'is_active' => true,
            'is_default' => true,
        ]);
        $corePerm = Permission::query()->create([
            'identifier' => 'core.qa.view',
            'name' => ['ko' => '코어', 'en' => 'Core', 'ja' => 'コア'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'order' => 1,
        ]);

        return compact('perm', 'role', 'edited', 'menu', 'def', 'tpl', 'corePerm');
    }

    /**
     * 플러그인 범위 팩을 끄면 그 팩이 플러그인 소유 권한·역할·메뉴·알림 정의·알림 템플릿에 넣은 ja 를 걷고,
     * 운영자가 고친 값(user_overrides)과 다른 소유자(코어) 행의 ja 는 남긴다. 다시 켜면 ja 가 다시 들어간다.
     *
     * 종전에는 플러그인 범위 비활성화가 플러그인 행의 이름·설명만 걷어, 끈 뒤에도 메뉴·역할·권한·알림에
     * ja 문구가 남았다(2026-10-07 g7-plugin-sirsoft-gdpr-ja 실측).
     *
     * @effects deactivation_strips_extension_pack_locale_from_owned_rows, reactivation_restores_extension_pack_locale
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_plugin_pack_strips_locale_from_plugin_owned_rows(): void
    {
        $rows = $this->createPluginOwnedRows();
        $pack = $this->createPluginPack();

        try {
            $this->listener->handleActivated($pack);

            $this->assertSame('閲覧', $rows['perm']->refresh()->name['ja']);
            $this->assertSame('運営者', $rows['role']->refresh()->name['ja']);
            $this->assertSame('履歴', $rows['menu']->refresh()->name['ja']);
            $this->assertSame('お知らせ', $rows['def']->refresh()->name['ja']);
            $this->assertSame('件名', $rows['tpl']->refresh()->subject['ja']);

            // 운영자가 ja 이름을 직접 고친 역할(실제로는 HasUserOverrides 가 name.ja 를 기록한다)
            $edited = $rows['edited']->refresh();
            $edited->name = array_merge($edited->name, ['ja' => '運営者が直した値']);
            $edited->user_overrides = ['name.ja'];
            $edited->save();

            $this->listener->handleDeactivated($pack);

            $this->assertArrayNotHasKey('ja', $rows['perm']->refresh()->name);
            $this->assertArrayNotHasKey('ja', $rows['perm']->description);
            $this->assertArrayNotHasKey('ja', $rows['role']->refresh()->name);
            $this->assertArrayNotHasKey('ja', $rows['menu']->refresh()->name);
            $this->assertArrayNotHasKey('ja', $rows['def']->refresh()->name);
            $this->assertArrayNotHasKey('ja', $rows['tpl']->refresh()->subject);
            $this->assertArrayNotHasKey('ja', $rows['tpl']->body);
            $this->assertSame('보기', $rows['perm']->name['ko']);
            $this->assertSame('<p>Body</p>', $rows['tpl']->body['en']);
            $this->assertSame('運営者が直した値', $rows['edited']->refresh()->name['ja'], '운영자 수정값은 비활성화가 걷지 않는다');
            $this->assertSame('コア', $rows['corePerm']->refresh()->name['ja'], '다른 소유자 행의 ja 는 플러그인 팩과 무관하다');

            $this->listener->handleActivated($pack);

            $this->assertSame('閲覧', $rows['perm']->refresh()->name['ja']);
            $this->assertSame('履歴', $rows['menu']->refresh()->name['ja']);
            $this->assertSame('<p>本文</p>', $rows['tpl']->refresh()->body['ja']);
            $this->assertSame('運営者が直した値', $rows['edited']->refresh()->name['ja'], '재활성화도 운영자 수정값을 덮지 않는다');
        } finally {
            File::deleteDirectory(base_path('lang-packs/test-plugin-qa-ja'));
        }
    }

    /**
     * 확장에 내장된 언어팩(bundled_with_extension)은 그 확장 자신의 언어라, 꺼질 때 확장 소유 행의 그 로케일을 걷지 않는다.
     *
     * @effects deactivation_keeps_bundled_with_extension_locale
     *
     * @return void 반환값 없음
     */
    public function test_deactivate_bundled_with_extension_pack_keeps_owned_row_locale(): void
    {
        $rows = $this->createPluginOwnedRows();
        $rows['perm']->name = array_merge($rows['perm']->name, ['ja' => '内蔵']);
        $rows['perm']->save();
        $pack = $this->createPluginPack('bundled_with_extension');

        try {
            $this->listener->handleDeactivated($pack);

            $this->assertSame('内蔵', $rows['perm']->refresh()->name['ja']);
        } finally {
            File::deleteDirectory(base_path('lang-packs/test-plugin-qa-ja'));
        }
    }
}
