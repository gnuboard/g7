<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use App\Exceptions\LanguagePackOperationException;
use App\Exceptions\LanguagePackSlotConflictException;
use App\Extension\Helpers\ExtensionBackupHelper;
use App\Extension\Helpers\GithubHelper;
use App\Extension\Helpers\InstalledExtensionLookup;
use App\Extension\Helpers\ZipInstallHelper;
use App\Extension\HookManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\LanguagePack;
use App\Services\Extension\ExtensionIconResolver;
use App\Services\LanguagePack\LanguagePackBaseLocales;
use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Support\OutboundUrlValidator;
use App\Support\SafeExceptionMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $items = $this->annotateFilesMissing($merged->forPage($page, $perPage)->values());

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

        return $this->annotateFilesMissing(
            $dbCollection
                ->concat($builtInVirtual)
                ->concat($uninstalledVirtual)
                ->sortBy('locale')
                ->values()
        );
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
     * 각 팩에 드리프트 파생 플래그 `files_missing` 와 복구 가능 여부 `bundled_source_available` 를 부여합니다.
     *
     * `files_missing` 은 DB 에 active 로 기록됐으나 설치본 디렉토리가 실재하지 않는 행을 표면화하기 위한 것으로,
     * 이 상태의 팩은 런타임에 오류 없이 base locale 로 조용히 폴백한다(이슈 #496 의 드리프트 증상).
     *
     * `bundled_source_available` 은 그 팩을 `lang-packs/_bundled/` 소스로 재설치해 복구할 수 있는지를 나타낸다.
     * 설치 경로(source_type)가 아니라 **번들 소스의 실재 여부**로 판정하므로, github/url/zip 으로 설치된 팩이라도
     * 동일 식별자의 번들 소스가 있으면 복구 대상이 된다 — 판정을 source_type 에 걸면 그 팩들은 드리프트가
     * 보이기만 하고 복구 수단이 없는 상태로 남는다.
     *
     * @param  Collection<int, LanguagePack>  $packs  대상 컬렉션
     * @return Collection<int, LanguagePack> 동일 컬렉션(플래그 부여됨)
     */
    private function annotateFilesMissing(Collection $packs): Collection
    {
        foreach ($packs as $pack) {
            $pack->setAttribute('files_missing', $this->isInstalledButFilesMissing($pack));
            $pack->setAttribute('bundled_source_available', $this->hasBundledSource($pack));
        }

        return $this->annotateIcons($packs);
    }

    /**
     * 언어팩 행에 아이콘 URL·출처를 부여합니다.
     *
     * 언어팩은 대개 자기 아이콘을 담지 않는다 — 기본은 **대상 확장의 아이콘**이고
     * 화면이 거기에 로케일 배지를 얹는다(국기는 쓰지 않는다. 언어≠국가).
     * 우선순위는 자기 선언 → 코어 아이콘(scope=core) → 대상 확장 아이콘 → 없음이다.
     *
     * @param  Collection<int, LanguagePack>  $packs  대상 컬렉션
     * @return Collection<int, LanguagePack> 동일 컬렉션(icon_url·icon_source 부여됨)
     */
    private function annotateIcons(Collection $packs): Collection
    {
        foreach ($packs as $pack) {
            $this->annotateIcon($pack);
        }

        return $packs;
    }

    /**
     * 언어팩 한 벌에 icon_url·icon_source 를 부여합니다.
     *
     * 목록과 단건 조회가 같은 판정을 쓰도록 여기 한 곳에 둔다 — 상세 창은 목록을 거치지 않고
     * 이 경로로 받은 행을 그대로 그리므로, 목록에만 얹으면 상세 창에서 아이콘이 사라진다.
     *
     * 설치 확인 창(`ExtensionInstallPreviewBuilder`)도 이 메서드를 쓴다 — 거기서 해석기를
     * 따로 부르면 「자기 선언 → 코어 → 대상 확장 → 없음」 4갈래 판정이 두 벌로 갈린다.
     * **읽기 전용 경로에서만** 부른다(`find()`·`findByIdentifier()` 는 활성화·삭제로 이어져
     * 모델이 그대로 저장되는데, 이 두 속성은 컬럼이 아니라 저장 시 500 이 된다).
     *
     * @param  LanguagePack  $pack  대상 언어팩
     * @return LanguagePack 동일 인스턴스(icon_url·icon_source 부여됨)
     */
    public function annotateIcon(LanguagePack $pack): LanguagePack
    {
        $resolver = app(ExtensionIconResolver::class);

        $identifier = (string) $pack->identifier;
        $version = $pack->version !== null ? (string) $pack->version : null;

        if ($identifier !== '' && $resolver->resolve('language-pack', $identifier) !== null) {
            $pack->setAttribute('icon_url', $resolver->url('language-pack', $identifier, $version));
            $pack->setAttribute('icon_source', 'manifest');

            return $pack;
        }

        $scope = (string) $pack->scope;

        if ($scope === 'core') {
            $pack->setAttribute('icon_url', ExtensionIconResolver::CORE_ICON_URL);
            $pack->setAttribute('icon_source', 'core');

            return $pack;
        }

        $target = (string) ($pack->target_identifier ?? '');

        if ($target !== ''
            && in_array($scope, ['module', 'plugin', 'template'], true)
            && $resolver->resolve($scope, $target) !== null) {
            $pack->setAttribute('icon_url', $resolver->url($scope, $target));
            $pack->setAttribute('icon_source', 'target');

            return $pack;
        }

        $pack->setAttribute('icon_url', null);
        $pack->setAttribute('icon_source', 'none');

        return $pack;
    }

    /**
     * 팩과 동일한 식별자의 번들 소스(`lang-packs/_bundled/{identifier}`)가 실재하는지 확인합니다.
     *
     * @param  LanguagePack  $pack  판정 대상 팩
     * @return bool 번들 소스가 있으면 true
     */
    private function hasBundledSource(LanguagePack $pack): bool
    {
        $identifier = (string) ($pack->getAttribute('bundled_identifier') ?? $pack->identifier);
        if ($identifier === '') {
            return false;
        }

        return File::isDirectory(base_path('lang-packs/_bundled/'.$identifier));
    }

    /**
     * 설치본 파일이 부재(드리프트)해 번들 소스로 복구 가능한 설치 행을 반환합니다.
     *
     * `getUninstalledBundledPacks()` 는 슬롯이 점유된 팩을 후보에서 제외하므로 드리프트 행을 잡지 못합니다.
     * 프로비저닝이 "수동 복구" 경로까지 겸하려면 이 집합이 함께 대상이 되어야 합니다.
     *
     * @param  array<string, mixed>  $filters  필터 (scope/locale/target_identifier/search/vendor)
     * @return Collection<int, LanguagePack> 드리프트 설치 행 컬렉션
     */
    public function getDriftedInstalledPacks(array $filters = []): Collection
    {
        return $this->annotateFilesMissing(
            $this->repository
                ->getFilteredCollection($filters)
                ->filter(fn (LanguagePack $pack) => $this->isInstalledButFilesMissing($pack))
                ->filter(fn (LanguagePack $pack) => $this->hasBundledSource($pack))
                ->values()
        );
    }

    /**
     * 팩이 "설치(active) 로 기록됐으나 설치본 파일이 부재" 한 드리프트 상태인지 판정합니다.
     *
     * 가상 행(built_in / uninstalled)은 실제 설치본이 아니므로 대상에서 제외합니다.
     *
     * @param  LanguagePack  $pack  판정 대상 팩
     * @return bool 드리프트면 true
     */
    private function isInstalledButFilesMissing(LanguagePack $pack): bool
    {
        // 가상 행(exists=false: built_in/uninstalled)은 설치본 개념이 없으므로 제외
        if (! $pack->exists) {
            return false;
        }

        if ($pack->status !== LanguagePackStatus::Active->value) {
            return false;
        }

        try {
            return ! File::isDirectory($pack->resolveDirectory());
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 코어와 설치된 확장의 `lang/{ko,en}/` 디렉토리를 스캔하여 가상 보호 LanguagePack
     * 인스턴스 컬렉션을 반환합니다 (요구사항 #1, #2).
     *
     * 스캔 대상 — **런타임이 실제로 로드하는 경로**와 같아야 한다. 어긋나면 관리자 화면이
     * "내장 번역" 으로 보여주는 것과 화면에 나오는 문구의 출처가 달라진다.
     *  - 코어: lang/{ko,en}/
     *  - 설치된 모듈: modules/{id}/src/lang/{ko,en}/ (TranslationServiceProvider 기준)
     *  - 설치된 플러그인: plugins/{id}/lang/{ko,en}/
     *  - 설치된 템플릿: templates/{id}/lang/{ko,en}.json 또는 lang/partial/{ko,en}/
     *
     * 「설치된 확장」 은 확장 테이블에 active·inactive 행이 있는 확장이다(InstalledExtensionLookup).
     * 설치 소스(번들·마켓·업로드)와 무관하게 모두 나오고, 설치하지 않은 번들 확장은 나오지 않는다 —
     * 동봉 원본은 설치 전 보관본이라 런타임이 읽지 않는다.
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

        // 설치된 확장 식별자는 로케일마다 다시 조회하지 않는다.
        $installed = [
            LanguagePackScope::Module->value => InstalledExtensionLookup::identifiers('module'),
            LanguagePackScope::Plugin->value => InstalledExtensionLookup::identifiers('plugin'),
            LanguagePackScope::Template->value => InstalledExtensionLookup::identifiers('template'),
        ];

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

            // 설치된 모듈 (런타임 등록 경로 = src/lang)
            $packs = $packs->concat($this->scanInstalledExtensionLang(
                installRoot: 'modules',
                identifiers: $installed[LanguagePackScope::Module->value],
                scope: LanguagePackScope::Module->value,
                langSubpath: 'src/lang/'.$locale,
                locale: $locale,
                filters: $filters,
            ));

            // 설치된 플러그인
            $packs = $packs->concat($this->scanInstalledExtensionLang(
                installRoot: 'plugins',
                identifiers: $installed[LanguagePackScope::Plugin->value],
                scope: LanguagePackScope::Plugin->value,
                langSubpath: 'lang/'.$locale,
                locale: $locale,
                filters: $filters,
            ));

            // 설치된 템플릿 (lang/{locale}.json 또는 lang/partial/{locale}/)
            $packs = $packs->concat($this->scanInstalledTemplateLang(
                $installed[LanguagePackScope::Template->value],
                $locale,
                $filters,
            ));
        }

        return $packs->values();
    }

    /**
     * 설치된 확장(모듈·플러그인)의 설치본 lang/{locale}/ 자원을 스캔합니다.
     *
     * 설치 기록은 있는데 설치본 디렉토리나 lang 디렉토리가 없으면 그 확장은 건너뛴다 —
     * 런타임도 그 자리에서 번역을 읽지 못하므로 "내장 번역" 으로 보여줄 것이 없다.
     *
     * @param  string  $installRoot  base_path 기준 설치 루트 (예: 'modules')
     * @param  array<int, string>  $identifiers  설치된 확장 식별자 목록
     * @param  string  $scope  스코프 enum 값
     * @param  string  $langSubpath  확장 디렉토리 하위의 lang 경로 (예: 'src/lang/ko')
     * @param  string  $locale  로케일
     * @param  array<string, mixed>  $filters  필터
     * @return Collection<int, LanguagePack> 설치본 lang 이 있는 확장의 가상 보호 행
     */
    private function scanInstalledExtensionLang(
        string $installRoot,
        array $identifiers,
        string $scope,
        string $langSubpath,
        string $locale,
        array $filters,
    ): Collection {
        $packs = collect();
        foreach ($identifiers as $extId) {
            $extDir = base_path($installRoot.DIRECTORY_SEPARATOR.$extId);
            $langDir = $extDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $langSubpath);
            if (! File::isDirectory($langDir)) {
                continue;
            }

            [$vendor, $version] = $this->readBuiltInManifestMeta($extDir, $scope, $extId);

            $pack = $this->repository->buildVirtualBuiltInPack(
                scope: $scope,
                targetIdentifier: $extId,
                locale: $locale,
                vendor: $vendor,
                version: $version,
                langPathRelative: $installRoot.'/'.$extId.'/'.$langSubpath,
            );
            if ($this->matchesBuiltInFilters($pack, $filters)) {
                $packs->push($pack);
            }
        }

        return $packs;
    }

    /**
     * 설치된 템플릿의 설치본 lang/{locale}.json 또는 lang/partial/{locale}/ 자원을 스캔합니다.
     *
     * @param  array<int, string>  $identifiers  설치된 템플릿 식별자 목록
     * @param  string  $locale  로케일
     * @param  array<string, mixed>  $filters  필터
     * @return Collection<int, LanguagePack> 설치본 lang 이 있는 템플릿의 가상 보호 행
     */
    private function scanInstalledTemplateLang(array $identifiers, string $locale, array $filters): Collection
    {
        $packs = collect();
        foreach ($identifiers as $tplId) {
            $tplDir = base_path('templates'.DIRECTORY_SEPARATOR.$tplId);
            $hasJson = is_file($tplDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json');
            $hasPartial = File::isDirectory($tplDir.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.'partial'.DIRECTORY_SEPARATOR.$locale);
            if (! $hasJson && ! $hasPartial) {
                continue;
            }

            [$vendor, $version] = $this->readBuiltInManifestMeta($tplDir, LanguagePackScope::Template->value, $tplId);

            $pack = $this->repository->buildVirtualBuiltInPack(
                scope: LanguagePackScope::Template->value,
                targetIdentifier: $tplId,
                locale: $locale,
                vendor: $vendor,
                version: $version,
                langPathRelative: 'templates/'.$tplId.'/lang',
            );
            if ($this->matchesBuiltInFilters($pack, $filters)) {
                $packs->push($pack);
            }
        }

        return $packs;
    }

    /**
     * 설치본 매니페스트에서 가상 보호 행의 vendor·version 을 읽습니다.
     *
     * @param  string  $extDir  확장 설치본 절대 경로
     * @param  string  $scope  스코프 enum 값 (매니페스트 파일명 결정)
     * @param  string  $extId  확장 식별자 (매니페스트에 vendor 가 없을 때 접두사로 대신한다)
     * @return array{0: string, 1: string} [vendor, version] — 매니페스트가 없으면 ['g7', '0.0.0']
     */
    private function readBuiltInManifestMeta(string $extDir, string $scope, string $extId): array
    {
        $manifestPath = $extDir.DIRECTORY_SEPARATOR.$this->guessManifestFilename($scope);
        $manifest = is_file($manifestPath) ? json_decode((string) @file_get_contents($manifestPath), true) : null;

        if (! is_array($manifest)) {
            return ['g7', '0.0.0'];
        }

        return [
            (string) ($manifest['vendor'] ?? explode('-', $extId)[0] ?? 'g7'),
            (string) ($manifest['version'] ?? '0.0.0'),
        ];
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
     * @return bool 필터에 맞으면 true
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
            $virtual->setAttribute('install_notice', $this->resolveInstallNotice($manifest));
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
        // 아이콘을 얹지 않는다 — 이 경로는 활성화·해제·삭제로 이어져 모델이 그대로 저장되는데,
        // 컬럼이 없는 속성이 실려 있으면 저장이 깨진다. 화면용 주석은 findOrBundled 가 맡는다.
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
        // find() 와 같은 이유로 아이콘을 얹지 않는다 — Artisan 커맨드가 이 모델을 저장한다.
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
        // 이 메서드는 상세 화면이 쓰는 읽기 전용 경로다 — 저장으로 이어지지 않으므로 아이콘을 얹는다.
        if (is_numeric($id)) {
            $pack = $this->find((int) $id);

            return $pack !== null ? $this->annotateIcon($pack) : null;
        }

        // 1) lang-packs/_bundled/{id}/ 의 ja 등 별도 패키지 가상 행
        $manifestPath = base_path('lang-packs/_bundled/'.$id.'/language-pack.json');
        if (File::isFile($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);
            if (is_array($manifest) && ($manifest['identifier'] ?? null) === $id) {
                return $this->annotateIcon(
                    $this->repository->buildVirtualFromManifest($manifest, (string) $id)
                );
            }
        }

        // 2) 코어/번들 확장의 lang/{ko,en}/ 가상 보호 행
        foreach ($this->getVirtualBuiltInPacks() as $pack) {
            if ($pack->identifier === $id) {
                return $this->annotateIcon($pack);
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
        } catch (Throwable $e) {
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
            // parseUrl 은 [소유자, 저장소] 목록을 돌려준다 — 종전의 키 분해(owner/repo)는 늘 실패해 GitHub 경로가 동작하지 않았다.
            [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
            $zipPath = GithubHelper::downloadZip($owner, $repo, $tempPath);
            ZipInstallHelper::extractZip($zipPath, $extractPath);

            return $this->finalizeInstall($extractPath, 'github', $githubUrl, $autoActivate, $installedBy, $force);
        } catch (Throwable $e) {
            $this->cleanupPending($extractPath);
            throw $e;
        } finally {
            // 다운로드 임시 디렉토리는 성공/실패와 무관하게 소비가 끝난 시점에 정리한다.
            // catch 에만 두면 설치가 성공할 때마다 ZIP 사본이 storage/app/temp 에 잔존한다.
            File::deleteDirectory($tempPath);
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
        // 서버가 이 URL 을 대신 내려받으므로, 내부 네트워크 주소가 목적지가 되지 않도록 차단한다.
        if (! OutboundUrlValidator::isPublicHttpUrl($url)) {
            throw new LanguagePackOperationException('language_packs.errors.download_url_not_public');
        }

        $this->assertInstallDirectoriesWritable();

        $tempId = (string) Str::uuid();
        $tempPath = storage_path('app/temp/'.$tempId);
        $extractPath = base_path('lang-packs/_pending/'.$tempId);

        try {
            File::ensureDirectoryExists($tempPath);
            $zipPath = $tempPath.DIRECTORY_SEPARATOR.'pack.zip';

            $response = Http::timeout(120)->get($url);
            if (! $response->successful()) {
                // 응답 상태를 사유로 싣는다 — 비우면 치환 자리가 남아 관리자 화면에
                // 리터럴 ':error' 가 그대로 노출된다.
                throw new LanguagePackOperationException('language_packs.errors.download_failed', [
                    'url' => $url,
                    'error' => 'HTTP '.$response->status(),
                ]);
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
        } catch (Throwable $e) {
            $this->cleanupPending($extractPath);
            throw $e;
        } finally {
            // 다운로드 임시 디렉토리는 성공/실패와 무관하게 소비가 끝난 시점에 정리한다.
            // catch 에만 두면 설치가 성공할 때마다 ZIP 사본이 storage/app/temp 에 잔존한다.
            File::deleteDirectory($tempPath);
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
        } catch (Throwable $e) {
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
     * @param  bool  $force  다운그레이드·충돌 가드를 건너뛸지 여부
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
        // 설치는 대상 확장이 **설치돼 있으면** 꺼져 있어도 허용한다(켜는 것은 activate() 가 여전히 대상 활성을 요구).
        // 확장 설치 창은 설치 직후 비활성인 그 확장 자신의 언어팩을 함께 고르므로, 여기서 거절하면 늘 실패했다.
        $this->assertTargetExtensionExists($manifest, allowInactiveTarget: true);
        $targetInactive = $this->isTargetExtensionInactive($manifest);

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

        // 재설치(update path) 시 자기 자신을 슬롯 충돌로 오인하지 않도록 $existing->id 를 제외.
        // 이전엔 재설치 시 자기가 active 였더라도 $shouldActivate 가 false 가 되어
        // status 가 active → installed 로 강등 → 의존하는 확장 언어팩이 'core_locale_missing'
        // 으로 차단되는 회귀가 발생.
        // 켜 달라는 요청이지만 대상 확장이 꺼져 있으면 지금은 켜지 않고 「대상 켜짐 대기」로 표시해 둔다 —
        // 대상 확장을 켤 때(LanguagePackBundledRegistrar::activateAwaitingForExtension) 함께 켜진다.
        $awaitingHost = $autoActivate && $targetInactive;

        $shouldActivate = $autoActivate
            && ! $targetInactive
            && ! $this->repository->findActiveForSlot(
                $manifest['scope'],
                $manifest['target_identifier'] ?? null,
                $manifest['locale'],
                $existing?->id
            );

        $pack = DB::transaction(function () use ($existing, $manifest, $sourceType, $sourceUrl, $installedBy, $awaitingHost) {
            $data = $this->buildPackData($manifest, $sourceType, $sourceUrl, $installedBy);

            // 설치 트랜잭션은 항상 installed 로만 기록한다. 활성화는 아래에서 activate() 를
            // 경유해야 의존성 검사·대상 확장 존재 확인·슬롯 충돌 검사가 반드시 수행되고,
            // activated 훅도 한 곳에서만 발화한다.
            //
            // 이 규칙의 부수 효과로, 이미 active 인 팩을 auto_activate 없이 덮어쓰면
            // active → installed 로 강등된다. 겉보기엔 버그지만 의도된 안전장치다 —
            // 활성 팩만 require 경로에 배선되므로, 방금 외부에서 반입된 내용이
            // 활성화 권한자의 동의 없이 그 자리를 이어받는 일이 없어야 한다.
            // (설치 권한만 가진 사용자가 활성 팩을 덮어쓰는 방식으로 활성화 권한 게이트를
            //  우회하는 경로를 닫는다. 정상 운영자는 auto_activate 로 활성 상태를 유지하며,
            //  그 플래그는 RequiresActivationPermission 이 지킨다.)
            $data['status'] = LanguagePackStatus::Installed->value;
            // 대기 표시는 이번 설치 요청의 뜻을 따른다 — 켜 달라고 하지 않은 재설치는 이전 대기 표시도 지운다.
            $data['deactivated_reason'] = $awaitingHost ? LanguagePackDeactivationReason::AwaitingHost->value : null;

            return $existing
                ? $this->repository->update($existing, $data)
                : $this->repository->create($data);
        });

        // 캐시 전 계층 무효화 (registry + translator + template-lang)
        $this->refreshCache();

        HookManager::doAction(
            $existing ? 'core.language_packs.updated' : 'core.language_packs.installed',
            $pack
        );

        if ($shouldActivate) {
            // activate() 내부에서 상태 기록·캐시 무효화·activated 훅 발화를 모두 담당한다.
            $pack = $this->activate($pack, false);
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
                'deactivated_reason' => null,
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
                $failed[] = ['id' => $id, 'reason' => SafeExceptionMessage::for($e)];
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 보안 규칙 검사 — 허용된 파일 형식·위치·내용만 통과시킵니다.
     *
     * 언어팩의 PHP 파일은 활성화되면 `LanguagePackTranslator` 가 `require` 하므로 그 내용이
     * 그대로 실행된다. 과거에는 위험 함수 11개를 정규식으로 나열해 막았으나 목록에 없는
     * 함수(`file_put_contents` `fopen` `call_user_func` `assert` 백틱 등)로 전부 우회됐다.
     * 따라서 판정을 뒤집어, 아래 네 가지를 모두 만족하는 것만 통과시킨다.
     *
     *  1. 확장자 화이트리스트 — `.json` / `.php` / `.md` (대소문자 무관)
     *  2. PHP 파일 위치 강제 — `backend/{manifest locale}/{group}.php` 또는 `backend/{group}.php`
     *  3. 심볼릭 링크 거부 — 패키지 밖 파일을 끌어들이는 통로 차단
     *  4. PHP 내용은 "번역 배열만" (LanguagePackPhpArrayValidator 문법 검사)
     *
     * 번들 여부와 무관하게 같은 경로로 검사한다 — 예외를 두면 "동봉된 것으로 위장하면 통과"
     * 라는 틈이 생기고, 앞으로 언어팩을 만들 때 기준이 둘로 갈린다.
     *
     * @param  string  $packageRoot  패키지 루트 디렉토리
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return void 반환값 없음
     *
     * @throws RuntimeException 보안 위반 발견 시
     */
    private function assertSecurityRules(string $packageRoot, array $manifest): void
    {
        $locale = (string) ($manifest['locale'] ?? '');

        // `backend/{locale}/{group}.php` 와 평면형 `backend/{group}.php` 만 허용.
        $phpLocationPattern = '#^backend/(?:'.preg_quote($locale, '#').'/)?[A-Za-z0-9_-]+\.php$#';

        foreach ($this->collectPackageEntries($packageRoot) as $relative => $absolute) {
            if (is_link($absolute)) {
                throw new LanguagePackOperationException('language_packs.errors.symlink_not_allowed', [
                    'file' => $relative,
                ]);
            }

            if (is_dir($absolute)) {
                continue;
            }

            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

            if ($extension === 'php') {
                if ($locale === '' || preg_match($phpLocationPattern, $relative) !== 1) {
                    throw new LanguagePackOperationException('language_packs.errors.php_outside_backend', [
                        'file' => $relative,
                    ]);
                }

                $violation = LanguagePackPhpArrayValidator::inspectFile($absolute);

                if ($violation !== null) {
                    throw new LanguagePackOperationException('language_packs.errors.php_file_not_literal_array', [
                        'file' => $relative,
                        'line' => $violation['line'],
                    ]);
                }

                continue;
            }

            // 압축 도구가 끼워 넣는 OS 메타데이터는 형식 검사에서 제외한다.
            // PHP 는 위 분기에서 이미 위치 규칙에 걸리므로 이 예외로 새는 실행 경로가 없다.
            if ($this->isOsMetadataEntry($relative)) {
                continue;
            }

            if (! in_array($extension, ['json', 'md'], true)) {
                throw new LanguagePackOperationException('language_packs.errors.unsupported_file_type', [
                    'file' => $relative,
                    'extension' => $extension === '' ? '(none)' : $extension,
                ]);
            }
        }
    }

    /**
     * 패키지 안의 모든 엔트리(디렉토리 포함)를 상대경로 ⇒ 절대경로로 수집합니다.
     *
     * 심볼릭 링크를 따라 들어가지 않는다 — 링크는 호출부가 거부 대상으로 판정하며,
     * 따라 들어가면 순환 링크로 무한 재귀에 빠질 수 있다.
     *
     * @param  string  $packageRoot  패키지 루트 디렉토리
     * @param  string  $prefix  현재 상대경로 접두사
     * @return array<string, string> 상대경로(슬래시 구분) ⇒ 절대경로
     */
    private function collectPackageEntries(string $packageRoot, string $prefix = ''): array
    {
        $base = $prefix === '' ? $packageRoot : $packageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $prefix);

        if (! is_dir($base)) {
            return [];
        }

        $entries = [];

        foreach (scandir($base) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $relative = $prefix === '' ? $name : $prefix.'/'.$name;
            $absolute = $base.DIRECTORY_SEPARATOR.$name;

            $entries[$relative] = $absolute;

            if (is_dir($absolute) && ! is_link($absolute)) {
                $entries += $this->collectPackageEntries($packageRoot, $relative);
            }
        }

        return $entries;
    }

    /**
     * 압축 도구가 생성하는 OS 메타데이터 엔트리인지 판정합니다.
     *
     * @param  string  $relative  패키지 루트 기준 상대경로
     * @return bool 메타데이터면 true
     */
    private function isOsMetadataEntry(string $relative): bool
    {
        if (str_starts_with($relative, '__MACOSX/') || $relative === '__MACOSX') {
            return true;
        }

        return in_array(basename($relative), ['.DS_Store', 'Thumbs.db', '.gitkeep', '.gitignore'], true);
    }

    /**
     * 언어팩 설치 디렉토리(`lang-packs/`, `lang-packs/_pending/`) 의 쓰기 권한을 사전 검증합니다.
     *
     * 모듈/플러그인/템플릿 install 과 동일 수준의 가드 — 권한 부족 시 chmod 안내 메시지가
     * 포함된 `RuntimeException` 을 던집니다. 컨트롤러는 본 예외를 422 응답으로 변환합니다.
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 활성화(activate)는 모듈/플러그인 시스템과 동일한 강도로 — 비활성 확장에 언어팩이 켜지지 않게 차단합니다.
     * 설치는 `$allowInactiveTarget=true` 로 불러 설치돼 있으나 꺼진 대상을 허용합니다(꺼진 채 설치, 켜기는 대상 활성 후).
     * 미설치 대상·버전 미달은 설치에서도 차단합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  bool  $allowInactiveTarget  설치돼 있으나 꺼진 대상을 허용할지 여부 (설치 경로 전용)
     * @return void 반환값 없음
     *
     * @throws RuntimeException 대상 확장 미설치/비활성(허용하지 않을 때)/버전 미달 시
     */
    private function assertTargetExtensionExists(array $manifest, bool $allowInactiveTarget = false): void
    {
        $reason = $this->resolveTargetExtensionBlockedReason($manifest, $allowInactiveTarget);
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
     * 우선순위: 코어 locale 미활성 → 대상 미설치 → 대상 버전 미달.
     * 대상이 설치돼 있으나 꺼져 있는 것은 설치 차단 사유가 아닙니다 — 꺼진 채 설치되고, 켜는 것은 대상을 켠 뒤에
     * 합니다(안내는 `resolveInstallNotice()`). 어느 것도 해당하지 않으면 null 을 반환합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return string|null 차단 사유 키 또는 null
     */
    public function resolveInstallBlockedReason(array $manifest): ?string
    {
        return $this->resolveCoreLocaleBlockedReason($manifest)
            ?? $this->resolveTargetExtensionBlockedReason($manifest, true);
    }

    /**
     * 설치는 되지만 알려 둘 사정을 단일 키워드로 반환합니다 (UI 안내용, 설치를 막지 않음).
     *
     * 현재는 대상 확장이 설치돼 있으나 꺼진 경우 `target_inactive` — 설치는 꺼진 채 되고, 그 확장을 켠 뒤에 켤 수 있습니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return string|null 안내 사유 키 또는 null
     */
    public function resolveInstallNotice(array $manifest): ?string
    {
        return $this->isTargetExtensionInactive($manifest) ? 'target_inactive' : null;
    }

    /**
     * 대상 확장이 설치돼 있으나 꺼져 있는지 판정합니다 (코어 범위·대상 없음·미설치는 false).
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @return bool 설치돼 있고 비활성이면 true
     */
    private function isTargetExtensionInactive(array $manifest): bool
    {
        $scope = $manifest['scope'] ?? null;
        $target = $manifest['target_identifier'] ?? null;

        if ($scope === LanguagePackScope::Core->value || ! $target) {
            return false;
        }

        $row = $this->repository->findHostExtensionRow($scope, $target);

        return $row !== null && ($row->status ?? null) !== ExtensionStatus::Active->value;
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
     * @param  bool  $allowInactiveTarget  true 면 설치돼 있으나 꺼진 대상을 차단하지 않고 버전 검사로 넘어간다 (설치 경로)
     * @return string|null 'target_not_installed' / 'target_inactive' / 'target_version_too_old' 또는 null
     */
    private function resolveTargetExtensionBlockedReason(array $manifest, bool $allowInactiveTarget = false): ?string
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

        if (($row->status ?? null) !== ExtensionStatus::Active->value && ! $allowInactiveTarget) {
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    private function cleanupPending(string $path): void
    {
        try {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        } catch (Throwable $e) {
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
        // 전량 순회는 allForUpdateCheck() — paginate 는 HTTP page 파라미터를 암묵 해석해
        // `?page=2` 요청에서 순회가 0건이 된다 (공개 이슈 #102 동형)
        $packs = $identifier
            ? collect([$this->repository->findByIdentifier($identifier)])->filter()
            : $this->repository->allForUpdateCheck();

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
     * 후보는 GitHub 공개 최신과 번들(`lang-packs/_bundled`) 판 중 높은 버전이다(같으면 GitHub) — 모듈·플러그인·템플릿과 같은 규칙.
     * 확장 공개 릴리스가 코어 출시보다 늦으면 옛 공개 판만 보고 「최신」으로 끝나지 않게 한다.
     *
     * @return array{identifier:string, current:string, latest:string|null, source:string|null, has_update:bool, error:string|null}
     */
    private function checkSinglePackUpdate(LanguagePack $pack): array
    {
        $githubLatest = null;
        $error = null;

        // 1순위: GitHub (manifest.github_url SSoT — 모듈/플러그인/템플릿과 동일 패턴.
        //          매니페스트에 없으면 source_url 이 GitHub URL 인 경우 폴백)
        $githubUrl = $this->resolveGithubUrl($pack);
        if ($githubUrl !== null) {
            try {
                [$owner, $repo] = GithubHelper::parseUrl($githubUrl);
                $release = GithubHelper::fetchLatestRelease($owner, $repo);
                // 헬퍼는 `version`(태그의 v 를 뗀 값)을 돌려준다 — 종전에는 `tag_name` 을 읽어 늘 비어 번들로 폴백했다.
                $githubLatest = ltrim((string) ($release['version'] ?? $release['tag_name'] ?? ''), 'v') ?: null;
                $error = $release['error'] ?? null;
            } catch (Throwable $e) {
                $error = $e->getMessage();
                Log::warning('language-pack github update check failed', [
                    'identifier' => $pack->identifier,
                    'error' => $error,
                ]);
            }
        }

        // 번들 판 (lang-packs/_bundled/{identifier}/language-pack.json) — GitHub 보다 높으면 번들을 후보로
        $latest = $githubLatest;
        $source = $githubLatest !== null ? 'github' : null;
        $bundledLatest = $this->resolveBundledLatestVersion($pack);
        if ($bundledLatest !== null && ($latest === null || version_compare($bundledLatest, $latest, '>'))) {
            $latest = $bundledLatest;
            $source = 'bundled';
        }

        if ($latest === null) {
            return [
                'identifier' => $pack->identifier,
                'current' => $pack->version,
                'latest' => null,
                'source' => null,
                'has_update' => false,
                'error' => $error,
            ];
        }

        $this->repository->update($pack, ['latest_version' => $latest]);

        return [
            'identifier' => $pack->identifier,
            'current' => $pack->version,
            'latest' => $latest,
            'source' => $source,
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
            if (is_string($manifestUrl) && self::isGithubUrl($manifestUrl)) {
                return $manifestUrl;
            }
        }

        $sourceUrl = (string) $pack->source_url;
        if (self::isGithubUrl($sourceUrl)) {
            return $sourceUrl;
        }

        return null;
    }

    /**
     * URL 이 실제 GitHub host 를 가리키는지 판정합니다.
     *
     * 접두사 매칭은 `https://github.com@evil.example/` 같은 userinfo 위장을 통과시키므로
     * host 를 완전 일치로 검증합니다.
     *
     * @param  string  $url  판정 대상 URL
     * @return bool GitHub URL 이면 true
     */
    private static function isGithubUrl(string $url): bool
    {
        return OutboundUrlValidator::isHostAllowed($url, ['github.com', 'www.github.com']);
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

        // DB 에 설치된 모든 언어팩 (보호 가상 행 제외 — protected 로 필터됨).
        // 전량 순회는 allForUpdateCheck() — paginate 의 암묵 page 해석 회피 (#102 동형)
        $packs = $this->repository->allForUpdateCheck();

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
        $preferBundled = false;
        if (! $force) {
            $check = $this->checkSinglePackUpdate($pack);
            if (! $check['has_update']) {
                throw new LanguagePackOperationException('language_packs.errors.update_already_latest');
            }
            // 확인이 고른 출처에서 받는다 — 번들이 공개 최신보다 높아 번들을 후보로 골랐으면 GitHub 에서 옛 판을 받지 않는다.
            $preferBundled = ($check['source'] ?? null) === 'bundled';
        }

        $identifier = $pack->identifier;
        $previousStatus = $pack->status;
        $previousReason = $pack->deactivated_reason;
        $autoActivate = $pack->isActive();
        $backupPath = null;

        // 출처 결정: force=true 또는 확인이 번들을 골랐으면(번들이 공개 최신보다 높음) + bundled 존재 → bundled, 그 외 → GitHub
        $useBundled = ($force || $preferBundled) && $this->hasBundledManifest($identifier);
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

            // 4. 꺼져 있던 팩의 상태·사유 복원 — 재설치 경로는 활성이 아닌 팩을 installed·사유 없음으로 기록한다.
            //    그대로 두면 꺼 둔 팩(inactive)은 대상 확장을 다시 켤 때의 「함께 켤 언어팩」 목록(inactive 만 고른다)에서,
            //    대상 확장을 기다리던 팩(awaiting_host)은 대상 확장 활성화의 자동 켜기에서 빠져 꺼진 채 남는다.
            if (! $autoActivate && $updated->status === LanguagePackStatus::Installed->value) {
                $restore = [];
                if ($previousStatus === LanguagePackStatus::Inactive->value) {
                    $restore['status'] = LanguagePackStatus::Inactive->value;
                }
                if ($previousReason !== null && $updated->deactivated_reason !== $previousReason) {
                    $restore['deactivated_reason'] = $previousReason;
                }
                if ($restore !== []) {
                    $this->repository->update($updated, $restore);
                    $updated = $this->repository->findById($updated->id) ?? $updated;
                    $this->refreshCache();
                }
            }

            // 5. 백업 정리
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

            // 상태 복원 — 파일은 위에서 백업으로 원상 복원되므로 상태도 이전 상태로 되돌린다.
            // Updating: 설치 트랜잭션 이전에 실패한 경우.
            // Installed: 설치 트랜잭션이 installed 를 기록한 뒤(활성화 단계 이전/도중) 실패한 경우 —
            //   여기서 복원하지 않으면 active 였던 팩이 installed 로 방치되어 해당 로케일의
            //   백엔드 번역이 오류·로그 없이 통째로 폴백된다 (#597 보완 실측에서 유사 상태 실측).
            //   활성화가 성공적으로 끝난 뒤(active) 실패한 경우는 덮어쓰지 않는다.
            try {
                $current = $this->repository->findByIdentifier($identifier);
                $restorable = [
                    LanguagePackStatus::Updating->value,
                    LanguagePackStatus::Installed->value,
                ];
                if ($current
                    && $current->status !== $previousStatus
                    && in_array($current->status, $restorable, true)) {
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
     * @return void 반환값 없음
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
                $validationErrors[] = SafeExceptionMessage::for($e);
            }

            $existing = $this->repository->findByIdentifier($manifest['identifier'] ?? '');

            // 아이콘 규격 검사는 설치를 막지 않는다 — 경고만 보이고 화면은 유형 글리프로 떨어진다.
            $icon = app(ExtensionIconResolver::class)
                ->inspectRootForApi($packageRoot, $manifest['icon'] ?? null);

            return [
                'manifest' => $manifest,
                'icon' => $icon,
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
