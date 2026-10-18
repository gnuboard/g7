<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Settings;

use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\VerificationKginicis\Plugin;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 라이브 모드 라이브 MID/API 키 미입력 저장 차단.
 *
 * 코어 플러그인 설정 저장 경로(PUT /api/admin/plugins/{id}/settings)에서
 * is_test_mode=false 일 때 live_mid / live_api_key 를 required 로 강제하는지 검증한다.
 *
 * @since 1.0.0-beta.1
 */
class InicisLiveModeSettingsValidationTest extends PluginTestCase
{
    private const IDENTIFIER = 'sirsoft-verification_kginicis';

    private User $admin;

    private string $token;

    /**
     * 플러그인 인스턴스를 관리자에 등록하고 설정 수정 권한을 가진 관리자 토큰을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 코어 PluginManager 가 테스트 환경에서 본 플러그인 인스턴스를 반환하도록 수동 등록.
        // (UpdatePluginSettingsRequest::rules() 가 getPlugin()->getSettingsSchema() 를 사용)
        $manager = app(PluginManager::class);
        $ref = new \ReflectionClass($manager);
        $prop = $ref->getProperty('plugins');
        $prop->setAccessible(true);
        $plugins = $prop->getValue($manager);
        $plugins[self::IDENTIFIER] = new Plugin;
        $prop->setValue($manager, $plugins);

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * core.plugins.update 권한을 가진 admin 사용자 생성.
     *
     * @return User 권한이 붙은 관리자
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.plugins.update'],
            ['name' => json_encode(['ko' => '플러그인 수정', 'en' => 'Update Plugins']), 'type' => 'admin']
        );

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'inicis_settings_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 인증 헤더가 적용된 PUT 요청 헬퍼.
     *
     * @param  array<string, mixed>  $body  저장 요청 본문
     * @return TestResponse 설정 저장 응답
     */
    private function putSettings(array $body)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/'.self::IDENTIFIER.'/settings', $body);
    }

    /**
     * 라이브 모드에서 라이브 자격증명을 비우면 두 항목 모두 422 로 거절된다.
     *
     * @scenario mode=live,live_credentials=empty
     *
     * @effects live_mode_with_empty_live_credentials_returns_422_with_live_mid_and_live_api_key_errors
     *
     * @return void 반환값 없음
     */
    public function test_live_mode_with_empty_live_credentials_is_rejected(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_api_key' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['live_mid', 'live_api_key']);
    }

    /**
     * 라이브 자격증명 오류 문구가 영문 키 대신 한국어 항목 이름을 쓴다.
     *
     * @scenario mode=live,live_credentials=empty,locale=ko
     *
     * @effects validation_error_messages_use_korean_field_labels_not_english_keys
     *
     * @return void 반환값 없음
     */
    public function test_validation_error_messages_use_korean_field_labels(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_mid' => '',
            'live_api_key' => '',
        ]);

        $response->assertStatus(422);

        $errors = $response->json('errors');
        // 코어 검증기 수정 없이 ServiceProvider 의 Lang::addLines 로 주입한 한국어 라벨이 노출되어야 한다.
        $this->assertStringContainsString('라이브 MID', $errors['live_mid'][0]);
        $this->assertStringNotContainsString('live mid', $errors['live_mid'][0]);
        $this->assertStringContainsString('라이브 API 키', $errors['live_api_key'][0]);
    }

    /**
     * ja 요청에서는 라이브 자격증명 오류 문구가 일본어 항목 이름을 쓴다.
     *
     * @scenario mode=live,live_credentials=empty,locale=ja
     *
     * @effects validation_error_messages_use_japanese_field_labels_when_locale_ja
     *
     * @return void 반환값 없음
     */
    public function test_validation_error_messages_use_japanese_field_labels(): void
    {
        // 실제 요청 경로 검증: ja 언어팩 활성 환경(supported_locales 에 ja 포함 + Accept-Language: ja)
        // 을 재현해 라이브 모드 빈 자격증명 저장을 시도하면, 코어 SetLocale 미들웨어가 요청 locale 을
        // ja 로 확정하고 ValidateInicisSettingsListener 가 요청 처리 시점(검증 규칙 필터)에 ja 항목
        // 라벨을 등록하므로, 422 응답 에러 메시지가 영문 키('live mid')가 아니라 일본어
        // 라벨('ライブ MID')로 노출돼야 한다.
        // (이전 booted() 콜백 방식은 plugin ja lang 주입보다 먼저 실행돼 ko 로 폴백되던 결함 —
        //  요청 처리 시점 등록으로 바꿔 회귀 차단. mock 없이 실제 훅 체인 + HTTP 응답 관찰.)
        $originalLocales = config('app.supported_locales');
        config(['app.supported_locales' => array_values(array_unique(array_merge((array) $originalLocales, ['ja'])))]);
        Lang::addLines([
            'messages.settings.live_mid_attribute' => 'ライブ MID',
            'messages.settings.live_api_key_attribute' => 'ライブ API キー',
        ], 'ja', self::IDENTIFIER);

        // 코어 SetLocale 미들웨어의 1순위는 인증 사용자의 users.language 다. 요청 처리 중 locale 이
        // ja 로 확정되도록 admin 의 언어를 ja 로 둔다 (= ja 사용자가 ja 화면에서 저장 시도하는 실제 상황).
        $this->admin->forceFill(['language' => 'ja'])->save();

        try {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
                'Accept-Language' => 'ja',
            ])->putJson('/api/admin/plugins/'.self::IDENTIFIER.'/settings', [
                'is_test_mode' => false,
                'live_mid' => '',
                'live_api_key' => '',
            ]);

            $response->assertStatus(422);

            $errors = $response->json('errors');
            // 영문 키가 아니라 일본어 항목 라벨이 메시지에 들어가야 한다.
            $this->assertStringContainsString('ライブ MID', $errors['live_mid'][0], 'ja 요청에서 라이브 MID 라벨이 일본어로 노출되어야 한다');
            $this->assertStringNotContainsString('live mid', $errors['live_mid'][0]);
            $this->assertStringContainsString('ライブ API キー', $errors['live_api_key'][0], 'ja 요청에서 라이브 API 키 라벨이 일본어로 노출되어야 한다');
        } finally {
            // 전역 상태(supported_locales) 복원 — 후속 테스트 격리 보장.
            config(['app.supported_locales' => $originalLocales]);
        }
    }

    /**
     * ko 요청에서 설정 항목 7개 모두의 오류 문구가 화면과 같은 한국어 항목 이름을 쓴다.
     *
     * 7.1.0 전수 실측(P-11): 리스너가 라이브 자격증명만, 그것도 라이브 모드일 때만 언어별 이름을
     * 등록했다. 나머지 항목은 설정 스키마 label 로 대체돼 「중복체크 기준」이 「중복 판정 필드」로 나갔다.
     *
     * @scenario locale=ko
     *
     * @effects every_settings_field_error_label_follows_request_locale
     *
     * @return void 반환값 없음
     */
    public function test_every_settings_field_error_label_follows_korean_request(): void
    {
        $this->assertEveryFieldLabelFollowsLocale('ko');
    }

    /**
     * en 요청에서 설정 항목 7개 모두의 오류 문구가 영어 항목 이름을 쓰고 한국어가 남지 않는다.
     *
     * @scenario locale=en
     *
     * @effects every_settings_field_error_label_follows_request_locale, no_korean_field_label_in_non_korean_error
     *
     * @return void 반환값 없음
     */
    public function test_every_settings_field_error_label_follows_english_request(): void
    {
        $this->assertEveryFieldLabelFollowsLocale('en');
    }

    /**
     * ja 요청에서 설정 항목 7개 모두의 오류 문구가 일본어 언어팩의 항목 이름을 쓰고 한국어가 남지 않는다.
     *
     * ja 이름은 번들 일본어 언어팩 파일의 실제 값을 그대로 싣는다 — 언어팩에 키가 없으면 기대값이
     * 안내 문구가 돼 실패한다.
     *
     * @scenario locale=ja
     *
     * @effects every_settings_field_error_label_follows_request_locale, no_korean_field_label_in_non_korean_error
     *
     * @return void 반환값 없음
     */
    public function test_every_settings_field_error_label_follows_japanese_request(): void
    {
        $this->assertEveryFieldLabelFollowsLocale('ja');
    }

    /**
     * 모든 설정 항목이 형식 오류로 거절되는 저장을 보내고, 오류 문구의 항목 이름이 요청 언어를 따르는지 본다.
     *
     * 테스트 모드 값이 참·거짓이 아니고 문자열 칸에 목록이 오면 모든 항목이 422 가 된다(라이브 모드 아님).
     *
     * @param  string  $locale  요청 언어(ko|en|ja)
     * @return void 반환값 없음
     */
    private function assertEveryFieldLabelFollowsLocale(string $locale): void
    {
        $body = [
            'is_test_mode' => 'not-a-boolean',
            'test_mid' => ['x'],
            'test_api_key' => ['x'],
            'live_mid' => ['x'],
            'live_api_key' => ['x'],
            'duplicate_field' => 'zz',
            'duplicate_block_enabled' => 'not-a-boolean',
        ];

        $packFile = base_path('lang-packs/_bundled/g7-plugin-sirsoft-verification_kginicis-ja/backend/ja/messages.php');
        $jaSettings = (require $packFile)['settings'] ?? [];
        $lines = [];
        foreach ($jaSettings as $key => $value) {
            if (is_string($value)) {
                $lines['messages.settings.'.$key] = $value;
            }
        }
        Lang::addLines($lines, 'ja', self::IDENTIFIER);

        $ko = [
            'is_test_mode' => '테스트 모드',
            'test_mid' => '테스트 MID',
            'test_api_key' => '테스트 API 키',
            'live_mid' => '라이브 MID',
            'live_api_key' => '라이브 API 키',
            'duplicate_field' => '중복체크 기준',
            'duplicate_block_enabled' => '중복 가입 차단',
        ];
        $expected = [
            'ko' => $ko,
            'en' => [
                'is_test_mode' => 'Test Mode',
                'test_mid' => 'Test MID',
                'test_api_key' => 'Test API Key',
                'live_mid' => 'Live MID',
                'live_api_key' => 'Live API Key',
                'duplicate_field' => 'Duplicate Check Basis',
                'duplicate_block_enabled' => 'Block Duplicate Registration',
            ],
            'ja' => array_combine(array_keys($ko), array_map(
                static fn (string $field) => $jaSettings[$field.'_attribute'] ?? "(ja 언어팩에 {$field}_attribute 없음)",
                array_keys($ko),
            )),
        ][$locale];

        $originalLocales = config('app.supported_locales');
        config(['app.supported_locales' => array_values(array_unique(array_merge((array) $originalLocales, ['ja'])))]);

        try {
            // 코어 SetLocale 미들웨어의 1순위는 인증 사용자의 users.language 다.
            $this->admin->forceFill(['language' => $locale])->save();
            $this->app['auth']->forgetGuards();

            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
                'Accept-Language' => $locale,
            ])->putJson('/api/admin/plugins/'.self::IDENTIFIER.'/settings', $body);

            $response->assertStatus(422);
            $errors = $response->json('errors');

            foreach ($expected as $field => $label) {
                $this->assertArrayHasKey($field, $errors, "{$locale}: {$field} 오류가 있어야 한다");
                $this->assertStringContainsString($label, $errors[$field][0], "{$locale}: {$field} 오류 문구의 항목 이름");
                if ($locale !== 'ko') {
                    $this->assertStringNotContainsString($ko[$field], $errors[$field][0], "{$locale}: {$field} 항목 이름이 한국어로 남으면 안 된다");
                }
            }
        } finally {
            config(['app.supported_locales' => $originalLocales]);
        }
    }

    /**
     * 라이브 모드에서 라이브 자격증명을 채우면 저장된다.
     *
     * @scenario mode=live,live_credentials=filled,locale=ko
     *
     * @effects live_mode_with_filled_live_credentials_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_live_mode_with_filled_live_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => false,
            'live_mid' => '1234567',
            'live_api_key' => 'live-secret-key',
        ]);

        $response->assertStatus(200);
    }

    /**
     * 테스트 모드에서는 라이브 자격증명이 비어도 저장된다.
     *
     * @scenario mode=test,live_credentials=empty,locale=ko
     *
     * @effects test_mode_with_empty_live_credentials_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_test_mode_with_empty_live_credentials_is_accepted(): void
    {
        $response = $this->putSettings([
            'is_test_mode' => true,
            'live_mid' => '',
            'live_api_key' => '',
        ]);

        $response->assertStatus(200);
    }

    /**
     * 라이브 API 키는 설정 파일에 암호화돼 저장되고 서비스 조회로 원문이 돌아온다.
     *
     * @scenario mode=live,live_credentials=filled
     *
     * @effects live_api_key_is_not_stored_as_plaintext_in_settings_file, live_api_key_decrypts_back_to_original_via_settings_service_get
     *
     * @return void 반환값 없음
     */
    public function test_live_api_key_is_stored_encrypted(): void
    {
        $this->putSettings([
            'is_test_mode' => false,
            'live_mid' => '1234567',
            'live_api_key' => 'live-secret-key',
        ])->assertStatus(200);

        // 저장 파일에 평문이 남지 않아야 한다 (sensitive 암호화).
        // PluginTestCase 가 'plugins' 디스크를 임시 디렉토리로 격리하므로 실제 storage_path 를
        // 하드코딩하지 않고 격리된 디스크를 통해 파일을 확인한다.
        $settingsFile = self::IDENTIFIER.'/settings/setting.json';
        $this->assertTrue(Storage::disk('plugins')->exists($settingsFile));
        $raw = Storage::disk('plugins')->get($settingsFile);
        $this->assertStringNotContainsString('live-secret-key', $raw);

        // 복호화 왕복은 원문과 일치해야 한다.
        $value = app(PluginSettingsService::class)->get(self::IDENTIFIER, 'live_api_key');
        $this->assertSame('live-secret-key', $value);
    }
}
