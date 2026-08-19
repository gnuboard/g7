<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\JsonConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 단건 설정 저장(`PUT /api/admin/settings/{key}`)의 값 형태 검증
 *
 * 종전 규칙은 `required|string|max:1000` 이었다. 그래서 두 가지가 깨졌다.
 *  - 한 번 입력한 값을 빈 칸으로 되돌릴 수 없다 (`required` 가 빈 문자열을 거부해 422).
 *  - 폼 전송의 boolean/정수가 문자열로 저장되어, 값을 읽는 쪽의 타입 비교가 어긋난다.
 *
 * 값의 타입은 `config/settings/defaults.json` 의 기본값이 SSoT 다.
 */
class SettingsSingleValueUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->createAdminUser()->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 사용자 생성
     */
    private function createAdminUser(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $scopedRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $scopedRole->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($scopedRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 단건 저장 요청을 보냅니다.
     *
     * @param  string  $key  설정 키
     * @param  array<string, mixed>  $payload  요청 본문
     */
    private function putSetting(string $key, array $payload): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/settings/'.$key, $payload);
    }

    /**
     * 저장된 값을 저장소에서 직접 읽습니다.
     *
     * @param  string  $key  설정 키
     * @return mixed 저장값
     */
    private function stored(string $key): mixed
    {
        return (new JsonConfigRepository)->get($key);
    }

    // ─── 빈 값 되돌리기 ──────────────────────────────────────

    /**
     * 문자열 설정을 빈 값으로 되돌릴 수 있습니다. (실패-먼저)
     *
     * @scenario declared_type=string, value_disposition=cleared
     *
     * @effects setting_cleared_to_empty_string
     */
    #[Test]
    public function string_setting_can_be_cleared_to_empty(): void
    {
        $this->putSetting('seo.meta_keywords', ['value' => 'g7, cms'])->assertOk();
        $this->assertSame('g7, cms', $this->stored('seo.meta_keywords'));

        $this->putSetting('seo.meta_keywords', ['value' => ''])->assertOk();

        $this->assertSame('', $this->stored('seo.meta_keywords'), '빈 값으로 되돌릴 수 없습니다.');
    }

    /**
     * null 도 빈 값으로 받아들이며, 문자열 설정에는 빈 문자열로 남깁니다.
     *
     * `ConvertEmptyStringsToNull` 미들웨어가 `''` 를 null 로 바꾸므로 두 입력은
     * 요청 시점에 이미 같다. 저장 형태는 선언 타입(기본값 `''`)에 맞춘다.
     *
     * @scenario declared_type=string, value_disposition=cleared
     *
     * @effects setting_cleared_to_empty_string
     */
    #[Test]
    public function null_value_is_accepted(): void
    {
        $this->putSetting('seo.meta_keywords', ['value' => null])->assertOk();

        $this->assertSame('', $this->stored('seo.meta_keywords'));
    }

    /**
     * `value` 키 자체가 빠진 요청은 거부합니다.
     *
     * "값을 비운다" 와 "값을 안 보냈다" 는 다르다 — 후자는 잘못된 payload 다.
     *
     * @scenario declared_type=string, value_disposition=rejected
     *
     * @effects missing_value_key_rejected_with_422
     */
    #[Test]
    public function missing_value_key_is_rejected(): void
    {
        $this->putSetting('seo.meta_keywords', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    /**
     * 문자열 설정은 값이 숫자·불리언처럼 보여도 문자열 그대로 저장합니다.
     *
     * 정규화는 선언 타입이 boolean·정수일 때만 캐스팅해야 한다. 문자열 설정까지 캐스팅하면
     * 앞자리 0 이 사라지거나(`"0123"` → 123) 문구가 boolean 이 되어, 운영자가 넣은 값이
     * 조용히 다른 것이 된다.
     *
     * 앞뒤 공백은 프레임워크의 `TrimStrings` 미들웨어가 요청 시점에 이미 제거하므로
     * 이 경로의 판정 대상이 아니다.
     *
     * @scenario declared_type=string, value_disposition=accepted
     *
     * @effects string_setting_stored_verbatim
     */
    #[Test]
    public function string_setting_is_stored_verbatim(): void
    {
        foreach (['0123', 'false', '42'] as $value) {
            $this->putSetting('seo.meta_keywords', ['value' => $value])->assertOk();

            $stored = $this->stored('seo.meta_keywords');
            $this->assertIsString($stored, "'{$value}' 가 문자열이 아닌 타입으로 캐스팅되었습니다.");
            $this->assertSame($value, $stored, '문자열 값이 변형되었습니다.');
        }
    }

    // ─── 타입 보존 ──────────────────────────────────────

    /**
     * boolean 설정에 문자열 "true" 를 보내도 boolean 으로 저장됩니다. (실패-먼저)
     *
     * @scenario declared_type=boolean, value_disposition=accepted
     *
     * @effects boolean_setting_stored_as_boolean
     */
    #[Test]
    public function boolean_setting_stores_boolean_not_string(): void
    {
        $this->putSetting('seo.sitemap_enabled', ['value' => 'false'])->assertOk();

        $stored = $this->stored('seo.sitemap_enabled');
        $this->assertIsBool($stored, '문자열이 그대로 저장되었습니다.');
        $this->assertFalse($stored);

        $this->putSetting('seo.sitemap_enabled', ['value' => '1'])->assertOk();
        $this->assertTrue($this->stored('seo.sitemap_enabled'));
    }

    /**
     * JSON 본문의 진짜 boolean 도 그대로 저장됩니다.
     *
     * @scenario declared_type=boolean, value_disposition=accepted
     *
     * @effects boolean_setting_stored_as_boolean
     */
    #[Test]
    public function native_boolean_is_preserved(): void
    {
        $this->putSetting('general.maintenance_mode', ['value' => true])->assertOk();

        $this->assertTrue($this->stored('general.maintenance_mode'));
    }

    /**
     * boolean 으로 해석할 수 없는 값은 거부합니다.
     *
     * 조용히 false 로 캐스팅하면 오타 입력이 "정상 저장" 으로 통과한다.
     *
     * @scenario declared_type=boolean, value_disposition=rejected
     *
     * @effects uninterpretable_value_rejected_with_422
     */
    #[Test]
    public function uninterpretable_boolean_is_rejected(): void
    {
        $this->putSetting('seo.sitemap_enabled', ['value' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    /**
     * 정수 설정에 숫자 문자열을 보내면 정수로 저장됩니다. (실패-먼저)
     *
     * @scenario declared_type=integer, value_disposition=accepted
     *
     * @effects integer_setting_stored_as_integer
     */
    #[Test]
    public function integer_setting_stores_integer_not_string(): void
    {
        $this->putSetting('upload.max_file_size', ['value' => '25'])->assertOk();

        $stored = $this->stored('upload.max_file_size');
        $this->assertIsInt($stored, '문자열이 그대로 저장되었습니다.');
        $this->assertSame(25, $stored);
    }

    /**
     * 정수로 해석할 수 없는 값은 거부합니다.
     *
     * @scenario declared_type=integer, value_disposition=rejected
     *
     * @effects uninterpretable_value_rejected_with_422
     */
    #[Test]
    public function uninterpretable_integer_is_rejected(): void
    {
        $this->putSetting('upload.max_file_size', ['value' => 'twenty'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    /**
     * 비-문자열 설정에 빈 문자열을 보내면 null 로 비웁니다.
     *
     * @scenario declared_type=integer, value_disposition=cleared
     *
     * @effects setting_cleared_to_null
     */
    #[Test]
    public function empty_string_clears_non_string_setting(): void
    {
        $this->putSetting('upload.max_file_size', ['value' => '25'])->assertOk();
        $this->putSetting('upload.max_file_size', ['value' => ''])->assertOk();

        $this->assertNull($this->stored('upload.max_file_size'));
    }

    /**
     * 배열 값도 저장할 수 있습니다.
     *
     * @scenario declared_type=array, value_disposition=accepted
     *
     * @effects array_setting_stored_as_array
     */
    #[Test]
    public function array_value_is_accepted(): void
    {
        $this->putSetting('seo.bot_user_agents', ['value' => ['Googlebot', 'Bingbot']])->assertOk();

        $this->assertSame(['Googlebot', 'Bingbot'], $this->stored('seo.bot_user_agents'));
    }

    /**
     * boolean 설정도 빈 값으로 되돌릴 수 있습니다.
     *
     * @scenario declared_type=boolean, value_disposition=cleared
     *
     * @effects setting_cleared_to_null
     */
    #[Test]
    public function boolean_setting_can_be_cleared(): void
    {
        $this->putSetting('seo.sitemap_enabled', ['value' => 'true'])->assertOk();
        $this->putSetting('seo.sitemap_enabled', ['value' => ''])->assertOk();

        $this->assertNull($this->stored('seo.sitemap_enabled'), 'boolean 설정을 비울 수 없습니다.');
    }

    /**
     * 배열 설정도 빈 값으로 되돌릴 수 있습니다.
     *
     * @scenario declared_type=array, value_disposition=cleared
     *
     * @effects setting_cleared_to_null
     */
    #[Test]
    public function array_setting_can_be_cleared(): void
    {
        $this->putSetting('seo.bot_user_agents', ['value' => ['Googlebot']])->assertOk();
        $this->putSetting('seo.bot_user_agents', ['value' => ''])->assertOk();

        $this->assertNull($this->stored('seo.bot_user_agents'), '배열 설정을 비울 수 없습니다.');
    }

    // ─── 길이 상한 ──────────────────────────────────────

    /**
     * 문자열 상한(1000자)은 그대로 유지됩니다.
     *
     * @scenario declared_type=string, value_disposition=rejected
     *
     * @effects oversized_value_rejected_with_422
     */
    #[Test]
    public function oversized_string_is_rejected(): void
    {
        $this->putSetting('seo.meta_keywords', ['value' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    /**
     * 배열 값의 JSON 직렬화 상한(5000자)도 거부합니다.
     *
     * 문자열 상한만 있으면 배열 축으로 얼마든지 큰 값이 들어온다 — 설정 JSON 파일이
     * 요청 하나로 무한정 커진다.
     *
     * @scenario declared_type=array, value_disposition=rejected
     *
     * @effects oversized_array_rejected_with_422
     */
    #[Test]
    public function oversized_array_is_rejected(): void
    {
        // 항목당 12자 남짓 × 500 → JSON 직렬화 6000자 초과
        $agents = array_map(static fn (int $i): string => 'Bot'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 500));

        $this->assertGreaterThan(5000, mb_strlen((string) json_encode($agents)), '픽스처가 상한을 넘지 못합니다.');

        $this->putSetting('seo.bot_user_agents', ['value' => $agents])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }
}
