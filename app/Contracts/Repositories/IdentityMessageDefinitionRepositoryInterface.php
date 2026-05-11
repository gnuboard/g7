<?php

namespace App\Contracts\Repositories;

use App\Models\IdentityMessageDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface IdentityMessageDefinitionRepositoryInterface
{
    /**
     * ID로 메시지 정의 조회.
     *
     * @param  int  $id
     * @return IdentityMessageDefinition|null
     */
    public function findById(int $id): ?IdentityMessageDefinition;

    /**
     * (provider, scope_type, scope_value) 조합으로 메시지 정의 조회.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return IdentityMessageDefinition|null
     */
    public function findByScope(string $providerId, string $scopeType, ?string $scopeValue = null): ?IdentityMessageDefinition;

    /**
     * 활성 상태인 (provider, scope_type, scope_value) 메시지 정의 조회.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return IdentityMessageDefinition|null
     */
    public function getActiveByScope(string $providerId, string $scopeType, ?string $scopeValue = null): ?IdentityMessageDefinition;

    /**
     * 모든 활성 메시지 정의 조회.
     *
     * @return Collection
     */
    public function getAllActive(): Collection;

    /**
     * 활성 메시지 정의의 로케일별 라벨 맵 (N+1 회피용).
     *
     * 키: "{provider_id}|{scope_type}|{scope_value}", 값: 다국어 라벨
     *
     * @param  string|null  $locale
     * @return array<string, string>
     */
    public function getLabelMap(?string $locale = null): array;

    /**
     * 전체 메시지 정의 조회.
     *
     * @return Collection
     */
    public function getAll(): Collection;

    /**
     * 특정 확장의 메시지 정의 목록 조회.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection;

    /**
     * 메시지 정의 신규 생성.
     *
     * @param  array  $data
     * @return IdentityMessageDefinition
     */
    public function store(array $data): IdentityMessageDefinition;

    /**
     * 메시지 정의 수정.
     *
     * @param  IdentityMessageDefinition  $definition
     * @param  array  $data
     * @return IdentityMessageDefinition
     */
    public function update(IdentityMessageDefinition $definition, array $data): IdentityMessageDefinition;

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;
}
