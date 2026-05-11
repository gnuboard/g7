<?php

namespace App\Repositories;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 언어팩 Repository 구현체.
 */
class LanguagePackRepository implements LanguagePackRepositoryInterface
{
    use HasMultipleSearchFilters;

    /**
     * 식별자로 언어팩을 조회합니다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findByIdentifier(string $identifier): ?LanguagePack
    {
        return LanguagePack::query()->where('identifier', $identifier)->first();
    }

    /**
     * ID 로 언어팩을 조회합니다.
     *
     * @param  int  $id  언어팩 ID
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findById(int $id): ?LanguagePack
    {
        return LanguagePack::query()->find($id);
    }

    /**
     * 모든 활성 언어팩을 조회합니다.
     *
     * @return Collection<int, LanguagePack> 활성 언어팩 컬렉션
     */
    public function getActivePacks(): Collection
    {
        return LanguagePack::query()
            ->where('status', LanguagePackStatus::Active->value)
            ->get();
    }

    /**
     * 특정 슬롯의 활성 언어팩을 조회합니다.
     *
     * @param  string  $scope  스코프
     * @param  string|null  $targetIdentifier  대상 확장 식별자
     * @param  string  $locale  로케일
     * @param  int|null  $excludeId  결과에서 제외할 언어팩 id (재설치 시 자기 자신 제외용)
     * @return LanguagePack|null 활성 언어팩 또는 null
     */
    public function findActiveForSlot(
        string $scope,
        ?string $targetIdentifier,
        string $locale,
        ?int $excludeId = null
    ): ?LanguagePack {
        $query = LanguagePack::query()
            ->where('scope', $scope)
            ->where('target_identifier', $targetIdentifier)
            ->where('locale', $locale)
            ->where('status', LanguagePackStatus::Active->value);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * 특정 슬롯의 모든 후보 언어팩을 조회합니다 (벤더별).
     *
     * @param  string  $scope  스코프
     * @param  string|null  $targetIdentifier  대상 확장 식별자
     * @param  string  $locale  로케일
     * @return Collection<int, LanguagePack> 후보 언어팩 컬렉션
     */
    public function getPacksForSlot(
        string $scope,
        ?string $targetIdentifier,
        string $locale
    ): Collection {
        return LanguagePack::query()
            ->where('scope', $scope)
            ->where('target_identifier', $targetIdentifier)
            ->where('locale', $locale)
            ->orderByDesc('activated_at')
            ->orderByDesc('installed_at')
            ->get();
    }

    /**
     * 활성 코어 언어팩이 있는 모든 로케일을 반환합니다.
     *
     * @return array<int, string> 로케일 문자열 배열
     */
    public function getActiveCoreLocales(): array
    {
        return LanguagePack::query()
            ->where('scope', LanguagePackScope::Core->value)
            ->where('status', LanguagePackStatus::Active->value)
            ->pluck('locale')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 페이지네이션 + 필터링된 언어팩 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope, target_identifier, locale, status, vendor)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->buildFilteredQuery($filters)->paginate($perPage);
    }

    /**
     * 필터링된 언어팩 컬렉션을 페이지네이션 없이 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope, target_identifier, locale, status, vendor, search)
     * @return Collection<int, LanguagePack> 필터링된 언어팩 컬렉션
     */
    public function getFilteredCollection(array $filters = []): Collection
    {
        return $this->buildFilteredQuery($filters)->get();
    }

    /**
     * 공통 필터 쿼리 빌더 — `paginate()` 와 `getFilteredCollection()` 가 동일 로직을 공유합니다.
     *
     * `status=uninstalled` 필터는 가상 상태이므로 DB 쿼리에서 결과를 0건으로 강제합니다
     * (실제 미설치 번들은 Service 계층에서 합쳐집니다).
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @return \Illuminate\Database\Eloquent\Builder<LanguagePack> 정렬까지 적용된 쿼리 빌더
     */
    private function buildFilteredQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = LanguagePack::query();

        if (! empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }
        if (array_key_exists('target_identifier', $filters)) {
            $query->where('target_identifier', $filters['target_identifier']);
        }
        if (! empty($filters['locale'])) {
            $query->where('locale', $filters['locale']);
        }
        if (! empty($filters['status'])) {
            if ($filters['status'] === LanguagePackStatus::Uninstalled->value) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (! empty($filters['vendor'])) {
            $query->where('vendor', $filters['vendor']);
        }
        if (! empty($filters['search'])) {
            $this->applyOrSearchAcrossFields(
                $query,
                (string) $filters['search'],
                ['identifier', 'vendor', 'locale_native_name', 'locale_name']
            );
        }
        if (! empty($filters['exclude_protected'])) {
            $query->where('is_protected', false);
        }

        // 정렬은 안정 키만 사용 (scope/target_identifier/locale).
        // status 우선 정렬은 설치/활성화 직후 항목이 페이지 위치를 이탈해
        // 사용자가 다시 찾아야 하는 회귀를 만들어 제거함 (#263 의 active-first UX 폐기).
        return $query
            ->orderBy('scope')
            ->orderBy('target_identifier')
            ->orderBy('locale');
    }

    /**
     * 언어팩을 생성합니다.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return LanguagePack 생성된 언어팩
     */
    public function create(array $data): LanguagePack
    {
        return LanguagePack::query()->create($data);
    }

    /**
     * 언어팩을 갱신합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return LanguagePack 갱신된 언어팩
     */
    public function update(LanguagePack $pack, array $data): LanguagePack
    {
        $pack->fill($data);
        $pack->save();

        return $pack->fresh() ?? $pack;
    }

    /**
     * 언어팩을 삭제합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return bool 삭제 성공 여부
     */
    public function delete(LanguagePack $pack): bool
    {
        return (bool) $pack->delete();
    }

    /**
     * 특정 확장(scope, target_identifier)에 연결된 언어팩 전체를 조회합니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return Collection<int, LanguagePack> 언어팩 컬렉션
     */
    public function getPacksForTarget(string $scope, string $targetIdentifier): Collection
    {
        return LanguagePack::query()
            ->where('scope', $scope)
            ->where('target_identifier', $targetIdentifier)
            ->get();
    }

    /**
     * 특정 로케일에 속하는 모든 언어팩을 조회합니다.
     *
     * @param  string  $locale  로케일
     * @return Collection<int, LanguagePack> 언어팩 컬렉션
     */
    public function getPacksForLocale(string $locale): Collection
    {
        return LanguagePack::query()->where('locale', $locale)->get();
    }

    /**
     * 번들 manifest 로부터 가상 LanguagePack 인스턴스를 합성합니다 (DB 미저장).
     *
     * Model 인스턴스 생성 책임을 Repository 가 보유 — Service 는 본 메서드를 호출만 합니다.
     * exists=false 로 표시되어 영속성 동작에서 제외되며, `bundled_identifier` 가상 속성을
     * 함께 채워 Resource 가 행 액션에 노출할 수 있게 합니다.
     *
     * @param  array<string, mixed>  $manifest  번들 manifest 데이터
     * @param  string  $bundledIdentifier  `lang-packs/_bundled/{이 값}` 디렉토리명
     * @return LanguagePack 가상 LanguagePack 인스턴스 (DB 미저장)
     */
    public function buildVirtualFromManifest(array $manifest, string $bundledIdentifier): LanguagePack
    {
        $pack = new LanguagePack;
        $pack->id = null;
        $pack->identifier = (string) $manifest['identifier'];
        $pack->vendor = (string) ($manifest['vendor'] ?? '');
        $pack->scope = (string) ($manifest['scope'] ?? 'core');
        $pack->target_identifier = $manifest['target_identifier'] ?? null;
        $pack->locale = (string) ($manifest['locale'] ?? '');
        $pack->locale_name = (string) ($manifest['locale_name'] ?? ($manifest['locale'] ?? ''));
        $pack->locale_native_name = (string) ($manifest['locale_native_name'] ?? ($manifest['locale'] ?? ''));
        $pack->text_direction = (string) ($manifest['text_direction'] ?? 'ltr');
        $pack->version = (string) ($manifest['version'] ?? '0.0.0');
        $pack->license = $manifest['license'] ?? null;
        $pack->description = is_array($manifest['description'] ?? null) ? $manifest['description'] : null;
        $pack->status = \App\Enums\LanguagePackStatus::Uninstalled->value;
        $pack->is_protected = false; // lang-packs/_bundled/ 패키지는 사용자가 install/uninstall 자유 (PO #3)
        $pack->manifest = $manifest;
        $pack->source_type = \App\Enums\LanguagePackSourceType::Bundled->value;
        $pack->source_url = $bundledIdentifier;

        $pack->setAttribute('bundled_identifier', $bundledIdentifier);
        $pack->exists = false;

        return $pack;
    }

    /**
     * 코어/번들 확장의 lang/{ko,en}/ 디렉토리로부터 가상 보호 LanguagePack 인스턴스를 합성합니다.
     *
     * `built_in` 가상 행은 DB 행이 없는 상태로 항상 active+protected 표시되며, 사용자가
     * install/uninstall/activate/deactivate 할 수 없습니다 (PO #1, #2).
     *
     * @param  string  $scope  스코프 (core/module/plugin/template)
     * @param  string|null  $targetIdentifier  대상 확장 식별자 (core 일 때 null)
     * @param  string  $locale  로케일 (예: 'ko', 'en')
     * @param  string  $vendor  벤더 (확장 manifest.vendor 또는 'g7')
     * @param  string  $version  버전
     * @param  string  $langPathRelative  lang 디렉토리 상대 경로
     * @return LanguagePack 가상 LanguagePack 인스턴스
     */
    public function buildVirtualBuiltInPack(
        string $scope,
        ?string $targetIdentifier,
        string $locale,
        string $vendor,
        string $version,
        string $langPathRelative,
    ): LanguagePack {
        $identifier = $targetIdentifier !== null
            ? sprintf('%s-%s-%s-%s', $vendor, $scope, $targetIdentifier, $locale)
            : sprintf('%s-%s-%s', $vendor, $scope, $locale);

        $nativeName = match ($locale) {
            'ko' => '한국어',
            'en' => 'English',
            default => strtoupper($locale),
        };

        $pack = new LanguagePack;
        $pack->id = null;
        $pack->identifier = $identifier;
        $pack->vendor = $vendor;
        $pack->scope = $scope;
        $pack->target_identifier = $targetIdentifier;
        $pack->locale = $locale;
        $pack->locale_name = strtoupper($locale);
        $pack->locale_native_name = $nativeName;
        $pack->text_direction = \App\Enums\TextDirection::Ltr->value;
        $pack->version = $version;
        $pack->status = \App\Enums\LanguagePackStatus::Active->value;
        $pack->is_protected = true;
        $pack->source_type = \App\Enums\LanguagePackSourceType::BuiltIn->value;
        $pack->source_url = $langPathRelative;
        $pack->manifest = [
            'identifier' => $identifier,
            'vendor' => $vendor,
            'scope' => $scope,
            'target_identifier' => $targetIdentifier,
            'locale' => $locale,
            'version' => $version,
            'built_in' => true,
        ];

        $pack->exists = false;

        return $pack;
    }

    /**
     * 호스트 확장(modules/plugins/templates)의 status + version 행을 조회합니다.
     *
     * @param  string  $scope  스코프 (module/plugin/template). 그 외는 null 반환.
     * @param  string  $identifier  호스트 확장 식별자
     * @return object|null `{status, version}` 객체 또는 null
     */
    public function findHostExtensionRow(string $scope, string $identifier): ?object
    {
        $tableMap = [
            LanguagePackScope::Module->value => 'modules',
            LanguagePackScope::Plugin->value => 'plugins',
            LanguagePackScope::Template->value => 'templates',
        ];
        $table = $tableMap[$scope] ?? null;
        if (! $table) {
            return null;
        }

        return DB::table($table)
            ->where('identifier', $identifier)
            ->first(['status', 'version']);
    }
}
