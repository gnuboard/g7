<?php

namespace App\Services\LanguagePack;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Models\LanguagePack;
use Illuminate\Support\Collection;

/**
 * 언어팩 런타임 레지스트리.
 *
 * config('app.supported_locales') 가 본 레지스트리를 소스로 사용하며, 활성 언어팩 조회를 위해
 * 부팅 후 전 시스템에서 참조됩니다. 메모리 캐시는 명시적 invalidate() 호출 시에만 만료됩니다.
 */
class LanguagePackRegistry
{
    /**
     * 활성 언어팩 캐시.
     *
     * @var Collection<int, LanguagePack>|null
     */
    private ?Collection $activePacksCache = null;

    /**
     * 활성 코어 로케일 캐시.
     *
     * @var array<int, string>|null
     */
    private ?array $activeCoreLocalesCache = null;

    /**
     * 항상 포함되어야 하는 번들 로케일 (코어 ko/en 하드 가드).
     *
     * @var array<int, string>
     */
    private const BUNDLED_CORE_LOCALES = ['ko', 'en'];

    /**
     * 언어팩 Repository.
     */
    public function __construct(
        private readonly LanguagePackRepositoryInterface $repository
    ) {}

    /**
     * 활성 코어 로케일 목록을 반환합니다.
     *
     * 번들 ko/en 은 항상 포함되며, 추가로 active 상태인 코어 언어팩의 locale 이 합쳐집니다.
     *
     * @return array<int, string> 로케일 문자열 배열
     */
    public function getActiveCoreLocales(): array
    {
        if ($this->activeCoreLocalesCache !== null) {
            return $this->activeCoreLocalesCache;
        }

        $fromDb = $this->repository->getActiveCoreLocales();
        $merged = array_values(array_unique(array_merge(self::BUNDLED_CORE_LOCALES, $fromDb)));

        return $this->activeCoreLocalesCache = $merged;
    }

    /**
     * getActiveCoreLocales 의 별칭 (호환성).
     *
     * @return array<int, string> 로케일 문자열 배열
     */
    public function getCoreInstalledLocales(): array
    {
        return $this->getActiveCoreLocales();
    }

    /**
     * 설치된 모든 활성 언어팩의 locale → native_name 맵을 반환합니다.
     *
     * 번들 ko/en 의 native_name 은 코드 상수로 보장합니다.
     *
     * @return array<string, string> locale ⇒ native_name 맵
     */
    public function getLocaleNames(): array
    {
        $names = [
            'ko' => '한국어',
            'en' => 'English',
        ];

        foreach ($this->getActivePacks() as $pack) {
            if ($pack->scope !== LanguagePackScope::Core->value) {
                continue;
            }
            $names[$pack->locale] = $pack->locale_native_name ?: $pack->locale;
        }

        return $names;
    }

    /**
     * 모든 활성 언어팩을 반환합니다 (옵션으로 scope 필터링).
     *
     * @param  string|null  $scope  필터링할 스코프 (null 이면 전체)
     * @return Collection<int, LanguagePack> 활성 언어팩 컬렉션
     */
    public function getActivePacks(?string $scope = null): Collection
    {
        if ($this->activePacksCache === null) {
            $this->activePacksCache = $this->repository->getActivePacks();
        }

        if ($scope === null) {
            return $this->activePacksCache;
        }

        return $this->activePacksCache->filter(
            fn (LanguagePack $pack) => $pack->scope === $scope
        )->values();
    }

    /**
     * 특정 로케일의 코어 언어팩이 활성 상태인지 확인합니다.
     *
     * 번들 ko/en 은 항상 true 를 반환합니다.
     *
     * @param  string  $locale  로케일
     * @return bool 활성 코어 언어팩 존재 여부
     */
    public function hasActiveCoreLocale(string $locale): bool
    {
        if (in_array($locale, self::BUNDLED_CORE_LOCALES, true)) {
            return true;
        }

        return in_array($locale, $this->getActiveCoreLocales(), true);
    }

    /**
     * 특정 슬롯의 활성 언어팩을 반환합니다.
     *
     * @param  string  $scope  스코프
     * @param  string|null  $target  대상 확장 식별자
     * @param  string  $locale  로케일
     * @return LanguagePack|null 활성 언어팩 또는 null
     */
    public function getActivePackForSlot(string $scope, ?string $target, string $locale): ?LanguagePack
    {
        return $this->repository->findActiveForSlot($scope, $target, $locale);
    }

    /**
     * 캐시를 만료시킵니다 (활성화/비활성화/제거 직후 호출).
     *
     * @return void
     */
    public function invalidate(): void
    {
        $this->activePacksCache = null;
        $this->activeCoreLocalesCache = null;
    }
}
