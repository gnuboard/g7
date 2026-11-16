<?php

namespace App\Services;

use App\Contracts\Repositories\ExtensionTrustKeyRepositoryInterface;
use App\Extension\HookManager;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\PackageSignatureVerifier;
use App\Extension\Integrity\UnsignedPackagePolicy;
use App\Models\ExtensionTrustKey;
use App\Models\User;
use App\Services\Auth\ReauthenticationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 확장 신뢰 키(제작자 공개키) 관리 서비스.
 *
 * 관리자 화면(환경설정 > 보안 「확장 신뢰 키」)과 `extension:trust-key` 명령이 함께 쓴다. key_id 는 입력받지 않고
 * label·공개키로 계산한다. 내장 키는 코드 상수라 여기서 바꾸지 않고 읽기 전용으로 보여 준다.
 *
 * @since 7.2.0
 */
class ExtensionTrustKeyService
{
    /**
     * 서비스를 만듭니다.
     *
     * @param  ExtensionTrustKeyRepositoryInterface  $repository  제작자 키 저장소
     * @param  ReauthenticationService  $reauthentication  민감 작업 재확인
     * @param  PackageSignatureVerifier  $verifier  서명 검증기 (sodium 가용 여부)
     * @param  UnsignedPackagePolicy  $policy  미서명 정책 (차단 스위치 상태)
     */
    public function __construct(
        private readonly ExtensionTrustKeyRepositoryInterface $repository,
        private readonly ReauthenticationService $reauthentication,
        private readonly PackageSignatureVerifier $verifier,
        private readonly UnsignedPackagePolicy $policy,
    ) {}

    /**
     * 화면에 보일 신뢰 키 현황을 돌려줍니다.
     *
     * @return array{builtin: array<int, array{key_id: string, label: string, status: string, public_key: string}>, keys: Collection<int, ExtensionTrustKey>, block_unsigned: bool, sodium_available: bool} 현황
     */
    public function overview(): array
    {
        return [
            'builtin' => array_map(fn (array $key) => [
                'key_id' => (string) ($key['key_id'] ?? ''),
                'label' => (string) ($key['label'] ?? ''),
                'status' => (string) ($key['status'] ?? ''),
                'public_key' => (string) ($key['public_key'] ?? ''),
            ], $this->repository->builtin()),
            'keys' => $this->repository->all(),
            'block_unsigned' => $this->policy->blocksUnsigned(),
            'sodium_available' => $this->verifier->sodiumAvailable(),
        ];
    }

    /**
     * 등록된 제작자 키 목록을 돌려줍니다.
     *
     * @return Collection<int, ExtensionTrustKey> 키 목록
     */
    public function all(): Collection
    {
        return $this->repository->all();
    }

    /**
     * ID 로 제작자 키를 찾습니다.
     *
     * @param  int  $id  키 ID
     * @return ExtensionTrustKey|null 키
     */
    public function find(int $id): ?ExtensionTrustKey
    {
        return $this->repository->findById($id);
    }

    /**
     * key_id 로 제작자 키를 찾습니다.
     *
     * @param  string  $keyId  key_id
     * @return ExtensionTrustKey|null 키
     */
    public function findByKeyId(string $keyId): ?ExtensionTrustKey
    {
        return $this->repository->findByKeyId($keyId);
    }

    /**
     * 입력 공개키·이름으로 계산될 key_id 를 돌려줍니다.
     *
     * @param  string  $label  서명자 이름
     * @param  string  $publicKey  base64 공개키
     * @return string|null key_id (공개키 형식이 틀리면 null)
     */
    public function computeKeyId(string $label, string $publicKey): ?string
    {
        $raw = PackageSignatureFormat::decodePublicKey($publicKey);

        return $raw === null ? null : PackageSignatureFormat::keyId($label, $raw);
    }

    /**
     * 새 키가 이미 등록됐거나 내장 키와 겹치는지 검사해 오류 문장을 돌려줍니다.
     *
     * @param  string  $label  서명자 이름
     * @param  string  $publicKey  base64 공개키
     * @return string|null 오류 문장 (문제없으면 null)
     */
    public function conflictFor(string $label, string $publicKey): ?string
    {
        $keyId = $this->computeKeyId($label, $publicKey);
        if ($keyId === null) {
            return null;
        }

        $normalized = base64_encode((string) PackageSignatureFormat::decodePublicKey($publicKey));
        foreach ($this->repository->builtin() as $builtin) {
            if (($builtin['key_id'] ?? null) === $keyId || ($builtin['public_key'] ?? null) === $normalized) {
                return (string) __('extension_integrity.validation.builtin_conflict');
            }
        }

        if ($this->repository->findByKeyId($keyId) !== null) {
            return (string) __('extension_integrity.validation.duplicate_key');
        }

        return null;
    }

    /**
     * 제작자 키를 등록합니다.
     *
     * @param  array{public_key: string, label: string, vendor_scope: array<int, string>}  $data  검증된 입력
     * @param  int|null  $createdBy  등록한 관리자 ID (명령이면 null)
     * @return ExtensionTrustKey 등록한 키
     *
     * @throws ValidationException 이미 등록됐거나 내장 키와 겹칠 때
     */
    public function create(array $data, ?int $createdBy = null): ExtensionTrustKey
    {
        $conflict = $this->conflictFor($data['label'], $data['public_key']);
        if ($conflict !== null) {
            throw ValidationException::withMessages(['public_key' => [$conflict]]);
        }

        // 훅 — before_create → 저장 → after_create. 입력을 바꾸는 필터 훅은 두지 않는다: 확장이 공개키·vendor 범위를
        // 바꿀 수 있으면 신뢰 목록을 넓히는 길이 된다(신뢰 키 추가·삭제는 관리자 재확인을 거친 이 서비스만 한다).
        HookManager::doAction('core.extension_trust_keys.before_create', $data);

        $raw = (string) PackageSignatureFormat::decodePublicKey($data['public_key']);
        $vendors = array_values(array_unique(array_map(fn ($v) => strtolower(trim((string) $v)), $data['vendor_scope'])));

        $key = $this->repository->create([
            'key_id' => PackageSignatureFormat::keyId($data['label'], $raw),
            'public_key' => base64_encode($raw),
            'label' => $data['label'],
            'vendor_scope' => $vendors,
            'created_by' => $createdBy,
        ]);

        HookManager::doAction('core.extension_trust_keys.after_create', $key);

        return $key;
    }

    /**
     * 제작자 키를 지웁니다.
     *
     * @param  ExtensionTrustKey  $key  지울 키
     * @return bool 지웠으면 true
     */
    public function delete(ExtensionTrustKey $key): bool
    {
        HookManager::doAction('core.extension_trust_keys.before_delete', $key);

        $deleted = $this->repository->delete($key);

        if ($deleted) {
            HookManager::doAction('core.extension_trust_keys.after_delete', $key);
        }

        return $deleted;
    }

    /**
     * 요청 관리자 본인을 다시 확인합니다 (비밀번호 또는 인증 토큰).
     *
     * @param  User  $admin  요청 관리자
     * @param  array<string, mixed>  $credentials  `password` 또는 `verification_token`
     * @return string ReauthenticationService 결과 상수
     */
    public function reauthenticate(User $admin, array $credentials): string
    {
        return $this->reauthentication->verify($admin, $credentials);
    }
}
