<?php

namespace App\Support;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Support\Facades\Log;

/**
 * 신뢰하는 외부 스크립트 호스트 집계 (KVE-2026-1915 신뢰 출처 허용목록)
 *
 * 레이아웃 `scripts[].src` 는 기본적으로 same-origin path-only 만 허용한다(원격 코드 로드
 * 차단). 그러나 CKEditor5(cdn.ckeditor.com)·Daum 우편번호(t1.daumcdn.net)처럼 외부 CDN
 * 스크립트를 정당하게 로드하는 번들 확장이 있다. 그 확장이 manifest 의
 * `trusted_script_hosts` 로 자기 호스트를 **선언**하고, 코어가 활성 확장 전수에서 이 목록을
 * 집계한다. 런타임 스크립트 로더(TemplateApp)·저장측 검증(SafeLayoutExpressions)·정적 검사
 * (audit)가 모두 이 집계 결과만 신뢰 호스트로 허용한다.
 *
 * 신뢰 경계: 편집기가 저장하는 레이아웃(저권한 액터)에는 외부 스크립트를 허용하지 않는
 * 것이 기본이고, 여기서 허용되는 것은 **확장이 코드로 선언한 호스트**뿐이다.
 */
class TrustedScriptHosts
{
    /**
     * 확장이 동적으로 신뢰 호스트를 추가할 수 있는 필터 훅 이름.
     */
    public const FILTER_HOOK = 'core.layout.trusted_script_hosts';

    /**
     * 활성 모듈·플러그인·템플릿이 선언한 신뢰 호스트 전체를 집계합니다.
     *
     * @return array<int, string> 중복 제거된 호스트명 목록 (소문자)
     */
    public static function hosts(): array
    {
        $hosts = [];

        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getTrustedScriptHosts')) {
                    $hosts = array_merge($hosts, $module->getTrustedScriptHosts());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TrustedScriptHosts: 모듈 집계 실패 - '.$e->getMessage());
        }

        try {
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getTrustedScriptHosts')) {
                    $hosts = array_merge($hosts, $plugin->getTrustedScriptHosts());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TrustedScriptHosts: 플러그인 집계 실패 - '.$e->getMessage());
        }

        // 템플릿은 모듈/플러그인과 달리 PHP 확장 클래스가 없고 manifest 배열로 다뤄지므로,
        // 활성 템플릿의 template.json 에서 직접 읽는다. 타입별 활성 템플릿은 각각 하나뿐이다.
        try {
            $templateManager = app(TemplateManager::class);

            foreach (['admin', 'user'] as $type) {
                $template = $templateManager->getActiveTemplate($type);

                if (! is_array($template) || ! isset($template['trusted_script_hosts'])) {
                    continue;
                }

                $declared = $template['trusted_script_hosts'];

                if (! is_array($declared)) {
                    continue;
                }

                $hosts = array_merge($hosts, $declared);
            }
        } catch (\Throwable $e) {
            Log::warning('TrustedScriptHosts: 템플릿 집계 실패 - '.$e->getMessage());
        }

        // 확장이 훅으로 동적 추가할 수 있는 경로 (manifest 외 경로)
        $hosts = HookManager::applyFilters(self::FILTER_HOOK, $hosts);

        if (! is_array($hosts)) {
            $hosts = [];
        }

        // 정규화: 문자열·비어있지 않음·소문자·중복 제거
        $normalized = [];
        foreach ($hosts as $host) {
            if (! is_string($host)) {
                continue;
            }
            $host = strtolower(trim($host));
            if ($host !== '') {
                $normalized[$host] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * URL 에서 호스트명을 추출합니다.
     *
     * `//host/path`(protocol-relative)·`https://host/path`(scheme 포함) 모두 처리합니다.
     * same-origin 경로(`/path`)는 호스트가 없으므로 null 을 반환합니다.
     *
     * @param  string  $url  검사 대상 URL
     * @return string|null 소문자 호스트명 (없으면 null)
     */
    public static function hostOf(string $url): ?string
    {
        $host = parse_url(self::normalizeForOriginCheck(trim($url)), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * origin 판정 전에 URL 을 브라우저 URL 파서와 동일하게 정규화합니다.
     *
     * 브라우저(WHATWG URL)는 파싱 전에 ASCII tab·LF·CR 을 제거하고, special scheme
     * (http/https)에서 백슬래시를 슬래시와 동등하게 처리합니다. 이 정규화 없이 판정하면
     * 같은 문자열을 계층마다 다른 출처로 읽습니다:
     *
     * - `https://evil.com\@cdn.ckeditor.com/x.js` — 정규화 없이는 호스트가
     *   `cdn.ckeditor.com`(userinfo 해석)이지만 브라우저는 `evil.com` 에서 로드합니다.
     * - `/\/cdn.ckeditor.com/x.js` — 문자열상 path 지만 브라우저는 authority 로 읽습니다.
     *
     * 정규화 후 판정하면 경로 중간의 백슬래시·탭(`/js/a\b.js`)은 authority 를 만들지
     * 않으므로 그대로 통과합니다(과차단 없음).
     *
     * 이 메서드가 origin 판정 정규화의 SSoT 입니다 — 저장측 규칙
     * (`App\Rules\SafeLayoutExpressions`)이 위임하고, 클라이언트
     * (`TemplateApp.normalizeScriptSrcForOriginCheck`)·정적 검사
     * (`layout-scripts-src-same-origin`)가 동형 구현을 갖습니다. 한 계층만 바꾸면
     * 그 계층만 다른 출처를 보게 되며, 예외도 경고도 없이 판정만 갈립니다.
     *
     * @param  string  $url  원본 URL
     * @return string 정규화된 URL
     */
    public static function normalizeForOriginCheck(string $url): string
    {
        // ASCII tab / LF / CR 제거 (브라우저 파서가 파싱 전에 제거하는 문자)
        $stripped = str_replace(["\t", "\n", "\r"], '', $url);

        // 백슬래시를 슬래시로 (special scheme 에서 등가)
        $slashed = str_replace('\\', '/', $stripped);

        // 선행 슬래시가 3개 이상이어도 브라우저는 authority 시작으로 접는다
        // (`///host/x` ≡ `//host/x`, `https:///host/x` ≡ `https://host/x`).
        // 접지 않으면 `/\/host/x` 가 정규화 후 `///host/x` 가 되어 parse_url 은
        // 호스트를 못 찾는데 브라우저는 host 에서 로드하는 갈림이 생긴다.
        // 경로 중간의 연속 슬래시(`/js//a.js`)는 브라우저도 경로로 두므로 건드리지 않는다.
        return preg_replace('#^([a-z][a-z0-9+.\-]*:)?/{2,}#i', '$1//', $slashed) ?? $slashed;
    }

    /**
     * URL 의 호스트가 신뢰 목록에 있는지 판정합니다.
     *
     * @param  string  $url  검사 대상 URL
     * @param  array<int, string>|null  $hosts  신뢰 호스트 목록 (미지정 시 self::hosts())
     * @return bool 신뢰 호스트면 true (호스트 없는 same-origin 경로는 false)
     */
    public static function isTrustedUrl(string $url, ?array $hosts = null): bool
    {
        $host = self::hostOf($url);

        if ($host === null) {
            return false;
        }

        $hosts ??= self::hosts();

        return in_array($host, $hosts, true);
    }
}
