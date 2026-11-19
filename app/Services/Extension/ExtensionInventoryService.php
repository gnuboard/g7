<?php

namespace App\Services\Extension;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\TemplateManager;
use App\Support\CoreVersionDiskReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 설치·번들 확장의 상태를 한 번에 읽습니다 — `php artisan extension:list` 가 부른다.
 *
 * 비대화형 실행(cron·스크립트)이 「이 확장이 설치·활성인가, 판은 몇인가」를 묻는 창구다. DB 행과 활성 폴더·번들 폴더의
 * 매니페스트를 **읽기만** 한다 — DB·캐시·파일 쓰기가 없고 GitHub 도 조회하지 않는다. `update_available` 은 DB 에 저장된
 * 값(마지막 업데이트 확인 결과)을 그대로 싣는다.
 *
 * 출력 형식은 `schema` 번호로 고정한다. 키를 바꾸거나 빼면 {@see self::SCHEMA} 를 올린다.
 *
 * @since 7.2.0
 */
class ExtensionInventoryService
{
    /** 출력 형식 번호 — 키를 바꾸면 올린다 */
    public const SCHEMA = 1;

    /** 조회할 수 있는 종류 (`all` 은 전부) */
    public const TYPES = ['module', 'plugin', 'template', 'language-pack'];

    /** 종류별 디렉토리·매니페스트 파일명 */
    private const LAYOUT = [
        'module' => ['dir' => 'modules', 'manifest' => 'module.json'],
        'plugin' => ['dir' => 'plugins', 'manifest' => 'plugin.json'],
        'template' => ['dir' => 'templates', 'manifest' => 'template.json'],
        'language-pack' => ['dir' => 'lang-packs', 'manifest' => 'language-pack.json'],
    ];

    /** 종류별 DB 테이블 — 하나라도 없으면 설치 전으로 본다 */
    private const TABLES = ['modules', 'plugins', 'templates', 'language_packs'];

    /**
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 저장소
     * @param  PluginRepositoryInterface  $pluginRepository  플러그인 저장소
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소
     * @param  LanguagePackRepositoryInterface  $languagePackRepository  언어팩 저장소
     * @param  TemplateManager  $templateManager  템플릿 관리자 (운영자가 고친 레이아웃 수)
     */
    public function __construct(
        private ModuleRepositoryInterface $moduleRepository,
        private PluginRepositoryInterface $pluginRepository,
        private TemplateRepositoryInterface $templateRepository,
        private LanguagePackRepositoryInterface $languagePackRepository,
        private TemplateManager $templateManager,
    ) {}

    /**
     * 확장 테이블이 있는지(그누보드7 이 설치됐는지) 답합니다.
     *
     * DB 에 닿지 못해도(설정 전·접속 실패) 설치 전으로 본다.
     *
     * @return bool 확장 테이블이 모두 있으면 true
     */
    public function isInstalled(): bool
    {
        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 설치·번들 확장 목록을 만듭니다.
     *
     * 설치된 확장(DB 행)과, 번들에만 있고 설치되지 않은 확장(`status: not_installed`)을 함께 싣는다. 종류 → 식별자 순으로 정렬한다.
     *
     * @param  string|null  $type  종류 (`module`·`plugin`·`template`·`language-pack`, null 이면 전부)
     * @return array{schema: int, core_version: string|null, core_version_on_disk: string|null, items: list<array<string, mixed>>} 목록
     */
    public function snapshot(?string $type = null): array
    {
        $items = [];

        foreach ($type === null ? self::TYPES : [$type] as $kind) {
            array_push($items, ...$this->itemsOf($kind));
        }

        return [
            'schema' => self::SCHEMA,
            'core_version' => is_string(config('app.version')) ? (string) config('app.version') : null,
            'core_version_on_disk' => CoreVersionDiskReader::shippedVersion(),
            'items' => $items,
        ];
    }

    /**
     * 한 종류의 항목을 만듭니다.
     *
     * @param  string  $type  종류
     * @return list<array<string, mixed>> 항목 (식별자 순)
     */
    private function itemsOf(string $type): array
    {
        $root = base_path(self::LAYOUT[$type]['dir']);
        $manifestName = self::LAYOUT[$type]['manifest'];
        $bundled = ExtensionPendingHelper::loadBundledExtensions($root, $manifestName);
        $items = [];

        foreach ($this->records($type) as $record) {
            $identifier = (string) $record->identifier;
            $items[$identifier] = $this->item($type, $identifier, $record, $bundled[$identifier] ?? null, $root, $manifestName);
        }

        foreach ($bundled as $identifier => $manifest) {
            $identifier = (string) $identifier;
            if (! isset($items[$identifier])) {
                $items[$identifier] = $this->item($type, $identifier, null, $manifest, $root, $manifestName);
            }
        }

        ksort($items, SORT_STRING);

        return array_values($items);
    }

    /**
     * 종류의 DB 행을 읽습니다.
     *
     * @param  string  $type  종류
     * @return iterable<int|string, object> DB 행
     */
    private function records(string $type): iterable
    {
        return match ($type) {
            'module' => $this->moduleRepository->getAllKeyedByIdentifier(),
            'plugin' => $this->pluginRepository->getAllKeyedByIdentifier(),
            'template' => $this->templateRepository->getAllKeyedByIdentifier(),
            'language-pack' => $this->languagePackRepository->allForUpdateCheck(),
        };
    }

    /**
     * 항목 하나를 만듭니다.
     *
     * @param  string  $type  종류
     * @param  string  $identifier  식별자
     * @param  object|null  $record  DB 행 (설치되지 않았으면 null)
     * @param  array<string, mixed>|null  $bundledManifest  번들 매니페스트 (없으면 null)
     * @param  string  $root  종류 디렉토리 절대 경로
     * @param  string  $manifestName  매니페스트 파일명
     * @return array{identifier: string, type: string, template_type: string|null, status: string, version: string|null, active_dir_version: string|null, bundled_version: string|null, update_available: bool|null, vendor_mode: string|null, layout_modified_count: int|null} 항목
     */
    private function item(string $type, string $identifier, ?object $record, ?array $bundledManifest, string $root, string $manifestName): array
    {
        $activeManifest = $this->readManifest($root.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.$manifestName);

        return [
            'identifier' => $identifier,
            'type' => $type,
            'template_type' => $type === 'template' ? $this->templateType($record, $activeManifest, $bundledManifest) : null,
            'status' => $record !== null ? $this->statusOf($record) : 'not_installed',
            'version' => $record !== null && $record->version !== null ? (string) $record->version : null,
            'active_dir_version' => $this->versionOf($activeManifest),
            'bundled_version' => $this->versionOf($bundledManifest),
            'update_available' => $record !== null ? $this->storedUpdateAvailable($type, $record) : null,
            'vendor_mode' => in_array($type, ['module', 'plugin'], true) && $record !== null && $record->vendor_mode !== null ? (string) $record->vendor_mode : null,
            'layout_modified_count' => $type === 'template' && $record !== null
                ? (int) ($this->templateManager->hasModifiedLayouts($identifier)['modified_count'] ?? 0)
                : null,
        ];
    }

    /**
     * DB 행의 상태 문자열.
     *
     * @param  object  $record  DB 행
     * @return string 상태 (enum 이면 그 값)
     */
    private function statusOf(object $record): string
    {
        $status = $record->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    /**
     * DB 에 저장된 업데이트 가능 여부 — 다시 확인하지 않는다.
     *
     * 언어팩은 `update_available` 열이 없어 저장된 `latest_version` 이 설치 판보다 높은지로 답한다(역시 DB 값만 쓴다).
     *
     * @param  string  $type  종류
     * @param  object  $record  DB 행
     * @return bool 업데이트 가능 여부
     */
    private function storedUpdateAvailable(string $type, object $record): bool
    {
        if ($type !== 'language-pack') {
            return (bool) $record->update_available;
        }

        $latest = $record->latest_version;

        return is_string($latest) && $latest !== '' && is_string($record->version) && version_compare($latest, $record->version, '>');
    }

    /**
     * 템플릿 종류(admin·user) — DB 행, 활성 매니페스트, 번들 매니페스트 순으로 읽는다.
     *
     * @param  object|null  $record  DB 행
     * @param  array<string, mixed>|null  $activeManifest  활성 매니페스트
     * @param  array<string, mixed>|null  $bundledManifest  번들 매니페스트
     * @return string|null 템플릿 종류
     */
    private function templateType(?object $record, ?array $activeManifest, ?array $bundledManifest): ?string
    {
        $type = $record?->type ?? ($activeManifest['type'] ?? ($bundledManifest['type'] ?? null));

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * 매니페스트의 판 번호.
     *
     * @param  array<string, mixed>|null  $manifest  매니페스트
     * @return string|null 판 번호 (없으면 null)
     */
    private function versionOf(?array $manifest): ?string
    {
        $version = $manifest['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * 매니페스트 파일을 읽습니다.
     *
     * @param  string  $path  매니페스트 경로
     * @return array<string, mixed>|null 매니페스트 (없거나 읽지 못하면 null)
     */
    private function readManifest(string $path): ?array
    {
        if (! File::isFile($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);

        return is_array($data) ? $data : null;
    }
}
