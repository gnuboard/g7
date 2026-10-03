<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 정책 보안 설정 저장 테스트 (#736 §4.2)
 *
 * 새 설정 키가 보안 탭 저장으로 실제 저장되는지(규칙이 없으면 조용히 버려진다), 범위·교차 검증이 422 로
 * 막는지, 숫자 문자열이 정수로 저장되는지, 화면에 내보내는 키가 비밀번호 작성·재사용 규칙 10개뿐인지 본다.
 */
class PasswordPolicySettingsTest extends TestCase
{
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 관리자로 인증합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->makeAdmin());
    }

    /**
     * 보안 탭을 저장합니다.
     *
     * @param  array<string, mixed>  $security  보안 설정
     * @return TestResponse 응답
     */
    private function saveSecurity(array $security): TestResponse
    {
        return $this->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => array_merge(['force_https' => false, 'login_attempt_enabled' => true], $security),
        ]);
    }

    /**
     * 새 키 전부가 저장되고, 숫자 입력칸이 보낸 문자열은 정수로 저장된다.
     *
     * @scenario tab=security, input_state=valid_strings
     *
     * @effects all_policy_keys_persist_with_integer_types
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function every_policy_key_is_saved_with_integer_types(): void
    {
        $this->saveSecurity([
            'password_require_char_types' => true,
            'password_block_identifier' => true,
            'password_block_repeated_chars' => true,
            'password_repeated_chars_length' => '5',
            'password_block_sequential_chars' => true,
            'password_sequential_chars_length' => '3',
            'password_reuse_prevention_enabled' => true,
            'password_reuse_count' => '4',
            'password_expiry_enabled' => true,
            'password_expiry_days' => '60',
            'password_expiry_notice_days' => '10',
            'password_expiry_scope' => 'admin',
            'password_expiry_action' => 'remind',
            'password_expiry_remind_days' => '14',
            'password_expiry_batch_size' => '2',
        ])->assertOk();

        $stored = app(ConfigRepositoryInterface::class)->getCategory('security');

        $this->assertTrue($stored['password_require_char_types']);
        $this->assertSame(5, $stored['password_repeated_chars_length']);
        $this->assertSame(3, $stored['password_sequential_chars_length']);
        $this->assertSame(4, $stored['password_reuse_count']);
        $this->assertSame(60, $stored['password_expiry_days']);
        $this->assertSame(10, $stored['password_expiry_notice_days']);
        $this->assertSame('admin', $stored['password_expiry_scope']);
        $this->assertSame('remind', $stored['password_expiry_action']);
        $this->assertSame(14, $stored['password_expiry_remind_days']);
        $this->assertSame(2, $stored['password_expiry_batch_size']);
    }

    /**
     * 범위를 벗어난 값과 모르는 선택지는 422 로 막는다.
     *
     * @scenario tab=security, input_state=out_of_range
     *
     * @effects out_of_range_values_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function out_of_range_values_are_rejected(): void
    {
        $cases = [
            'password_reuse_count' => 11,
            'password_expiry_days' => 29,
            'password_expiry_notice_days' => 31,
            'password_repeated_chars_length' => 7,
            'password_expiry_batch_size' => 100001,
            'password_expiry_scope' => 'everyone',
            'password_expiry_action' => 'ignore',
        ];

        foreach ($cases as $key => $value) {
            $this->saveSecurity([$key => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors(["security.{$key}"]);
        }
    }

    /**
     * 만료를 켠 채 안내일이 기간보다 짧지 않으면 교차 검증 422 다(기간 30·안내 30).
     *
     * @scenario tab=security, input_state=notice_not_shorter
     *
     * @effects notice_must_be_shorter_than_expiry
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function notice_days_must_be_shorter_than_expiry_days_when_enabled(): void
    {
        $response = $this->saveSecurity([
            'password_expiry_enabled' => true,
            'password_expiry_days' => 30,
            'password_expiry_notice_days' => 30,
        ])->assertStatus(422);

        $this->assertSame(
            [__('validation.settings.password_expiry_notice_days_lt_expiry_days')],
            $response->json('errors')['security.password_expiry_notice_days']
        );

        $this->saveSecurity([
            'password_expiry_enabled' => false,
            'password_expiry_days' => 30,
            'password_expiry_notice_days' => 30,
        ])->assertOk();
    }

    /**
     * 화면에는 비밀번호 작성·재사용 규칙 10키만 내보낸다 — 만료·IP·2FA·토큰은 내보내지 않는다.
     *
     * @scenario surface=frontend_settings
     *
     * @effects frontend_exposes_only_ten_password_keys
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_frontend_receives_only_the_ten_composition_keys(): void
    {
        $security = app(SettingsService::class)->getFrontendSettings()['security'] ?? null;

        $this->assertIsArray($security);
        $this->assertEqualsCanonicalizing([
            'password_min_length',
            'require_password_special_char',
            'password_require_char_types',
            'password_block_identifier',
            'password_block_repeated_chars',
            'password_repeated_chars_length',
            'password_block_sequential_chars',
            'password_sequential_chars_length',
            'password_reuse_prevention_enabled',
            'password_reuse_count',
        ], array_keys($security));

        $this->assertIsInt($security['password_min_length']);
        $this->assertIsBool($security['password_reuse_prevention_enabled']);
    }
}
