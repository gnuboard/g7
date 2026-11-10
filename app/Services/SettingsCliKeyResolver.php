<?php

namespace App\Services;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Support\EnvPriority;
use App\Support\Settings\ComputedSettingFields;

/**
 * 터미널 설정 명령(`settings:get`·`settings:set`)의 코어 설정 키 해석.
 *
 * 키는 설정 파일과 같은 저장소 키(`범주.항목[.하위]`)로 받는다. 저장은 관리자 화면과 같은 경로를 타야 하므로
 * 범주가 화면의 어느 탭에 속하는지(탭 일괄 저장 경로 `SaveSettingsRequest`) 또는 탭이 없는지(단건 경로
 * `UpdateSettingRequest`)를 여기서 정한다. 탭 안에서 화면이 쓰는 키 이름(`frontend_key`)도 여기서 바꾼다.
 */
class SettingsCliKeyResolver
{
    /**
     * 자기 이름의 탭으로 저장되는 범주 — `SaveSettingsRequest` 의 `_tab` 허용 목록에서 `advanced` 를 뺀 것.
     * 탭을 신설하면 그쪽 목록과 함께 고친다(테스트가 두 목록의 일치를 잠근다).
     */
    public const TAB_CATEGORIES = [
        'general', 'mail', 'upload', 'seo', 'security', 'drivers', 'notifications', 'identity', 'analytics', 'privacy', 'content',
    ];

    /** 여러 범주를 한 폼에 모아 저장하는 탭 */
    public const ADVANCED_TAB = 'advanced';

    /** 스키마가 `merge_into` 를 선언하지 않아도 고급 탭이 저장해 온 범주 (`SettingsService::buildAdvancedCategoryFieldMap` 과 같은 기본 목록) */
    private const LEGACY_ADVANCED_CATEGORIES = ['cache', 'debug'];

    /**
     * 스키마 표시가 없어도 민감 값으로 다루는 항목 이름 (비밀번호·비밀 키·토큰).
     *
     * 하이픈으로 이어 붙인 동작 이름(권한 식별자 `posts.read-secret` = 비밀글 읽기)은 값의 성격이 아니라
     * 대상을 가리키는 이름이라 제외한다 — 설정 항목 이름은 `client_secret`·`smtp_password` 처럼 밑줄로 잇는다.
     */
    private const SENSITIVE_NAME_PATTERN = '/(?<!-)(password|secret|token|license_key|access_key|secret_key)$/';

    /**
     * @param  ConfigRepositoryInterface  $configRepository  설정 저장소 (기본값·화면 스키마)
     */
    public function __construct(
        private readonly ConfigRepositoryInterface $configRepository,
    ) {}

    /**
     * 저장소 키를 저장 경로 정보로 해석한다.
     *
     * @param  string  $key  저장소 키 (예: `mail.host`, `identity.purpose_providers.login`)
     * @return array{category: string, field: string, rest: string|null, tab: string|null, frontend_field: string, known: bool}|null
     *                                                                                                                               해석 결과 (범주·항목이 없으면 null)
     */
    public function resolve(string $key): ?array
    {
        $parts = explode('.', $key, 3);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$category, $field] = $parts;
        $rest = $parts[2] ?? null;

        $defaults = $this->configRepository->getDefaults();
        $schemaFields = $this->schemaFields($category);

        return [
            'category' => $category,
            'field' => $field,
            'rest' => $rest,
            'tab' => $this->tabOf($category),
            'frontend_field' => (string) ($schemaFields[$field]['frontend_key'] ?? $field),
            'known' => is_array($defaults[$category] ?? null) && array_key_exists($field, $defaults[$category]),
        ];
    }

    /**
     * 범주가 저장되는 탭 (탭이 없으면 null — 단건 경로).
     *
     * @param  string  $category  범주
     * @return string|null 탭 이름
     */
    public function tabOf(string $category): ?string
    {
        if (in_array($category, self::TAB_CATEGORIES, true)) {
            return $category;
        }

        $schema = $this->configRepository->getFrontendSchema()[$category] ?? [];
        if (($schema['merge_into'] ?? $schema['frontend_name'] ?? null) === self::ADVANCED_TAB
            || in_array($category, self::LEGACY_ADVANCED_CATEGORIES, true)) {
            return self::ADVANCED_TAB;
        }

        return null;
    }

    /**
     * 민감 값인지 — 화면 스키마의 `sensitive`, `.env` 민감 키, 또는 이름(비밀번호·비밀 키·토큰).
     *
     * @param  string  $category  범주
     * @param  string  $field  항목
     * @return bool 민감 값이면 true
     */
    public function isSensitive(string $category, string $field): bool
    {
        return ! empty($this->schemaFields($category)[$field]['sensitive'])
            || EnvPriority::isSensitive($category.'.'.$field)
            || self::isSensitiveName($field);
    }

    /**
     * 이름만으로 민감 값인지 (스키마 표시가 없는 항목·하위 키용 — 터미널 조회·지정이 같은 규칙을 쓴다).
     *
     * @param  string  $name  항목 이름 (점 표기 키의 마지막 조각)
     * @return bool 민감 값이면 true
     */
    public static function isSensitiveName(string $name): bool
    {
        return preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1;
    }

    /**
     * 다른 값에서 계산되는 항목인지 (화면 스키마의 `computed`).
     *
     * @param  string  $category  범주
     * @param  string  $field  항목
     * @return bool 계산 항목이면 true
     */
    public function isComputed(string $category, string $field): bool
    {
        return ComputedSettingFields::isComputed($this->schemaFields($category)[$field] ?? null);
    }

    /**
     * 범주의 계산 항목 — 「항목 => 화면 키」. 판정은 관리자 설정 저장과 같은 `ComputedSettingFields` 한 곳.
     *
     * @param  string  $category  범주
     * @return array<string, string> 계산 항목
     */
    public function computedFields(string $category): array
    {
        return ComputedSettingFields::frontendKeys(['fields' => $this->schemaFields($category)]);
    }

    /**
     * 설정 범주 목록 (기본값 선언 순서).
     *
     * @return array<int, string> 범주 이름
     */
    public function categories(): array
    {
        return array_values(array_filter(
            array_keys($this->configRepository->getDefaults()),
            static fn ($category) => is_string($category) && ! str_starts_with($category, '_'),
        ));
    }

    /**
     * 범주의 화면 스키마 항목.
     *
     * @param  string  $category  범주
     * @return array<string, array<string, mixed>> 항목 스키마
     */
    private function schemaFields(string $category): array
    {
        $fields = $this->configRepository->getFrontendSchema()[$category]['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }
}
