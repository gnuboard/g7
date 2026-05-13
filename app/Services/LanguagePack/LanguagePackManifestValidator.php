<?php

namespace App\Services\LanguagePack;

use App\Enums\LanguagePackScope;
use Illuminate\Validation\ValidationException;

/**
 * 언어팩 manifest (language-pack.json) 검증 클래스.
 *
 * 검증 규칙은 계획서 §5 의 "검증 규칙" 섹션을 따릅니다.
 *  - identifier 첫 segment 가 namespace 필드와 일치 (모든 번들에 공통인 prefix — vendor 는 제작자)
 *  - identifier 가 네이밍 공식({namespace}-{scope}-{target?}-{locale}) 부합
 *  - vendor 는 모듈/플러그인 매니페스트와 동일하게 제작자 식별자 (kebab-case 영문)
 *  - namespace 는 예약어(core/module/plugin/template) 또는 숫자 시작 금지
 *  - scope=core 인데 target_identifier 지정 금지
 *  - scope ∈ {module, plugin, template} 이고 target_identifier 미지정 금지
 *  - locale 이 IETF BCP-47 태그 형식
 *  - g7_version 은 top-level (모듈/플러그인/템플릿 매니페스트와 동일한 위치)
 *  - requires.target_version semver 제약 형식
 */
class LanguagePackManifestValidator
{
    /**
     * 예약어 namespace 목록 (identifier 의 첫 segment 로 사용 금지).
     *
     * @var array<int, string>
     */
    private const RESERVED_NAMESPACES = ['core', 'module', 'plugin', 'template'];

    /**
     * IETF BCP-47 단순 검증 패턴 (대소문자 혼용 + 하이픈 서브태그).
     *
     * 완전한 BCP-47 파서는 아니지만 G7 에서 다루는 일반적 로케일(ko, en, ja, zh-CN, pt-BR)을 수용합니다.
     */
    private const BCP47_PATTERN = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-[a-zA-Z0-9]{5,8})?$/';

    /**
     * 식별자 패턴 (kebab-case, 영문 시작, 숫자/언더스코어 금지) — namespace 와 vendor 양쪽에 동일 적용.
     */
    private const KEBAB_IDENTIFIER_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /**
     * manifest 배열을 검증합니다.
     *
     * 검증 실패 시 ValidationException 을 throw 합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  string|null  $packageRoot  파일 존재 검증을 위한 패키지 루트 디렉토리 (옵션, 미사용 — 호환을 위해 시그니처 유지)
     * @return void
     *
     * @throws ValidationException 검증 실패 시
     */
    public function validate(array $manifest, ?string $packageRoot = null): void
    {
        $errors = [];

        $this->validateRequiredKeys($manifest, $errors);
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $this->validateNamespace($manifest, $errors);
        $this->validateVendor($manifest, $errors);
        $this->validateScopeAndTarget($manifest, $errors);
        $this->validateLocale($manifest, $errors);
        $this->validateIdentifierNaming($manifest, $errors);
        $this->validateG7Version($manifest, $errors);
        $this->validateRequires($manifest, $errors);

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 필수 키 존재 여부를 검증합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateRequiredKeys(array $manifest, array &$errors): void
    {
        $required = ['identifier', 'namespace', 'vendor', 'name', 'version', 'scope', 'locale', 'locale_native_name'];

        foreach ($required as $key) {
            if (! array_key_exists($key, $manifest) || $manifest[$key] === '' || $manifest[$key] === null) {
                $errors['manifest.'.$key][] = sprintf('필수 필드 "%s" 가 누락되었습니다.', $key);
            }
        }
    }

    /**
     * namespace 필드를 검증합니다 (identifier 의 첫 segment 와 일치 — 번들 prefix).
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateNamespace(array $manifest, array &$errors): void
    {
        $namespace = (string) ($manifest['namespace'] ?? '');

        if (! preg_match(self::KEBAB_IDENTIFIER_PATTERN, $namespace)) {
            $errors['manifest.namespace'][] = 'namespace 는 영문 소문자로 시작하는 kebab-case 형식이어야 합니다.';
        }

        if (in_array($namespace, self::RESERVED_NAMESPACES, true)) {
            $errors['manifest.namespace'][] = sprintf('namespace "%s" 는 예약어이므로 사용할 수 없습니다.', $namespace);
        }
    }

    /**
     * vendor 필드(제작자)를 검증합니다 — 모듈/플러그인 매니페스트와 동일한 의미.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateVendor(array $manifest, array &$errors): void
    {
        $vendor = (string) ($manifest['vendor'] ?? '');

        if (! preg_match(self::KEBAB_IDENTIFIER_PATTERN, $vendor)) {
            $errors['manifest.vendor'][] = 'vendor 는 영문 소문자로 시작하는 kebab-case 형식이어야 합니다.';
        }
    }

    /**
     * scope 와 target_identifier 정합성을 검증합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateScopeAndTarget(array $manifest, array &$errors): void
    {
        $scope = (string) ($manifest['scope'] ?? '');

        if (! LanguagePackScope::isValid($scope)) {
            $errors['manifest.scope'][] = sprintf(
                'scope 는 %s 중 하나여야 합니다.',
                implode(', ', LanguagePackScope::values())
            );

            return;
        }

        $scopeEnum = LanguagePackScope::from($scope);
        $target = $manifest['target_identifier'] ?? null;

        if ($scopeEnum->isCore() && $target !== null) {
            $errors['manifest.target_identifier'][] = 'scope=core 인 경우 target_identifier 는 null 이어야 합니다.';
        }

        if ($scopeEnum->requiresTarget() && (empty($target) || ! is_string($target))) {
            $errors['manifest.target_identifier'][] = sprintf(
                'scope=%s 인 경우 target_identifier 가 필수입니다.',
                $scope
            );
        }
    }

    /**
     * locale 형식을 검증합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateLocale(array $manifest, array &$errors): void
    {
        $locale = (string) ($manifest['locale'] ?? '');

        if (! preg_match(self::BCP47_PATTERN, $locale)) {
            $errors['manifest.locale'][] = sprintf(
                'locale "%s" 는 IETF BCP-47 형식이 아닙니다 (예: ko, en, ja, zh-CN).',
                $locale
            );
        }
    }

    /**
     * identifier 의 네이밍 공식을 검증합니다.
     *
     * 공식 ({namespace} 는 manifest.namespace 필드 — 모든 G7 공식 번들의 공통 prefix `g7`):
     *  - scope=core     : {namespace}-core-{locale}
     *  - scope=module   : {namespace}-module-{target}-{locale}
     *  - scope=plugin   : {namespace}-plugin-{target}-{locale}
     *  - scope=template : {namespace}-template-{target}-{locale}
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateIdentifierNaming(array $manifest, array &$errors): void
    {
        $identifier = (string) ($manifest['identifier'] ?? '');
        $namespace = (string) ($manifest['namespace'] ?? '');
        $scope = (string) ($manifest['scope'] ?? '');
        $locale = (string) ($manifest['locale'] ?? '');
        $target = $manifest['target_identifier'] ?? null;

        if (! str_starts_with($identifier, $namespace.'-')) {
            $errors['manifest.identifier'][] = sprintf(
                'identifier "%s" 는 namespace "%s" 로 시작해야 합니다.',
                $identifier,
                $namespace
            );

            return;
        }

        $expected = match ($scope) {
            LanguagePackScope::Core->value => sprintf('%s-core-%s', $namespace, $locale),
            LanguagePackScope::Module->value, LanguagePackScope::Plugin->value, LanguagePackScope::Template->value
                => sprintf('%s-%s-%s-%s', $namespace, $scope, (string) $target, $locale),
            default => null,
        };

        if ($expected !== null && $identifier !== $expected) {
            $errors['manifest.identifier'][] = sprintf(
                'identifier 는 네이밍 공식상 "%s" 여야 합니다 (현재 "%s").',
                $expected,
                $identifier
            );
        }
    }

    /**
     * top-level g7_version semver 제약을 검증합니다 (모듈/플러그인/템플릿 매니페스트와 동일한 위치).
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateG7Version(array $manifest, array &$errors): void
    {
        $g7 = $manifest['g7_version'] ?? null;
        if ($g7 !== null && $g7 !== '' && ! $this->isSemverConstraint((string) $g7)) {
            $errors['manifest.g7_version'][] = 'g7_version 제약이 올바른 semver 형식이 아닙니다.';
        }
    }

    /**
     * requires 섹션을 검증합니다 (target_version, depends_on_core_locale).
     *
     * g7_version 은 top-level 로 분리됨 (validateG7Version 참조) — 모듈/플러그인/템플릿 매니페스트와 일관.
     *
     * @param  array<string, mixed>  $manifest  manifest 데이터
     * @param  array<string, array<int, string>>  $errors  에러 누적 배열
     * @return void
     */
    private function validateRequires(array $manifest, array &$errors): void
    {
        $requires = $manifest['requires'] ?? [];

        if (! is_array($requires)) {
            $errors['manifest.requires'][] = 'requires 는 객체여야 합니다.';

            return;
        }

        $target = $requires['target_version'] ?? null;
        if ($target !== null && $target !== '' && ! $this->isSemverConstraint((string) $target)) {
            $errors['manifest.requires.target_version'][] = 'target_version 제약이 올바른 semver 형식이 아닙니다.';
        }
    }

    /**
     * Composer 스타일 semver 제약의 단순 검증.
     *
     * 정확한 Composer 파서를 호출하지는 않으며, 일반적으로 사용되는 형태(`>=`, `^`, `~`, `||`, `,`)만 허용합니다.
     *
     * @param  string  $constraint  검사할 제약 문자열
     * @return bool 유효 여부
     */
    private function isSemverConstraint(string $constraint): bool
    {
        $trimmed = trim($constraint);

        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match(
            '/^[\^~><=!\d\.\*\-\+a-zA-Z\s\|,]+$/',
            $trimmed
        );
    }
}
