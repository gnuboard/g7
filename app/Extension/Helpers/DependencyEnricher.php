<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Support\ExtensionDependencies;
use App\Support\ExtensionIntegrations;
use App\Support\LocalizedValue;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * 확장 의존성 정보 보강 헬퍼 — 의존 확장 충족 판정식의 단일 원천
 *
 * manifest 의 dependencies 필드(`{modules|plugins}.{id}: versionConstraint`) 를 받아
 * 각 의존 확장의 설치 버전, 활성 여부, 버전 제약 충족 여부를 포함한 상세 배열로 변환합니다.
 *
 * 「의존 확장이 조건을 만족하는가」는 템플릿 설치·활성화·런타임 503·목록 API, 모듈·플러그인 설치·활성화
 * (ChecksDependencyVersions), 설치 미리보기, 업데이트 경고가 모두 이 판정식({@see statusOf()})을 부릅니다.
 * 판정을 경로마다 따로 구현하던 동안 템플릿 활성화 사본만 버전 비교를 빠뜨려, 활성화 200 직후 사이트 전체가
 * 503 이 됐습니다(#152). 사유는 `not_installed` → `inactive` → `version_mismatch` 순으로 하나만 보고합니다.
 */
class DependencyEnricher
{
    /** 판정 결과 — 충족 */
    public const STATUS_MET = 'met';

    /** 판정 결과 — 설치되지 않음 */
    public const STATUS_NOT_INSTALLED = 'not_installed';

    /** 판정 결과 — 설치됐지만 꺼짐 */
    public const STATUS_INACTIVE = 'inactive';

    /** 판정 결과 — 켜졌지만 요구 버전에 못 미침 */
    public const STATUS_VERSION_MISMATCH = 'version_mismatch';

    /**
     * 의존성 배열을 상세 정보로 보강합니다.
     *
     * @param  array  $dependencies  manifest 의 dependencies 배열 (`['modules' => [...], 'plugins' => [...]]`)
     * @param  bool  $treatUpdatingAsActive  업데이트 중(`updating`)인 확장을 활성으로 볼지 — 런타임 503 판정만 true
     * @return array<int, array{identifier: string, name: string, type: string, required_version: string, installed_version: string|null, is_installed: bool, is_active: bool, is_met: bool, status: string}> 보강된 의존성 목록 (type 필드로 module/plugin 구분)
     */
    public static function enrich(array $dependencies, bool $treatUpdatingAsActive = false): array
    {
        $repositories = [
            'module' => app(ModuleRepositoryInterface::class),
            'plugin' => app(PluginRepositoryInterface::class),
        ];

        $result = [];
        foreach (self::iterate($dependencies) as $dependency) {
            $record = $repositories[$dependency['type']]->findByIdentifier($dependency['identifier']);
            $status = self::statusOf($record, $dependency['constraint'], $treatUpdatingAsActive);

            $result[] = [
                'identifier' => $dependency['identifier'],
                'name' => self::resolveName($record, $dependency['identifier'], $dependency['type']),
                'type' => $dependency['type'],
                'required_version' => $dependency['constraint'],
                'installed_version' => $record?->version,
                'is_installed' => $record !== null,
                'is_active' => self::isActiveRecord($record, $treatUpdatingAsActive),
                'is_met' => $status === self::STATUS_MET,
                'status' => $status,
            ];
        }

        return $result;
    }

    /**
     * 버전 제약 값을 정규화합니다 — `null`·빈 문자열·공백·문자열이 아닌 값은 「제약 없음(`*`)」입니다.
     *
     * @param  mixed  $constraint  manifest 의 버전 제약 값
     * @return string 정규화한 제약 (앞뒤 공백 제거)
     */
    public static function normalizeConstraint(mixed $constraint): string
    {
        return ExtensionDependencies::normalizeConstraint($constraint);
    }

    /**
     * 의존 선언을 (식별자, 종류, 버전 제약) 으로 펼칩니다. 맵형·목록형 모두 받고, 목록형의 제약은 `*` 입니다.
     *
     * @param  array<string, mixed>  $dependencies  ['modules' => [id => 제약] | [id, ...], 'plugins' => ...]
     * @return \Generator<int, array{identifier: string, type: string, constraint: string}> 펼친 의존 항목 (모듈 먼저)
     */
    public static function iterate(array $dependencies): \Generator
    {
        $constraints = ExtensionDependencies::constraints($dependencies);

        foreach (['modules' => 'module', 'plugins' => 'plugin'] as $key => $type) {
            foreach ($constraints[$key] as $identifier => $constraint) {
                yield ['identifier' => (string) $identifier, 'type' => $type, 'constraint' => $constraint];
            }
        }
    }

    /**
     * 의존 확장 레코드 하나의 충족 상태를 판정합니다 — 미설치 → 꺼짐 → 버전 미달 → 충족 순으로 첫 사유 하나.
     *
     * 꺼져 있으면서 버전도 낮으면 `inactive` 만 보고합니다(켜도 다시 막히므로 운영자가 단계적으로 알게 된다).
     *
     * @param  object|null  $record  의존 확장 모델 (Module/Plugin, 미설치면 null)
     * @param  string  $constraint  정규화한 버전 제약
     * @param  bool  $treatUpdatingAsActive  업데이트 중인 확장을 활성으로 볼지 — 런타임 503 판정만 true
     * @return string met|not_installed|inactive|version_mismatch
     */
    public static function statusOf(?object $record, string $constraint, bool $treatUpdatingAsActive = false): string
    {
        if ($record === null) {
            return self::STATUS_NOT_INSTALLED;
        }

        if (! self::isActiveRecord($record, $treatUpdatingAsActive)) {
            return self::STATUS_INACTIVE;
        }

        $installed = $record->version ?? null;

        return self::checkVersionConstraint(is_string($installed) ? $installed : null, $constraint)
            ? self::STATUS_MET
            : self::STATUS_VERSION_MISMATCH;
    }

    /**
     * 미충족 항목을 사람이 읽는 한 문장으로 잇습니다 (예: 「게시판(sirsoft-board) 버전 미달 1.2.2 → >=1.3.0, GDPR(sirsoft-gdpr) 꺼짐」).
     *
     * 사유 원문(`version_mismatch` 등)을 노출하지 않습니다. `status` 가 met 인 항목은 건너뜁니다.
     *
     * @param  array<int, array<string, mixed>>  $items  {identifier, name?, status, required_version?, installed_version?} 목록
     * @return string 쉼표로 이은 문장 (미충족이 없으면 빈 문자열)
     */
    public static function describeUnmet(array $items): string
    {
        $parts = [];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === self::STATUS_MET || $status === '') {
                continue;
            }

            $identifier = (string) ($item['identifier'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $label = $name !== '' && $name !== $identifier
                ? __('extensions.dependency_status.label', ['name' => $name, 'identifier' => $identifier])
                : $identifier;

            $parts[] = $label.' '.self::describeReason($item);
        }

        return implode(', ', $parts);
    }

    /**
     * 미충족 항목 하나의 사유를 사람이 읽는 말로 옮깁니다 (「미설치」·「꺼짐」·「버전 미달 1.2.2 → >=1.3.0」).
     *
     * @param  array<string, mixed>  $item  {status, required_version?, installed_version?}
     * @return string 사유 문구
     */
    public static function describeReason(array $item): string
    {
        return match ($item['status'] ?? null) {
            self::STATUS_VERSION_MISMATCH => __('extensions.dependency_status.version_mismatch', [
                'installed' => (string) ($item['installed_version'] ?? ''),
                'required' => (string) ($item['required_version'] ?? ''),
            ]),
            self::STATUS_INACTIVE => __('extensions.dependency_status.inactive'),
            default => __('extensions.dependency_status.not_installed'),
        };
    }

    /**
     * 레코드가 활성(또는 플래그일 때 업데이트 중)인지 판정합니다.
     *
     * @param  object|null  $record  의존 확장 모델
     * @param  bool  $treatUpdatingAsActive  업데이트 중인 확장을 활성으로 볼지
     * @return bool 활성 여부
     */
    private static function isActiveRecord(?object $record, bool $treatUpdatingAsActive): bool
    {
        if ($record === null) {
            return false;
        }

        $status = $record->status ?? null;

        return $status === ExtensionStatus::Active->value
            || ($treatUpdatingAsActive && $status === ExtensionStatus::Updating->value);
    }

    /**
     * 선택 연동 선언(`integrations`)을 화면 표시용 목록으로 보강합니다 (#639, 버전 제약 7.1.4).
     *
     * `dependencies` 와 달리 설치를 막지 않으므로, 각 연동 대상의 이름·설치·활성 여부와 요구 버전 충족 여부를 싣습니다
     * (관리자 확장 상세의 「함께 쓰면 연동되는 확장」 행, 미설치면 「미설치」, 설치됐지만 버전이 모자라면 「버전 미달」 배지).
     * `is_met` 은 「설치됨 && 버전 제약 충족」이고, 버전 제약이 없으면(`'*'`) 설치만으로 충족입니다.
     *
     * @param  mixed  $integrations  manifest 의 integrations 값 (목록형·버전 제약 객체형·정규화 결과 모두 받음)
     * @return array<int, array{identifier: string, name: string, type: string, is_installed: bool, is_active: bool, required_version: string, installed_version: string|null, is_met: bool}> 보강된 연동 목록
     */
    public static function enrichIntegrations(mixed $integrations): array
    {
        $normalized = ExtensionIntegrations::constraints($integrations);
        $repositories = [
            'modules' => [ModuleRepositoryInterface::class, 'module'],
            'plugins' => [PluginRepositoryInterface::class, 'plugin'],
            'templates' => [TemplateRepositoryInterface::class, 'template'],
        ];

        $result = [];
        foreach ($repositories as $type => [$contract, $singular]) {
            if ($normalized[$type] === []) {
                continue;
            }

            $repository = app($contract);
            foreach ($normalized[$type] as $identifier => $constraint) {
                $identifier = (string) $identifier;
                $record = $repository->findByIdentifier($identifier);
                $installedVersion = $record !== null && is_string($record->version ?? null) && $record->version !== ''
                    ? $record->version
                    : null;

                $result[] = [
                    'identifier' => $identifier,
                    'name' => self::resolveName($record, $identifier, $singular),
                    'type' => $singular,
                    'is_installed' => $record !== null,
                    'is_active' => $record !== null && ($record->status ?? null) === ExtensionStatus::Active->value,
                    'required_version' => $constraint,
                    'installed_version' => $installedVersion,
                    'is_met' => $record !== null && self::integrationVersionMet($installedVersion, $constraint),
                ];
            }
        }

        return $result;
    }

    /**
     * 연동 대상의 설치 버전이 요구 버전을 충족하는지 판정합니다. 제약이 없으면(`'*'`) 늘 충족입니다.
     *
     * @param  string|null  $installedVersion  설치 버전 (없으면 null)
     * @param  string  $constraint  요구 버전 제약
     * @return bool 충족 여부
     */
    private static function integrationVersionMet(?string $installedVersion, string $constraint): bool
    {
        if ($constraint === ExtensionIntegrations::ANY_VERSION) {
            return true;
        }

        return $installedVersion !== null && self::checkVersionConstraint($installedVersion, $constraint);
    }

    /**
     * Semver 제약 조건을 검증합니다 — 제약 없음(`*`·빈 값·null)은 늘 충족, 설치 버전이 비면 미충족, 비교 불가는 미충족(예외 없음).
     *
     * @param  string|null  $installedVersion  설치된 버전
     * @param  mixed  $versionConstraint  요구 버전 제약 (정규화 전 값도 받음)
     * @return bool 제약 충족 여부
     */
    public static function checkVersionConstraint(?string $installedVersion, mixed $versionConstraint): bool
    {
        $constraint = self::normalizeConstraint($versionConstraint);
        if ($constraint === ExtensionDependencies::ANY_VERSION) {
            return true;
        }

        if ($installedVersion === null || trim($installedVersion) === '') {
            return false;
        }

        try {
            return Semver::satisfies($installedVersion, $constraint);
        } catch (\Throwable $e) {
            Log::warning('Version constraint check failed', [
                'installed_version' => $installedVersion,
                'constraint' => $constraint,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 확장 모델에서 현재 로케일의 표시 이름을 반환합니다 (연동 안내·역조회 목록용).
     *
     * @param  object|null  $record  확장 모델 (Module/Plugin/Template)
     * @param  string  $fallback  모델이 없거나 이름이 비었을 때 쓸 식별자
     * @return string 이름
     */
    public static function displayName(?object $record, string $fallback): string
    {
        return self::resolveName($record, $fallback);
    }

    /**
     * 확장의 현재 로케일 표시 이름 — 설치 기록 이름 → 확장 폴더 manifest 이름 → 식별자 순으로 고릅니다.
     *
     * 설치 기록이 없는(미설치) 의존·연동 대상도 화면이 식별자 대신 이름을 보이도록 manifest 를 읽는다. 설치 창의
     * 「함께 쓰면 연동되는 확장」·「함께 설치」 줄, 설치 결과 연동 안내, 사이트 503 화면의 미설치 의존 이름이 이 값을 쓴다.
     *
     * @param  object|null  $record  확장 모델 (Module/Plugin/Template, 미설치면 null)
     * @param  string  $fallback  이름을 찾지 못했을 때 쓸 식별자 (manifest 를 찾을 식별자이기도 하다)
     * @param  string|null  $type  확장 종류 (module|plugin|template) — 주면 기록 이름이 없을 때 manifest 이름을 읽는다
     * @return string 이름
     */
    protected static function resolveName(?object $record, string $fallback, ?string $type = null): string
    {
        return self::recordName($record)
            ?? ($type !== null ? self::manifestName($type, $fallback) : null)
            ?? $fallback;
    }

    /**
     * 확장 모델에 저장된 이름에서 현재 로케일 값을 고릅니다.
     *
     * 빈 문자열·빈 다국어 값은 없는 것으로 본다 — 화면은 이 값을 그대로 보여 주므로 빈 칸이 된다(#736).
     *
     * @param  object|null  $record  확장 모델
     * @return string|null 이름 (없으면 null)
     */
    private static function recordName(?object $record): ?string
    {
        if (! $record) {
            return null;
        }

        $name = $record->name ?? null;

        if (is_string($name) && str_starts_with($name, '{')) {
            $decoded = json_decode($name, true);
            $name = is_array($decoded) ? $decoded : $name;
        }

        return self::pickName($name);
    }

    /**
     * 확장 폴더의 manifest 에서 현재 로케일 이름을 읽습니다 — 활성 → 대기(`_pending`) → 번들(`_bundled`) 순.
     *
     * 확장 관리자가 미설치 확장의 목록 정보를 만들 때와 같은 폴더 순서다. 읽지 못하면 null.
     *
     * @param  string  $type  확장 종류 (module|plugin|template)
     * @param  string  $identifier  확장 식별자
     * @return string|null 이름 (없으면 null)
     */
    private static function manifestName(string $type, string $identifier): ?string
    {
        [$directory, $manifestFile] = match ($type) {
            'module' => ['modules', 'module.json'],
            'plugin' => ['plugins', 'plugin.json'],
            'template' => ['templates', 'template.json'],
            default => [null, null],
        };

        // 식별자는 폴더 이름으로 쓰인다 — 경로 구분자·상위 경로가 섞인 값은 읽지 않는다
        if ($directory === null || $identifier === '' || strpbrk($identifier, '/\\') !== false || str_contains($identifier, '..')) {
            return null;
        }

        // audit:allow install-path-reads-bundled-or-template-id reason: 활성본을 먼저 읽고, 아직 설치되지 않아 활성본이 없는 확장의 표시 이름만 대기·동봉 원본에서 읽는다
        foreach (['', '_pending'.DIRECTORY_SEPARATOR, '_bundled'.DIRECTORY_SEPARATOR] as $subDirectory) {
            $path = base_path($directory.DIRECTORY_SEPARATOR.$subDirectory.$identifier.DIRECTORY_SEPARATOR.$manifestFile);
            if (! is_file($path)) {
                continue;
            }

            $manifest = json_decode((string) @file_get_contents($path), true);
            $name = is_array($manifest) ? self::pickName($manifest['name'] ?? null) : null;
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * 이름 값(문자열·다국어 배열)에서 현재 로케일 글자를 고릅니다. 빈 값이면 null.
     *
     * @param  mixed  $name  이름 값
     * @return string|null 이름
     */
    private static function pickName(mixed $name): ?string
    {
        if (is_array($name)) {
            $picked = LocalizedValue::pick($name, App::getLocale());

            return $picked !== null && trim($picked) !== '' ? $picked : null;
        }

        return is_string($name) && trim($name) !== '' ? $name : null;
    }
}
