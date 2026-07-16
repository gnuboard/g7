<?php

namespace Tests\Unit\Listeners\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\LanguagePack;
use App\Models\Permission;
use App\Models\Role;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = $this->app->make(SyncDatabaseTranslations::class);

        $this->packRoot = base_path('lang-packs/'.$this->packIdentifier);
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

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

    public function test_case_b_skips_locale_when_user_overrides_registered(): void
    {
        // Permission 은 HasUserOverrides 미사용이므로 Role 로 검증 (Role/Menu/Notification 모두 동일 정책)
        $role = Role::query()->create([
            'identifier' => 'test_role_b',
            'name' => ['ko' => '바', 'en' => 'Bar', 'ja' => '사용자수정'],
            'description' => ['ko' => 'D', 'en' => 'D'],
            'is_active' => true,
            'user_overrides' => ['name' => ['updated_at' => now()->toIso8601String()]],
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

    public function test_deactivate_preserves_locale_when_user_overrides_registered(): void
    {
        $role = Role::query()->create([
            'identifier' => 'test_role_zed',
            'name' => ['ko' => '제드', 'en' => 'Zed', 'ja' => '사용자수정ja'],
            'description' => ['ko' => '', 'en' => ''],
            'is_active' => true,
            'user_overrides' => ['name' => ['updated_at' => now()->toIso8601String()]],
        ]);

        $pack = $this->setupPackWithPermissionsSeed([
            'test_role_zed' => ['name' => 'ゼド', 'description' => ''],
        ]);

        $this->listener->handleDeactivated($pack);

        $role->refresh();
        // 사용자 보존 — 비활성화해도 ja 키 유지
        $this->assertSame('사용자수정ja', $role->name['ja']);
    }

    public function test_activate_merges_identity_message_locale_into_definition_and_template(): void
    {
        // Definition: provider_id=g7:core.mail + scope_type=purpose + scope_value=signup → seed key `mail.purpose.signup`
        $def = IdentityMessageDefinition::query()->create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'signup',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
            'description' => ['ko' => '회원가입 단계 인증', 'en' => 'Signup-stage verification'],
            'channels' => ['mail'],
            'variables' => [],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        $tpl = IdentityMessageTemplate::query()->create([
            'definition_id' => $def->id,
            'channel' => 'mail',
            'subject' => ['ko' => '인증 코드', 'en' => 'Verification code'],
            'body' => ['ko' => '<p>안녕하세요</p>', 'en' => '<p>Hello</p>'],
            'is_active' => true,
            'is_default' => true,
        ]);

        File::put($this->packRoot.'/seed/identity_messages.json', json_encode([
            'mail.purpose.signup' => [
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

    public function test_deactivate_strips_identity_message_locale(): void
    {
        $def = IdentityMessageDefinition::query()->create([
            'provider_id' => 'g7:core.mail',
            'scope_type' => 'purpose',
            'scope_value' => 'signup',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup', 'ja' => '会員登録認証'],
            'description' => ['ko' => '설명', 'en' => 'Desc'],
            'channels' => ['mail'],
            'variables' => [],
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'is_active' => true,
            'is_default' => true,
        ]);

        File::put($this->packRoot.'/seed/identity_messages.json', json_encode([]));
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
}
