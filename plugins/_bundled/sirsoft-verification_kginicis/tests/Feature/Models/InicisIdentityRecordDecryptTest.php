<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Models;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * InicisIdentityRecord 모델의 PII accessor 복호화 회귀 테스트.
 *
 * provider 의 buildPiiPayload 는 `Crypt::encryptString` 으로 raw 문자열을 암호화하지만
 * 모델 accessor 가 `Crypt::decrypt` (serialize 기반) 를 사용하면 unserialize 에러로 실패한다.
 * 회귀 차단: encryptString ↔ decryptString 으로 일관성 보장.
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityRecordDecryptTest extends PluginTestCase
{
    public function test_name_accessor_returns_plaintext_when_encrypted_with_encrypt_string(): void
    {
        $user = User::factory()->create();
        $record = InicisIdentityRecord::query()->create([
            'user_id' => $user->id,
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => Crypt::encryptString('DI-VAL'),
            'di_hash' => hash('sha256', 'DI-VAL'),
            'ci_encrypted' => Crypt::encryptString('CI-VAL'),
            'ci_hash' => hash('sha256', 'CI-VAL'),
            'ci2_encrypted' => null,
            'ci2_hash' => null,
            'gender' => 'M',
            'is_foreigner' => false,
            'is_adult' => true,
            'verified_at' => now(),
        ]);

        $reload = InicisIdentityRecord::query()->find($record->id);

        $this->assertSame('홍길동', $reload->name);
        $this->assertSame('01012345678', $reload->phone);
        $this->assertSame('19900101', $reload->birthday);
        $this->assertSame('DI-VAL', $reload->di);
        $this->assertSame('CI-VAL', $reload->ci);
    }

    public function test_accessors_return_null_when_encrypted_columns_are_null(): void
    {
        $user = User::factory()->create();
        $record = InicisIdentityRecord::query()->create([
            'user_id' => $user->id,
            'name_encrypted' => Crypt::encryptString('홍길동'),
            'phone_encrypted' => Crypt::encryptString('01012345678'),
            'birthday_encrypted' => Crypt::encryptString('19900101'),
            'di_encrypted' => null,
            'di_hash' => null,
            'ci_encrypted' => null,
            'ci_hash' => null,
            'ci2_encrypted' => null,
            'ci2_hash' => null,
            'gender' => 'M',
            'is_foreigner' => true,
            'is_adult' => false,
            'verified_at' => now(),
        ]);

        $reload = InicisIdentityRecord::query()->find($record->id);

        $this->assertNull($reload->di);
        $this->assertNull($reload->ci);
        $this->assertNull($reload->ci2);
    }
}
