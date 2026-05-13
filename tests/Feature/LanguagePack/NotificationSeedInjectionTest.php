<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Extension\HookManager;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
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
