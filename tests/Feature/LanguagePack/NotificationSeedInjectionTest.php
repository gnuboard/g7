<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * NotificationDefinitionSeeder 와 활성 코어 언어팩의 seed/notifications.json 통합 검증.
 *
 * 시더 → applyFilters('seed.notifications.translations', ...) → 활성 ja 패키지의 seed JSON
 * 으로 다국어 키 보강 → DB 동기화의 end-to-end 흐름 검증.
 *
 * 본 테스트는 시더 호출 자체를 검증하지 않고, **applyFilters 통과 후 정의 배열에 ja 키가
 * 추가되는지** 만 검증한다 (lang pack 인프라 통합 가드 — `seeder-translation-filter` 룰의
 * 코어 시더 검사 분기와 짝).
 */
class NotificationSeedInjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $packRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packRoot = base_path('lang-packs/test-feature-notif-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    /**
     * @scenario domain=notification, entry=seeder_filter
     *
     * @effects seeder_filter_injects_active_pack_locales
     */
    public function test_apply_filters_injects_ja_translation_when_active_pack_has_seed(): void
    {
        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'welcome' => [
                'definition' => ['name' => 'ようこそ通知', 'description' => '新規会員へのウェルカム'],
                'templates' => ['mail' => ['subject' => 'ようこそ', 'body' => '<p>{user_name}</p>']],
            ],
        ]));

        LanguagePack::query()->create([
            'identifier' => 'test-feature-notif-ja',
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

        // injectNotifications 는 `type` 필드를 lookup key 로 사용 (config/core.php notification_definitions 형태)
        $definitions = [
            [
                'type' => 'welcome',
                'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
                'description' => ['ko' => '신규 회원 환영', 'en' => 'New member welcome'],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
                        'body' => ['ko' => '<p>{user_name}</p>', 'en' => '<p>{user_name}</p>'],
                    ],
                ],
            ],
        ];

        $result = HookManager::applyFilters('seed.notifications.translations', $definitions);

        $this->assertSame('ようこそ通知', $result[0]['name']['ja']);
        $this->assertSame('新規会員へのウェルカム', $result[0]['description']['ja']);
        $this->assertSame('ようこそ', $result[0]['templates'][0]['subject']['ja']);
        $this->assertSame('<p>{user_name}</p>', $result[0]['templates'][0]['body']['ja']);
        // ko/en 보존
        $this->assertSame('회원가입 환영', $result[0]['name']['ko']);
        $this->assertSame('Welcome', $result[0]['name']['en']);
    }

    /**
     * 실경로 회귀 — 코어 시더(loadConfigSeed)는 config/core.php 원형(연관 배열,
     * 항목에 type 키 없음)을 그대로 필터에 넘긴다. injector 가 $def['type'] 만 보면
     * 전 항목이 스킵되어 ja 가 조용히 주입되지 않는다 (2026-08-24 실측 — #597 보완 실측).
     *
     * @scenario domain=notification, entry=seeder_filter
     *
     * @effects seeder_filter_injects_ja_for_real_seeder_payload
     */
    public function test_apply_filters_injects_ja_for_config_shaped_associative_definitions(): void
    {
        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'welcome' => [
                'definition' => ['name' => 'ようこそ通知', 'description' => '新規会員へのウェルカム'],
                'templates' => ['mail' => ['subject' => 'ようこそ', 'body' => '<p>{user_name}</p>']],
            ],
        ]));

        LanguagePack::query()->create([
            'identifier' => 'test-feature-notif-ja',
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

        // config/core.php notification_definitions 원형과 동일한 형태 — 키가 곧 type.
        $definitions = [
            'welcome' => [
                'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
                'description' => ['ko' => '신규 회원 환영', 'en' => 'New member welcome'],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
                        'body' => ['ko' => '<p>{user_name}</p>', 'en' => '<p>{user_name}</p>'],
                    ],
                ],
            ],
        ];

        $result = HookManager::applyFilters('seed.notifications.translations', $definitions);

        $this->assertSame('ようこそ通知', $result['welcome']['name']['ja'] ?? null,
            '연관 배열(실제 시더 페이로드) 형태에서 definition name 에 ja 가 병합되어야 한다');
        $this->assertSame('ようこそ', $result['welcome']['templates'][0]['subject']['ja'] ?? null,
            '연관 배열 형태에서 템플릿 subject 에 ja 가 병합되어야 한다');
        // ko/en 보존
        $this->assertSame('회원가입 환영', $result['welcome']['name']['ko']);
    }

    /**
     * [기본값 복원] 경로 회귀 — NotificationTemplateService::getDefaultTemplateData 는
     * core.notification.filter_default_definitions 필터만 타고 언어팩 주입에는 참여하지
     * 않아, 복원을 누르면 팩이 주입해 둔 로케일(ja)이 ko/en 대체로 영구 소실됐다
     * (실사례: 2026-08-23 복원 실측이 welcome ja 를 지움 — #597 보완 실측에서 확정).
     * 복원 기본값에도 활성 팩 seed 로케일이 병합되어야 한다.
     *
     * @scenario domain=notification, entry=reset_default
     *
     * @effects reset_default_data_includes_lang_pack_locales
     */
    public function test_reset_default_data_includes_lang_pack_locales(): void
    {
        File::put($this->packRoot.'/seed/notifications.json', json_encode([
            'welcome' => [
                'definition' => ['name' => 'ようこそ通知', 'description' => '新規会員へのウェルカム'],
                'templates' => ['mail' => ['subject' => 'ようこそ', 'body' => '<p>{user_name}</p>']],
            ],
        ]));

        LanguagePack::query()->create([
            'identifier' => 'test-feature-notif-ja',
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

        $defaultData = $this->app->make(NotificationTemplateService::class)
            ->getDefaultTemplateData('welcome', 'mail');

        $this->assertNotEmpty($defaultData, 'config/core.php 의 welcome/mail 기본 템플릿을 찾아야 한다');
        $this->assertSame('ようこそ', $defaultData['subject']['ja'] ?? null,
            '[기본값 복원] 기본값에 활성 팩 seed 의 ja subject 가 병합되어야 한다 — 없으면 복원이 팩 번역을 지운다');
        $this->assertSame('<p>{user_name}</p>', $defaultData['body']['ja'] ?? null,
            '[기본값 복원] 기본값에 활성 팩 seed 의 ja body 가 병합되어야 한다');
        $this->assertArrayHasKey('ko', $defaultData['subject'], 'ko 는 config 원본에서 보존');
    }

    public function test_apply_filters_passthrough_when_no_active_pack(): void
    {
        $definitions = [
            [
                'type' => 'welcome',
                'name' => ['ko' => '환영', 'en' => 'Welcome'],
                'templates' => [],
            ],
        ];

        $result = HookManager::applyFilters('seed.notifications.translations', $definitions);

        $this->assertSame($definitions, $result);
    }
}
