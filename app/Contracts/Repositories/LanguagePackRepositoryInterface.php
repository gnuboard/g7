<?php

namespace App\Contracts\Repositories;

use App\Models\LanguagePack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 언어팩 Repository 인터페이스.
 */
interface LanguagePackRepositoryInterface
{
    /**
     * 식별자로 언어팩을 조회합니다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findByIdentifier(string $identifier): ?LanguagePack;

    /**
     * ID 로 언어팩을 조회합니다.
     *
     * @param  int  $id  언어팩 ID
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findById(int $id): ?LanguagePack;

    /**
     * 모든 활성 언어팩을 조회합니다.
     *
     * @return Collection<int, LanguagePack> 활성 언어팩 컬렉션
     */
    public function getActivePacks(): Collection;

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
    ): ?LanguagePack;

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
    ): Collection;

    /**
     * 활성 코어 언어팩이 있는 모든 로케일을 반환합니다.
     *
     * @return array<int, string> 로케일 문자열 배열
     */
    public function getActiveCoreLocales(): array;

    /**
     * 페이지네이션 + 필터링된 언어팩 목록을 조회합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope, target_identifier, locale, status, vendor)
     * @param  int  $perPage  페이지당 건수
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 필터링된 언어팩 컬렉션을 페이지네이션 없이 조회합니다.
     *
     * 미설치 번들 가상 레코드(`lang-packs/_bundled/{identifier}`)와 병합한 뒤
     * Service 계층에서 수동 페이지네이션을 수행하기 위해 사용됩니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope, target_identifier, locale, status, vendor, search)
     * @return Collection<int, LanguagePack> 필터링된 언어팩 컬렉션
     */
    public function getFilteredCollection(array $filters = []): Collection;

    /**
     * 언어팩을 생성합니다.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return LanguagePack 생성된 언어팩
     */
    public function create(array $data): LanguagePack;

    /**
     * 언어팩을 갱신합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  array<string, mixed>  $data  갱신 데이터
     * @return LanguagePack 갱신된 언어팩
     */
    public function update(LanguagePack $pack, array $data): LanguagePack;

    /**
     * 언어팩을 삭제합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return bool 삭제 성공 여부
     */
    public function delete(LanguagePack $pack): bool;

    /**
     * 특정 확장(scope, target_identifier)에 연결된 언어팩 전체를 조회합니다.
     *
     * @param  string  $scope  스코프
     * @param  string  $targetIdentifier  대상 확장 식별자
     * @return Collection<int, LanguagePack> 언어팩 컬렉션
     */
    public function getPacksForTarget(string $scope, string $targetIdentifier): Collection;

    /**
     * 특정 로케일에 속하는 모든 언어팩을 조회합니다 (cascade 삭제 시 사용).
     *
     * @param  string  $locale  로케일
     * @return Collection<int, LanguagePack> 언어팩 컬렉션
     */
    public function getPacksForLocale(string $locale): Collection;

    /**
     * 번들 manifest 로부터 가상 LanguagePack 인스턴스를 합성합니다 (DB 미저장).
     *
     * 미설치(uninstalled) 번들의 가상 행 합성 책임을 Repository 로 일원화 — Service 가
     * Model 을 직접 인스턴스화하지 않도록 합니다. exists=false 로 표시되며, `bundled_identifier`
     * 가상 속성을 함께 채워 Resource 가 행 액션에 노출할 수 있게 합니다.
     *
     * @param  array<string, mixed>  $manifest  번들 manifest 데이터
     * @param  string  $bundledIdentifier  `lang-packs/_bundled/{이 값}` 디렉토리명
     * @return LanguagePack 가상 LanguagePack 인스턴스 (DB 미저장)
     */
    public function buildVirtualFromManifest(array $manifest, string $bundledIdentifier): LanguagePack;

    /**
     * 코어/번들 확장의 lang/{ko,en}/ 디렉토리로부터 가상 보호 LanguagePack 인스턴스를 합성합니다.
     *
     * 항상 active+protected 로 표시되며 사용자가 install/uninstall/activate/deactivate 할 수 없습니다.
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
    ): LanguagePack;

    /**
     * 호스트 확장(modules/plugins/templates)의 status + version 행을 조회합니다.
     *
     * `language_packs.requires.target_version` 검사 등 cross-domain 조회를 Repository
     * 경계 안으로 캡슐화하기 위한 메서드입니다. Service 가 DB facade 를 직접 호출하지 않도록 합니다.
     *
     * @param  string  $scope  스코프 (module/plugin/template). core 또는 알 수 없는 값은 null 반환.
     * @param  string  $identifier  호스트 확장 식별자
     * @return object|null `{status, version}` 객체 또는 행 부재/스코프 미지원 시 null
     */
    public function findHostExtensionRow(string $scope, string $identifier): ?object;
}
