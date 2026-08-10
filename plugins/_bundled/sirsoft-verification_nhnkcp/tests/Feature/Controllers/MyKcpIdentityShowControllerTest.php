<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 마이페이지 본인확인 카드 API 검증.
 *
 * 사용자가 자기 정보를 확인할 수 있게 하되 이름/생년월일/휴대폰은 가려서 내려주고, 동일인
 * 식별값(CI/DI)은 응답에 포함하지 않는다.
 *
 * @since 1.0.0
 */
class MyKcpIdentityShowControllerTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-verification_nhnkcp/me/identity/nhnkcp';

    private KcpIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);
    }

    /**
     * @scenario record_exists=true,auth=authenticated
     *
     * @effects verified_user_receives_masked_fields
     */
    public function test_verified_user_receives_masked_fields(): void
    {
        $user = User::factory()->create();
        $this->seedRecord($user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame('홍*동', $data['name_masked'], '성과 끝 글자만 남기고 가운데를 가린다 (와이어프레임 §4.4)');
        $this->assertSame('1990-**-**', $data['birthday_masked']);
        $this->assertSame('010-****-5678', $data['phone_masked']);
        $this->assertTrue($data['is_adult']);
        $this->assertFalse($data['is_foreigner']);
        $this->assertNotEmpty($data['method']);
        $this->assertNotEmpty($data['verified_at']);
    }

    /**
     * @scenario record_exists=true,auth=authenticated
     *
     * @effects response_excludes_identity_identifiers
     */
    public function test_response_excludes_identity_identifiers_and_plain_values(): void
    {
        $user = User::factory()->create();
        $this->seedRecord($user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson(self::ENDPOINT);
        $body = (string) $response->getContent();

        foreach (['di', 'ci', 'di_hash', 'ci_hash'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $response->json('data'));
        }

        $this->assertStringNotContainsString('DI-CARD-0001', $body);
        $this->assertStringNotContainsString('CI-CARD-0001', $body);
        $this->assertStringNotContainsString('01012345678', $body, '휴대폰 평문이 노출되면 안 된다');
        $this->assertStringNotContainsString('홍길동', $body, '실명 평문이 노출되면 안 된다');
    }

    /**
     * @scenario record_exists=false,auth=authenticated
     *
     * @effects user_without_record_receives_null_data
     */
    public function test_user_without_record_receives_null_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));
    }

    /**
     * @scenario auth=guest,record_exists=false
     *
     * @effects guest_request_is_rejected
     */
    public function test_guest_request_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * 본인확인 record 를 심는다.
     *
     * @param  int  $userId  사용자 ID
     */
    /**
     * 짧은 이름의 마스킹 경계 — 두 글자는 성만, 한 글자는 가릴 곳이 없어 그대로 둔다.
     *
     * 가운데를 가리는 규칙을 짧은 이름에 그대로 적용하면 이름이 통째로 사라지거나
     * 별표만 남아 본인 확인이 되지 않는다.
     *
     * @param  string  $name  원본 이름
     * @param  string  $expected  기대 마스킹 결과
     *
     * @scenario record_exists=true,auth=authenticated
     *
     * @effects verified_user_receives_masked_fields
     */
    #[DataProvider('shortNameProvider')]
    public function test_short_names_are_masked_within_boundary(string $name, string $expected): void
    {
        $user = User::factory()->create();
        $this->seedRecord($user->id, $name);

        $response = $this->actingAs($user, 'sanctum')->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $this->assertSame($expected, $response->json('data.name_masked'));
    }

    /**
     * 이름 길이별 마스킹 기대값.
     *
     * @return array<string, array{string, string}> 원본 이름 / 기대 결과
     */
    public static function shortNameProvider(): array
    {
        return [
            '네 글자' => ['남궁길동', '남**동'],
            '세 글자' => ['홍길동', '홍*동'],
            '두 글자' => ['김철', '김*'],
            '한 글자' => ['민', '민'],
        ];
    }

    private function seedRecord(int $userId, string $name = '홍길동'): void
    {
        $this->recordRepository->upsertForUser($userId, [
            'comm_id' => 'SKT',
            'name_encrypted' => Crypt::encryptString($name),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => Crypt::encryptString('DI-CARD-0001'),
            'di_hash' => hash_hmac('sha256', 'DI-CARD-0001', (string) config('app.key')),
            'ci_encrypted' => Crypt::encryptString('CI-CARD-0001'),
            'ci_hash' => hash_hmac('sha256', 'CI-CARD-0001', (string) config('app.key')),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'verified_at' => now(),
        ]);
    }
}
