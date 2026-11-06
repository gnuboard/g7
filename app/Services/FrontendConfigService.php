<?php

namespace App\Services;

use App\Extension\Auth\CredentialVerifierManager;
use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSnapshot;
use App\Services\Auth\LoginEntryService;
use App\Support\Custom\CustomRoutes;
use App\Support\EmptyMapChildren;

/**
 * 프론트엔드 공개 설정 조립 — G7 셸(`window.G7Config`)과 공개 설정 API 가 **같은 함수**로 만든다 (#792 D8).
 *
 * 셸에 실리는 설정(코어 공개 설정·모듈/플러그인 공개 설정·앱 설정·로케일·실시간 알림 접속정보)을 외부 화면
 * (Next.js·HTML+jQuery·Blade)도 `GET /api/frontend-config`(사용자)·`GET /api/admin/frontend-config`(관리자)로
 * 받게 한다. 노출 범위는 셸과 같다 — 각 설정의 frontend_schema(expose·sensitive)가 거른다. 엔진 전용 값(자산 URL·
 * 번들 URL·상태 계약·신뢰 스크립트 호스트)은 API 에 싣지 않는다. 사용자 템플릿이 없어도 동작한다.
 *
 * @since 7.2.0
 */
class FrontendConfigService
{
    /** 사용자 범위 */
    public const SCOPE_USER = 'user';

    /** 관리자 범위 */
    public const SCOPE_ADMIN = 'admin';

    /**
     * @param  SettingsService  $settingsService  코어 설정 서비스
     * @param  ModuleSettingsService  $moduleSettingsService  모듈 설정 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 서비스
     * @param  TemplateSettingsService  $templateSettingsService  템플릿 설정 서비스
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ModuleSettingsService $moduleSettingsService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly TemplateSettingsService $templateSettingsService,
    ) {}

    /**
     * 셸·API 공통 설정 묶음을 만듭니다.
     *
     * `settings` 의 카테고리 값은 화면이 이름표로 읽는 맵이라, 보이는 필드가 없는 카테고리(`identity`)도 `[]` 가 아니라
     * `{}` 로 나가도록 빈 카테고리를 빈 객체로 바꿔 돌려준다 — 셸과 API 가 이 한 곳의 값을 그대로 싣는다.
     *
     * @param  string  $scope  범위 (`user`|`admin`)
     * @return array{settings: array<string, mixed>, plugins: array<string, mixed>, modules: array<string, mixed>, templateSettings: array<string, mixed>, appConfig: array<string, mixed>, consentInitial: array<string, mixed>|null} 설정 묶음
     */
    public function settingsBundle(string $scope): array
    {
        try {
            $settings = $this->settingsService->getFrontendSettings();
        } catch (\Exception $e) {
            $settings = [];
        }

        $consentInitial = null;
        if ($scope === self::SCOPE_USER) {
            // 동의 연동 모드 트래커가 「동의를 확인할 곳이 있는가」를 알아야 한다. 코어는 어느 확장이
            // 답하는지 모르고, 동의 제공자 선택 상태가 none 이 아닌지만 계산해 싣는다(설정이 아니라 계산값).
            $governsConsent = false;
            try {
                $governsConsent = app(ConsentManager::class)->selection()->governsConsent();
            } catch (\Throwable $e) {
                $governsConsent = false;
            }

            if (isset($settings['analytics']) && is_array($settings['analytics'])) {
                $settings['analytics']['consent_provider'] = $governsConsent;
            }

            // 코어 동의 런타임의 첫 값 — 회원 토큰은 브라우저 저장소에 있어 이 시점에는 누구의 동의인지 모른다.
            // 그래서 `ready` 를 싣지 않고, 제공자가 없으면 `none`, 있으면 `unknown` 으로 두고 런타임이 곧바로 조회한다.
            $consentInitial = $governsConsent ? ConsentSnapshot::unknown()->toArray() : ConsentSnapshot::none()->toArray();
        }

        try {
            $plugins = $this->pluginSettingsService->getAllActiveSettings();
        } catch (\Exception $e) {
            $plugins = [];
        }

        try {
            $modules = $this->moduleSettingsService->getAllActiveSettings();
        } catch (\Exception $e) {
            $modules = [];
        }

        // 활성 템플릿 공개 설정(#434) — 이 범위(사용자·관리자)의 활성 템플릿 하나의 frontend_schema 노출값
        try {
            $templateSettings = $this->templateSettingsService->getActiveFrontendSettings($scope);
        } catch (\Throwable $e) {
            $templateSettings = [];
        }

        try {
            $appConfig = $this->settingsService->getAppConfigForFrontend();
        } catch (\Exception $e) {
            $appConfig = [];
        }

        // 로그인 화면이 읽는 계산값(7.2.0) — 로그인 칸 형식(사내 아이디를 받는 외부 자격 증명 검증자가 있으면 `email_or_username`)과
        // 로그인 화면 자동 이동 경로(확장 필터, 같은 출처 경로만). 요청마다 계산한다 — 설정 캐시 무효화가 필요 없다.
        $appConfig['auth'] = $this->loginEntryConfig();

        return [
            'settings' => EmptyMapChildren::asObjects($settings),
            'plugins' => $plugins,
            'modules' => $modules,
            'templateSettings' => $templateSettings,
            'appConfig' => $appConfig,
            'consentInitial' => $consentInitial,
        ];
    }

    /**
     * 로그인 화면용 계산값을 만듭니다 — 사용자·관리자 경로 각각.
     *
     * `registration_open` 은 셀프 가입 실효값이다 — 원 설정값 `security.registration_enabled` 에 확장 필터 `core.auth.self_registration_open`
     * (예: 사이트 전체 회사 계정 강제)을 거친 값으로, 가입 폼·가입 링크는 이것을 본다. 원 설정값은 관리자 스위치가 저장값을 보이도록 그대로 둔다(W37).
     *
     * @return array{login_identifier: array{user: string, admin: string}, auto_redirect: array{user: string|null, admin: string|null}, registration_open: bool} 계산값
     */
    private function loginEntryConfig(): array
    {
        $identifier = ['user' => 'email', 'admin' => 'email'];
        $autoRedirect = ['user' => null, 'admin' => null];

        try {
            $verifiers = app(CredentialVerifierManager::class);
            $entry = app(LoginEntryService::class);

            foreach (['user', 'admin'] as $guard) {
                $identifier[$guard] = $verifiers->acceptsUsernames($guard) ? 'email_or_username' : 'email';
                $autoRedirect[$guard] = $entry->autoRedirect($guard);
            }
        } catch (\Throwable $e) {
            // 확장 오류가 화면 설정 묶음 전체를 깨지 않게 한다 — 이메일 로그인·자동 이동 없음으로 둔다.
        }

        try {
            $registrationOpen = app(AuthService::class)->isSelfRegistrationOpen();
        } catch (\Throwable $e) {
            // 확장 필터 오류는 원 설정값으로 — 서버의 가입 차단 판정도 같은 필터를 거치므로 여기서 묶음 전체를 깨지 않는다
            $registrationOpen = (bool) g7_core_settings('security.registration_enabled', true);
        }

        return ['login_identifier' => $identifier, 'auto_redirect' => $autoRedirect, 'registration_open' => $registrationOpen];
    }

    /**
     * 실시간 알림(Reverb) 클라이언트 접속 정보. 브로드캐스트 키가 없으면 null.
     *
     * @return array{appKey: string, host: string, port: int, scheme: string}|null 접속 정보
     */
    public function websocket(): ?array
    {
        if (! config('broadcasting.connections.reverb.key')) {
            return null;
        }

        return [
            'appKey' => config('broadcasting.connections.reverb.key'),
            'host' => config('g7.websocket.client.host', config('broadcasting.connections.reverb.options.host', 'localhost')),
            'port' => (int) config('g7.websocket.client.port', config('broadcasting.connections.reverb.options.port', 80)),
            'scheme' => config('g7.websocket.client.scheme', config('broadcasting.connections.reverb.options.scheme', 'https')),
        ];
    }

    /**
     * 셸·API 가 싣는 현재 로케일.
     *
     * @return string 로케일 코드
     */
    public function locale(): string
    {
        return app()->getLocale();
    }

    /**
     * 엔진이 전체 페이지 이동으로 넘길 custom 주소 패턴 (`G7Config.customRoutes`).
     *
     * @return array{user: array<int, string>, admin: array<int, string>} 범위별 패턴
     */
    public function customRoutes(): array
    {
        try {
            return CustomRoutes::patterns();
        } catch (\Throwable $e) {
            return ['user' => [], 'admin' => []];
        }
    }

    /**
     * 공개 설정 API 응답을 만듭니다 — 셸과 같은 값, 엔진 전용 키 제외.
     *
     * @param  string  $scope  범위 (`user`|`admin`)
     * @return array<string, mixed> 응답 데이터
     */
    public function build(string $scope): array
    {
        $bundle = $this->settingsBundle($scope);

        $data = [
            'scope' => $scope,
            'locale' => $this->locale(),
            'settings' => (object) $bundle['settings'],
            'modules' => (object) $bundle['modules'],
            'plugins' => (object) $bundle['plugins'],
            'template_settings' => (object) $bundle['templateSettings'],
            'app_config' => (object) $bundle['appConfig'],
            'websocket' => $this->websocket(),
            'custom_routes' => $this->customRoutes(),
        ];

        if ($scope === self::SCOPE_USER) {
            $data['consent_initial'] = $bundle['consentInitial'];
        }

        return $data;
    }
}
