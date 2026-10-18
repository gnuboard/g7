<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Controllers;

use App\Models\User;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * GET /api/plugins/sirsoft-verification_kginicis/me/identity/inicis Feature 테스트.
 *
 * CRUD 도메인 — 마이그레이션 + 실제 DB + auth 미들웨어 + Resource 마스킹 모두 검증.
 *
 * 골든 패스 + 권한 경계 + null 처리:
 *  - 비인증 → 401
 *  - 인증 + 본인 record 없음 → data=null
 *  - 인증 + 본인 record 존재 → 마스킹 PII 반환
 */
class MyInicisIdentityShowControllerTest extends PluginTestCase
{
    private const ENDPOINT = '/api/plugins/sirsoft-verification_kginicis/me/identity/inicis';

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_authenticated_user_without_record_returns_data_null(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    public function test_authenticated_user_with_record_returns_masked_pii(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $repo = app(InicisIdentityRecordRepositoryInterface::class);
        $repo->upsertForUser((int) $user->id, [
            'name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('홍길동'),
            'phone_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('01012345678'),
            'birthday_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('19900101'),
            'di_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('DI-VAL'),
            'di_hash' => hash('sha256', 'DI-VAL'),
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'provider_dev_cd' => 'SKT',
            'verified_at' => now(),
            're_verified_at' => null,
        ]);

        $response = $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertSame('KG이니시스 본인확인', $data['method']);
        $this->assertSame('홍**', $data['name_masked']);
        $this->assertSame('1990-**-**', $data['birthday_masked']);
        $this->assertSame('010-****-5678', $data['phone_masked']);
        $this->assertTrue($data['is_adult']);
        $this->assertFalse($data['is_foreigner']);
        $this->assertNotEmpty($data['verified_at']);
    }

    public function test_re_verified_at_takes_precedence_over_verified_at(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $repo = app(InicisIdentityRecordRepositoryInterface::class);
        $repo->upsertForUser((int) $user->id, [
            'name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('홍'),
            'phone_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('01012345678'),
            'birthday_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('19900101'),
            'di_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('DI'),
            'di_hash' => hash('sha256', 'DI'),
            'is_foreigner' => false,
            'is_adult' => true,
            'verified_at' => now()->subDays(10),
            're_verified_at' => now(),
        ]);

        $data = $this->getJson(self::ENDPOINT)->json('data');
        $today = now()->format('Y-m-d');
        $this->assertStringStartsWith($today, $data['verified_at']);
    }

    public function test_response_does_not_leak_raw_pii_or_di_or_ci_hashes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $repo = app(InicisIdentityRecordRepositoryInterface::class);
        $repo->upsertForUser((int) $user->id, [
            'name_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('홍길동'),
            'phone_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('01012345678'),
            'birthday_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('19900101'),
            'di_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('DI-SECRET-XYZ'),
            'di_hash' => hash('sha256', 'DI-SECRET-XYZ'),
            'ci_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('CI-SECRET-ABC'),
            'ci_hash' => hash('sha256', 'CI-SECRET-ABC'),
            'is_foreigner' => false,
            'is_adult' => true,
            'verified_at' => now(),
        ]);

        $payload = $this->getJson(self::ENDPOINT)->getContent();

        $this->assertStringNotContainsString('홍길동', $payload);
        $this->assertStringNotContainsString('01012345678', $payload);
        $this->assertStringNotContainsString('19900101', $payload);
        $this->assertStringNotContainsString('DI-SECRET-XYZ', $payload);
        $this->assertStringNotContainsString('CI-SECRET-ABC', $payload);
        $this->assertStringNotContainsString(hash('sha256', 'DI-SECRET-XYZ'), $payload);
        $this->assertStringNotContainsString(hash('sha256', 'CI-SECRET-ABC'), $payload);
    }
}
