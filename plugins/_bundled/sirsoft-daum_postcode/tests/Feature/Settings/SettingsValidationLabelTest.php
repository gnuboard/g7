<?php

namespace Plugins\Sirsoft\DaumPostcode\Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\DaumPostcode\Tests\PluginTestCase;

/**
 * 환경설정 저장 검증 — 팝업 크기 범위와 오류 문구의 항목 이름.
 *
 * 팝업 너비와 높이는 설정 스키마의 min 100, max 2000 으로 묶이고 코어 설정 저장 검증이 범위 밖을 422 로
 * 거절한다. 스키마 label 은 ko/en 만 들고 있어, 설정 리스너가 항목 이름을 요청 언어로 등록하지 않으면
 * 일본어 화면의 오류 문구에 한국어 항목 이름(「팝업 너비 (px)」)이 섞인다.
 *
 * @scenario-manifest tests/scenarios/settings-validation-labels.yaml
 */
class SettingsValidationLabelTest extends PluginTestCase
{
    private User $admin;

    private string $token;

    /**
     * 설정 수정 권한을 가진 관리자 토큰을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

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
            'identifier' => 'postcode_settings_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync([$permission->id]);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->admin = $user->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 범위 밖 팝업 크기는 422 로 거절되고 문구가 허용 범위를 알린다.
     *
     * @scenario locale=en
     *
     * @effects popup_size_out_of_range_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_out_of_range_popup_size_is_rejected(): void
    {
        $response = $this->putSettings('en', ['popup_width' => -10, 'popup_height' => 5000]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['popup_width', 'popup_height']);
        $this->assertStringContainsString('100', $response->json('errors.popup_width.0'));
        $this->assertStringContainsString('2000', $response->json('errors.popup_height.0'));
    }

    /**
     * 범위 안 팝업 크기는 저장된다.
     *
     * @scenario locale=ko
     *
     * @effects popup_size_in_range_saves
     *
     * @return void 반환값 없음
     */
    public function test_in_range_popup_size_is_saved(): void
    {
        $this->putSettings('ko', ['display_mode' => 'popup', 'popup_width' => 100, 'popup_height' => 2000])
            ->assertStatus(200);
    }

    /**
     * ko 요청에서 설정 항목 4개 모두의 오류 문구가 화면과 같은 한국어 항목 이름을 쓴다.
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
     * en 요청에서 오류 문구가 영어 항목 이름을 쓰고 한국어가 남지 않는다.
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
     * ja 요청에서 오류 문구가 일본어 언어팩의 항목 이름을 쓰고 한국어가 남지 않는다.
     *
     * ja 이름은 번들 일본어 언어팩 파일의 실제 값을 싣는다 — 언어팩에 키가 없으면 기대값이 안내 문구가 돼 실패한다.
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
     * 모든 설정 항목이 거절되는 저장을 보내고, 오류 문구의 항목 이름이 요청 언어를 따르는지 본다.
     *
     * @param  string  $locale  요청 언어(ko|en|ja)
     * @return void 반환값 없음
     */
    private function assertEveryFieldLabelFollowsLocale(string $locale): void
    {
        $packFile = base_path('lang-packs/_bundled/g7-plugin-sirsoft-daum_postcode-ja/backend/ja/messages.php');
        $jaSettings = is_file($packFile) ? ((require $packFile)['settings'] ?? []) : [];
        $lines = [];
        foreach ($jaSettings as $key => $value) {
            if (is_string($value)) {
                $lines['messages.settings.'.$key] = $value;
            }
        }
        Lang::addLines($lines, 'ja', self::IDENTIFIER);

        $ko = [
            'display_mode' => '표시 방식',
            'popup_width' => '팝업 너비 (px)',
            'popup_height' => '팝업 높이 (px)',
            'theme_color' => '테마 색상',
        ];
        $expected = [
            'ko' => $ko,
            'en' => [
                'display_mode' => 'Display Mode',
                'popup_width' => 'Popup Width (px)',
                'popup_height' => 'Popup Height (px)',
                'theme_color' => 'Theme Color',
            ],
            'ja' => array_combine(array_keys($ko), array_map(
                static fn (string $field) => $jaSettings[$field.'_attribute'] ?? "(ja 언어팩에 {$field}_attribute 없음)",
                array_keys($ko),
            )),
        ][$locale];

        $response = $this->putSettings($locale, [
            'display_mode' => 'zz',
            'popup_width' => -10,
            'popup_height' => 5000,
            'theme_color' => ['x'],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        foreach ($expected as $field => $label) {
            $this->assertArrayHasKey($field, $errors, "{$locale}: {$field} 오류가 있어야 한다");
            $this->assertStringContainsString($label, $errors[$field][0], "{$locale}: {$field} 오류 문구의 항목 이름");
            if ($locale !== 'ko') {
                $this->assertStringNotContainsString($ko[$field], $errors[$field][0], "{$locale}: {$field} 항목 이름이 한국어로 남으면 안 된다");
            }
        }
    }

    /**
     * 요청 언어를 정해 설정 저장을 보낸다.
     *
     * 코어 SetLocale 미들웨어의 1순위는 인증 사용자의 users.language 이므로 관리자 언어도 함께 바꾼다.
     *
     * @param  string  $locale  요청 언어
     * @param  array<string, mixed>  $body  저장 본문
     * @return TestResponse 설정 저장 응답
     */
    private function putSettings(string $locale, array $body)
    {
        $originalLocales = config('app.supported_locales');
        config(['app.supported_locales' => array_values(array_unique(array_merge((array) $originalLocales, ['ja'])))]);

        try {
            $this->admin->forceFill(['language' => $locale])->save();
            $this->app['auth']->forgetGuards();

            return $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
                'Accept-Language' => $locale,
            ])->putJson('/api/admin/plugins/'.self::IDENTIFIER.'/settings', $body);
        } finally {
            config(['app.supported_locales' => $originalLocales]);
        }
    }
}
