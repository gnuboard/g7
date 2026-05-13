<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LanguagePackSeedInjector IDV 도메인 단위 테스트.
 *
 * 본인인증 메시지(identity_messages) 의 ja 다국어 키 병합 검증.
 * identity_policies / identity_purposes 는 모델/config 가 다국어 데이터 자체를 보유하지 않고
 * lang/{locale}/identity.php 의 i18n 키 참조 패턴이므로 lang pack seed 대상 외.
 */
class LanguagePackSeedInjectorIdentityTest extends TestCase
{
    use RefreshDatabase;

    private LanguagePackSeedInjector $injector;

    private LanguagePackRegistry $registry;

    private string $packRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(LanguagePackRegistry::class);
        $this->injector = $this->app->make(LanguagePackSeedInjector::class);

        $this->packRoot = base_path('lang-packs/test-core-idv-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        File::deleteDirectory(base_path('lang-packs/test-module-idv-ja'));
        parent::tearDown();
    }

    private function setupCorePackWithSeed(array $seedData): void
    {
        File::put($this->packRoot.'/seed/identity_messages.json', json_encode($seedData));

        LanguagePack::query()->create([
            'identifier' => 'test-core-idv-ja',
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

        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->forgetInstance(LanguagePackSeedInjector::class);
        $this->registry = $this->app->make(LanguagePackRegistry::class);
        $this->injector = $this->app->make(LanguagePackSeedInjector::class);
    }

    public function test_inject_identity_messages_merges_definition_and_templates_for_string_keyed(): void
    {
        // config/core.php::identity_messages 패턴 (string-keyed array)
        $this->setupCorePackWithSeed([
            'mail.purpose.signup' => [
                'definition' => [
                    'name' => '会員登録認証',
                    'description' => '会員登録時のメール認証',
                ],
                'templates' => [
                    'mail' => [
                        'subject' => '[アプリ] 会員登録認証コード',
                        'body' => '<p>認証コード: {code}</p>',
                    ],
                ],
            ],
        ]);

        $definitions = [
            'mail.purpose.signup' => [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'purpose',
                'scope_value' => 'signup',
                'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
                'description' => ['ko' => '회원가입 인증', 'en' => ''],
                'channels' => ['mail'],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '[앱] 회원가입 인증', 'en' => '[App] Signup'],
                        'body' => ['ko' => '<p>코드: {code}</p>', 'en' => '<p>Code: {code}</p>'],
                    ],
                ],
            ],
        ];

        $result = $this->injector->injectIdentityMessages($definitions);

        $this->assertSame('会員登録認証', $result['mail.purpose.signup']['name']['ja']);
        $this->assertSame('会員登録時のメール認証', $result['mail.purpose.signup']['description']['ja']);
        $this->assertSame('[アプリ] 会員登録認証コード', $result['mail.purpose.signup']['templates'][0]['subject']['ja']);
        $this->assertSame('<p>認証コード: {code}</p>', $result['mail.purpose.signup']['templates'][0]['body']['ja']);

        // ko/en 보존
        $this->assertSame('회원가입 인증', $result['mail.purpose.signup']['name']['ko']);
        $this->assertSame('Signup Verification', $result['mail.purpose.signup']['name']['en']);
    }

    public function test_inject_identity_messages_skips_definitions_without_seed_match(): void
    {
        $this->setupCorePackWithSeed([
            'mail.purpose.signup' => [
                'definition' => ['name' => '会員登録認証', 'description' => ''],
                'templates' => [],
            ],
        ]);

        $definitions = [
            'mail.provider_default' => [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'provider_default',
                'scope_value' => '',
                'name' => ['ko' => '기본', 'en' => 'Default'],
                'description' => ['ko' => '', 'en' => ''],
                'templates' => [],
            ],
        ];

        $result = $this->injector->injectIdentityMessages($definitions);

        // seed 미매칭 → ja 키 추가되지 않음
        $this->assertArrayNotHasKey('ja', $result['mail.provider_default']['name']);
        $this->assertSame('기본', $result['mail.provider_default']['name']['ko']);
    }

    public function test_inject_extension_identity_messages_merges_module_pack_seed(): void
    {
        $packRoot = base_path('lang-packs/test-module-idv-ja');
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/identity_messages.json', json_encode([
            'mail.policy.module_specific' => [
                'definition' => ['name' => 'モジュール固有', 'description' => ''],
                'templates' => [
                    'mail' => ['subject' => '件名', 'body' => '本文'],
                ],
            ],
        ]));

        LanguagePack::query()->create([
            'identifier' => 'test-module-idv-ja',
            'vendor' => 'test',
            'scope' => LanguagePackScope::Module->value,
            'target_identifier' => 'test-module',
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
        $this->registry->invalidate();

        // 모듈 측은 numeric-indexed (config/core.php 와 달리 list 형태로 선언)
        $definitions = [
            [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'policy',
                'scope_value' => 'module_specific',
                'name' => ['ko' => '모듈 고유', 'en' => 'Module Specific'],
                'description' => ['ko' => '', 'en' => ''],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '제목', 'en' => 'Subject'],
                        'body' => ['ko' => '본문', 'en' => 'Body'],
                    ],
                ],
            ],
        ];

        $result = $this->injector->injectExtensionIdentityMessages($definitions, 'test-module');

        $this->assertSame('モジュール固有', $result[0]['name']['ja']);
        $this->assertSame('件名', $result[0]['templates'][0]['subject']['ja']);
        $this->assertSame('本文', $result[0]['templates'][0]['body']['ja']);
    }

    public function test_inject_identity_messages_skips_when_no_active_pack(): void
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

        $result = $this->injector->injectIdentityMessages($definitions);

        $this->assertSame($definitions, $result);
    }
}
