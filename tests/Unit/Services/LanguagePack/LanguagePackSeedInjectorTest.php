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
 * LanguagePackSeedInjector 단위 테스트.
 *
 * 활성 코어 언어팩의 seed/*.json 데이터를 시드 입력 배열에 정확히 병합하는지 검증.
 */
class LanguagePackSeedInjectorTest extends TestCase
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

        $this->packRoot = base_path('lang-packs/test-core-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packRoot);
        parent::tearDown();
    }

    /**
     * 활성 코어 ja 언어팩 1건 + seed 파일을 함께 생성합니다.
     *
     * @param  string  $entity  엔티티 이름 (permissions/roles/menus/notifications)
     * @param  array<string, mixed>  $seedData  seed 데이터
     * @return void
     */
    private function setupCorePackWithSeed(string $entity, array $seedData): void
    {
        File::put($this->packRoot.'/seed/'.$entity.'.json', json_encode($seedData));

        LanguagePack::query()->create([
            'identifier' => 'test-core-ja',
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

        // singleton 인스턴스 재생성 (booted() 캐시 회피)
        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->forgetInstance(LanguagePackSeedInjector::class);
        $this->registry = $this->app->make(LanguagePackRegistry::class);
        $this->injector = $this->app->make(LanguagePackSeedInjector::class);
    }

    public function test_inject_core_permissions_adds_locale_keys(): void
    {
        $this->setupCorePackWithSeed('permissions', [
            'core.users.read' => [
                'name' => 'ユーザー閲覧',
                'description' => 'ユーザー情報を閲覧する権限',
            ],
        ]);

        $config = [
            'module' => [
                'identifier' => 'core',
                'name' => ['ko' => '코어', 'en' => 'Core'],
                'description' => ['ko' => '', 'en' => ''],
            ],
            'categories' => [
                [
                    'identifier' => 'core.users',
                    'name' => ['ko' => '사용자', 'en' => 'Users'],
                    'description' => ['ko' => '', 'en' => ''],
                    'permissions' => [
                        [
                            'identifier' => 'core.users.read',
                            'name' => ['ko' => '사용자 조회', 'en' => 'Read Users'],
                            'description' => ['ko' => '사용자 정보를 조회할 수 있습니다.', 'en' => 'Can view users.'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->injector->injectCorePermissions($config);

        $this->assertSame(
            'ユーザー閲覧',
            $result['categories'][0]['permissions'][0]['name']['ja']
        );
        $this->assertSame(
            'ユーザー情報を閲覧する権限',
            $result['categories'][0]['permissions'][0]['description']['ja']
        );
    }

    public function test_inject_notifications_3tier_definition_and_template(): void
    {
        $this->setupCorePackWithSeed('notifications', [
            'welcome' => [
                'definition' => ['name' => 'ようこそ', 'description' => '新規登録時の歓迎'],
                'templates' => [
                    'mail' => ['subject' => '[アプリ] ご登録ありがとう', 'body' => 'こんにちは'],
                ],
            ],
        ]);

        $defs = [
            [
                'type' => 'welcome',
                'name' => ['ko' => '환영', 'en' => 'Welcome'],
                'description' => ['ko' => '', 'en' => ''],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '환영합니다', 'en' => 'Welcome'],
                        'body' => ['ko' => '안녕하세요', 'en' => 'Hi'],
                    ],
                ],
            ],
        ];

        $result = $this->injector->injectNotifications($defs);

        $this->assertSame('ようこそ', $result[0]['name']['ja']);
        $this->assertSame('[アプリ] ご登録ありがとう', $result[0]['templates'][0]['subject']['ja']);
        $this->assertSame('こんにちは', $result[0]['templates'][0]['body']['ja']);
    }

    public function test_inject_skips_when_no_active_pack(): void
    {
        // active 팩 없음 → 입력 그대로 반환
        $config = [
            'module' => ['identifier' => 'core', 'name' => ['ko' => '코어']],
            'categories' => [],
        ];

        $result = $this->injector->injectCorePermissions($config);

        $this->assertSame($config, $result);
    }

    public function test_inject_extension_entity_merges_module_seed(): void
    {
        $packRoot = base_path('lang-packs/acme-module-sirsoft-ecommerce-ja');
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/shipping_types.json', json_encode([
            'parcel' => ['name' => '宅配便'],
        ]));

        try {
            LanguagePack::query()->create([
                'identifier' => 'acme-module-sirsoft-ecommerce-ja',
                'vendor' => 'acme',
                'scope' => LanguagePackScope::Module->value,
                'target_identifier' => 'sirsoft-ecommerce',
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

            $entries = [
                ['code' => 'parcel', 'name' => ['ko' => '택배', 'en' => 'Parcel']],
            ];

            $result = $this->injector->injectExtensionEntity($entries, 'sirsoft-ecommerce', 'shipping_types', 'code');

            $this->assertSame('宅配便', $result[0]['name']['ja']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }

    public function test_inject_extension_notifications_merges_module_pack_seed(): void
    {
        $packRoot = base_path('lang-packs/g7-module-sirsoft-ecommerce-ja');
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/notifications.json', json_encode([
            'order_paid' => [
                'definition' => ['name' => '注文支払い完了', 'description' => '注文が支払われた時に送信'],
                'templates' => [
                    'mail' => ['subject' => '【ショップ】ご注文ありがとうございます', 'body' => 'ご注文を承りました'],
                ],
            ],
        ]));

        try {
            LanguagePack::query()->create([
                'identifier' => 'g7-module-sirsoft-ecommerce-ja',
                'vendor' => 'g7',
                'scope' => LanguagePackScope::Module->value,
                'target_identifier' => 'sirsoft-ecommerce',
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'bundled',
            ]);
            $this->registry->invalidate();

            $defs = [
                [
                    'type' => 'order_paid',
                    'name' => ['ko' => '주문 결제 완료', 'en' => 'Order Paid'],
                    'description' => ['ko' => '', 'en' => ''],
                    'templates' => [
                        [
                            'channel' => 'mail',
                            'subject' => ['ko' => '주문 감사합니다', 'en' => 'Thanks for your order'],
                            'body' => ['ko' => '주문이 접수되었습니다', 'en' => 'Order received'],
                        ],
                    ],
                ],
            ];

            $result = $this->injector->injectExtensionNotifications($defs, 'sirsoft-ecommerce');

            $this->assertSame('注文支払い完了', $result[0]['name']['ja']);
            $this->assertSame('注文が支払われた時に送信', $result[0]['description']['ja']);
            $this->assertSame('【ショップ】ご注文ありがとうございます', $result[0]['templates'][0]['subject']['ja']);
            $this->assertSame('ご注文を承りました', $result[0]['templates'][0]['body']['ja']);
            // 기존 ko/en 보존
            $this->assertSame('주문 결제 완료', $result[0]['name']['ko']);
            $this->assertSame('Order Paid', $result[0]['name']['en']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }

    public function test_inject_extension_notifications_skips_other_target(): void
    {
        $packRoot = base_path('lang-packs/g7-module-sirsoft-ecommerce-ja');
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/notifications.json', json_encode([
            'order_paid' => ['definition' => ['name' => '注文支払い完了']],
        ]));

        try {
            LanguagePack::query()->create([
                'identifier' => 'g7-module-sirsoft-ecommerce-ja',
                'vendor' => 'g7',
                'scope' => LanguagePackScope::Module->value,
                'target_identifier' => 'sirsoft-ecommerce',
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'bundled',
            ]);
            $this->registry->invalidate();

            $defs = [['type' => 'order_paid', 'name' => ['ko' => '주문 결제 완료']]];

            // 다른 모듈(sirsoft-board) 대상 호출 → ja 병합 없음
            $result = $this->injector->injectExtensionNotifications($defs, 'sirsoft-board');

            $this->assertArrayNotHasKey('ja', $result[0]['name']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }
}
