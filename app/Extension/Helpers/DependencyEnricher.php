<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Support\ExtensionIntegrations;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\App;

/**
 * 확장 의존성 정보 보강 헬퍼
 *
 * manifest 의 dependencies 필드(`{modules|plugins}.{id}: versionConstraint`) 를 받아
 * 각 의존 확장의 설치 버전, 활성 여부, 버전 제약 충족 여부를 포함한 상세 배열로 변환합니다.
 * ModuleManager / PluginManager / TemplateResource 가 공용으로 사용합니다.
 */
class DependencyEnricher
{
    /**
     * 의존성 배열을 상세 정보로 보강합니다.
     *
     * @param  array  $dependencies  manifest 의 dependencies 배열 (`['modules' => [...], 'plugins' => [...]]`)
     * @return array<int, array<string, mixed>> 보강된 의존성 목록 (type 필드로 module/plugin 구분)
     */
    public static function enrich(array $dependencies): array
    {
        $result = [];

        foreach (self::enrichByType($dependencies, 'modules') as $item) {
            $result[] = $item;
        }

        foreach (self::enrichByType($dependencies, 'plugins') as $item) {
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 선택 연동 선언(`integrations`)을 화면 표시용 목록으로 보강합니다 (#639).
     *
     * `dependencies` 와 달리 버전 제약이 없고 설치를 막지 않으므로, 각 연동 대상의 이름과 설치·활성 여부만 싣습니다
     * (관리자 확장 상세의 「함께 쓰면 연동되는 확장」 행, 미설치면 「미설치」 배지).
     *
     * @param  mixed  $integrations  manifest 의 integrations 값 (정규화 전·후 모두 받음)
     * @return array<int, array{identifier: string, name: string, type: string, is_installed: bool, is_active: bool}> 보강된 연동 목록
     */
    public static function enrichIntegrations(mixed $integrations): array
    {
        $normalized = ExtensionIntegrations::normalize($integrations);
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
            foreach ($normalized[$type] as $identifier) {
                $record = $repository->findByIdentifier($identifier);

                $result[] = [
                    'identifier' => $identifier,
                    'name' => self::resolveName($record, $identifier),
                    'type' => $singular,
                    'is_installed' => $record !== null,
                    'is_active' => $record !== null && ($record->status ?? null) === ExtensionStatus::Active->value,
                ];
            }
        }

        return $result;
    }

    /**
     * 특정 타입(modules/plugins)의 의존성만 보강합니다.
     *
     * @param  array  $dependencies  manifest 의 dependencies 배열
     * @param  string  $type  'modules' 또는 'plugins'
     * @return array<int, array<string, mixed>> 보강된 의존성 목록
     */
    protected static function enrichByType(array $dependencies, string $type): array
    {
        if (! isset($dependencies[$type]) || ! is_array($dependencies[$type])) {
            return [];
        }

        $singular = $type === 'modules' ? 'module' : 'plugin';
        $repository = $type === 'modules'
            ? app(ModuleRepositoryInterface::class)
            : app(PluginRepositoryInterface::class);

        $result = [];

        foreach ($dependencies[$type] as $identifier => $versionConstraint) {
            $record = $repository->findByIdentifier($identifier);
            $activeRecord = $repository->findActiveByIdentifier($identifier);

            $installedVersion = $record?->version;
            $isActive = (bool) $activeRecord;
            $isMet = $isActive
                && $installedVersion
                && self::checkVersionConstraint($installedVersion, (string) $versionConstraint);

            $result[] = [
                'identifier' => $identifier,
                'name' => self::resolveName($record, $identifier),
                'type' => $singular,
                'required_version' => $versionConstraint,
                'installed_version' => $installedVersion,
                'is_active' => $isActive,
                'is_met' => $isMet,
            ];
        }

        return $result;
    }

    /**
     * Semver 제약 조건을 검증합니다.
     *
     * @param  string  $installedVersion  설치된 버전
     * @param  string  $versionConstraint  요구 버전 제약
     * @return bool 제약 충족 여부
     */
    public static function checkVersionConstraint(string $installedVersion, string $versionConstraint): bool
    {
        try {
            return Semver::satisfies($installedVersion, $versionConstraint);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 확장 모델에서 현재 로케일의 이름을 추출합니다.
     *
     * @param  object|null  $record  확장 모델 (Module/Plugin)
     * @param  string  $fallback  모델이 없을 때 반환할 식별자
     * @return string 이름
     */
    protected static function resolveName(?object $record, string $fallback): string
    {
        if (! $record) {
            return $fallback;
        }

        $name = $record->name ?? null;

        if (is_array($name)) {
            $locale = App::getLocale();
            $fallbackLocale = config('app.fallback_locale', 'ko');

            return (string) ($name[$locale] ?? $name[$fallbackLocale] ?? reset($name) ?: $fallback);
        }

        if (is_string($name) && str_starts_with($name, '{')) {
            $decoded = json_decode($name, true);
            if (is_array($decoded)) {
                $locale = App::getLocale();
                $fallbackLocale = config('app.fallback_locale', 'ko');

                return (string) ($decoded[$locale] ?? $decoded[$fallbackLocale] ?? reset($decoded) ?: $fallback);
            }
        }

        // 빈 문자열 이름도 식별자로 대체한다 — 화면은 이 값을 그대로 보여 주므로 빈 칸이 된다(#736 O-F10).
        return is_string($name) && $name !== '' ? $name : $fallback;
    }
}
