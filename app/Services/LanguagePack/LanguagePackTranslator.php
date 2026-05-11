<?php

namespace App\Services\LanguagePack;

use Illuminate\Translation\Translator;

/**
 * Laravel Translator 를 데코레이트하여 코어 언어팩 폴백을 제공하는 번역기.
 *
 * Laravel 의 FileLoader 는 전역 네임스페이스(`*`)에 추가 경로 주입을 지원하지 않으므로,
 * 본 클래스가 기본 lang/ 디렉토리에서 키를 못 찾았을 때 코어 언어팩의 backend 디렉토리를
 * 추가 경로로 탐색하여 번역을 보완합니다.
 *
 * 모듈/플러그인/템플릿 언어팩은 네임스페이스가 명확하므로 표준 loadTranslationsFrom() 메커니즘에 위임하고,
 * 본 데코레이터는 코어(scope=core)에 한해서만 폴백 동작을 수행합니다.
 */
class LanguagePackTranslator extends Translator
{
    /**
     * 코어 언어팩 backend 디렉토리 경로 캐시 (locale ⇒ 디렉토리 절대 경로 배열).
     *
     * @var array<string, array<int, string>>|null
     */
    private ?array $coreFallbackPaths = null;

    /**
     * 모듈/플러그인/템플릿 언어팩 backend 디렉토리 경로 캐시.
     *
     * Laravel FileLoader 의 hints 는 namespace ⇒ 단일 경로 문자열만 저장하며 addNamespace
     * 가 덮어쓰는 구조이므로, 모듈 자체 src/lang 등록 후 언어팩이 같은 namespace 를
     * loadTranslationsFrom 으로 등록하면 hint 가 손상되어 ko 가 raw key 로 떨어진다.
     * 본 캐시는 namespace ⇒ locale ⇒ 디렉토리 절대 경로 배열로 fallback 만 보관해
     * 표준 hint 를 유지하면서 ja 등 추가 locale 을 보완한다.
     *
     * @var array<string, array<string, array<int, string>>>|null
     */
    private ?array $namespaceFallbackPaths = null;

    /**
     * 추가 경로를 등록합니다.
     *
     * @param  string  $locale  로케일
     * @param  string  $path  디렉토리 절대 경로
     * @return void
     */
    public function addCoreFallbackPath(string $locale, string $path): void
    {
        if ($this->coreFallbackPaths === null) {
            $this->coreFallbackPaths = [];
        }

        $this->coreFallbackPaths[$locale][] = $path;
    }

    /**
     * 등록된 폴백 경로를 반환합니다.
     *
     * @return array<string, array<int, string>> locale ⇒ 디렉토리 절대 경로 배열
     */
    public function getCoreFallbackPaths(): array
    {
        return $this->coreFallbackPaths ?? [];
    }

    /**
     * 모듈/플러그인/템플릿 namespace 의 fallback 경로를 등록합니다.
     *
     * 모듈 자체 src/lang 의 ko/en 등록을 보존하면서 언어팩의 ja 등 추가 locale 을
     * 보완하기 위한 메커니즘. load() 에서 표준 hint 로 못 찾은 키를 본 경로에서 추가 로드합니다.
     *
     * @param  string  $namespace  네임스페이스 (예: sirsoft-ecommerce)
     * @param  string  $locale  로케일 (예: ja)
     * @param  string  $path  로케일 디렉토리 절대 경로 (예: lang-packs/.../backend/ja)
     * @return void
     */
    public function addNamespaceFallbackPath(string $namespace, string $locale, string $path): void
    {
        if ($this->namespaceFallbackPaths === null) {
            $this->namespaceFallbackPaths = [];
        }

        $this->namespaceFallbackPaths[$namespace][$locale][] = $path;
    }

    /**
     * 등록된 namespace fallback 경로 맵을 반환합니다.
     *
     * @return array<string, array<string, array<int, string>>> namespace ⇒ locale ⇒ 경로 배열
     */
    public function getNamespaceFallbackPaths(): array
    {
        return $this->namespaceFallbackPaths ?? [];
    }

    /**
     * 번역 파일을 로드합니다.
     *
     * 표준 loader 가 키를 찾지 못하면 등록된 코어 언어팩 폴백 경로의 PHP 배열 파일을 추가 병합합니다.
     * 시그니처는 부모 `Illuminate\Translation\Translator::load($namespace, $group, $locale)` 와 일치해야 override 가 동작합니다.
     *
     * @param  string|null  $namespace  네임스페이스 (`*` 또는 null = 전역)
     * @param  string  $group  파일 그룹명 (확장자 제외)
     * @param  string  $locale  로케일
     * @return void
     */
    public function load($namespace, $group, $locale): void
    {
        parent::load($namespace, $group, $locale);

        if ($namespace === null || $namespace === '*') {
            $this->mergeFallbacks(
                paths: $this->coreFallbackPaths[$locale] ?? [],
                namespace: '*',
                group: $group,
                locale: $locale,
            );

            return;
        }

        $this->mergeFallbacks(
            paths: $this->namespaceFallbackPaths[$namespace][$locale] ?? [],
            namespace: $namespace,
            group: $group,
            locale: $locale,
        );
    }

    /**
     * 등록된 fallback 경로의 PHP 배열 파일을 기존 로드 결과에 병합합니다.
     *
     * 기존 번역(`lang/{locale}/{group}.php` 또는 namespace primary path)이 우선이며,
     * fallback 은 누락 키만 보완합니다.
     *
     * @param  array<int, string>  $paths  디렉토리 절대 경로 배열
     * @param  string  $namespace  로드 대상 네임스페이스 (`*` 또는 namespace 식별자)
     * @param  string  $group  파일 그룹명
     * @param  string  $locale  로케일
     * @return void
     */
    private function mergeFallbacks(array $paths, string $namespace, string $group, string $locale): void
    {
        if (empty($paths)) {
            return;
        }

        $existing = $this->loaded[$namespace][$group][$locale] ?? [];

        foreach ($paths as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$group.'.php';
            if (! is_file($candidate)) {
                continue;
            }

            $contents = require $candidate;
            if (! is_array($contents)) {
                continue;
            }

            $existing = array_replace_recursive($contents, $existing);
        }

        $this->loaded[$namespace][$group][$locale] = $existing;
    }
}
