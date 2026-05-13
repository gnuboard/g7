<?php

namespace App\Http\Resources;

use App\Enums\LanguagePackOrigin;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * 언어팩 단건 API 리소스.
 */
class LanguagePackResource extends BaseApiResource
{
    /**
     * 언어팩을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 언어팩 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'identifier' => $this->getValue('identifier'),
            'vendor' => $this->getValue('vendor'),
            'scope' => $this->getValue('scope'),
            'target_identifier' => $this->getValue('target_identifier'),
            'locale' => $this->getValue('locale'),
            'locale_name' => $this->getValue('locale_name'),
            'locale_native_name' => $this->getValue('locale_native_name'),
            'text_direction' => $this->getValue('text_direction'),
            'version' => $this->getValue('version'),
            'latest_version' => $this->getValue('latest_version'),
            'target_version_constraint' => $this->getValue('target_version_constraint'),
            'target_version_mismatch' => (bool) $this->getValue('target_version_mismatch', false),
            'name' => $this->resolveLocalizedManifestField('name'),
            'license' => $this->getValue('license'),
            'description' => $this->getLocalizedField('description'),
            'status' => $this->getValue('status'),
            'is_protected' => (bool) $this->getValue('is_protected', false),
            'source_type' => $this->getValue('source_type'),
            'origin' => LanguagePackOrigin::fromSourceTypeValue($this->getValue('source_type'))?->value,
            'source_url' => $this->getValue('source_url'),
            'github_url' => $this->resolveManifestField('github_url'),
            'github_changelog_url' => $this->resolveManifestField('github_changelog_url'),
            'bundled_identifier' => $this->getValue('bundled_identifier'),
            'install_blocked_reason' => $this->getValue('install_blocked_reason'),
            'target_name' => $this->resolveTargetName(),
            'installed_at' => $this->formatTimestamp('installed_at'),
            'activated_at' => $this->formatTimestamp('activated_at'),
            'created_at' => $this->formatTimestamp('created_at'),
            'updated_at' => $this->formatTimestamp('updated_at'),
            'has_update' => $this->resolveHasUpdate(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 매니페스트 스냅샷의 다국어 필드를 현재 로케일 기준으로 해석합니다.
     *
     * 모듈/플러그인의 `name` 컬럼이 다국어 JSON 인 패턴과 일관 — 현재 로케일 → fallback locale → ko → 첫 값.
     * 매니페스트에 단일 문자열로 들어있으면 그대로 반환 (구버전 호환).
     *
     * @param  string  $key  매니페스트 최상위 키 (예: 'name', 'description')
     * @return string|null 해석된 문자열 또는 null
     */
    private function resolveLocalizedManifestField(string $key): ?string
    {
        $manifest = $this->getValue('manifest');
        if (! is_array($manifest) || ! array_key_exists($key, $manifest)) {
            return null;
        }

        $value = $manifest[$key];

        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $locale = \Illuminate\Support\Facades\App::getLocale();
        $fallback = (string) config('app.fallback_locale', 'ko');

        foreach ([$locale, $fallback, 'ko', 'en'] as $candidate) {
            if (isset($value[$candidate]) && is_string($value[$candidate]) && $value[$candidate] !== '') {
                return $value[$candidate];
            }
        }

        $first = reset($value);

        return is_string($first) && $first !== '' ? $first : null;
    }

    /**
     * 매니페스트 스냅샷에서 단일 문자열 필드를 추출합니다.
     *
     * 모듈/플러그인/템플릿의 `github_url` 노출 패턴(매니페스트 SSoT)과 일관되게,
     * 언어팩도 DB `manifest` JSON 컬럼에 저장된 매니페스트에서 직접 읽습니다.
     *
     * @param  string  $key  매니페스트 최상위 키
     * @return string|null 문자열 값 또는 null
     */
    private function resolveManifestField(string $key): ?string
    {
        $manifest = $this->getValue('manifest');
        if (! is_array($manifest)) {
            return null;
        }

        $value = $manifest[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * 업데이트 가능 여부를 계산합니다 (latest_version > version).
     *
     * @return bool 업데이트 가능 시 true
     */
    private function resolveHasUpdate(): bool
    {
        $latest = $this->getValue('latest_version');
        $current = $this->getValue('version');

        if (! $latest || ! $current) {
            return false;
        }

        return version_compare((string) $latest, (string) $current, '>');
    }

    /**
     * 상세(manifest 포함) 응답을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> manifest 가 포함된 상세 응답
     */
    public function toDetailArray(Request $request): array
    {
        $manifest = $this->getValue('manifest');
        $resource = $this->resource;
        $directoryPath = null;
        $changelogEntries = [];

        if ($resource && method_exists($resource, 'resolveDirectory')) {
            try {
                $directory = $resource->resolveDirectory();
                $directoryPath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $directory);
                $changelogPath = $directory.DIRECTORY_SEPARATOR.'CHANGELOG.md';
                if (is_file($changelogPath)) {
                    $changelogEntries = \App\Extension\Helpers\ChangelogParser::parse($changelogPath);
                }
            } catch (\Throwable $e) {
                $directoryPath = null;
            }
        }

        return array_merge($this->toArray($request), [
            'manifest' => $manifest,
            'validation_summary' => [
                'target_version_mismatch' => (bool) $this->getValue('target_version_mismatch', false),
                'depends_on_core_locale' => is_array($manifest)
                    ? ($manifest['requires']['depends_on_core_locale'] ?? null)
                    : null,
            ],
            'source_meta' => [
                'type' => $this->getValue('source_type'),
                'url' => $this->getValue('source_url'),
                'installed_by' => $this->getValue('installed_by'),
                'latest_version' => $this->getValue('latest_version'),
                'directory_path' => $directoryPath,
            ],
            'changelog_entries' => $changelogEntries,
        ]);
    }

    /**
     * 리소스 권한 매핑.
     *
     * 미설치 번들 가상 행(`status === uninstalled`)은 활성/비활성/제거 액션이
     * 무의미하므로 `can_install` 만 노출합니다. 모듈/플러그인 행 액션과 동일 패턴.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        if ($this->getValue('status') === 'uninstalled') {
            return [
                'can_install' => 'core.language_packs.install',
            ];
        }

        return [
            'can_activate' => 'core.language_packs.manage',
            'can_deactivate' => 'core.language_packs.manage',
            'can_uninstall' => 'core.language_packs.manage',
        ];
    }

    /**
     * resourceMeta 후처리 — 의존성 미충족 시 can_install 을 false 로 강제.
     *
     * 권한이 있어도 코어 locale/대상 확장 의존성이 미충족이면 설치할 수 없으므로,
     * UI 가 단일 플래그로 행/모달 버튼 disabled 상태를 결정할 수 있도록 정렬합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 권한 메타
     */
    protected function resourceMeta(Request $request): array
    {
        $meta = parent::resourceMeta($request);
        $blocked = $this->getValue('install_blocked_reason');
        if ($blocked && isset($meta['abilities']['can_install'])) {
            $meta['abilities']['can_install'] = false;
        }

        return $meta;
    }

    /**
     * 대상 확장의 현재 로케일 이름을 반환합니다.
     *
     * 모듈/플러그인/템플릿의 `name` 컬럼은 다국어 JSON 텍스트입니다 — 활성 행을 조회하여
     * 현재 로케일 키, ko, en, 첫 값 순서로 폴백합니다. 코어/타깃 없음 행은 null.
     *
     * @return string|null 다국어 명칭 또는 null
     */
    private function resolveTargetName(): ?string
    {
        $scope = $this->getValue('scope');
        $target = $this->getValue('target_identifier');
        if (! $target) {
            return null;
        }

        $tableMap = [
            'module' => 'modules',
            'plugin' => 'plugins',
            'template' => 'templates',
        ];
        $table = $tableMap[$scope] ?? null;
        if (! $table) {
            return null;
        }

        $row = \Illuminate\Support\Facades\DB::table($table)
            ->where('identifier', $target)
            ->first(['name']);
        if (! $row || ! $row->name) {
            return null;
        }

        $decoded = is_string($row->name) ? json_decode($row->name, true) : $row->name;
        if (! is_array($decoded)) {
            return is_string($row->name) ? $row->name : null;
        }

        $locale = \Illuminate\Support\Facades\App::getLocale();

        return $decoded[$locale] ?? $decoded[config('app.fallback_locale', 'ko')] ?? (reset($decoded) ?: null);
    }

    /**
     * 타임스탬프 컬럼을 사용자 타임존 기준 문자열로 변환합니다.
     *
     * @param  string  $key  컬럼 키
     * @return string|null 포맷된 문자열 또는 null
     */
    private function formatTimestamp(string $key): ?string
    {
        $value = $this->getValue($key);
        if (! $value) {
            return null;
        }

        return TimezoneHelper::toUserDateTimeString(Carbon::parse($value));
    }
}
