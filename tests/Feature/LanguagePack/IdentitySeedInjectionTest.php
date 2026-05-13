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
 * IdentityMessageDefinitionSeeder 와 활성 코어 언어팩의 seed/identity_messages.json 통합 검증.
 *
 * 시더 → applyFilters('seed.identity_messages.translations', ...) → 활성 ja 패키지의 seed JSON
 * 으로 다국어 키 보강 → DB 동기화의 end-to-end 흐름 검증.
 *
 * 본 테스트는 시더 호출 자체를 검증하지 않고 (helper 가 DB 작성 — 이미 다른 곳에서 검증),
 * **applyFilters 통과 후 정의 배열에 ja 키가 추가되는지** 만 검증한다 (lang pack 인프라 통합 가드).
 */
class IdentitySeedInjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $packRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packRoot = base_path('lang-packs/test-feature-idv-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    public function test_apply_filters_injects_ja_translation_when_active_pack_has_seed(): void
    {
        File::put($this->packRoot.'/seed/identity_messages.json', json_encode([
            'mail.purpose.signup' => [
                'definition' => ['name' => '会員登録認証', 'description' => ''],
                'templates' => ['mail' => ['subject' => '[アプリ] 認証コード', 'body' => '<p>{code}</p>']],
            ],
        ]));

        LanguagePack::query()->create([
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
        // ServiceProvider 의 필터 closure 가 캡처한 LanguagePackSeedInjector → Registry cache 무효화.
        $this->app->make(LanguagePackRegistry::class)->invalidate();

        // config/core.php 와 동일 형태의 string-keyed array
        $definitions = [
            'mail.purpose.signup' => [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'purpose',
                'scope_value' => 'signup',
                'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
                'description' => ['ko' => '회원가입 인증', 'en' => ''],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '[앱] 인증 코드', 'en' => '[App] Code'],
                        'body' => ['ko' => '<p>{code}</p>', 'en' => '<p>{code}</p>'],
                    ],
                ],
            ],
        ];

        $result = HookManager::applyFilters('seed.identity_messages.translations', $definitions);

        $this->assertSame('会員登録認証', $result['mail.purpose.signup']['name']['ja']);
        $this->assertSame('[アプリ] 認証コード', $result['mail.purpose.signup']['templates'][0]['subject']['ja']);
        $this->assertSame('<p>{code}</p>', $result['mail.purpose.signup']['templates'][0]['body']['ja']);
        // ko/en 보존 검증
        $this->assertSame('회원가입 인증', $result['mail.purpose.signup']['name']['ko']);
        $this->assertSame('Signup Verification', $result['mail.purpose.signup']['name']['en']);
    }

    public function test_apply_filters_passthrough_when_no_active_pack(): void
    {
        $definitions = [
            'mail.purpose.signup' => [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'purpose',
                'scope_value' => 'signup',
                'name' => ['ko' => '회원가입', 'en' => 'Signup'],
                'templates' => [],
            ],
        ];

        $result = HookManager::applyFilters('seed.identity_messages.translations', $definitions);

        // 활성 ja 팩 없음 → 입력 그대로 반환
        $this->assertSame($definitions, $result);
    }
}
