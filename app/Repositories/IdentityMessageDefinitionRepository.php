<?php

namespace App\Repositories;

use App\Contracts\Repositories\IdentityMessageDefinitionRepositoryInterface;
use App\Models\IdentityMessageDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class IdentityMessageDefinitionRepository implements IdentityMessageDefinitionRepositoryInterface
{
    /**
     * ID로 메시지 정의 조회.
     *
     * @param  int  $id
     * @return IdentityMessageDefinition|null
     */
    public function findById(int $id): ?IdentityMessageDefinition
    {
        return IdentityMessageDefinition::find($id);
    }

    /**
     * (provider, scope_type, scope_value) 조합으로 메시지 정의 조회.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return IdentityMessageDefinition|null
     */
    public function findByScope(string $providerId, string $scopeType, ?string $scopeValue = null): ?IdentityMessageDefinition
    {
        return IdentityMessageDefinition::byProvider($providerId)
            ->byScope($scopeType, $scopeValue)
            ->first();
    }

    /**
     * 활성 상태인 (provider, scope_type, scope_value) 메시지 정의 조회.
     *
     * @param  string  $providerId
     * @param  string  $scopeType
     * @param  string|null  $scopeValue
     * @return IdentityMessageDefinition|null
     */
    public function getActiveByScope(string $providerId, string $scopeType, ?string $scopeValue = null): ?IdentityMessageDefinition
    {
        return IdentityMessageDefinition::active()
            ->byProvider($providerId)
            ->byScope($scopeType, $scopeValue)
            ->first();
    }

    /**
     * 모든 활성 메시지 정의 조회.
     *
     * @return Collection
     */
    public function getAllActive(): Collection
    {
        return IdentityMessageDefinition::active()->get();
    }

    /**
     * 활성 메시지 정의의 로케일별 라벨 맵.
     *
     * 키: "{provider_id}|{scope_type}|{scope_value}", 값: 다국어 라벨
     *
     * @param  string|null  $locale
     * @return array<string, string>
     */
    public function getLabelMap(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();

        return IdentityMessageDefinition::active()
            ->get(['id', 'provider_id', 'scope_type', 'scope_value', 'name'])
            ->mapWithKeys(fn (IdentityMessageDefinition $def) => [
                "{$def->provider_id}|{$def->scope_type->value}|{$def->scope_value}" => $def->getLocalizedName($locale),
            ])
            ->all();
    }

    /**
     * 전체 메시지 정의 조회.
     *
     * @return Collection
     */
    public function getAll(): Collection
    {
        return IdentityMessageDefinition::all();
    }

    /**
     * 특정 확장의 메시지 정의 목록 조회.
     *
     * @param  string  $extensionType
     * @param  string  $extensionIdentifier
     * @return Collection
     */
    public function getByExtension(string $extensionType, string $extensionIdentifier): Collection
    {
        return IdentityMessageDefinition::byExtension($extensionType, $extensionIdentifier)->get();
    }

    /**
     * 메시지 정의 신규 생성.
     *
     * @param  array  $data
     * @return IdentityMessageDefinition
     */
    public function store(array $data): IdentityMessageDefinition
    {
        return IdentityMessageDefinition::create($data)->fresh();
    }

    /**
     * 메시지 정의 수정.
     *
     * @param  IdentityMessageDefinition  $definition
     * @param  array  $data
     * @return IdentityMessageDefinition
     */
    public function update(IdentityMessageDefinition $definition, array $data): IdentityMessageDefinition
    {
        $definition->update($data);

        return $definition->fresh();
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array  $filters
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = IdentityMessageDefinition::with('templates');

        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        if (! empty($filters['scope_type'])) {
            $query->where('scope_type', $filters['scope_type']);
        }

        if (! empty($filters['scope_value'])) {
            $query->where('scope_value', $filters['scope_value']);
        }

        if (! empty($filters['extension_type'])) {
            $query->where('extension_type', $filters['extension_type']);
        }

        if (! empty($filters['extension_identifier'])) {
            $query->where('extension_identifier', $filters['extension_identifier']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['channel'])) {
            $query->whereJsonContains('channels', $filters['channel']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $locales = config('app.supported_locales', ['ko', 'en']);

            $query->where(function ($q) use ($search, $locales) {
                $q->where('provider_id', 'like', "%{$search}%")
                    ->orWhere('scope_value', 'like', "%{$search}%");
                foreach ($locales as $locale) {
                    $q->orWhere("name->{$locale}", 'like', "%{$search}%");
                }
            });
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }
}
