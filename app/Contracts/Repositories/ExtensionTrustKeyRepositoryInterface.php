<?php

namespace App\Contracts\Repositories;

use App\Models\ExtensionTrustKey;
use Illuminate\Support\Collection;

/**
 * 확장 신뢰 키(제작자 공개키) Repository 인터페이스
 *
 * @since 7.2.0
 */
interface ExtensionTrustKeyRepositoryInterface
{
    /**
     * 등록된 제작자 키를 등록 순으로 돌려줍니다.
     *
     * @return Collection<int, ExtensionTrustKey> 키 목록
     */
    public function all(): Collection;

    /**
     * 코어 내장 신뢰 키(코드 상수 — 읽기 전용)를 돌려줍니다.
     *
     * @return array<int, array{key_id: string, public_key: string, label: string, status: string}> 내장 키
     */
    public function builtin(): array;

    /**
     * key_id 로 키를 찾습니다.
     *
     * @param  string  $keyId  key_id
     * @return ExtensionTrustKey|null 키 (없으면 null)
     */
    public function findByKeyId(string $keyId): ?ExtensionTrustKey;

    /**
     * ID 로 키를 찾습니다.
     *
     * @param  int  $id  키 ID
     * @return ExtensionTrustKey|null 키 (없으면 null)
     */
    public function findById(int $id): ?ExtensionTrustKey;

    /**
     * 키를 등록합니다.
     *
     * @param  array{key_id: string, public_key: string, label: string, vendor_scope: array<int, string>, created_by: int|null}  $attributes  키 속성
     * @return ExtensionTrustKey 등록한 키
     */
    public function create(array $attributes): ExtensionTrustKey;

    /**
     * 키를 지웁니다.
     *
     * @param  ExtensionTrustKey  $key  지울 키
     * @return bool 지웠으면 true
     */
    public function delete(ExtensionTrustKey $key): bool;
}
