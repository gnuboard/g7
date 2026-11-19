<?php

namespace App\Extension\Integrity;

use App\Contracts\Repositories\ExtensionTrustKeyRepositoryInterface;

/**
 * 패키지 ZIP 과 서명 파일을 대조합니다 (Ed25519 + SHA-256).
 *
 * 검증은 두 번이다.
 *  1. 해제 전(`verify`) — 형식·알고리즘 → 기대 패키지 대조 → 크기·SHA-256 → 키 신뢰 → 서명.
 *  2. 해제 뒤·복사 전(`verifyManifest`) — 서명 파일이 말한 식별자·버전이 실제 manifest 와 같은지, 언어팩은 manifest
 *     vendor 가 제작자 키 범위 안인지.
 *
 * 키 신뢰: 코어 패키지는 내장 키(`BuiltinTrustKeys`)만. 확장·언어팩은 내장 키 또는 제작자 키 — 제작자 키는 패키지
 * vendor 가 그 키의 `vendor_scope` 안이어야 한다. key_id 는 저장된 label·공개키로 다시 계산해 대조한다.
 * sodium 확장이 없으면 서명을 확인할 수 없으므로 `unverifiable`(미서명 정책을 따름)이 된다.
 *
 * @since 7.2.0
 */
class PackageSignatureVerifier
{
    /**
     * 검증기를 만듭니다.
     *
     * @param  ExtensionTrustKeyRepositoryInterface  $trustKeys  제작자 키 저장소
     */
    public function __construct(
        private readonly ExtensionTrustKeyRepositoryInterface $trustKeys,
    ) {}

    /**
     * ZIP 바이트와 서명 파일을 검증합니다 (해제 전).
     *
     * @param  string  $zipPath  ZIP 경로
     * @param  string|null  $signatureJson  서명 파일 내용 (없으면 null → unsigned)
     * @param  PackageExpectation  $expectation  기대 패키지
     * @return PackageVerification 검증 결과
     */
    public function verify(string $zipPath, ?string $signatureJson, PackageExpectation $expectation): PackageVerification
    {
        if ($signatureJson === null || trim($signatureJson) === '') {
            return PackageVerification::unsigned();
        }

        $doc = PackageSignatureFormat::parse($signatureJson);
        if ($doc === null) {
            return new PackageVerification(PackageVerificationStatus::Invalid, 'format_unknown');
        }

        $package = [
            'type' => $doc['package']['type'],
            'identifier' => $doc['package']['identifier'],
            'version' => $doc['package']['version'],
        ];
        $keyId = $doc['signer']['key_id'];
        $label = is_string($doc['signer']['label'] ?? null) ? $doc['signer']['label'] : null;
        $base = new PackageVerification(PackageVerificationStatus::Invalid, null, $package, $keyId, $label);

        // 기대 패키지 대조 — 다른 확장·다른 판의 정상 서명 파일을 붙여 통과시키는 것을 막는다
        if ($package['type'] !== $expectation->type) {
            return $base->withStatus(PackageVerificationStatus::Invalid, 'type_mismatch', [
                'expected' => $expectation->type, 'actual' => $package['type'],
            ]);
        }
        if ($expectation->identifier !== null && $package['identifier'] !== $expectation->identifier) {
            return $base->withStatus(PackageVerificationStatus::Invalid, 'identifier_mismatch', [
                'expected' => $expectation->identifier, 'actual' => $package['identifier'],
            ]);
        }
        $expectedVersion = PackageExpectation::normalizeVersion($expectation->version);
        if ($expectedVersion !== null && PackageExpectation::normalizeVersion($package['version']) !== $expectedVersion) {
            return $base->withStatus(PackageVerificationStatus::Invalid, 'version_mismatch', [
                'expected' => $expectedVersion, 'actual' => $package['version'],
            ]);
        }

        // 바이트 대조
        if (! is_file($zipPath) || filesize($zipPath) !== $doc['artifact']['size']) {
            return $base->withStatus(PackageVerificationStatus::Invalid, 'size_mismatch');
        }
        if (! hash_equals(strtolower($doc['artifact']['sha256']), (string) hash_file('sha256', $zipPath))) {
            return $base->withStatus(PackageVerificationStatus::Invalid, 'digest_mismatch');
        }

        // 키 신뢰
        $trust = $this->resolveTrust($keyId, $package['type'], $package['identifier']);
        if ($trust['status'] !== null) {
            return new PackageVerification($trust['status'], $trust['reason'], $package, $keyId, $trust['label'] ?? $label, $trust['source'], $trust['params'] ?? []);
        }

        $verified = new PackageVerification(PackageVerificationStatus::Verified, null, $package, $keyId, $trust['label'], $trust['source']);

        if (! $this->sodiumAvailable()) {
            return $verified->withStatus(PackageVerificationStatus::Unverifiable, 'sodium_missing');
        }

        $signature = base64_decode($doc['signature'], true);
        if (! is_string($signature) || strlen($signature) !== 64) {
            return $verified->withStatus(PackageVerificationStatus::Invalid, 'signature_mismatch');
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($signature, PackageSignatureFormat::signingString($doc), $trust['raw_public_key']);
        } catch (\SodiumException) {
            $ok = false;
        }

        return $ok ? $verified : $verified->withStatus(PackageVerificationStatus::Invalid, 'signature_mismatch');
    }

    /**
     * 해제한 manifest 가 서명된 패키지와 같은지 대조합니다 (해제 뒤·복사 전).
     *
     * 서명이 없거나 이미 차단된 결과는 그대로 돌려준다.
     *
     * @param  PackageVerification  $verification  해제 전 검증 결과
     * @param  array<string, mixed>  $manifest  해제한 manifest (module.json·language-pack.json 등)
     * @return PackageVerification 대조 결과
     */
    public function verifyManifest(PackageVerification $verification, array $manifest): PackageVerification
    {
        $signedStates = [PackageVerificationStatus::Verified, PackageVerificationStatus::Unverifiable];
        if (! in_array($verification->status, $signedStates, true) || $verification->package === null) {
            return $verification;
        }

        $package = $verification->package;
        $manifestId = is_string($manifest['identifier'] ?? null) ? $manifest['identifier'] : null;
        $manifestVersion = is_string($manifest['version'] ?? null) ? $manifest['version'] : null;

        // 코어 패키지는 manifest 식별자가 없다 — 판만 대조한다
        if ($package['type'] !== PackageExpectation::TYPE_CORE && $manifestId !== $package['identifier']) {
            return $verification->withStatus(PackageVerificationStatus::Invalid, 'manifest_identifier_mismatch', [
                'expected' => (string) $package['identifier'], 'actual' => (string) ($manifestId ?? '-'),
            ]);
        }

        if (PackageExpectation::normalizeVersion($manifestVersion) !== PackageExpectation::normalizeVersion($package['version'])) {
            return $verification->withStatus(PackageVerificationStatus::Invalid, 'manifest_version_mismatch', [
                'expected' => (string) $package['version'], 'actual' => (string) ($manifestVersion ?? '-'),
            ]);
        }

        // 언어팩 vendor 는 식별자가 아니라 manifest 칸이다 — 해제 뒤에야 제작자 키 범위를 대조할 수 있다
        if ($package['type'] === PackageExpectation::TYPE_LANGUAGE_PACK
            && $verification->keySource === PackageVerification::KEY_SOURCE_VENDOR
            && $verification->keyId !== null) {
            $vendor = is_string($manifest['vendor'] ?? null) ? $manifest['vendor'] : '';
            $key = $this->trustKeys->findByKeyId($verification->keyId);
            if ($key === null || $vendor === '' || ! $key->coversVendor($vendor)) {
                return $verification->withStatus(PackageVerificationStatus::Untrusted, 'vendor_out_of_scope', ['vendor' => $vendor !== '' ? $vendor : '-']);
            }
        }

        return $verification;
    }

    /**
     * 이 서버에서 서명을 확인할 수 있는지(sodium 확장) 확인합니다.
     *
     * @return bool sodium 서명 검증 함수가 있으면 true
     */
    public function sodiumAvailable(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * key_id 의 신뢰 여부를 판정합니다.
     *
     * @param  string  $keyId  서명 파일의 key_id
     * @param  string  $type  패키지 종류
     * @param  string  $identifier  패키지 식별자
     * @return array{status: PackageVerificationStatus|null, reason: string|null, source: string|null, label: string|null, raw_public_key?: string, params?: array<string, string>} 신뢰하면 status=null
     */
    private function resolveTrust(string $keyId, string $type, string $identifier): array
    {
        $builtin = BuiltinTrustKeys::find($keyId);
        if ($builtin !== null) {
            $raw = PackageSignatureFormat::decodePublicKey((string) ($builtin['public_key'] ?? ''));
            $label = (string) ($builtin['label'] ?? '');
            $status = (string) ($builtin['status'] ?? '');

            if ($raw === null || PackageSignatureFormat::keyId($label, $raw) !== $keyId) {
                return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'key_id_mismatch', 'source' => PackageVerification::KEY_SOURCE_BUILTIN, 'label' => $label];
            }
            if (! (TrustKeyStatus::tryFrom($status)?->acceptsSignatures() ?? false)) {
                return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'key_revoked', 'source' => PackageVerification::KEY_SOURCE_BUILTIN, 'label' => $label];
            }

            return ['status' => null, 'reason' => null, 'source' => PackageVerification::KEY_SOURCE_BUILTIN, 'label' => $label, 'raw_public_key' => $raw];
        }

        // 코어는 내장 키로만 — 제작자 키로 서명한 코어 패키지는 미신뢰
        if ($type === PackageExpectation::TYPE_CORE) {
            return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'core_requires_builtin_key', 'source' => null, 'label' => null];
        }

        $key = $this->trustKeys->findByKeyId($keyId);
        if ($key === null) {
            return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'key_unknown', 'source' => null, 'label' => null, 'params' => ['key' => $keyId]];
        }

        $raw = PackageSignatureFormat::decodePublicKey($key->public_key);
        if ($raw === null || PackageSignatureFormat::keyId($key->label, $raw) !== $keyId) {
            return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'key_id_mismatch', 'source' => PackageVerification::KEY_SOURCE_VENDOR, 'label' => $key->label];
        }

        // 확장 vendor 는 식별자 앞부분 — 언어팩은 manifest 칸이라 해제 뒤 verifyManifest 가 본다
        if ($type !== PackageExpectation::TYPE_LANGUAGE_PACK) {
            $vendor = self::vendorOfIdentifier($identifier);
            if ($vendor === '' || ! $key->coversVendor($vendor)) {
                return ['status' => PackageVerificationStatus::Untrusted, 'reason' => 'vendor_out_of_scope', 'source' => PackageVerification::KEY_SOURCE_VENDOR, 'label' => $key->label, 'params' => ['vendor' => $vendor !== '' ? $vendor : '-']];
            }
        }

        return ['status' => null, 'reason' => null, 'source' => PackageVerification::KEY_SOURCE_VENDOR, 'label' => $key->label, 'raw_public_key' => $raw];
    }

    /**
     * 확장 식별자(`{vendor}-{name}`)에서 vendor 를 뗍니다.
     *
     * @param  string  $identifier  확장 식별자
     * @return string vendor (구분자가 없으면 빈 문자열)
     */
    public static function vendorOfIdentifier(string $identifier): string
    {
        $pos = strpos($identifier, '-');

        return $pos === false || $pos === 0 ? '' : strtolower(substr($identifier, 0, $pos));
    }
}
