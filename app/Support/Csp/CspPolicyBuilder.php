<?php

namespace App\Support\Csp;

use App\Extension\TemplateManager;
use App\Rules\CspSourceHost;
use App\Support\TemplateExternals;
use App\Support\TrustedScriptHosts;
use Illuminate\Support\Facades\Log;

/**
 * 코어 CSP 헤더(Content-Security-Policy) 정책 조립 (#158)
 *
 * 코어 정책은 **스크립트 계열만** 다룬다 — `script-src`·`worker-src`·`object-src`·`base-uri`
 * (+ 위반 보고 `report-uri`). 스타일·이미지·연결 등 다른 지시어는 운영 사이트마다 사정이
 * 달라 코어가 기본값을 강요하지 않는다.
 *
 * `script-src` 허용 호스트는 자동 합산이다:
 *   - 활성 모듈·플러그인·템플릿의 `trusted_script_hosts` 선언 + 필터 훅({@see TrustedScriptHosts::hosts()})
 *   - 활성 템플릿 externals 의 외부 script 호스트({@see TemplateExternals::externalScriptHosts()})
 *   - 운영자 추가 입력(`security.csp_extra_script_hosts`)
 * 정책 문자열에 그대로 이어 붙이므로 합산 결과도 {@see CspSourceHost::isValid()} 로 한 번 더 거른다
 * (확장 선언에 `;`·따옴표가 섞이면 다른 지시어가 끼어든다).
 *
 * 모드는 `.env G7_CSP_MODE`(config `core.csp.mode_override`)가 화면 설정보다 우선한다 —
 * 강제 모드로 관리자 화면까지 막힌 운영자가 빠져나올 탈출구다. `EnvPriority` 잠금은 그 스위치가
 * 켜진 설치에서만 동작하므로 쓰지 않는다.
 *
 * 인스턴스 단위로 결과를 기억한다(요청당 한 번 조립 — 미들웨어·설정 응답이 각자 새 인스턴스를 쓴다).
 */
class CspPolicyBuilder
{
    /** 끔 */
    public const MODE_OFF = 'off';

    /** 점검만 (Content-Security-Policy-Report-Only) */
    public const MODE_REPORT_ONLY = 'report_only';

    /** 강제 (Content-Security-Policy) */
    public const MODE_ENFORCE = 'enforce';

    /** 허용 모드 목록 */
    public const MODES = [self::MODE_OFF, self::MODE_REPORT_ONLY, self::MODE_ENFORCE];

    /** 위반 보고 경로 (같은 출처 상대 경로 — 요청 Host 를 정책에 싣지 않는다) */
    public const REPORT_PATH = '/api/csp-report';

    /** @var array<int, string>|null 조립한 자동 합산 호스트 */
    private ?array $autoHosts = null;

    /** @var string|null 조립한 정책 문자열 */
    private ?string $policy = null;

    /**
     * `.env` 탈출구 값을 돌려줍니다.
     *
     * @return string|null 유효한 모드면 그 값, 아니면 null
     */
    public function modeOverride(): ?string
    {
        $override = config('core.csp.mode_override');

        if (! is_string($override)) {
            return null;
        }

        $override = strtolower(trim($override));

        return in_array($override, self::MODES, true) ? $override : null;
    }

    /**
     * 적용 모드를 돌려줍니다 (`.env` 탈출구 우선, 저장값이 잘못됐으면 끔).
     *
     * @return string off|report_only|enforce
     */
    public function mode(): string
    {
        $override = $this->modeOverride();

        if ($override !== null) {
            return $override;
        }

        $mode = g7_core_settings('security.csp_mode', self::MODE_OFF);

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
    }

    /**
     * 모드에 맞는 응답 헤더 이름을 돌려줍니다.
     *
     * @param  string  $mode  적용 모드
     * @return string|null 헤더 이름 (끔이면 null)
     */
    public static function headerName(string $mode): ?string
    {
        return match ($mode) {
            self::MODE_REPORT_ONLY => 'Content-Security-Policy-Report-Only',
            self::MODE_ENFORCE => 'Content-Security-Policy',
            default => null,
        };
    }

    /**
     * 활성 확장 선언에서 자동으로 합산되는 호스트를 돌려줍니다.
     *
     * @return array<int, string> 소문자·중복 제거·정렬된 호스트 목록
     */
    public function autoHosts(): array
    {
        if ($this->autoHosts !== null) {
            return $this->autoHosts;
        }

        $hosts = TrustedScriptHosts::hosts();

        try {
            $templateManager = app(TemplateManager::class);

            foreach (['admin', 'user'] as $type) {
                $template = $templateManager->getActiveTemplate($type);

                if (! is_array($template) || empty($template['externals']) || ! is_array($template['externals'])) {
                    continue;
                }

                $normalized = TemplateExternals::normalize($template['externals'], $template['identifier'] ?? null, null);
                $hosts = array_merge($hosts, TemplateExternals::externalScriptHosts($normalized));
            }
        } catch (\Throwable $e) {
            Log::warning('CspPolicyBuilder: 템플릿 externals 집계 실패 - '.$e->getMessage());
        }

        return $this->autoHosts = $this->normalizeHosts($hosts, 'auto');
    }

    /**
     * 운영자가 추가한 호스트를 돌려줍니다.
     *
     * @return array<int, string> 소문자·중복 제거·정렬된 호스트 목록
     */
    public function extraHosts(): array
    {
        $extra = g7_core_settings('security.csp_extra_script_hosts', []);

        return $this->normalizeHosts(is_array($extra) ? $extra : [], 'extra');
    }

    /**
     * 정책 문자열을 조립합니다.
     *
     * @return string `script-src 'self' …; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report`
     */
    public function build(): string
    {
        if ($this->policy !== null) {
            return $this->policy;
        }

        $hosts = $this->normalizeHosts(array_merge($this->autoHosts(), $this->extraHosts()), 'merged');
        $scriptSrc = trim("'self' ".implode(' ', $hosts));

        return $this->policy = implode('; ', [
            'script-src '.$scriptSrc,
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri '.self::REPORT_PATH,
        ]);
    }

    /**
     * 호스트 목록을 정규화합니다 (문자열·trim·소문자·형식 검사·중복 제거·정렬).
     *
     * @param  array<int|string, mixed>  $hosts  원래 목록
     * @param  string  $source  로그용 출처 이름
     * @return array<int, string> 정규화된 목록
     */
    private function normalizeHosts(array $hosts, string $source): array
    {
        $normalized = [];

        foreach ($hosts as $host) {
            if (! is_string($host)) {
                continue;
            }

            $host = strtolower(trim($host));

            if ($host === '') {
                continue;
            }

            if (! CspSourceHost::isValid($host)) {
                Log::warning('CspPolicyBuilder: CSP 호스트 형식이 아니어서 정책에서 뺐습니다', ['source' => $source, 'host' => $host]);

                continue;
            }

            $normalized[$host] = true;
        }

        $result = array_keys($normalized);
        sort($result);

        return $result;
    }
}
