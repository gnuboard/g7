<?php

namespace Tests\Feature\Upgrades;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\UpgradeContext;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;
use App\Models\LanguagePack;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Upgrades\Data\V7_0_9\Migrations\ResyncActiveLanguagePackTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 7.0.9 업그레이드 스텝 — 활성 언어팩 seed 번역 재동기화 백필 검증.
 *
 * 7.0.9 이전의 [기본값 복원] 결함·코어 시더 주입 불능으로 소실된 팩 로케일이
 * 업그레이드 시 병합 복구되는지, 그리고 운영자 수정(user_overrides)은 덮지
 * 않는지를 실제 활성화 훅 경로(core.language_packs.activated)로 검증한다.
 */
class Upgrade709LangPackResyncTest extends TestCase
{
    use RefreshDatabase;

    private string $packIdentifier = 'test-upgrade-resync-ja';

    private string $packRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        // upgrade data 파일은 composer autoload 대상이 아니며 AbstractUpgradeStep 이
        // 실행 시점에 require_once 로 수동 로드한다. 테스트에서도 동일하게 수동 로드.
        require_once base_path('upgrades/data/7.0.9/migrations/01_ResyncActiveLanguagePackTranslations.php');

        $this->packRoot = base_path('lang-packs/'.$this->packIdentifier);
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    /**
     * 활성 팩 행을 생성합니다 (resolveDirectory → lang-packs/{identifier}).
     *
     * @return LanguagePack 생성된 팩
     */
    private function createActivePack(): LanguagePack
    {
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
     * 마이그레이션을 업그레이드 컨텍스트로 실행합니다.
     *
     * @return void
     */
    private function runMigration(): void
    {
        (new ResyncActiveLanguagePackTranslations)->run(
            new UpgradeContext('7.0.8', '7.0.9', '7.0.9')
        );
    }

    /**
     * [기본값 복원]이 지운 알림 템플릿 ja 가 업그레이드 재동기화로 복구되고,
     * 운영자가 수정한(user_overrides 등록) 템플릿의 ja 는 덮이지 않는다.
     *
     * @scenario domain=notification, entry=upgrade_resync
     *
     * @effects upgrade_resync_restores_wiped_pack_locales
     */
    public function test_resync_restores_wiped_notification_locale_and_preserves_overrides(): void
    {
        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'welcome' => [
                'definition' => ['name' => 'ようこそ通知'],
                'templates' => ['mail' => ['subject' => 'ようこそ', 'body' => '<p>{user_name}</p>']],
            ],
            'password_changed' => [
                'templates' => ['mail' => ['subject' => 'パスワード変更', 'body' => '<p>{user_name}</p>']],
            ],
        ]));
        $this->createActivePack();

        // 복원 결함이 ja 를 지운 상태 재현: ko/en 만 남고 user_overrides 는 null.
        $wipedDef = NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '환영', 'en' => 'Welcome'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
        ]);
        $wiped = NotificationTemplate::create([
            'definition_id' => $wipedDef->id,
            'channel' => 'mail',
            'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
            'body' => ['ko' => '<p>환영</p>', 'en' => '<p>welcome</p>'],
            'is_active' => true,
            'is_default' => true,
        ]);

        // 운영자 수정 상태 재현: ja 커스텀 + user_overrides 보존 선언.
        $customDef = NotificationDefinition::create([
            'type' => 'password_changed',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '비밀번호 변경', 'en' => 'Password changed'],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_password_change'],
        ]);
        $custom = NotificationTemplate::create([
            'definition_id' => $customDef->id,
            'channel' => 'mail',
            'subject' => ['ko' => '커스텀 제목', 'ja' => 'カスタム件名'],
            'body' => ['ko' => '<p>커스텀</p>', 'ja' => '<p>カスタム</p>'],
            'is_active' => true,
            'is_default' => false,
        ]);
        // 운영자 수정 시 updating 이벤트가 기록하는 실제 형식 = dot-path 리스트 "{column}.{locale}"
        $custom->user_overrides = ['subject.ja', 'body.ja'];
        $custom->saveQuietly();

        $this->assertArrayNotHasKey('ja', $wiped->fresh()->subject, '사전 조건: 소실 상태(ja 부재)');

        $this->runMigration();

        $this->assertSame('ようこそ', $wiped->fresh()->subject['ja'] ?? null,
            '소실된 템플릿 subject 에 활성 팩 seed 의 ja 가 병합 복구되어야 한다');
        $this->assertSame('ようこそ通知', $wipedDef->fresh()->name['ja'] ?? null,
            '정의 name 에도 ja 가 병합되어야 한다');
        $this->assertSame('カスタム件名', $custom->fresh()->subject['ja'] ?? null,
            '운영자 수정(user_overrides 등록) 템플릿의 ja 는 seed 로 덮이지 않아야 한다');
    }

    /**
     * 본인인증 메시지 템플릿의 소실된 ja 도 같은 재동기화로 복구된다.
     *
     * @scenario domain=identity, entry=upgrade_resync
     *
     * @effects upgrade_resync_restores_wiped_identity_locales
     */
    public function test_resync_restores_wiped_identity_message_locale(): void
    {
        File::put($this->packRoot.'/seed/identity_messages.json', json_encode([
            'mail.purpose.signup' => [
                'definition' => ['name' => '会員登録認証'],
                'templates' => ['mail' => ['subject' => '[アプリ] 認証コード', 'body' => '<p>{code}</p>']],
            ],
        ]));
        $this->createActivePack();

        $cfg = config('core.identity_messages')['mail.purpose.signup'];
        $definition = IdentityMessageDefinition::query()->create([
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'provider_id' => $cfg['provider_id'],
            'scope_type' => $cfg['scope_type'],
            'scope_value' => $cfg['scope_value'],
            'name' => ['ko' => '회원가입 인증'],
            'channels' => ['mail'],
            'variables' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
        $template = IdentityMessageTemplate::query()->create([
            'definition_id' => $definition->id,
            'channel' => 'mail',
            'subject' => ['ko' => '[앱] 인증 코드'],
            'body' => ['ko' => '<p>{code}</p>'],
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->assertArrayNotHasKey('ja', $template->fresh()->subject, '사전 조건: 소실 상태(ja 부재)');

        $this->runMigration();

        $this->assertSame('[アプリ] 認証コード', $template->fresh()->subject['ja'] ?? null,
            '소실된 본인인증 템플릿 subject 에 활성 팩 seed 의 ja 가 병합 복구되어야 한다');
    }
}
