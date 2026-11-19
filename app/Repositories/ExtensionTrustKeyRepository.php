<?php

namespace App\Repositories;

use App\Contracts\Repositories\ExtensionTrustKeyRepositoryInterface;
use App\Extension\Integrity\BuiltinTrustKeys;
use App\Models\ExtensionTrustKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * 확장 신뢰 키(제작자 공개키) Repository
 *
 * 표가 아직 없는 사이트(7.2.0 마이그레이션 전 — 코어 업데이트 도중 옛 판으로 검증이 돌 때)에서는 빈 목록으로 답한다.
 * 그 경우 제작자 키 서명은 「미신뢰 키」가 되고 미서명 정책이 아닌 차단으로 끝난다(신뢰 목록을 넓히지 않는 쪽으로 실패).
 *
 * @since 7.2.0
 */
class ExtensionTrustKeyRepository implements ExtensionTrustKeyRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        if (! $this->tableExists()) {
            return collect();
        }

        return ExtensionTrustKey::query()->orderBy('id')->get()->toBase();
    }

    /**
     * {@inheritDoc}
     */
    public function builtin(): array
    {
        return BuiltinTrustKeys::all();
    }

    /**
     * {@inheritDoc}
     */
    public function findByKeyId(string $keyId): ?ExtensionTrustKey
    {
        if (! $this->tableExists()) {
            return null;
        }

        return ExtensionTrustKey::query()->where('key_id', $keyId)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ExtensionTrustKey
    {
        if (! $this->tableExists()) {
            return null;
        }

        return ExtensionTrustKey::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $attributes): ExtensionTrustKey
    {
        return ExtensionTrustKey::query()->create($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ExtensionTrustKey $key): bool
    {
        return (bool) $key->delete();
    }

    /**
     * 신뢰 키 표가 있는지 확인합니다.
     *
     * @return bool 표가 있으면 true
     */
    private function tableExists(): bool
    {
        return Schema::hasTable('extension_trust_keys');
    }
}
