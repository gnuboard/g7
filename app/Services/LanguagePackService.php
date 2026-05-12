<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Exceptions\LanguagePackSlotConflictException;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\HookManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\LanguagePack;
use App\Services\LanguagePack\LanguagePackBaseLocales;
use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Exceptions\LanguagePackOperationException;
use RuntimeException;
use Throwable;

/**
 * 언어팩 설치/활성화/비활성화/제거 서비스.
 *
 * 컨트롤러는 본 Service 에 위임하며, 입력 검증은 FormRequest 에서 사전 처리됩니다.
 * Service 내부에서는 도메인 검증(manifest 구조, 의존성, 슬롯 충돌)만 수행합니다.
 */
class LanguagePackService
{
    use ClearsTemplateCaches;

    /**
     * @param  LanguagePackRepositoryInterface  $repository  언어팩 Repository
     * @param  LanguagePackManifestValidator  $validator  manifest 검증기
     * @param  LanguagePackRegistry  $registry  런타임 레지스트리
     * @param  CacheInterface  $cache  캐시 인터페이스
     */
    public function __construct(
        private readonly LanguagePackRepositoryInterface $repository,
        private readonly LanguagePackManifestValidator $validator,
        private readonly LanguagePackRegistry $registry,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 페이지네이션된 언어팩 목록을 반환합니다.
     *
     * 모듈/플러그인 관리 화면의 풀 패리티를 위해, DB 에 등록된 언어팩과
     * `lang-packs/_bundled/` 의 미설치 번들 가상 레코드를 합쳐 반환합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (scope/locale/status/search/vendor/page)
     * @param  int  $perPage  페이지당 건수
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 병합 페이지네이션 결과
     */
    public function list(array $filters = [], int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $statusFilter = $filters['status'] ?? null;

        $dbCollection = $this->repository->getFilteredCollection($filters);
        $occupiedSlots = $this->collectSlotKeys($dbCollection);

        // 가상 보호 행 (코어/번들 확장의 lang/{ko,en}/) — DB 슬롯에 없는 것만
        $builtInVirtual = $this->getVirtualBuiltInPacks($filters)
            ->reject(fn (LanguagePack $pack) => isset($occupiedSlots[$this->slotKey($pack)]))
            ->each(fn (LanguagePack $pack) => $occupiedSlots[$this->slotKey($pack)] = true);

        // 미설치 가상 행 (lang-packs/_bundled/) — DB 슬롯 + built-in 슬롯에 없는 것만
        $uninstalledVirtual = $this->shouldIncludeUninstalledBundled($statusFilter)
            ? $this->getUninstalledBundledPacks($filters)
                ->reject(fn (LanguagePack $pack) => isset($occupiedSlots[$this->slotKey($pack)]))
            : collect();

        // DB + built-in + uninstalled 가상 행을 동일한 안정 키로 정렬해야
        // 설치/활성화로 행 종류가 바뀌어도 페이지 위치가 흔들리지 않는다.
        $merged = $dbCollection
            ->concat($builtInVirtual)
            ->concat($uninstalledVirtual)
            ->sortBy([
                ['scope', 'asc'],
                ['target_identifier', 'asc'],
                ['locale', 'asc'],
            ])
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $items = $merged->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $merged->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * 한 확장(또는 코어) 에 귀속된 모든 로케일 언어팩을 머지하여 반환합니다.
     *
     * 모듈/플러그인/템플릿 detail API 의 `language_packs` 필드 데이터 소스로 사용되며,
     * `list()` 와 동일한 머지 전략(DB > built_in 가상 > 미설치 번들 가상) 을 단일
     * 슬롯 그룹(scope + target_identifier) 으로 한정해 적용합니다.
     *
     * @param  LanguagePackScope  $scope  대상 스코프 (module/plugin/template/core)
     * @param  string|null  $targetIdentifier  대상 확장 식별자 (코어는 null)
     * @return Collection<int, LanguagePack> 로케일별 정렬 된 머지 컬렉션
     */
    public function getPacksForExtension(LanguagePackScope $scope, ?string $targetIdentifier): Collection
    {
        $filters = [
            'scope' => $scope->value,
            'target_identifier' => $targetIdentifier,
        ];

        $dbCollection = $this->repository->getFilteredCollection($filters);
        $occupiedSlots = $this->collectSlotKeys($dbCollection);

        $builtInVirtual = $this->getVirtualBuiltInPacks($filters)
            ->reject(fn (LanguagePack $pack) => isset($occupiedSlots[$this->slotKey($pack)]))
            ->each(fn (LanguagePack $pack) => $occupiedSlots[$this->slotKey($pack)] = true);

        $uninstalledVirtual = $this->getUninstalledBundledPacks($filters)
            ->reject(fn (LanguagePack $pack) => isset($occupiedSlots[$this->slotKey($pack)]));

        return $dbCollection
            ->concat($builtInVirtual)
            ->concat($uninstalledVirtual)
            ->sortBy('locale')
            ->values();
    }

    /**
     * 컬렉션에서 슬롯 키 (scope|target|locale) 맵을 생성합니다.
     *
     * @param  Collection<int, LanguagePack>  $collection  컬렉션
     * @return array<string, true>
     */
    private function collectSlotKeys(Collection $collection): array
    {
        return $collection
            ->mapWithKeys(fn (LanguagePack $pack) => [$this->slotKey($pack) => true])
            ->all();
    }

    /**
     * 슬롯 키를 생성합니다.
     *
     * @param  LanguagePack  $pack  팩
     * @return string 슬롯 키 (scope|target_identifier|locale)
     */
    private function slotKey(LanguagePack $pack): string
    {
        return $pack->scope.'|'.($pack->target_identifier ?? '').'|'.$pack->locale;
    }

    /**
     * 코어/번들 확장의 `lang/{ko,en}/` 디렉토리를 자동 스캔하여 가상 보호 LanguagePack
     * 인스턴스 컬렉션을 반환합니다 (요구사항 #1, #2).
     *
     * 스캔 대상:
     *  - 코어: lang/{ko,en}/
     *  - 번들 모듈: modules/_bundled/{id}/resources/lang/{ko,en}/
     *  - 번들 플러그인: plugins/_bundled/{id}/lang/{ko,en}/
     *  - 번들 템플릿: templates/_bundled/{id}/lang/{ko,en}.json
     *
     * 발견된 각 슬롯에 대해 Repository::buildVirtualBuiltInPack() 으로 가상 행 합성.
     * 모든 가상 행은 status=active, is_protected=true, source_type=built_in.
     *
     * @param  array<string, mixed>  $filters  필터 (scope/locale/target_identifier/search/vendor)
     * @return Collection<int, LanguagePack> 가상 보호 LanguagePack 컬렉션
     */
    public function getVirtualBuiltInPacks(array $filters = []): Collection
    {
        $packs = collect();

        foreach (LanguagePackBaseLocales::all() as $locale) {
            // 코어
            if (File::isDirectory(base_path('lang/'.$locale))) {
                $pack = $this->repository->buildVirtualBuiltInPack(
                    scope: LanguagePackScope::Core->value,
                    targetIdentifier: null,
                    locale: $locale,
                    vendor: 'g7',
                    version: (string) config('app.version', '0.0.0'),
                    langPathRelative: 'lang/'.$locale,
                );
                if ($this->matchesBuiltInFilters($pack, $filters)) {
                    $packs->push($pack);
                }
            }

            // 번들 모듈
            $packs = $packs->concat($this->scanBundledExtensionLang(
                bundledRoot: 'modules/_bundled',
                scope: LanguagePackScope::Module->value,
                langSubpath: 'resources/lang/'.$locale,
                locale: $locale,
                filters: $filters,
            ));

            // 번들 플러그인
            $packs = $packs->concat($this->scanBundledExtensionLang(
                bundledRoot: 'plugins/_bundled',
                scope: LanguagePackScope::Plugin->value,
                langSubpath: 'lang/'.$locale,
                locale: $locale,
                filters: $filters,
            ));

            // 번들 템플릿 (lang/{locale}.json 또는 lang/partial/{locale}/)
            $packs = $packs->concat($this->scanBundledTemplateLang($locale, $filters));
        }

        return $packs->values();
    }

    /**
     * 번들 확장 디렉토리(modules/_bundled, plugins/_bundled) 의 lang/{locale}/ 자원을 스캔합니다.
     *
     * @param  string  $bundledRoot  base_path 기준 상대 경로 (예: 'modules/_bundled')
     * @param  string  $scope  스코프 enum 값
     * @param  string  $langSubpath  확장 디렉토리 하위의 lang 경로 (예: 'resources/lang/ko')
     * @param  string  $locale  로케일
     * @param  array<string, mixed>  $filters  필터
     * @return Collection<int, LanguagePack>
     */
    private function scanBundledExtensionLang(
        string $bundledRoot,
        string $scope,
        string $langSubpath,
        string $locale,
        array $filters,
    ): Collection {
        $absRoot = base_path($bundledRoot);
        if (! File::isDirectory($absRoot)) {
            return collect();
        }

        $packs = collect();
        foreach (File::directories($absRoot) as $extDir) {
            $extId = basename($extDir);
            $langDir = $extDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $langSubpath);
            if (! File::isDirectory($langDir)) {
                continue;
            }

            $manifestPath = $extDir.DIRECTORY_SEPARATOR.$this->guessManifestFilename($scope);
            $manifest = is_file($manifestPath) ? json_decode((string) @file_get_contents($manifestPath), true) : null;
            $vendor = is_array($manifest) ? (string) ($manifest['vendor'] ?? explode('-', $extId)[0] ?? 'g7') : 'g7';
            $version = is_array($manifest) ? (string) ($manifest['version'] ?? '0.0.0') : '0.0.0';

            $pack = $this->repository->buildVirtualBuiltInPack(
                scope: $scope,
                targetIdentifier: $extId,
                locale: $locale,
                vendor: $vendor,
                version: $version,
                langPathRelative: $bundledRoot.'/'.$extId.'/'.$langSubpath,
            );
            if ($this->matchesBuiltInFilters($pack, $filters)) {
                $packs->push($pack);
            }
        }

        return $packs;
    }

    /**
     * 번들 템플릿의 lang/{locale}.json 또는 lang/partial/{locale}/ 자원을 스캔합니다.
     *
     * @param  string  $locale  로케일
     * @param  array<string, mixed>  $filters  필터
     * @return Collection<int, LanguagePack>
     */
    private function scanBundledTemplateLang(string $locale, array $filters): Collection
    {
        $absRoot = base_path('templates/_bundled');
        if (! File::isDirectory($absRoot)) {
            return collect();
        }

        $packs = collect();
        foreach (File::directories($absRoot) as $tplDir) {
            $tplId = basename($tplDir);
            $hasJson = is_file($tplDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json');
            $hasPartial = File::isDirectory($tplDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'partial'.DIRECTORY_SEPARATOR.$locale);
            if (! $hasJson && ! $hasPartial) {
                continue;
            }

            $manifestPath = $tplDir.DIRECTORY_SEPARATOR.'template.json';
            $manifest = is_file($manifestPath) ? json_decode((string) @file_get_contents($manifestPath), true) : null;
            $vendor = is_array($manifest) ? (string) ($manifest['vendor'] ?? explode('-', $tplId)[0] ?? 'g7') : 'g7';
            $version = is_array($manifest) ? (string) ($manifest['version'] ?? '0.0.0') : '0.0.0';

            $pack = $this->repository->buildVirtualBuiltInPack(
                scope: LanguagePackScope::Template->value,
                targetIdentifier: $tplId,
                locale: $locale,
                vendor: $vendor,
                version: $version,
                langPathRelative: 'templates/_bundled/'.$tplId.'/lang',
            );
            if ($this->matchesBuiltInFilters($pack, $filters)) {
                $packs->push($pack);
            }
        }

        return $packs;
    }

    /**
     * 스코프별 manifest 파일명을 추측합니다.
     *
     * @param  string  $scope  스코프
     * @return string manifest 파일명
     */
    private function guessManifestFilename(string $scope): string
    {
        return match ($scope) {
            'module' => 'module.json',
            'plugin' => 'plugin.json',
            'template' => 'template.json',
            default => 'manifest.json',
        };
    }

    /**
     * 가상 보호 행이 list 필터에 맞는지 검사합니다.
     *
     * @param  LanguagePack  $pack  가상 행
     * @param  array<string, mixed>  $filters  필터
     * @return bool
     */
    private function matchesBuiltInFilters(LanguagePack $pack, array $filters): bool
    {
        if (! empty($filters['scope']) && $pack->scope !== $filters['scope']) {
            return false;
        }
        if (! empty($filters['locale']) && $pack->locale !== $filters['locale']) {
            return false;
        }
        if (array_key_exists('target_identifier', $filters) && $filters['target_identifier'] !== null) {
            // 개별 확장 언어팩 관리 화면 — 그 확장에 해당되는 행만 노출 (정정 사항).
            // 코어 보호 팩은 환경설정 > 언어팩 관리(필터 없음) 화면에서만 노출됨.
            if ($pack->target_identifier !== $filters['target_identifier']) {
                return false;
            }
        }
        if (! empty($filters['vendor']) && $pack->vendor !== $filters['vendor']) {
            return false;
        }
        if (! empty($filters['exclude_protected']) && $pack->is_protected) {
            return false;
        }
        if (! empty($filters['status']) && $filters['status'] !== LanguagePackStatus::Active->value) {
            return false;
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $haystack = mb_strtolower($pack->identifier.' '.$pack->vendor.' '.$pack->locale.' '.$pack->locale_native_name);
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `lang-packs/_bundled/{identifier}` 디렉토리를 스캔하여, DB 에 동일 슬롯
     * (scope/target_identifier/locale) 레코드가 없는 미설치 번들을 가상 LanguagePack
     * 인스턴스로 반환합니다. 모듈/플러그인의 `getUninstalledModules()` 와 동일 패턴.
     *
     * 가상 인스턴스의 특성:
     *  - `id` 는 null (DB 미존재 → exists=false)
     *  - `status` = `LanguagePackStatus::Uninstalled` 가상값 (DB 컬럼에는 없음)
     *  - `source_type` = `bundled` (`installFromBundled` 가 받는 식별자와 동일)
     *  - 추가 가상 속성 `bundled_identifier` 로 행 액션이 사용할 식별자 노출
     *
     * 필터(filters)는 Repository 와 동일 의미로 처리하여 일관성을 유지합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope/locale/target_identifier/search/vendor)
     * @return Collection<int, LanguagePack> 미설치 번들 가상 LanguagePack 컬렉션
     */
    public function getUninstalledBundledPacks(array $filters = []): Collection
    {
        $bundledRoot = base_path('lang-packs/_bundled');
        if (! File::isDirectory($bundledRoot)) {
            return collect();
        }

        $installedSlots = $this->repository
            ->getFilteredCollection([])
            ->mapWithKeys(fn (LanguagePack $pack) => [
                $pack->scope.'|'.($pack->target_identifier ?? '').'|'.$pack->locale => true,
            ])
            ->all();

        $packs = collect();

        foreach (File::directories($bundledRoot) as $dir) {
            $manifestPath = $dir.DIRECTORY_SEPARATOR.'language-pack.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            $raw = @file_get_contents($manifestPath);
            $manifest = $raw ? json_decode($raw, true) : null;
            if (! is_array($manifest) || empty($manifest['identifier'])) {
                continue;
            }

            $scope = $manifest['scope'] ?? null;
            $locale = $manifest['locale'] ?? null;
            if (! $scope || ! $locale) {
                continue;
            }

            $targetIdentifier = $manifest['target_identifier'] ?? null;
            $slotKey = $scope.'|'.($targetIdentifier ?? '').'|'.$locale;
            if (isset($installedSlots[$slotKey])) {
                continue;
            }

            if (! $this->matchesBundledFilters($manifest, $filters)) {
                continue;
            }

            $virtual = $this->repository->buildVirtualFromManifest($manifest, basename($dir));
            $virtual->setAttribute('install_blocked_reason', $this->resolveInstallBlockedReason($manifest));
            $packs->push($virtual);
        }

        return $packs->values();
    }

    /**
     * `status` 필터가 미설치 번들 포함을 허용하는지 판단합니다.
     *
     * @param  string|null  $statusFilter  status 필터 값 (null/공백/uninstalled 만 허용)
     * @return bool 포함 시 true
     */
    private function shouldIncludeUninstalledBundled(?string $statusFilter): bool
    {
        if ($statusFilter === null || $statusFilter === '') {
            return true;
        }

        return $statusFilter === LanguagePackStatus::Uninstalled->value;
    }

    /**
     * 번들 manifest 가 필터 조건을 충족하는지 검사합니다 (Repository 와 동일 의미).
     *
     * @param  array<string, mixed>  $manifest  번들 manifest
     * @param  array<string, mixed>  $filters  필터 조건
     * @return bool 통과 시 true
     */
    private function matchesBundledFilters(array $manifest, array $filters): bool
    {
        if (! empty($filters['scope']) && ($manifest['scope'] ?? null) !== $filters['scope']) {
            return false;
        }
        if (! empty($filters['locale']) && ($manifest['locale'] ?? null) !== $filters['locale']) {
            return false;
        }
        if (array_key_exists('target_identifier', $filters) && $filters['target_identifier'] !== null) {
            if (($manifest['target_identifier'] ?? null) !== $filters['target_identifier']) {
                return false;
            }
        }
        if (! empty($filters['vendor']) && ($manifest['vendor'] ?? null) !== $filters['vendor']) {
            return false;
        }
        if (! empty($filters['search'])) {
            $needle = mb_strtolower((string) $filters['search']);
            $haystack = mb_strtolower(implode(' ', array_filter([
                (string) ($manifest['identifier'] ?? ''),
                (string) ($manifest['vendor'] ?? ''),
                (string) ($manifest['locale_name'] ?? ''),
                (string) ($manifest['locale_native_name'] ?? ''),
            ])));
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }
        // exclude_protected 는 DB 행 단계에서 LanguagePack::is_protected 컬럼으로 처리.
        // 번들 manifest 는 보호 행을 표현하지 않으므로 (Repository::buildVirtualFromManifest 가 컨텍스트로 결정)
        // 여기서는 항상 통과시킨다.

        return true;
    }

    /**
     * ID 로 언어팩을 조회합니다.
     *
     * @param  int  $id  언어팩 ID
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function find(int $id): ?LanguagePack
    {
        return $this->repository->findById($id);
    }

    /**
     * 식별자로 언어팩을 조회합니다.
     *
     * Artisan 커맨드(language-pack:update / language-pack:uninstall) 의 단일 진입점으로 사용됩니다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findByIdentifier(string $identifier): ?LanguagePack
    {
        return $this->repository->findByIdentifier($identifier);
    }

    /**
     * 언어 셀렉터에 즉시 노출 가능한 활성 로케일 목록을 반환합니다.
     *
     * config('app.supported_locales') 의 허용 집합과 활성 코어
     * 언어팩의 합집합을 반환하여 코어 기본 로케일이 누락되지 않도록
     * 합니다. 언어팩 설치/활성화 직후 사용자 UI 갱신용으로 사용됩니다.
     *
     * @return array<int, string> 로케일 문자열 배열
     */
    public function getActiveLocales(): array
    {
        $configLocales = (array) config('app.supported_locales', ['ko', 'en']);
        $activeLocales = $this->repository->getActiveCoreLocales();

        return array_values(array_unique(array_merge($configLocales, $activeLocales)));
    }

    /**
     * 정수 ID 또는 번들 식별자(문자열) 로 언어팩을 조회합니다.
     *
     * 정수면 `find()` 로 DB 레코드를, 식별자면 `lang-packs/_bundled/{id}` manifest 로부터
     * `LanguagePackRepository::buildVirtualFromManifest()` 가상 모델을 반환합니다 (모듈 관리의 미설치 가상 행과 동일 패턴).
     * 입력 형태별 dispatch 만 담당하며 검증/모델 합성은 모두 위임합니다.
     *
     * @param  int|string  $id  ID(정수) 또는 번들 식별자
     * @return LanguagePack|null 언어팩 또는 null
     */
    public function findOrBundled(int|string $id): ?LanguagePack
    {
        if (is_numeric($id)) {
            return $this->find((int) $id);
        }

        // 1) lang-packs/_bundled/{id}/ 의 ja 등 별도 패키지 가상 행
        $manifestPath = base_path('lang-packs/_bundled/'.$id.'/language-pack.json');
        if (File::isFile($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);
            if (is_array($manifest) && ($manifest['identifier'] ?? null) === $id) {
                return $this->repository->buildVirtualFromManifest($manifest, (string) $id);
            }
        }

        // 2) 코어/번들 확장의 lang/{ko,en}/ 가상 보호 행
        foreach ($this->getVirtualBuiltInPacks() as $pack) {
            if ($pack->identifier === $id) {
                return $pack;
            }
        }

        return null;
    }

    /**
     * 업로드된 ZIP 파일에서 언어팩을 설치합니다.
     *
     * @param  UploadedFile  $file  업로드된 ZIP 파일
     * @param  bool  $autoActivate  설치 후 자동 활성화 여부
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @return LanguagePack 설치된 언어팩
     *
     * @throws RuntimeException 설치 실패 시
     */
    public function installFromFile(UploadedFile $file, bool $autoActivate = false, ?int $installedBy = null): LanguagePack
    {
        $this->assertInstallDirectoriesWritable();

        $tempId = (string) Str::uuid();
        $extractPath = base_path('lang-packs/_pending/'.$tempId);

        try {
            $zipPath = $file->getRealPath();
            ZipInstallHelper::extractZip($zipPath, $extractPath);

            return $this->finalizeInstall($extractPath, 'zip', $file->getClientOriginalName(), $autoActivate, $installedBy);
        } catch (\Throwable $e) {
            $this->cleanupPending($extractPath);
            throw $e;
        }
    }

    /**
     * GitHub URL 에서 언어팩을 설치합니다.
     *
     * @param  string  $githubUrl  GitHub repository URL
     * @param  bool  $autoActivate  설치 후 자동 활성화 여부
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @param  bool  $force  다운그레이드 차단 우회 여부 (true 면 신버전 < 기존버전 도 허용)
     * @return LanguagePack 설치된 언어팩
     *
     * @throws RuntimeException 설치 실패 시
     */
    public function installFromGithub(string $githubUrl, bool $autoActivate = false, ?int $installedBy = null, bool $force = false): LanguagePack
    {
        $this->assertInstallDirectoriesWritable();

        $tempId = (string) Str::uuid();
        $tempPath = storage_path('app/temp/'.$tempId);
        $extractPath = base_path('lang-packs/_pending/'.$tempId);

        try {
            File::ensureDirectoryExists($tempPath);
            ['owner' => $owner, 'repo' => $repo] = GithubHelper::parseUrl($githubUrl);
            $zipPath = GithubHelper::downloadZip($owner, $repo, $tempPath);
            ZipInstallHelper::extractZip($zipPath, $extractPath);

            return $this->finalizeInstall($extractPath, 'github', $githubUrl, $autoActivate, $installedBy, $force);
        } catch (\Throwable $e) {
            $this->cleanupPending($extractPath);
            File::deleteDirectory($tempPath);
            throw $e;
        }
    }

    /**
     * 임의 URL 에서 언어팩을 설치합니다.
     *
     * @param  string  $url  ZIP 다운로드 URL
     * @param  string|null  $checksum  SHA-256 체크섬 (옵션)
     * @param  bool  $autoActivate  설치 후 자동 활성화 여부
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @param  bool  $force  다운그레이드 차단 우회 여부 (true 면 신버전 < 기존버전 도 허용)
     * @return LanguagePack 설치된 언어팩
     *
     * @throws RuntimeException 설치 실패 시
     */
    public function installFromUrl(string $url, ?string $checksum, bool $autoActivate = false, ?int $installedBy = null, bool $force = false): LanguagePack
    {
        $this->assertInstallDirectoriesWritable();

        $tempId = (string) Str::uuid();
        $tempPath = storage_path('app/temp/'.$tempId);
        $extractPath = base_path('lang-packs/_pending/'.$tempId);

        try {
            File::ensureDirectoryExists($tempPath);
            $zipPath = $tempPath.DIRECTORY_SEPARATOR.'pack.zip';

            $response = Http::timeout(120)->get($url);
            if (! $response->successful()) {
                throw new LanguagePackOperationException('language_packs.errors.download_failed', ['url' => $url]);
            }
            File::put($zipPath, $response->body());

            if ($checksum) {
                $actual = hash_file('sha256', $zipPath);
                if (! hash_equals($checksum, $actual)) {
                    throw new LanguagePackOperationException('language_packs.errors.checksum_mismatch');
                }
            }

            ZipInstallHelper::extractZip($zipPath, $extractPath);

            return $this->finalizeInstall($extractPath, 'url', $url, $autoActivate, $installedBy, $force);
        } catch (\Throwable $e) {
            $this->cleanupPending($extractPath);
            File::deleteDirectory($tempPath);
            throw $e;
        }
    }

    /**
     * `lang-packs/_bundled/{identifier}` 의 번들 소스에서 언어팩을 설치(또는 재설치)합니다.
     *
     * 모듈/플러그인/템플릿의 `_bundled` 설치 패턴과 동일하게, 코어/공식 언어팩을
     * 외부 다운로드 없이 로컬 번들 디렉토리에서 가져와 활성 디렉토리로 복사합니다.
     * 시더만으로는 손상된 활성 디렉토리를 복구할 수 없는 운영상의 갭을 메웁니다.
     *
     * @param  string  $identifier  번들 디렉토리 식별자 (lang-packs/_bundled/{identifier})
     * @param  bool  $autoActivate  설치 후 자동 활성화 여부
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @param  bool  $force  다운그레이드 차단 우회 여부 (true 면 신버전 < 기존버전 도 허용)
     * @return LanguagePack 설치/갱신된 언어팩
     *
     * @throws RuntimeException 번들 디렉토리/manifest 부재 또는 검증 실패 시
     */
    public function installFromBundled(string $identifier, bool $autoActivate = false, ?int $installedBy = null, bool $force = false): LanguagePack
    {
        $this->assertInstallDirectoriesWritable();

        $bundledPath = base_path('lang-packs/_bundled/'.$identifier);
        if (! File::isDirectory($bundledPath)) {
            throw new LanguagePackOperationException('language_packs.errors.bundled_not_found', ['identifier' => $identifier]);
        }

        $tempId = (string) Str::uuid();
        $extractPath = base_path('lang-packs/_pending/'.$tempId);

        try {
            File::ensureDirectoryExists(dirname($extractPath));
            File::copyDirectory($bundledPath, $extractPath);

            return $this->finalizeInstall($extractPath, 'bundled', $identifier, $autoActivate, $installedBy, $force);
        } catch (\Throwable $e) {
            $this->cleanupPending($extractPath);
            throw $e;
        }
    }

    /**
     * _pending 디렉토리에서 manifest 검증, 디렉토리 이동, DB 등록까지 수행합니다.
     *
     * @param  string  $extractPath  추출된 디렉토리 경로
     * @param  string  $sourceType  설치 소스 유형 (zip/github/url)
     * @param  string  $sourceUrl  설치 소스 URL/파일명
     * @param  bool  $autoActivate  설치 후 자동 활성화 여부
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @return LanguagePack 설치/갱신된 언어팩
     *
     * @throws RuntimeException 검증/의존성/충돌 실패 시
     */
    private function finalizeInstall(
        string $extractPath,
        string $sourceType,
        string $sourceUrl,
        bool $autoActivate,
        ?int $installedBy,
        bool $force = false
    ): LanguagePack {
        $manifestPath = ZipInstallHelper::findManifest($extractPath, 'language-pack.json');
        if (! $manifestPath) {
            throw new LanguagePackOperationException('language_packs.errors.manifest_not_found');
        }

        $manifestRaw = File::get($manifestPath);
        $manifest = json_decode($manifestRaw, true);
        if (! is_array($manifest)) {
            throw new LanguagePackOperationException('language_packs.errors.manifest_invalid_json');
        }

        $packageRoot = dirname($manifestPath);
        $this->validator->validate($manifest, $packageRoot);

        $this->assertSecurityRules($packageRoot, $manifest);
        $this->assertDependencies($manifest);
        $this->assertTargetExtensionExists($manifest);

        $identifier = $manifest['identifier'];
        $existing = $this->repository->findByIdentifier($identifier);

        if ($existing && ! $force) {
            // force=true 는 모듈/플러그인/템플릿 update --force 와 동일 의미 — 다운그레이드 우회 허용.
            $this->assertNotDowngrade($existing, $manifest);
        }

        $finalPath = base_path('lang-packs/'.$identifier);
        if (File::isDirectory($finalPath)) {
            File::deleteDirectory($finalPath);
        }
        File::ensureDirectoryExists(dirname($finalPath));
        File::moveDirectory($packageRoot, $finalPath);

        if ($extractPath !== $packageRoot) {
            File::deleteDirectory($extractPath);
        }

        $pack = DB::transaction(function () use ($existing, $manifest, $sourceType, $sourceUrl, $autoActivate, $installedBy) {
            $data = $this->buildPackData($manifest, $sourceType, $sourceUrl, $installedBy);

            // 재설치(update path) 시 자기 자신을 슬롯 충돌로 오인하지 않도록 $existing->id 를 제외.
            // 이전엔 재설치 시 자기가 active 였더라도 $shouldActivate 가 false 가 되어
            // status 가 active → installed 로 강등 → 의존하는 확장 언어팩이 'core_locale_missing'
            // 으로 차단되는 회귀가 발생.
            $shouldActivate = $autoActivate
                && ! $this->repository->findActiveForSlot(
                    $manifest['scope'],
                    $manifest['target_identifier'] ?? null,
                    $manifest['locale'],
                    $existing?->id
                );

            $data['status'] = $shouldActivate
                ? LanguagePackStatus::Active->value
                : LanguagePackStatus::Installed->value;

            if ($shouldActivate) {
                $data['activated_at'] = now();
            }

            $pack = $existing
                ? $this->repository->update($existing, $data)
                : $this->repository->create($data);

            return $pack;
        });

        // 캐시 전 계층 무효화 (registry + translator + template-lang)
        $this->refreshCache();

        HookManager::doAction(
            $existing ? 'core.language_packs.updated' : 'core.language_packs.installed',
            $pack
        );

        if ($pack->status === LanguagePackStatus::Active->value) {
            HookManager::doAction('core.language_packs.activated', $pack);
        }

        return $pack;
    }

    /**
     * 언어팩을 활성화합니다 (슬롯 스위칭).
     *
     * 동일 슬롯(scope + target_identifier + locale)에 이미 다른 active 팩이 있고
     * `$force=false` 인 경우 `LanguagePackSlotConflictException` 을 던져 사용자에게
     * 교체 확인을 요청한다. `$force=true` 인 경우 기존 팩을 inactive 로 강등하고
     * 대상 팩을 active 로 승격한다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  bool  $force  기존 활성 팩이 있어도 강제로 교체
     * @return LanguagePack 활성화된 언어팩
     *
     * @throws LanguagePackSlotConflictException 슬롯에 다른 활성 팩이 있고 force=false 인 경우
     */
    public function activate(LanguagePack $pack, bool $force = false): LanguagePack
    {
        $this->assertNotInProgress($pack);

        if ($pack->isActive()) {
            return $pack;
        }

        // 요구사항 #5: activate 시점에도 의존성/버전 호환성 검사 (이전: install 시점만)
        $this->assertDependencies($pack->manifest ?? []);
        $this->assertTargetExtensionExists($pack->manifest ?? []);

        // 슬롯 충돌 사전 감지 — force=false 면 사용자 확인 모달을 위해 예외
        $current = $this->repository->findActiveForSlot(
            $pack->scope,
            $pack->target_identifier,
            $pack->locale
        );
        if ($current && $current->id !== $pack->id && ! $force) {
            throw new LanguagePackSlotConflictException($current, $pack);
        }

        DB::transaction(function () use ($pack, $current) {
            if ($current && $current->id !== $pack->id) {
                $this->repository->update($current, [
                    'status' => LanguagePackStatus::Inactive->value,
                ]);
                HookManager::doAction('core.language_packs.deactivated', $current);
            }

            $this->repository->update($pack, [
                'status' => LanguagePackStatus::Active->value,
                'activated_at' => now(),
            ]);
        });

        $this->refreshCache();
        $fresh = $this->repository->findById($pack->id) ?? $pack;
        HookManager::doAction('core.language_packs.activated', $fresh);

        return $fresh;
    }

    /**
     * 여러 언어팩을 일괄 활성화합니다 (요구사항 #7 reactivate 모달 → "활성화" 버튼).
     *
     * 각 ID 에 대해 activate() 를 호출하며, 실패한 항목은 reason 과 함께 응답에 포함합니다.
     * 의존성 검사는 activate() 내부에서 자동 수행됩니다 (§4).
     *
     * @param  array<int, int>  $ids  언어팩 ID 배열
     * @return array{succeeded: array<int, int>, failed: array<int, array<string, string>>}
     */
    public function bulkActivate(array $ids): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $pack = $this->repository->findById((int) $id);
            if (! $pack) {
                $failed[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            try {
                $this->activate($pack);
                $succeeded[] = $pack->id;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * 언어팩을 비활성화합니다.
     *
     * 슬롯에 다른 후보가 존재하면 가장 최근 inactive/installed 팩이 자동 active 로 승격됩니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  bool  $cascadeFromHost  호스트 확장 비활성화로 인한 cascade 호출 여부 (true 면 보호 행/protected 검증 우회)
     * @return LanguagePack 비활성화된 언어팩
     */
    public function deactivate(LanguagePack $pack, bool $cascadeFromHost = false): LanguagePack
    {
        $this->assertNotInProgress($pack);

        if (! $pack->isActive()) {
            return $pack;
        }

        // 요구사항 #6: 호스트 확장 cascade 컨텍스트에서는 protected 가드 우회
        if ($pack->isProtected() && ! $cascadeFromHost) {
            throw new LanguagePackOperationException('language_packs.errors.protected_pack');
        }

        DB::transaction(function () use ($pack) {
            $this->repository->update($pack, [
                'status' => LanguagePackStatus::Inactive->value,
            ]);

            $this->promoteSlotSuccessor($pack);
        });

        $this->refreshCache();
        HookManager::doAction('core.language_packs.deactivated', $pack);

        return $this->repository->findById($pack->id) ?? $pack;
    }

    /**
     * 언어팩을 제거합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  bool  $cascade  코어 제거 시 하위(module/plugin/template) 동일 locale 팩도 함께 제거
     * @return void
     */
    public function uninstall(LanguagePack $pack, bool $cascade = false): void
    {
        if ($pack->isProtected()) {
            throw new LanguagePackOperationException('language_packs.errors.protected_pack');
        }

        $this->assertNotInProgress($pack);

        DB::transaction(function () use ($pack, $cascade) {
            if ($pack->isActive()) {
                $this->promoteSlotSuccessor($pack);
            }

            if ($pack->source_type !== 'bundled_with_extension') {
                $directory = $pack->resolveDirectory();
                if (File::isDirectory($directory)) {
                    File::deleteDirectory($directory);
                }
            }

            $this->repository->delete($pack);

            if ($cascade && $pack->scope === LanguagePackScope::Core->value) {
                $relatedPacks = $this->repository
                    ->getPacksForLocale($pack->locale)
                    ->filter(fn (LanguagePack $p) => $p->scope !== LanguagePackScope::Core->value);

                foreach ($relatedPacks as $related) {
                    $this->repository->update($related, [
                        'status' => LanguagePackStatus::Inactive->value,
                    ]);
                }
            }
        });

        $this->refreshCache();
        HookManager::doAction('core.language_packs.uninstalled', $pack);
    }

    /**
     * 슬롯 비활성화 후 다음 후보를 active 로 승격합니다.
     *
     * @param  LanguagePack  $pack  방금 비활성화된 언어팩
     * @return void
     */
    private function promoteSlotSuccessor(LanguagePack $pack): void
    {
        $candidates = $this->repository
            ->getPacksForSlot($pack->scope, $pack->target_identifier, $pack->locale)
            ->filter(fn (LanguagePack $p) => $p->id !== $pack->id && $p->status !== LanguagePackStatus::Error->value);

        $next = $candidates->first();
        if ($next) {
            $this->repository->update($next, [
                'status' => LanguagePackStatus::Active->value,
                'activated_at' => now(),
            ]);
        }
    }

    /**
     * manifest → DB 컬럼 매핑 데이터를 빌드합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  string  $sourceType  설치 소스 유형
     * @param  string  $sourceUrl  설치 소스 URL
     * @param  int|null  $installedBy  설치자 사용자 ID
     * @return array<string, mixed> Repository::create/update 입력 데이터
     */
    private function buildPackData(array $manifest, string $sourceType, string $sourceUrl, ?int $installedBy): array
    {
        return [
            'identifier' => $manifest['identifier'],
            'vendor' => $manifest['vendor'],
            'scope' => $manifest['scope'],
            'target_identifier' => $manifest['target_identifier'] ?? null,
            'locale' => $manifest['locale'],
            'locale_name' => $manifest['locale_name'] ?? $manifest['locale'],
            'locale_native_name' => $manifest['locale_native_name'],
            'text_direction' => $manifest['text_direction'] ?? 'ltr',
            'version' => $manifest['version'],
            'target_version_constraint' => $manifest['requires']['target_version'] ?? null,
            'target_version_mismatch' => $this->checkTargetVersionMismatch($manifest),
            'license' => $manifest['license'] ?? null,
            'description' => is_array($manifest['description'] ?? null) ? $manifest['description'] : null,
            'is_protected' => false,
            'manifest' => $manifest,
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'installed_by' => $installedBy,
            'installed_at' => now(),
        ];
    }

    /**
     * 보안 규칙 검사 — backend/ 외의 PHP 파일 차단, eval/include 패턴 차단.
     *
     * @param  string  $packageRoot  패키지 루트 디렉토리
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return void
     *
     * @throws RuntimeException 보안 위반 발견 시
     */
    private function assertSecurityRules(string $packageRoot, array $manifest): void
    {
        $finder = function (string $directory) use (&$finder) {
            if (! File::isDirectory($directory)) {
                return [];
            }
            $files = [];
            foreach (File::allFiles($directory) as $file) {
                $files[] = $file->getRealPath();
            }

            return $files;
        };

        $allFiles = $finder($packageRoot);
        $backendDir = realpath($packageRoot.DIRECTORY_SEPARATOR.'backend') ?: null;

        foreach ($allFiles as $absolute) {
            if (! str_ends_with($absolute, '.php')) {
                continue;
            }

            if (! $backendDir || ! str_starts_with($absolute, $backendDir)) {
                throw new LanguagePackOperationException('language_packs.errors.php_outside_backend', [
                    'file' => str_replace($packageRoot, '', $absolute),
                ]);
            }

            $contents = File::get($absolute);
            if (preg_match('/\b(eval|include|include_once|require|require_once|exec|shell_exec|passthru|system|popen|proc_open)\s*\(/i', $contents)) {
                throw new LanguagePackOperationException('language_packs.errors.unsafe_php_pattern', [
                    'file' => str_replace($packageRoot, '', $absolute),
                ]);
            }
        }
    }

    /**
     * 언어팩 설치 디렉토리(`lang-packs/`, `lang-packs/_pending/`) 의 쓰기 권한을 사전 검증합니다.
     *
     * 모듈/플러그인/템플릿 install 과 동일 수준의 가드 — 권한 부족 시 chmod 안내 메시지가
     * 포함된 `RuntimeException` 을 던집니다. 컨트롤러는 본 예외를 422 응답으로 변환합니다.
     *
     * @return void
     *
     * @throws RuntimeException 디렉토리 미존재/쓰기 불가 시
     */
    private function assertInstallDirectoriesWritable(): void
    {
        foreach (['lang-packs', 'lang-packs/_pending'] as $relative) {
            $absolute = base_path($relative);
            if (! File::isDirectory($absolute)) {
                File::ensureDirectoryExists($absolute);
            }
            if (! is_writable($absolute)) {
                throw new LanguagePackOperationException('language_packs.errors.directory_not_writable', [
                    'path' => $relative,
                ]);
            }
        }
    }

    /**
     * 의존성 검증 — depends_on_core_locale 이 true 면 코어 언어팩 active 여부 확인.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return void
     *
     * @throws RuntimeException 의존성 미충족 시
     */
    private function assertDependencies(array $manifest): void
    {
        $reason = $this->resolveCoreLocaleBlockedReason($manifest);
        if ($reason !== null) {
            throw new LanguagePackOperationException('language_packs.errors.core_locale_missing', [
                'locale' => $manifest['locale'],
            ]);
        }
    }

    /**
     * 대상 확장 존재 + 활성 여부를 확인합니다.
     *
     * 모듈/플러그인 시스템과 동일한 강도로 — 비활성 확장에도 언어팩이 들러붙지 않게 차단합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return void
     *
     * @throws RuntimeException 대상 확장 미설치/비활성 시
     */
    private function assertTargetExtensionExists(array $manifest): void
    {
        $reason = $this->resolveTargetExtensionBlockedReason($manifest);
        if ($reason === 'target_not_installed') {
            throw new LanguagePackOperationException('language_packs.errors.target_not_installed', [
                'scope' => $manifest['scope'],
                'target' => $manifest['target_identifier'] ?? '',
            ]);
        }
        if ($reason === 'target_inactive') {
            throw new LanguagePackOperationException('language_packs.errors.target_inactive', [
                'scope' => $manifest['scope'],
                'target' => $manifest['target_identifier'] ?? '',
            ]);
        }
        if ($reason === 'target_version_too_old') {
            $constraint = $manifest['requires']['target_version'] ?? '';
            throw new LanguagePackOperationException('language_packs.errors.target_version_too_old', [
                'scope' => $manifest['scope'],
                'target' => $manifest['target_identifier'] ?? '',
                'constraint' => (string) $constraint,
            ]);
        }
    }

    /**
     * 설치 차단 사유를 단일 키워드로 반환합니다 (UI 표시 + 차단 검증의 SSoT).
     *
     * 우선순위: 코어 locale 미활성 → 대상 미설치 → 대상 비활성 → 대상 버전 미달.
     * 어느 것도 해당하지 않으면 null 을 반환합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return string|null 차단 사유 키 또는 null
     */
    public function resolveInstallBlockedReason(array $manifest): ?string
    {
        return $this->resolveCoreLocaleBlockedReason($manifest)
            ?? $this->resolveTargetExtensionBlockedReason($manifest);
    }

    /**
     * 코어 locale 의존성 차단 사유를 반환합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return string|null 'core_locale_missing' 또는 null
     */
    private function resolveCoreLocaleBlockedReason(array $manifest): ?string
    {
        $scope = $manifest['scope'] ?? null;
        if ($scope === LanguagePackScope::Core->value) {
            return null;
        }

        $dependsOn = $manifest['requires']['depends_on_core_locale'] ?? true;
        if (! $dependsOn) {
            return null;
        }

        $locale = $manifest['locale'] ?? '';
        if ($locale === '') {
            return null;
        }

        return $this->registry->hasActiveCoreLocale($locale) ? null : 'core_locale_missing';
    }

    /**
     * 대상 확장 존재/활성/버전 차단 사유를 반환합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return string|null 'target_not_installed' / 'target_inactive' / 'target_version_too_old' 또는 null
     */
    private function resolveTargetExtensionBlockedReason(array $manifest): ?string
    {
        $scope = $manifest['scope'] ?? null;
        $target = $manifest['target_identifier'] ?? null;

        if ($scope === LanguagePackScope::Core->value || ! $target) {
            return null;
        }

        $row = $this->repository->findHostExtensionRow($scope, $target);
        if (! $row) {
            return 'target_not_installed';
        }

        if (($row->status ?? null) !== 'active') {
            return 'target_inactive';
        }

        $constraint = $manifest['requires']['target_version'] ?? null;
        if ($constraint && $row->version) {
            $cleaned = ltrim((string) $constraint, '^~>=<! ');
            if (version_compare((string) $row->version, $cleaned, '<')) {
                return 'target_version_too_old';
            }
        }

        return null;
    }

    /**
     * 다운그레이드 차단 검사.
     *
     * @param  LanguagePack  $existing  기존 언어팩
     * @param  array<string, mixed>  $manifest  새 manifest
     * @return void
     *
     * @throws RuntimeException 다운그레이드 시도 시
     */
    private function assertNotDowngrade(LanguagePack $existing, array $manifest): void
    {
        if (version_compare($manifest['version'], $existing->version, '<')) {
            throw new LanguagePackOperationException('language_packs.errors.downgrade_blocked', [
                'from' => $existing->version,
                'to' => $manifest['version'],
            ]);
        }
    }

    /**
     * target_version 불일치 여부를 계산합니다 (경고 플래그용, 차단하지 않음).
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return bool 불일치 시 true
     */
    private function checkTargetVersionMismatch(array $manifest): bool
    {
        $scope = $manifest['scope'];
        $target = $manifest['target_identifier'] ?? null;
        $constraint = $manifest['requires']['target_version'] ?? null;

        if ($scope === LanguagePackScope::Core->value || ! $target || ! $constraint) {
            return false;
        }

        $row = $this->repository->findHostExtensionRow($scope, $target);
        if (! $row || ! $row->version) {
            return false;
        }

        // 단순 정확 일치 여부만 본다 (Composer 제약 평가는 별도 비용 — v1 범위 밖)
        $cleaned = ltrim($constraint, '^~>=<! ');

        return version_compare((string) $row->version, $cleaned, '<');
    }

    /**
     * _pending 임시 디렉토리를 정리합니다.
     *
     * @param  string  $path  정리 대상 경로
     * @return void
     */
    private function cleanupPending(string $path): void
    {
        try {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        } catch (\Throwable $e) {
            Log::warning('lang-pack pending cleanup failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GitHub 소스 언어팩의 latest_version 을 갱신합니다.
     *
     * @param  string|null  $identifier  특정 언어팩만 확인 (옵션)
     * @return array{checked:int, updates:int, details:array<int, array<string, mixed>>}
     */
    public function checkUpdates(?string $identifier = null): array
    {
        // 요구사항 #4: 모듈 패턴 — 모든 source_type 점검 (GitHub 1순위, 실패 시 bundled 폴백)
        $packs = $identifier
            ? collect([$this->repository->findByIdentifier($identifier)])->filter()
            : $this->repository->paginate([], 1000)->getCollection();

        $checked = 0;
        $updates = 0;
        $details = [];

        foreach ($packs as $pack) {
            // GitHub URL 해석 가능 (manifest.github_url 또는 source_url) 또는
            // bundled 폴백 가능한 팩만 점검 대상.
            if ($this->resolveGithubUrl($pack) === null && ! $this->hasBundledManifest($pack->identifier)) {
                continue;
            }

            $checked++;
            $entry = $this->checkSinglePackUpdate($pack);
            $details[] = $entry;

            if ($entry['has_update']) {
                $updates++;
            }
        }

        return ['checked' => $checked, 'updates' => $updates, 'details' => $details];
    }

    /**
     * 단일 GitHub 언어팩의 최신 버전을 조회하고 latest_version 을 갱신합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return array{identifier:string, current:string, latest:string|null, has_update:bool, error:string|null}
     */
    private function checkSinglePackUpdate(LanguagePack $pack): array
    {
        $latest = null;
        $error = null;

        // 1순위: GitHub (manifest.github_url SSoT — 모듈/플러그인/템플릿과 동일 패턴.
        //          매니페스트에 없으면 source_url 이 GitHub URL 인 경우 폴백)
        $githubUrl = $this->resolveGithubUrl($pack);
        if ($githubUrl !== null) {
            try {
                ['owner' => $owner, 'repo' => $repo] = GithubHelper::parseUrl($githubUrl);
                $release = GithubHelper::fetchLatestRelease($owner, $repo);
                $latest = ltrim((string) ($release['tag_name'] ?? ''), 'v') ?: null;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                Log::warning('language-pack github update check failed', [
                    'identifier' => $pack->identifier,
                    'error' => $error,
                ]);
            }
        }

        // 2순위: bundled 폴백 (lang-packs/_bundled/{identifier}/language-pack.json)
        if ($latest === null) {
            $latest = $this->resolveBundledLatestVersion($pack);
        }

        if ($latest === null) {
            return [
                'identifier' => $pack->identifier,
                'current' => $pack->version,
                'latest' => null,
                'has_update' => false,
                'error' => $error,
            ];
        }

        $this->repository->update($pack, ['latest_version' => $latest]);

        return [
            'identifier' => $pack->identifier,
            'current' => $pack->version,
            'latest' => $latest,
            'has_update' => version_compare($latest, $pack->version, '>'),
            'error' => null,
        ];
    }

    /**
     * 언어팩의 GitHub repository URL 을 해석합니다.
     *
     * 우선순위 (모듈/플러그인/템플릿의 `github_url` SSoT 패턴과 일관):
     *  1. `manifest.github_url` (DB `manifest` 컬럼에 스냅샷된 매니페스트 필드)
     *  2. `source_url` 이 `https://github.com/` 로 시작 (GitHub 설치 경로 호환)
     *
     * 둘 다 없거나 GitHub URL 이 아니면 null.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return string|null GitHub URL 또는 null
     */
    private function resolveGithubUrl(LanguagePack $pack): ?string
    {
        $manifest = $pack->manifest;
        if (is_array($manifest)) {
            $manifestUrl = $manifest['github_url'] ?? null;
            if (is_string($manifestUrl) && Str::startsWith($manifestUrl, 'https://github.com/')) {
                return $manifestUrl;
            }
        }

        $sourceUrl = (string) $pack->source_url;
        if (Str::startsWith($sourceUrl, 'https://github.com/')) {
            return $sourceUrl;
        }

        return null;
    }

    /**
     * 번들 manifest 로부터 최신 버전을 조회합니다 (요구사항 #4 폴백 경로).
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return string|null 최신 버전 문자열 또는 null
     */
    private function resolveBundledLatestVersion(LanguagePack $pack): ?string
    {
        $manifestPath = base_path('lang-packs/_bundled/'.$pack->identifier.'/language-pack.json');
        if (! is_file($manifestPath)) {
            return null;
        }

        $raw = @file_get_contents($manifestPath);
        $manifest = $raw ? json_decode($raw, true) : null;
        if (! is_array($manifest) || empty($manifest['version'])) {
            return null;
        }

        return (string) $manifest['version'];
    }

    /**
     * 번들 manifest 가 존재하는지 확인합니다.
     *
     * @param  string  $identifier  언어팩 식별자
     * @return bool 번들 디렉토리가 있고 manifest 가 유효하면 true
     */
    private function hasBundledManifest(string $identifier): bool
    {
        return is_file(base_path('lang-packs/_bundled/'.$identifier.'/language-pack.json'));
    }

    /**
     * `lang-packs/_bundled/` 의 신버전 manifest 와 DB 의 설치된 언어팩 버전을 직접 비교하여
     * 업데이트 가능한 언어팩 목록을 수집합니다.
     *
     * `core:update` 의 `runBundledExtensionUpdatePrompt` 가 사용. GitHub 검사를 우회하여
     * `_bundled` 만으로 업데이트 후보를 빠르게 판정 (모듈/플러그인/템플릿의
     * `CoreUpdateService::collectBundledExtensionUpdates()` 와 동일 패턴).
     *
     * @return array<int, array{identifier: string, current_version: string, latest_version: string}>
     */
    public function collectBundledLangPackUpdates(): array
    {
        $updates = [];
        $bundledRoot = base_path('lang-packs/_bundled');

        if (! is_dir($bundledRoot)) {
            return $updates;
        }

        // DB 에 설치된 모든 언어팩 (보호 가상 행 제외 — protected 로 필터됨)
        $packs = $this->repository->paginate([], 1000)->getCollection();

        foreach ($packs as $pack) {
            if (! $this->hasBundledManifest($pack->identifier)) {
                continue;
            }

            $manifestPath = $bundledRoot.'/'.$pack->identifier.'/language-pack.json';
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $bundledVersion = (string) ($manifest['version'] ?? '');
            $currentVersion = (string) $pack->version;

            if ($bundledVersion === '' || $currentVersion === '') {
                continue;
            }

            // 시맨틱 버전 비교: 번들이 더 크면 업데이트 후보
            if (version_compare($bundledVersion, $currentVersion, '>')) {
                $updates[] = [
                    'identifier' => $pack->identifier,
                    'current_version' => $currentVersion,
                    'latest_version' => $bundledVersion,
                ];
            }
        }

        return $updates;
    }

    /**
     * GitHub 소스 언어팩의 신버전을 다운로드하여 적용합니다.
     *
     * 모듈/플러그인/템플릿 update 플로우와 동일한 깊이의 안전 장치 적용:
     *  - 동시성 가드 (status=Updating 상태에서 재진입 차단)
     *  - 백업 + 실패 시 자동 롤백 (ExtensionBackupHelper)
     *  - 이전 상태 복원 보장 (Active/Inactive)
     *  - before/after hooks: core.language_packs.updated (성공 시)
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @param  bool  $force  버전 변동 가드 우회 여부 (true 면 같은 버전으로도 _bundled 강제 재적용)
     * @return LanguagePack 갱신된 언어팩
     *
     * @throws LanguagePackOperationException source_type 이 github 이 아니거나 source_url 이 없는 경우, 또는 진행 중일 때
     */
    public function performUpdate(LanguagePack $pack, bool $force = false): LanguagePack
    {
        // 요구사항 #4: bundled 도 update 가능. force=true 시 bundled 1순위, 그 외 GitHub 1순위 + bundled 폴백
        $githubUrl = $this->resolveGithubUrl($pack);
        if (! $githubUrl && ! $this->hasBundledManifest($pack->identifier)) {
            throw new LanguagePackOperationException('language_packs.errors.update_no_source');
        }

        $this->assertNotInProgress($pack);

        // force=false 일 때만 버전 변동 여부를 가드한다. force=true 는 모듈/플러그인/템플릿의
        // {type}:update --force 와 동일하게 버전이 같아도 _bundled 에서 강제 재적용을 허용한다.
        if (! $force) {
            $check = $this->checkSinglePackUpdate($pack);
            if (! $check['has_update']) {
                throw new LanguagePackOperationException('language_packs.errors.update_already_latest');
            }
        }

        $identifier = $pack->identifier;
        $previousStatus = $pack->status;
        $autoActivate = $pack->isActive();
        $backupPath = null;

        // 우선순위 결정: force=true + bundled 존재 → bundled, 그 외 → GitHub (manifest.github_url 우선)
        $useBundled = $force && $this->hasBundledManifest($identifier);
        if (! $useBundled && $githubUrl === null) {
            // GitHub URL 해석 실패 시 bundled 폴백 자동 사용
            $useBundled = $this->hasBundledManifest($identifier);
        }

        // 1. 상태 → Updating (가드용)
        $this->repository->update($pack, [
            'status' => LanguagePackStatus::Updating->value,
        ]);

        try {
            // 2. 활성 디렉토리 백업 (롤백 대비)
            $activeDir = base_path('lang-packs/'.$identifier);
            if (File::isDirectory($activeDir)) {
                $backupPath = ExtensionBackupHelper::createBackup('lang-packs', $identifier);
            }

            // 3. 신버전 다운로드 + 적용 (force 전파 — 다운그레이드 우회 허용)
            if ($useBundled) {
                $updated = $this->installFromBundled($identifier, $autoActivate, $pack->installed_by, $force);
            } else {
                $updated = $this->installFromGithub((string) $githubUrl, $autoActivate, $pack->installed_by, $force);
            }

            // 4. 백업 정리
            if ($backupPath) {
                ExtensionBackupHelper::deleteBackup($backupPath);
            }

            return $updated;
        } catch (Throwable $e) {
            Log::error('language pack update failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            // 백업 복원
            if ($backupPath) {
                try {
                    ExtensionBackupHelper::restoreFromBackup('lang-packs', $identifier, $backupPath);
                    ExtensionBackupHelper::deleteBackup($backupPath);
                } catch (Throwable $restoreError) {
                    Log::error('language pack backup restore failed', [
                        'identifier' => $identifier,
                        'error' => $restoreError->getMessage(),
                    ]);
                }
            }

            // 상태 복원 (DB 행이 finalizeInstall 에서 status 를 다시 쓰지 않은 경우만)
            try {
                $current = $this->repository->findByIdentifier($identifier);
                if ($current && $current->status === LanguagePackStatus::Updating->value) {
                    $this->repository->update($current, ['status' => $previousStatus]);
                }
            } catch (Throwable $statusError) {
                Log::error('language pack status restore failed', [
                    'identifier' => $identifier,
                    'error' => $statusError->getMessage(),
                ]);
            }

            // 백업 복원으로 디스크 상태가 바뀌었으므로 전 계층 캐시 갱신 + 프론트 cache busting
            $this->refreshCache();

            throw $e;
        }
    }

    /**
     * 진행 중인 작업이 없는지 확인합니다.
     *
     * 모듈/플러그인/템플릿의 ExtensionStatusGuard 와 동일한 의미의 가드.
     * status=Updating 인 팩에 대해 활성/제거/재업데이트 진입을 차단합니다.
     *
     * @param  LanguagePack  $pack  대상 언어팩
     *
     * @throws RuntimeException 진행 중일 때
     */
    public function assertNotInProgress(LanguagePack $pack): void
    {
        if ($pack->status === LanguagePackStatus::Updating->value) {
            throw new LanguagePackOperationException('language_packs.errors.operation_in_progress', [
                'identifier' => $pack->identifier,
            ]);
        }
    }

    /**
     * 번역/레지스트리/템플릿 언어 캐시를 무효화합니다.
     *
     * 추가로 `ext.cache_version` 을 bump 하여 프론트엔드의 lang/routes/layout fetch URL
     * 에 부착되는 `?v=` 쿼리 파라미터가 새 값으로 갱신되도록 한다 — 모듈/플러그인/템플릿
     * 활성화와 동일한 cache busting 메커니즘.
     *
     * @return array{registry:bool, translator:bool, template:bool, version:bool}
     */
    public function refreshCache(): array
    {
        $result = ['registry' => false, 'translator' => false, 'template' => false, 'version' => false];

        try {
            $this->registry->invalidate();
            $result['registry'] = true;
        } catch (Throwable $e) {
            Log::warning('language-pack registry invalidate failed', ['error' => $e->getMessage()]);
        }

        try {
            $translator = app('translator');
            if (method_exists($translator, 'setLoaded')) {
                $translator->setLoaded([]);
            }
            $result['translator'] = true;
        } catch (Throwable $e) {
            Log::warning('language-pack translator reset failed', ['error' => $e->getMessage()]);
        }

        try {
            if ($this->cache->supportsTags()) {
                $this->cache->flushTags(['template-lang']);
            } else {
                $this->cache->forget('template-lang');
            }
            $result['template'] = true;
        } catch (Throwable $e) {
            Log::warning('language-pack template cache flush failed', ['error' => $e->getMessage()]);
        }

        try {
            // 프론트엔드 cache busting — `?v=` 쿼리 파라미터가 새 타임스탬프로 갱신됨
            $this->incrementExtensionCacheVersion();
            $result['version'] = true;
        } catch (Throwable $e) {
            Log::warning('language-pack extension cache version bump failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * 업로드된 ZIP 의 manifest 와 검증 결과만 추출합니다 (실제 설치 X).
     *
     * @param  UploadedFile  $file  업로드된 ZIP
     * @return array{manifest:array<string, mixed>, validation:array<string, mixed>}
     *
     * @throws RuntimeException manifest 추출/검증 실패 시
     */
    public function previewManifest(UploadedFile $file): array
    {
        $tempId = (string) Str::uuid();
        $extractPath = base_path('lang-packs/_pending/preview-'.$tempId);

        try {
            $zipPath = $file->getRealPath();
            ZipInstallHelper::extractZip($zipPath, $extractPath);

            $manifestPath = ZipInstallHelper::findManifest($extractPath, 'language-pack.json');
            if (! $manifestPath) {
                throw new LanguagePackOperationException('language_packs.errors.manifest_not_found');
            }

            $manifestRaw = File::get($manifestPath);
            $manifest = json_decode($manifestRaw, true);
            if (! is_array($manifest)) {
                throw new LanguagePackOperationException('language_packs.errors.manifest_invalid_json');
            }

            $packageRoot = dirname($manifestPath);
            $validationErrors = [];

            try {
                $this->validator->validate($manifest, $packageRoot);
            } catch (Throwable $e) {
                $validationErrors[] = $e->getMessage();
            }

            $existing = $this->repository->findByIdentifier($manifest['identifier'] ?? '');

            return [
                'manifest' => $manifest,
                'validation' => [
                    'errors' => $validationErrors,
                    'is_valid' => $validationErrors === [],
                    'already_installed' => $existing !== null,
                    'existing_version' => $existing?->version,
                    'target_version_mismatch' => $this->checkTargetVersionMismatch($manifest),
                ],
            ];
        } finally {
            $this->cleanupPending($extractPath);
        }
    }
}
