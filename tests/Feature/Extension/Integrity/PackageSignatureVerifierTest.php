<?php

namespace Tests\Feature\Extension\Integrity;

use App\Contracts\Repositories\ExtensionTrustKeyRepositoryInterface;
use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageExpectation;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\PackageSignatureVerifier;
use App\Extension\Integrity\PackageVerification;
use App\Extension\Integrity\PackageVerificationStatus;
use App\Extension\Integrity\TrustKeyStatus;
use App\Extension\Integrity\UnsignedPackagePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\Concerns\PackageSignatureFixtures;
use Tests\TestCase;

/**
 * 패키지 서명 검증기·미서명 정책 (#916 ④, D16·D17·D31·D32)
 *
 * 형식·알고리즘 → 기대 패키지 → 크기·SHA-256 → 키 신뢰(코어는 내장 키만, 제작자 키는 vendor 범위) → Ed25519 서명 →
 * 해제 뒤 manifest 대조 순서와, 미서명 정책(경고 후 확인·차단 스위치·불일치/미신뢰 늘 차단)을 잠근다. 교차 언어 벡터
 * (`tests/Fixtures/PackageSignature/`)를 PHP 쪽에서도 검증한다.
 */
class PackageSignatureVerifierTest extends TestCase
{
    use PackageSignatureFixtures;
    use RefreshDatabase;

    /**
     * 스키마를 다시 만들지 않고 트랜잭션으로 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 시험 파일과 내장 키 대체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupPackageFixtures();

        parent::tearDown();
    }

    /**
     * 신뢰하는 제작자 키로 서명한 확장은 검증되고, 해제 뒤 manifest 대조도 통과합니다.
     *
     * @scenario case=verifier_vendor_key_verified
     *
     * @effects package_signed_by_trusted_vendor_key_is_verified
     *
     * @return void 반환값 없음
     */
    public function test_vendor_key_signature_is_verified(): void
    {
        $key = $this->fixtureKey('glitter');
        $this->trustVendorKey($key, ['glitter']);
        $zip = $this->makePackageZip('module', 'glitter-board', '1.2.0');
        $sig = $this->signPackage($zip, $key, 'module', 'glitter-board', '1.2.0');

        $result = $this->verifier()->verify($zip, File::get($sig), new PackageExpectation('module', 'glitter-board', '1.2.0'));

        $this->assertSame(PackageVerificationStatus::Verified, $result->status);
        $this->assertSame($key['key_id'], $result->keyId);
        $this->assertSame(PackageVerification::KEY_SOURCE_VENDOR, $result->keySource);
        $this->assertSame('glitter', $result->signerLabel);

        $after = $this->verifier()->verifyManifest($result, ['identifier' => 'glitter-board', 'version' => '1.2.0']);
        $this->assertSame(PackageVerificationStatus::Verified, $after->status);
        $this->assertSame(['integrity_status' => 'verified', 'integrity_key_id' => $key['key_id']], $after->toInstallColumns());
    }

    /**
     * ZIP 1바이트·서명 값·형식 변조와 기대 패키지 불일치는 서명 불일치(invalid)이고 사유가 갈린다.
     *
     * @scenario case=verifier_tamper_and_mismatch
     *
     * @effects tampered_or_mismatched_package_is_invalid
     *
     * @return void 반환값 없음
     */
    public function test_tamper_and_mismatch_are_invalid_with_distinct_reasons(): void
    {
        $key = $this->fixtureKey('glitter');
        $this->trustVendorKey($key, ['glitter']);
        $zip = $this->makePackageZip('plugin', 'glitter-pay', '2.0.0');
        $sig = File::get($this->signPackage($zip, $key, 'plugin', 'glitter-pay', '2.0.0'));
        $expect = new PackageExpectation('plugin', 'glitter-pay', '2.0.0');

        // 서명 값 1글자 변조
        $doc = json_decode($sig, true);
        $raw = base64_decode($doc['signature']);
        $raw[0] = chr(ord($raw[0]) ^ 0x01);
        $doc['signature'] = base64_encode($raw);
        $this->assertReason('signature_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, json_encode($doc), $expect));

        // 식별자·버전·종류 불일치 (다른 패키지의 정상 서명 파일)
        $this->assertReason('identifier_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, $sig, new PackageExpectation('plugin', 'glitter-other', '2.0.0')));
        $this->assertReason('version_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, $sig, new PackageExpectation('plugin', 'glitter-pay', '2.0.1')));
        $this->assertReason('type_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, $sig, new PackageExpectation('module', 'glitter-pay', '2.0.0')));

        // 알 수 없는 형식·알고리즘
        $this->assertReason('format_unknown', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, '{"format":"other"}', $expect));
        $wrongAlgo = json_decode($sig, true);
        $wrongAlgo['algorithm'] = 'rsa';
        $this->assertReason('format_unknown', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, json_encode($wrongAlgo), $expect));

        // ZIP 1바이트 변조 (크기는 같고 내용만 다름)
        $bytes = File::get($zip);
        $bytes[10] = chr(ord($bytes[10]) ^ 0xFF);
        File::put($zip, $bytes);
        $this->assertReason('digest_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verify($zip, $sig, $expect));

        // 해제 뒤 manifest 가 서명과 다름
        File::put($zip, $bytes); // 크기 그대로
        $verified = new PackageVerification(PackageVerificationStatus::Verified, null, ['type' => 'plugin', 'identifier' => 'glitter-pay', 'version' => '2.0.0'], $key['key_id'], 'glitter', PackageVerification::KEY_SOURCE_VENDOR);
        $this->assertReason('manifest_identifier_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verifyManifest($verified, ['identifier' => 'glitter-evil', 'version' => '2.0.0']));
        $this->assertReason('manifest_version_mismatch', PackageVerificationStatus::Invalid, $this->verifier()->verifyManifest($verified, ['identifier' => 'glitter-pay', 'version' => '9.0.0']));
    }

    /**
     * 등록되지 않은 키·vendor 범위 밖·제작자 키로 서명한 코어는 미신뢰, 내장 키는 vendor 와 무관하게 신뢰합니다(D31).
     *
     * @scenario case=verifier_trust_scope
     *
     * @effects untrusted_key_or_vendor_scope_or_core_rejected
     *
     * @return void 반환값 없음
     */
    public function test_trust_scope_rules(): void
    {
        $glitter = $this->fixtureKey('glitter');
        $zip = $this->makePackageZip('module', 'acme-shop', '1.0.0');
        $sig = File::get($this->signPackage($zip, $glitter, 'module', 'acme-shop', '1.0.0'));
        $expect = new PackageExpectation('module', 'acme-shop', '1.0.0');

        // 등록 안 된 키
        $this->assertReason('key_unknown', PackageVerificationStatus::Untrusted, $this->verifier()->verify($zip, $sig, $expect));

        // 등록됐지만 vendor 범위 밖 (glitter 키로 acme 확장 서명)
        $this->trustVendorKey($glitter, ['glitter']);
        $this->assertReason('vendor_out_of_scope', PackageVerificationStatus::Untrusted, $this->verifier()->verify($zip, $sig, $expect));

        // 제작자 키로 서명한 코어 — 내장 키만 받는다
        $coreZip = $this->makePackageZip('module', 'g7', '7.2.0', [], [], 'g7-7.2.0.zip');
        $coreSig = File::get($this->signPackage($coreZip, $glitter, 'core', 'g7', '7.2.0'));
        $this->assertReason('core_requires_builtin_key', PackageVerificationStatus::Untrusted, $this->verifier()->verify($coreZip, $coreSig, PackageExpectation::core('7.2.0')));

        // 내장 키는 코어·임의 vendor 모두 신뢰
        $builtin = $this->fixtureKey('sirsoft');
        $this->useBuiltinTestKey($builtin);
        $coreSigned = File::get($this->signPackage($coreZip, $builtin, 'core', 'g7', '7.2.0'));
        $core = $this->verifier()->verify($coreZip, $coreSigned, PackageExpectation::core('7.2.0'));
        $this->assertSame(PackageVerificationStatus::Verified, $core->status);
        $this->assertSame(PackageVerification::KEY_SOURCE_BUILTIN, $core->keySource);

        $acmeByBuiltin = File::get($this->signPackage($zip, $builtin, 'module', 'acme-shop', '1.0.0'));
        $this->assertSame(PackageVerificationStatus::Verified, $this->verifier()->verify($zip, $acmeByBuiltin, $expect)->status);
    }

    /**
     * 내장 키 상태 — retired 는 검증 통과, revoked 는 미신뢰.
     *
     * @scenario case=verifier_builtin_key_status
     *
     * @effects builtin_retired_passes_revoked_rejected
     *
     * @return void 반환값 없음
     */
    public function test_builtin_retired_passes_and_revoked_is_rejected(): void
    {
        $builtin = $this->fixtureKey('sirsoft');
        $zip = $this->makePackageZip('template', 'sirsoft-basic', '1.0.0');
        $sig = File::get($this->signPackage($zip, $builtin, 'template', 'sirsoft-basic', '1.0.0'));
        $expect = new PackageExpectation('template', 'sirsoft-basic', '1.0.0');

        $this->useBuiltinTestKey($builtin, TrustKeyStatus::Retired->value);
        $this->assertSame(PackageVerificationStatus::Verified, $this->verifier()->verify($zip, $sig, $expect)->status);

        $this->useBuiltinTestKey($builtin, TrustKeyStatus::Revoked->value);
        $this->assertReason('key_revoked', PackageVerificationStatus::Untrusted, $this->verifier()->verify($zip, $sig, $expect));
    }

    /**
     * sodium 이 없는 서버는 서명이 있어도 「검증 불가」(unverifiable)이고 미서명 정책을 따른다(D32).
     *
     * @scenario case=verifier_sodium_missing
     *
     * @effects sodium_missing_marks_unverifiable_and_follows_unsigned_policy
     *
     * @return void 반환값 없음
     */
    public function test_sodium_missing_is_unverifiable(): void
    {
        $key = $this->fixtureKey('glitter');
        $this->trustVendorKey($key, ['glitter']);
        $zip = $this->makePackageZip('module', 'glitter-board', '1.2.0');
        $sig = File::get($this->signPackage($zip, $key, 'module', 'glitter-board', '1.2.0'));

        $verifier = new class(app(ExtensionTrustKeyRepositoryInterface::class)) extends PackageSignatureVerifier
        {
            /**
             * sodium 이 없는 서버처럼 답합니다.
             *
             * @return bool 늘 false
             */
            public function sodiumAvailable(): bool
            {
                return false;
            }
        };

        $result = $verifier->verify($zip, $sig, new PackageExpectation('module', 'glitter-board', '1.2.0'));
        $this->assertReason('sodium_missing', PackageVerificationStatus::Unverifiable, $result);
        $this->assertTrue($result->status->followsUnsignedPolicy());

        try {
            app(UnsignedPackagePolicy::class)->decide($result, false);
            $this->fail('검증 불가 패키지가 확인 없이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertTrue($e->canContinue);
        }
        $this->assertSame($result, app(UnsignedPackagePolicy::class)->decide($result, true));
    }

    /**
     * 미서명 정책 — 확인 없으면 409(계속 가능), 확인하면 통과, 차단 스위치는 확인으로도 못 넘고, 불일치·미신뢰는 늘 422.
     *
     * @scenario case=unsigned_policy
     *
     * @effects unsigned_policy_confirm_block_switch_and_hard_reject
     *
     * @return void 반환값 없음
     */
    public function test_unsigned_policy_decisions(): void
    {
        $policy = app(UnsignedPackagePolicy::class);
        $unsigned = PackageVerification::unsigned();

        try {
            $policy->decide($unsigned, false);
            $this->fail('미서명이 확인 없이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(PackageIntegrityException::CODE_UNSIGNED, $e->integrityCode);
            $this->assertTrue($e->canContinue);
            $this->assertFalse($e->blockedByPolicy);
            $this->assertTrue($e->getErrors()['package_unsigned']);
            $this->assertTrue($e->getErrors()['integrity']['can_continue']);
        }

        $this->assertSame($unsigned, $policy->decide($unsigned, true));

        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, true);
        try {
            $policy->decide($unsigned, true);
            $this->fail('차단 스위치가 켜졌는데 미서명이 통과했다');
        } catch (PackageIntegrityException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertFalse($e->canContinue);
            $this->assertTrue($e->blockedByPolicy);
            $this->assertSame('extension_integrity.errors.package_unsigned_blocked', $e->getMessageKey());
        }
        Config::set('g7_settings.core.'.UnsignedPackagePolicy::BLOCK_SETTING, false);

        foreach ([
            [PackageVerificationStatus::Invalid, PackageIntegrityException::CODE_INVALID],
            [PackageVerificationStatus::Untrusted, PackageIntegrityException::CODE_UNTRUSTED],
        ] as [$status, $code]) {
            try {
                $policy->decide(new PackageVerification($status, 'key_unknown', null, null, null, null, ['key' => 'x']), true);
                $this->fail($status->value.' 가 통과했다');
            } catch (PackageIntegrityException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame($code, $e->integrityCode);
                $this->assertFalse($e->canContinue);
            }
        }

        $this->assertSame(PackageVerificationStatus::Bundled, $policy->decide(PackageVerification::bundled(), false)->status);
    }

    /**
     * 교차 언어 벡터 — Node 릴리스 도구와 같은 바이트(서명 대상 문자열·서명)를 PHP 가 만들고, fixture 서명 파일을 검증한다.
     *
     * @scenario case=cross_language_vector
     *
     * @effects php_and_node_signatures_are_byte_identical
     *
     * @return void 반환값 없음
     */
    public function test_cross_language_vector(): void
    {
        $dir = base_path('tests/Fixtures/PackageSignature');
        $vector = json_decode(File::get($dir.'/vector-1.json'), true);
        $key = $this->fixtureKey('glitter');

        $this->assertSame($vector['signing_string'], PackageSignatureFormat::signingString($vector['document']));
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key['seed']));
        $this->assertSame($vector['signature'], base64_encode(sodium_crypto_sign_detached($vector['signing_string'], $secret)));

        $this->trustVendorKey($key, ['glitter']);
        $zip = $dir.'/'.$vector['artifact_fixture']['zip'];
        $result = $this->verifier()->verify($zip, File::get($dir.'/'.$vector['artifact_fixture']['signature_file']), new PackageExpectation('module', 'glitter-vector', '1.0.0'));
        $this->assertSame(PackageVerificationStatus::Verified, $result->status);
    }

    /**
     * 결과의 상태·사유를 단언합니다.
     *
     * @param  string  $reason  기대 사유
     * @param  PackageVerificationStatus  $status  기대 상태
     * @param  PackageVerification  $result  결과
     * @return void 반환값 없음
     */
    private function assertReason(string $reason, PackageVerificationStatus $status, PackageVerification $result): void
    {
        $this->assertSame($status, $result->status, '상태가 다르다 (사유 '.($result->reason ?? '-').')');
        $this->assertSame($reason, $result->reason);
        $this->assertNotSame('extension_integrity.reasons.'.$reason, $result->reasonMessage(), '사유 문장이 번역되지 않았다');
    }

    /**
     * 검증기를 돌려줍니다.
     *
     * @return PackageSignatureVerifier 검증기
     */
    private function verifier(): PackageSignatureVerifier
    {
        return app(PackageSignatureVerifier::class);
    }
}
