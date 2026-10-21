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

    /**
     * 레지스트리·주입기를 준비하고 시험 전용 코어 팩 폴더(가짜 식별자 test-core-ja)를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->app->make(LanguagePackRegistry::class);
        $this->injector = $this->app->make(LanguagePackSeedInjector::class);

        $this->packRoot = base_path('lang-packs/test-core-ja');
        File::ensureDirectoryExists($this->packRoot.'/seed');
    }

    /**
     * 시험 전용 코어 팩 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
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
     * @return void 반환값 없음
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

    /**
     * 활성 코어 ja 팩의 seed/permissions.json 이 코어 권한 트리(모듈·카테고리·권한) 이름에 ja 키를 더한다.
     *
     * @scenario pack_state=active_ja_pack_present, locale=ja
     *
     * @effects core_permissions_seed_injects_locale_keys
     *
     * @return void 반환값 없음
     */
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

    /**
     * 활성 코어 ja 팩의 알림 seed 가 정의 이름·설명과 채널별 템플릿 제목·본문(3단)에 ja 를 넣는다.
     *
     * @scenario pack_state=active_ja_pack_present, locale=ja
     *
     * @effects core_notification_seed_injects_definition_and_templates
     *
     * @return void 반환값 없음
     */
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

    /**
     * 활성 팩이 없으면 코어 권한 설정을 그대로 돌려준다.
     *
     * @scenario pack_state=no_pack, locale=ja
     *
     * @effects input_returned_unchanged_when_no_active_pack
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈 범위 활성 ja 팩의 엔티티 seed(배송유형)가 코드 키로 매칭돼 이름에 ja 를 넣는다.
     *
     * @scenario scope=module, pack_state=active_ja_pack_present, locale=ja
     *
     * @effects module_entity_seed_merged_by_key
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈 범위 활성 ja 팩의 알림 seed 가 같은 대상 모듈 알림 정의·템플릿에 ja 를 넣고 ko/en 은 보존한다.
     *
     * @scenario scope=module, pack_state=active_ja_pack_present, locale=ja
     *
     * @effects module_notification_seed_merged_for_target, ko_en_preserved_after_ja_injection
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_notifications_merges_module_pack_seed(): void
    {
        // 실제 설치 팩(g7-module-sirsoft-ecommerce-ja)과 겹치지 않는 가짜 벤더 이름 — 이 폴더는 끝에 통째로 지운다.
        $packRoot = base_path('lang-packs/g7test-module-sirsoft-ecommerce-ja');
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
                'identifier' => 'g7test-module-sirsoft-ecommerce-ja',
                'vendor' => 'g7test',
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

    /**
     * 다른 대상 모듈로 호출하면 그 모듈의 알림에 ja 를 넣지 않는다.
     *
     * @scenario scope=module, pack_state=active_ja_pack_present, locale=ja
     *
     * @effects other_target_pack_does_not_inject
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_notifications_skips_other_target(): void
    {
        // 실제 설치 팩(g7-module-sirsoft-ecommerce-ja)과 겹치지 않는 가짜 벤더 이름 — 이 폴더는 끝에 통째로 지운다.
        $packRoot = base_path('lang-packs/g7test-module-sirsoft-ecommerce-ja');
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/notifications.json', json_encode([
            'order_paid' => ['definition' => ['name' => '注文支払い完了']],
        ]));

        try {
            LanguagePack::query()->create([
                'identifier' => 'g7test-module-sirsoft-ecommerce-ja',
                'vendor' => 'g7test',
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

    /**
     * manifest seed(name/description)를 가진 시험 전용 활성 ja 팩(가짜 식별자)과 그 폴더를 만듭니다.
     *
     * @param  string  $scope  팩 범위 (module|plugin|template)
     * @param  string  $target  주입 대상 확장 식별자
     * @param  array<string, mixed>  $seed  seed/manifest.json 내용
     * @return string 만든 팩 폴더 절대 경로 (호출부가 지운다)
     */
    private function setupExtensionManifestPack(string $scope, string $target, array $seed): string
    {
        // 팩 식별자는 실제 설치 팩(`g7-{scope}-{target}-ja`)과 겹치지 않는 가짜 벤더(`g7test`)로 둔다 — 호출부가 이 폴더를
        // 통째로 지우므로 실제 이름이면 개발 환경의 설치 ja 팩(gdpr·ecommerce·basic·marketing)이 사라진다.
        // 주입 대상은 target_identifier 로 고르므로 대상 확장 식별자는 그대로 둔다.
        $identifier = "g7test-{$scope}-{$target}-ja";
        $packRoot = base_path('lang-packs/'.$identifier);
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/manifest.json', json_encode($seed));

        LanguagePack::query()->create([
            'identifier' => $identifier,
            'vendor' => 'g7test',
            'scope' => $scope,
            'target_identifier' => $target,
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

        return $packRoot;
    }

    /**
     * 플러그인 범위 활성 ja 팩의 manifest seed 가 name·description 에 ja 를 넣고 ko/en 은 보존한다.
     *
     * @scenario scope=plugin, pack_state=active_ja_pack_present, name_shape=multilingual_array, description_state=present, seed_field_state=both_filled, locale=ja
     *
     * @effects ja_injected_into_name_and_description_when_pack_present, ko_en_preserved_after_ja_injection
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_manifest_adds_ja_and_preserves_ko_en(): void
    {
        $packRoot = $this->setupExtensionManifestPack('plugin', 'sirsoft-gdpr', [
            'name' => 'GDPR (一般データ保護規則)',
            'description' => 'クッキー同意バナーを提供します。',
        ]);

        try {
            $manifest = [
                'name' => ['ko' => 'GDPR', 'en' => 'GDPR'],
                'description' => ['ko' => '쿠키 동의 배너', 'en' => 'Cookie consent banner'],
            ];

            $result = $this->injector->injectExtensionManifest($manifest, 'sirsoft-gdpr', LanguagePackScope::Plugin->value);

            $this->assertSame('GDPR (一般データ保護規則)', $result['name']['ja']);
            $this->assertSame('クッキー同意バナーを提供します。', $result['description']['ja']);
            // ko/en 보존
            $this->assertSame('GDPR', $result['name']['ko']);
            $this->assertSame('Cookie consent banner', $result['description']['en']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }

    /**
     * 활성 ja 팩이 없으면 manifest 를 그대로 돌려준다.
     *
     * @scenario scope=module, pack_state=no_pack, name_shape=multilingual_array, description_state=present, locale=ja
     *
     * @effects input_returned_unchanged_when_no_active_pack
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_manifest_skips_when_no_pack(): void
    {
        $manifest = [
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'description' => ['ko' => '게시판 모듈', 'en' => 'Board module'],
        ];

        // 활성 ja 팩 없음 → 입력 그대로
        $result = $this->injector->injectExtensionManifest($manifest, 'sirsoft-board', LanguagePackScope::Module->value);

        $this->assertSame($manifest, $result);
        $this->assertArrayNotHasKey('ja', $result['name']);
    }

    /**
     * 다른 대상 확장으로 호출하면 그 확장의 manifest 에 ja 를 넣지 않는다.
     *
     * @scenario scope=module, pack_state=active_ja_pack_present, name_shape=multilingual_array, description_state=missing, seed_field_state=both_filled, locale=ja
     *
     * @effects other_target_pack_does_not_inject
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_manifest_skips_other_target(): void
    {
        $packRoot = $this->setupExtensionManifestPack('module', 'sirsoft-ecommerce', [
            'name' => 'eコマース',
        ]);

        try {
            $manifest = ['name' => ['ko' => '게시판', 'en' => 'Board']];

            // 다른 대상(sirsoft-board) 호출 → ja 병합 없음
            $result = $this->injector->injectExtensionManifest($manifest, 'sirsoft-board', LanguagePackScope::Module->value);

            $this->assertArrayNotHasKey('ja', $result['name']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }

    /**
     * 평문 문자열 name 은 배열로 바꾼 뒤 ja 를 넣고, 없던 description 은 seed 로 채운다.
     *
     * @scenario scope=template, pack_state=active_ja_pack_present, name_shape=plain_string, description_state=missing, seed_field_state=both_filled, locale=ja
     *
     * @effects plain_string_name_normalized_to_array_before_inject, missing_description_filled_from_seed
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_manifest_handles_string_name_and_missing_description(): void
    {
        $packRoot = $this->setupExtensionManifestPack('template', 'sirsoft-basic', [
            'name' => 'シルソフトベーシック',
            'description' => 'ユーザーテンプレート',
        ]);

        try {
            // name 이 string(비배열) 이고 description 누락된 입력 → 배열로 정규화 후 ja 주입
            $manifest = ['name' => 'sirsoft-basic'];

            $result = $this->injector->injectExtensionManifest($manifest, 'sirsoft-basic', LanguagePackScope::Template->value);

            $this->assertIsArray($result['name']);
            $this->assertSame('シルソフトベーシック', $result['name']['ja']);
            $this->assertSame('ユーザーテンプレート', $result['description']['ja']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }

    /**
     * seed 의 빈 name 은 주입하지 않고 채워진 description 만 넣는다.
     *
     * @scenario scope=plugin, pack_state=active_ja_pack_present, name_shape=multilingual_array, description_state=present, seed_field_state=name_empty, locale=ja
     *
     * @effects empty_seed_name_skipped
     *
     * @return void 반환값 없음
     */
    public function test_inject_extension_manifest_skips_empty_seed_fields(): void
    {
        $packRoot = $this->setupExtensionManifestPack('plugin', 'sirsoft-marketing', [
            'name' => '',
            'description' => 'マーケティング同意',
        ]);

        try {
            $manifest = ['name' => ['ko' => '마케팅'], 'description' => ['ko' => '마케팅 동의']];

            $result = $this->injector->injectExtensionManifest($manifest, 'sirsoft-marketing', LanguagePackScope::Plugin->value);

            // 빈 name 은 주입 안 함, description 만 주입
            $this->assertArrayNotHasKey('ja', $result['name']);
            $this->assertSame('マーケティング同意', $result['description']['ja']);
        } finally {
            File::deleteDirectory($packRoot);
        }
    }
}
