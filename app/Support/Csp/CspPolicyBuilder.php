<?php

namespace App\Support\Csp;

use App\Extension\TemplateManager;
use App\Rules\CspSourceHost;
use App\Support\TemplateExternals;
use App\Support\TrustedScriptHosts;
use App\Support\TrustedStyleHosts;
use Illuminate\Support\Facades\Log;

/**
 * 코어 CSP 헤더(Content-Security-Policy) 정책 조립 (공개 #158)
 *
 * 코어 정책은 두 묶음을 다룬다.
 *   - 스크립트 계열: `script-src`·`worker-src`·`object-src`·`base-uri` ({@see build()})
 *   - 스타일 계열(7.1.6): 강도 기본 `style-src-elem`, 엄격 `style-src` + `style-src-attr 'none'` ({@see buildStyle()})
 * 두 묶음은 모드(끔·점검만·강제)를 따로 가지며, 조합에 따라 헤더가 0~2개 나간다({@see headers()}).
 * 나가는 헤더마다 위반 보고 `report-uri` 가 맨 끝에 붙는다. 이미지·연결·프레임 등 다른 지시어는 운영
 * 사이트마다 사정이 달라 코어가 기본값을 강요하지 않는다.
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
 * 켜진 설치에서만 동작하므로 쓰지 않는다. 스타일 모드의 탈출구는 `.env G7_CSP_STYLE_MODE`
 * (config `core.csp.style_mode_override`)로 따로 두며, 두 탈출구는 서로 영향을 주지 않는다.
 *
 * 스타일 허용 호스트는 활성 템플릿 externals 의 외부 스타일시트 호스트
 * ({@see TemplateExternals::externalStyleHosts()}) + 켜진 확장의 `trusted_style_hosts` 선언({@see TrustedStyleHosts::hosts()})
 * + 운영자 추가 입력(`security.csp_extra_style_hosts`)이다.
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

    /** 스타일 정책 강도 — 기본 (`<style>` 요소와 허용 밖 CSS 파일만 막는다) */
    public const LEVEL_BASIC = 'basic';

    /** 스타일 정책 강도 — 엄격 (기본 + 태그의 style 속성도 막는다) */
    public const LEVEL_STRICT = 'strict';

    /** 허용 강도 목록 */
    public const LEVELS = [self::LEVEL_BASIC, self::LEVEL_STRICT];

    /** @var array<int, string>|null 조립한 자동 합산 호스트 */
    private ?array $autoHosts = null;

    /** @var array<int, string>|null 조립한 스타일 자동 합산 호스트 */
    private ?array $styleAutoHosts = null;

    /** @var string|null 조립한 정책 문자열 */
    private ?string $policy = null;

    /**
     * `.env` 탈출구 값을 돌려줍니다.
     *
     * @return string|null 유효한 모드면 그 값, 아니면 null
     */
    public function modeOverride(): ?string
    {
        return $this->resolveOverride('core.csp.mode_override');
    }

    /**
     * 적용 모드를 돌려줍니다 (`.env` 탈출구 우선, 저장값이 잘못됐으면 끔).
     *
     * @return string off|report_only|enforce
     */
    public function mode(): string
    {
        return $this->resolveMode($this->modeOverride(), 'security.csp_mode');
    }

    /**
     * 스타일 정책의 `.env` 탈출구(`G7_CSP_STYLE_MODE`) 값을 돌려줍니다.
     *
     * 스크립트 탈출구와 독립이다 — `G7_CSP_MODE=off` 가 스타일 정책을 끄지 않는다.
     *
     * @return string|null 유효한 모드면 그 값, 아니면 null
     */
    public function styleModeOverride(): ?string
    {
        return $this->resolveOverride('core.csp.style_mode_override');
    }

    /**
     * 스타일 정책의 적용 모드를 돌려줍니다 (`.env` 탈출구 우선, 저장값이 없거나 잘못됐으면 끔).
     *
     * @return string off|report_only|enforce
     */
    public function styleMode(): string
    {
        return $this->resolveMode($this->styleModeOverride(), 'security.csp_style_mode');
    }

    /**
     * 스타일 정책의 강도를 돌려줍니다 (저장값이 없거나 잘못됐으면 기본).
     *
     * @return string basic|strict
     */
    public function styleLevel(): string
    {
        $level = g7_core_settings('security.csp_style_level', self::LEVEL_BASIC);

        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : self::LEVEL_BASIC;
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

        $hosts = array_merge(
            TrustedScriptHosts::hosts(),
            $this->templateExternalHosts(fn (array $externals): array => TemplateExternals::externalScriptHosts($externals))
        );

        return $this->autoHosts = $this->normalizeHosts($hosts, 'auto');
    }

    /**
     * 스타일 정책에 자동으로 합산되는 호스트를 돌려줍니다.
     *
     * 활성 템플릿 externals 의 외부 스타일시트 호스트({@see TemplateExternals::externalStyleHosts()})와, 켜진
     * 모듈·플러그인이 manifest `trusted_style_hosts`(+ 필터 훅)로 선언한 호스트({@see TrustedStyleHosts::hosts()})다.
     * 확장 선언은 결제사 결제창처럼 외부 스크립트가 스스로 싣는 CSS 파일을 위한 것이다.
     *
     * @return array<int, string> 소문자·중복 제거·정렬된 호스트 목록
     */
    public function styleAutoHosts(): array
    {
        if ($this->styleAutoHosts !== null) {
            return $this->styleAutoHosts;
        }

        $hosts = array_merge(
            TrustedStyleHosts::hosts(),
            $this->templateExternalHosts(fn (array $externals): array => TemplateExternals::externalStyleHosts($externals))
        );

        return $this->styleAutoHosts = $this->normalizeHosts($hosts, 'style-auto');
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

        return $this->policy = $this->compose($this->scriptDirectives());
    }

    /**
     * 운영자가 추가한 스타일 호스트를 돌려줍니다.
     *
     * @return array<int, string> 소문자·중복 제거·정렬된 호스트 목록
     */
    public function styleExtraHosts(): array
    {
        $extra = g7_core_settings('security.csp_extra_style_hosts', []);

        return $this->normalizeHosts(is_array($extra) ? $extra : [], 'style-extra');
    }

    /**
     * 스크립트 계열 지시어 목록을 돌려줍니다 (`report-uri` 제외).
     *
     * @return array<int, string> `script-src …`·`worker-src …`·`object-src …`·`base-uri …`
     */
    public function scriptDirectives(): array
    {
        $hosts = $this->normalizeHosts(array_merge($this->autoHosts(), $this->extraHosts()), 'merged');

        return [
            'script-src '.trim("'self' ".implode(' ', $hosts)),
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
        ];
    }

    /**
     * 스타일 계열 지시어 목록을 돌려줍니다 (`report-uri` 제외).
     *
     * - 기본: `style-src-elem 'self' …` — `<style>` 요소와 허용 밖 CSS 파일만 막는다. style 속성에는 대응
     *   지시어를 내지 않아 허용된다. `style-src …; style-src-attr 'unsafe-inline'` 로 쓰지 않는 까닭은
     *   `style-src-attr` 을 모르는 구형 브라우저에서 엄격으로 동작하기 때문이다.
     * - 엄격: `style-src 'self' …; style-src-attr 'none'` — 구형 브라우저에서도 `style-src` 가 둘 다 막는다.
     *
     * `data:`·`blob:` 는 허용하지 않는다.
     *
     * @return array<int, string> 강도에 맞는 스타일 지시어
     */
    public function styleDirectives(): array
    {
        $hosts = $this->normalizeHosts(array_merge($this->styleAutoHosts(), $this->styleExtraHosts()), 'style-merged');
        $sources = trim("'self' ".implode(' ', $hosts));

        if ($this->styleLevel() === self::LEVEL_STRICT) {
            return [
                'style-src '.$sources,
                "style-src-attr 'none'",
            ];
        }

        return ['style-src-elem '.$sources];
    }

    /**
     * 스타일 정책 문자열을 조립합니다 (설정 화면 미리보기용 — 스타일 지시어 + `report-uri`).
     *
     * @return string `style-src-elem 'self' …; report-uri /api/csp-report` 또는 엄격 강도의 문자열
     */
    public function buildStyle(): string
    {
        return $this->compose($this->styleDirectives());
    }

    /**
     * 응답에 붙일 CSP 헤더를 돌려줍니다 (헤더 이름 => 정책 문자열).
     *
     * 스크립트 모드와 스타일 모드의 조합으로 정한다:
     *   - 둘 다 끔 → 없음
     *   - 한쪽만 켜짐 → 그쪽 지시어만 실린 헤더 하나
     *   - 같은 모드 → 헤더 하나 (스크립트 지시어 → 스타일 지시어)
     *   - 다른 모드 → 강제·점검만 헤더 하나씩
     * `report-uri` 는 나가는 헤더마다 맨 끝에 한 번 붙는다.
     *
     * @return array<string, string> 헤더 이름 => 정책 문자열 (0~2개)
     */
    public function headers(): array
    {
        $directives = [];

        $scriptHeader = self::headerName($this->mode());

        if ($scriptHeader !== null) {
            $directives[$scriptHeader] = $this->scriptDirectives();
        }

        $styleHeader = self::headerName($this->styleMode());

        if ($styleHeader !== null) {
            $directives[$styleHeader] = array_merge($directives[$styleHeader] ?? [], $this->styleDirectives());
        }

        return array_map(fn (array $list): string => $this->compose($list), $directives);
    }

    /**
     * 지시어 목록 끝에 `report-uri` 를 붙여 정책 문자열로 만듭니다.
     *
     * @param  array<int, string>  $directives  지시어 목록
     * @return string `; ` 로 이은 정책 문자열
     */
    private function compose(array $directives): string
    {
        $directives[] = 'report-uri '.self::REPORT_PATH;

        return implode('; ', $directives);
    }

    /**
     * `.env` 탈출구 설정값을 모드로 해석합니다.
     *
     * @param  string  $configKey  탈출구 config 키
     * @return string|null 유효한 모드면 그 값(소문자), 아니면 null
     */
    private function resolveOverride(string $configKey): ?string
    {
        $override = config($configKey);

        if (! is_string($override)) {
            return null;
        }

        $override = strtolower(trim($override));

        return in_array($override, self::MODES, true) ? $override : null;
    }

    /**
     * 탈출구와 저장값으로 적용 모드를 정합니다 (탈출구 우선, 저장값이 없거나 잘못됐으면 끔).
     *
     * @param  string|null  $override  해석된 탈출구 값
     * @param  string  $settingKey  저장값 설정 키
     * @return string off|report_only|enforce
     */
    private function resolveMode(?string $override, string $settingKey): string
    {
        if ($override !== null) {
            return $override;
        }

        $mode = g7_core_settings($settingKey, self::MODE_OFF);

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
    }

    /**
     * 활성 관리자·사용자 템플릿의 externals 에서 호스트를 모읍니다.
     *
     * @param  \Closure(array<int, array<string, mixed>>): array<int, string>  $collect  정규화된 externals 에서 호스트를 뽑는 함수
     * @return array<int, string> 모은 호스트 (정규화 전)
     */
    private function templateExternalHosts(\Closure $collect): array
    {
        $hosts = [];

        try {
            $templateManager = app(TemplateManager::class);

            foreach (['admin', 'user'] as $type) {
                $template = $templateManager->getActiveTemplate($type);

                if (! is_array($template) || empty($template['externals']) || ! is_array($template['externals'])) {
                    continue;
                }

                $normalized = TemplateExternals::normalize($template['externals'], $template['identifier'] ?? null, null);
                $hosts = array_merge($hosts, $collect($normalized));
            }
        } catch (\Throwable $e) {
            Log::warning('CspPolicyBuilder: 템플릿 externals 집계 실패 - '.$e->getMessage());
        }

        return $hosts;
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
