<?php

namespace App\Extension\Integrity;

/**
 * 패키지 하나의 무결성 검증 결과.
 *
 * `reason` 은 `extension_integrity.reasons.*` 다국어 키의 끝 이름이다(화면·명령이 같은 문장을 낸다).
 *
 * @since 7.2.0
 */
final class PackageVerification
{
    /** 내장 키(코어에 박힌 sirsoft 공개키)로 확인 */
    public const KEY_SOURCE_BUILTIN = 'builtin';

    /** 운영자가 등록한 제작자 키로 확인 */
    public const KEY_SOURCE_VENDOR = 'vendor';

    /**
     * 검증 결과를 만듭니다.
     *
     * @param  PackageVerificationStatus  $status  검증 상태
     * @param  string|null  $reason  사유 키 (`extension_integrity.reasons.*` 의 끝 이름, 정상이면 null)
     * @param  array{type?: string, identifier?: string, version?: string}|null  $package  서명 파일이 말하는 패키지 (서명이 없으면 null)
     * @param  string|null  $keyId  서명 키 식별자
     * @param  string|null  $signerLabel  서명자 이름
     * @param  string|null  $keySource  키 출처 (builtin|vendor, 키를 찾지 못했으면 null)
     * @param  array<string, string>  $reasonParams  사유 문장 치환 값
     */
    public function __construct(
        public readonly PackageVerificationStatus $status,
        public readonly ?string $reason = null,
        public readonly ?array $package = null,
        public readonly ?string $keyId = null,
        public readonly ?string $signerLabel = null,
        public readonly ?string $keySource = null,
        public readonly array $reasonParams = [],
    ) {}

    /**
     * 서명 파일이 없는 결과를 만듭니다.
     *
     * @return self 미서명 결과
     */
    public static function unsigned(): self
    {
        return new self(PackageVerificationStatus::Unsigned, 'unsigned');
    }

    /**
     * 번들 원본 설치 결과를 만듭니다.
     *
     * @return self 번들 결과
     */
    public static function bundled(): self
    {
        return new self(PackageVerificationStatus::Bundled);
    }

    /**
     * 같은 서명 정보에 상태·사유만 바꾼 결과를 만듭니다.
     *
     * @param  PackageVerificationStatus  $status  새 상태
     * @param  string|null  $reason  새 사유 키
     * @param  array<string, string>  $reasonParams  사유 치환 값
     * @return self 새 결과
     */
    public function withStatus(PackageVerificationStatus $status, ?string $reason, array $reasonParams = []): self
    {
        return new self($status, $reason, $this->package, $this->keyId, $this->signerLabel, $this->keySource, $reasonParams);
    }

    /**
     * 사유 문장을 현재 로케일로 돌려줍니다.
     *
     * @return string|null 사유 문장 (사유가 없으면 null)
     */
    public function reasonMessage(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return (string) __('extension_integrity.reasons.'.$this->reason, $this->reasonParams);
    }

    /**
     * 설치 행에 적을 칸을 돌려줍니다.
     *
     * @return array{integrity_status: string, integrity_key_id: string|null} 설치 행 칸
     */
    public function toInstallColumns(): array
    {
        return [
            'integrity_status' => $this->status->value,
            'integrity_key_id' => $this->status === PackageVerificationStatus::Verified ? $this->keyId : null,
        ];
    }

    /**
     * `toArray()` 모양에서 되살립니다 (웹 코어 업데이트처럼 단계가 요청을 건너 이어질 때).
     *
     * @param  array<string, mixed>  $data  `toArray()` 결과
     * @return self 검증 결과 (상태 값을 모르면 미서명)
     */
    public static function fromArray(array $data): self
    {
        $status = PackageVerificationStatus::tryFrom((string) ($data['status'] ?? '')) ?? PackageVerificationStatus::Unsigned;

        return new self(
            $status,
            is_string($data['reason'] ?? null) ? $data['reason'] : null,
            is_array($data['package'] ?? null) ? $data['package'] : null,
            is_string($data['key_id'] ?? null) ? $data['key_id'] : null,
            is_string($data['signer_label'] ?? null) ? $data['signer_label'] : null,
            is_string($data['key_source'] ?? null) ? $data['key_source'] : null,
        );
    }

    /**
     * 응답에 싣는 모양으로 돌려줍니다.
     *
     * @return array{status: string, reason: string|null, message: string|null, key_id: string|null, signer_label: string|null, key_source: string|null, package: array<string, string>|null} 응답 모양
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'message' => $this->reasonMessage(),
            'key_id' => $this->keyId,
            'signer_label' => $this->signerLabel,
            'key_source' => $this->keySource,
            'package' => $this->package,
        ];
    }
}
