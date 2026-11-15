<?php

namespace App\Support;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\Csp\CspPolicyBuilder;
use Illuminate\Support\Facades\Log;

/**
 * 신뢰하는 외부 스타일시트 호스트 집계 (스타일 정책 `style-src` 자동 허용)
 *
 * 확장은 CSS 를 동봉해 같은 사이트에서 싣는 것이 원칙이다. 그러나 결제사 결제창처럼 **외부 스크립트가 자기
 * 호스트의 CSS 파일을 스스로 싣는** 경우는 확장이 그 CSS 를 대신 실을 수 없다. 그 확장이 manifest 의
 * `trusted_style_hosts` 로 호스트를 **선언**하면, 코어가 켜진 모듈·플러그인 전수에서 이 목록을 모아 스타일 정책의
 * 허용 호스트에 더한다({@see CspPolicyBuilder::styleAutoHosts()}).
 *
 * 선언이 없던 때에는 스타일 정책을 강제한 사이트에서 결제창의 CSS 가 막혀 창이 화면을 덮지 못했고, 운영자가
 * 환경설정의 「추가 스타일 허용 호스트」에 결제사 호스트를 직접 넣어야 했다.
 *
 * 템플릿은 여기서 다루지 않는다 — 템플릿의 외부 스타일시트는 `externals` 선언에서 따로 합산된다
 * ({@see TemplateExternals::externalStyleHosts()}). 꺼진 확장의 선언은 들어가지 않는다.
 */
class TrustedStyleHosts
{
    /**
     * 확장이 동적으로 스타일 호스트를 더할 수 있는 필터 훅 이름.
     */
    public const FILTER_HOOK = 'core.csp.trusted_style_hosts';

    /**
     * 켜진 모듈·플러그인이 선언한 스타일 호스트 전체를 집계합니다.
     *
     * @return array<int, string> 중복 제거된 호스트명 목록 (소문자)
     */
    public static function hosts(): array
    {
        $hosts = [];

        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getTrustedStyleHosts')) {
                    $hosts = array_merge($hosts, $module->getTrustedStyleHosts());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TrustedStyleHosts: 모듈 집계 실패 - '.$e->getMessage());
        }

        try {
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getTrustedStyleHosts')) {
                    $hosts = array_merge($hosts, $plugin->getTrustedStyleHosts());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TrustedStyleHosts: 플러그인 집계 실패 - '.$e->getMessage());
        }

        // 확장이 훅으로 동적 추가할 수 있는 경로 (manifest 외 경로)
        $hosts = HookManager::applyFilters(self::FILTER_HOOK, $hosts);

        if (! is_array($hosts)) {
            $hosts = [];
        }

        // 정규화: 문자열·비어있지 않음·소문자·중복 제거 (형식 검사는 정책 조립기가 한다)
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
}
