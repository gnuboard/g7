<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Listeners\LanguagePack\SyncDatabaseTranslations;
use App\Models\LanguagePack;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\NotificationTemplateService;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 본인인증 알림의 언어팩(ja) 주입 검증 (#699 — 옛 seed/identity_messages.json 경로 대체)
 *
 * 7.1.0 부터 본인인증 메시지의 번역은 코어 언어팩 seed/notifications.json 의 `identity.*` type 키로
 * 공급된다. 실제 주입 경로는 언어팩 활성화 훅(SyncDatabaseTranslations::handleActivated)이다 —
 * 코어 시더의 필터 주입은 config 원형에 type 키가 없어 매칭되지 않는다
 * (mem: langpack-db-injection-paths-and-reset-wipe). [기본값 복원]도 팩 로케일을 지우지 않아야 한다.
 */
class IdentitySeedInjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $packRoot;

    /**
     * 임시 코어 ja 언어팩 디렉터리를 만들고 본인인증 알림 정의를 시딩합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->packRoot = base_path('lang-packs/test-feature-idv-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'identity.purpose.signup' => [
                'definition' => ['name' => '会員登録認証', 'description' => ''],
                'templates' => ['mail' => ['subject' => '[アプリ] 認証コード', 'body' => '<p>{code}</p>']],
            ],
        ]));

        $this->seed(NotificationDefinitionSeeder::class);
    }

    /**
     * 임시 언어팩 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    /**
     * 언어팩 활성화가 seed/notifications.json 의 identity.* 키를 정의·템플릿에 주입한다.
     *
     * @scenario domain=identity, entry=seeder_filter
     *
     * @effects seeder_filter_injects_active_pack_locales
     *
     * @return void 반환값 없음
     */
    public function test_activation_injects_ja_into_identity_definition(): void
    {
        $pack = $this->makeActivePack();

        $this->app->make(SyncDatabaseTranslations::class)->handleActivated($pack);

        $definition = NotificationDefinition::where('type', 'identity.purpose.signup')->firstOrFail();
        $this->assertSame('会員登録認証', $definition->name['ja'] ?? null);
        $template = $definition->templates()->where('channel', 'mail')->firstOrFail();
        $this->assertSame('[アプリ] 認証コード', $template->subject['ja'] ?? null);
        $this->assertArrayHasKey('ko', $template->subject, 'ko 원문은 보존된다');
    }

    /**
     * [기본값 복원]은 활성 팩 seed 의 ja 를 병합해 복원한다 — 팩 번역을 지우지 않는다.
     *
     * @scenario domain=identity, entry=reset_default
     *
     * @effects identity_reset_default_preserves_lang_pack_locales
     *
     * @return void 반환값 없음
     */
    public function test_reset_to_default_preserves_lang_pack_locales(): void
    {
        $this->makeActivePack();

        $template = NotificationTemplate::whereHas('definition', fn ($q) => $q->where('type', 'identity.purpose.signup'))
            ->where('channel', 'mail')->firstOrFail();
        $template->update([
            'subject' => ['ko' => '운영자가 바꾼 제목'],
            'body' => ['ko' => '운영자가 바꾼 본문 {code}'],
        ]);

        $service = $this->app->make(NotificationTemplateService::class);
        $updated = $service->resetToDefault(
            $template->fresh(),
            $service->getDefaultTemplateData('identity.purpose.signup', 'mail')
        );

        $this->assertSame('[アプリ] 認証コード', $updated->subject['ja'] ?? null,
            '복원 기본값에 활성 팩 seed 의 ja subject 가 병합되어야 한다 — 없으면 복원이 팩 번역을 지운다');
        $this->assertSame('<p>{code}</p>', $updated->body['ja'] ?? null);
        $this->assertStringContainsString('회원가입 인증 코드', $updated->subject['ko'], 'ko 는 config 원본으로 복원');
    }

    /**
     * 활성 코어 ja 언어팩 행을 만들고 레지스트리를 새로 읽게 합니다.
     *
     * @return LanguagePack 활성 언어팩
     */
    private function makeActivePack(): LanguagePack
    {
        $pack = LanguagePack::query()->create([
            'identifier' => 'test-feature-idv-ja',
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
        $this->app->make(LanguagePackRegistry::class)->invalidate();

        return $pack;
    }
}
