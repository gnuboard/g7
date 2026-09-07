<?php

namespace Plugins\Sirsoft\Gdpr;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Models\Menu;
use Plugins\Sirsoft\Gdpr\Http\Middleware\CookieConsentMiddleware;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAuthConsentListener;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAuthLogoutListener;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserDeleteListener;
use Plugins\Sirsoft\Gdpr\Listeners\GdprUserWithdrawListener;

/**
 * GDPR (일반 데이터 보호 규정) 플러그인
 *
 * 쿠키 동의 배너, 동의 이력 저장, 마이페이지 동의 철회 등 GDPR 핵심 대응
 * 기능을 제공합니다 (F-01/F-02/F-03/F-04).
 */
class Plugin extends AbstractPlugin
{
    /**
     * strictly necessary 허용목록 출하 기본 카탈로그.
     *
     * 이 상수는 **판정 목록이 아니라 출하 기본값**입니다. 실제 판정은 운영자 설정
     * `necessary_storage_allowlist` 가 하며, 이 카탈로그는 신규 설치 시 그 설정의 기본값과
     * 관리자 화면 추천 목록(`default_necessary_allowlist_preview`)으로만 쓰입니다.
     *
     * 그래서 새 확장이 저장 키를 도입해도 이 플러그인을 고칠 필요가 없습니다 — 운영자가
     * 관리자 화면에서 직접 추가합니다.
     *
     * 표기 규칙: 끝의 `*` 는 앞부분 매칭, 없으면 정확 일치.
     *
     * 잠금 항목(`auth_token` / `XSRF-TOKEN` / 세션 쿠키 / `gdpr_session`)은 여기 넣지
     * 않습니다 — 설정에 담기면 API 로 지울 수 있어 잠금이 아니게 됩니다
     * (self::lockedNecessaryStorage()).
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_NECESSARY_ALLOWLIST_CATALOG = [
        // 저장소(localStorage) — 사용자 명시 선택 · 구매 동선 · 관리자 화면 상태 · 복귀 기록
        'localStorage' => [
            // 사용자 명시 선택 (WP29 §3.6) — 다국어 설정과 화면 테마.
            // 테마가 목록에서 빠져 있는 동안에는 테마를 바꿔도 새로고침하면 되돌아갔다 (dev-g7#640).
            'g7_locale',
            'g7_color_scheme',
            // 코어 캐시 버전 — 운영자가 의도적 갱신 시 사용
            'g7_cache_version',
            // 자산 URL 형식 판정 캐시 — 사용자 정보가 아니라 서버 능력 판정 결과다.
            // 파기되면 재방문마다 기본 형식으로 첫 자산 요청을 보내 404 를 겪는다. 키에 캐시
            // 버전이 붙으므로 앞부분 매칭으로 등재한다.
            'g7_asset_url_mode*',
            // 장바구니 게스트 키 — 익명 카트 식별 (구매 동선 필수)
            'g7_cart_key',
            // devtools UI 상태
            'g7-devtools-panel',
            // 비회원 주문 조회 — 주문 이행에 필요 (Art.6(1)(b))
            'g7_guest_order_token',
            'g7_guest_order_number',
            'g7_guest_order_expires_at',
            // 관리자 화면 상태 (필터/정렬/컬럼/devtools) — 사용자 의사로 조작
            'g7_devtools_*',
            'g7_filters_*',
            'g7_columns_*',
            'g7_order_*',
            // 관리자 화면 표시 설정 — 테마와 같은 범주. `g7_filters_` 는 필터 '값' 이고
            // `g7_filter_visibility_` 는 필터 '표시 여부' 라 접두사가 서로 덮지 않는다.
            'g7_admin_sidebar_collapsed',
            'g7_filter_visibility_*',
            'g7_dismissed_warnings',
            // 레이아웃 편집기 작업 상태 (라우트 트리 접힘) — 운영자 편집 동선 유지
            'g7le.*',
            // 결제·본인인증 복귀 기록 — 실제 쓰기는 sessionStorage 지만, 이관 전 목록이 저장소
            // 종류를 가리지 않았으므로 판정 범위를 좁히지 않는다.
            '__sirsoftKginicisMobilePaymentReturnPending',
            'g7.identity.redirectStash',
            'sirsoft-verification_nhnkcp.formStash',
        ],
        // 세션 저장소(sessionStorage) — 탭 수명 동안의 복귀·작업 상태
        'sessionStorage' => [
            // 결제 진행 기록 — 결제창 복귀 시 종료·실패 사유 보고에 필요 (Art.6(1)(b)).
            // 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 부팅 시 파기되면 그 보고가 통째로 누락된다.
            'g7:sirsoft-pay_kginicis:pendingClose',
            'g7:sirsoft-pay_nhnkcp:pendingClose',
            'g7:sirsoft-tosspayments:pendingClose',
            // 본인인증 복귀 — 인증창에서 돌아왔을 때 원래 화면·입력 내용 복원에 필요
            'g7.identity.redirectStash',
            'sirsoft-verification_nhnkcp.formStash',
            // 결제창 복귀 대기 표식
            '__sirsoftKginicisMobilePaymentReturnPending',
            // 레이아웃 편집기 클립보드 — 같은 탭에서 다른 레이아웃으로 붙여넣기
            'g7le.*',
            // 관리자 화면 상태 — 저장소 종류를 가리지 않던 이관 전 판정 범위를 그대로 옮긴다
            'g7_devtools_*',
            'g7_filters_*',
            'g7_columns_*',
            'g7_order_*',
            'g7_filter_visibility_*',
        ],
        // 쿠키 — 클라이언트 쓰기 + 서버 Set-Cookie 공통 목록
        'cookie' => [
            // 유지보수 모드 우회 (운영자 전용)
            'laravel_maintenance',
        ],
    ];

    /**
     * 기본 차단 도메인 카탈로그 (BE SSoT).
     *
     * 신규 설치 시 `blocked_domains` 기본값 + `GdprSettingsController` 응답
     * `default_blocked_domains_preview` 의 데이터 출처입니다.
     *
     * 본 상수가 유일한 정본입니다. `resources/js/blocker-domains.ts` 의
     * `DEFAULT_BLOCKED_DOMAINS` 는 런타임 소비처가 없어 번들에서 tree-shake 되며
     * (그 파일 헤더의 `audit:allow` 참조) 내용도 이 카탈로그와 다릅니다 — 두 목록을
     * "동일하게 유지" 하지 않습니다. 신규 설치 기본값과 관리자 UI 추천 도메인은
     * 모두 이 상수에서 나옵니다.
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_BLOCKED_DOMAINS_CATALOG = [
        // Phase 2: 외부 functional 서비스 도메인 카탈로그 — 운영자가 admin UI 의 functional 차단 도메인
        // TagInput 자동완성 추천으로 노출. 운영자가 사이트에 해당 도구 도입 시 클릭으로 등록.
        // 외부 functional 도구는 회색 영역 (analytics/marketing 분류도 가능) — 운영자 판단 영역.
        'functional' => [
            // 고객지원 챗봇
            '*.crisp.chat',
            'client.crisp.chat',
            '*.intercom.io',
            'widget.intercom.io',
            '*.tawk.to',
            'embed.tawk.to',
            // 다국어 자동 번역 위젯
            'cdn.weglot.com',
            '*.weglot.com',
            // 사용자 설정 외부 서비스
            '*.usercentrics.eu',
        ],
        'analytics' => [
            'google-analytics.com',
            '*.google-analytics.com',
            'googletagmanager.com',
            '*.googletagmanager.com',
            'ssl.google-analytics.com',
            '*.hotjar.com',
            'static.hotjar.com',
            '*.mixpanel.com',
            'cdn.mxpnl.com',
            '*.amplitude.com',
            'cdn.amplitude.com',
            '*.segment.io',
            '*.segment.com',
            'wcs.naver.net',
            'wcs.naver.com',
            '*.beusable.net',
        ],
        'marketing' => [
            'facebook.net',
            'connect.facebook.net',
            'facebook.com',
            '*.facebook.com',
            'doubleclick.net',
            '*.doubleclick.net',
            'googleadservices.com',
            'googlesyndication.com',
            'ads.google.com',
            '*.criteo.com',
            'static.criteo.net',
            '*.adnxs.com',
            '*.taboola.com',
            'cdn.taboola.com',
            '*.outbrain.com',
            '*.kakao.com',
            'analytics.ad.daum.net',
            'platform.twitter.com',
            '*.twitter.com',
            'platform.linkedin.com',
            '*.linkedin.com',
        ],
    ];

    /**
     * 플러그인이 제공하는 훅 정보 반환
     *
     * @return array 훅 정의 배열 (action 2개)
     */
    public function getHooks(): array
    {
        return [
            [
                'name' => 'sirsoft-gdpr.consent.granted',
                'type' => 'action',
                'description' => [
                    'ko' => '동의 부여 시 발화',
                    'en' => 'Fired when consent is granted',
                ],
                'parameters' => [
                    'consent' => 'Model - GdprUserConsent',
                    'source' => 'string - banner|preference_center|register|mypage',
                ],
            ],
            [
                'name' => 'sirsoft-gdpr.consent.revoked',
                'type' => 'action',
                'description' => [
                    'ko' => '동의 철회 시 발화',
                    'en' => 'Fired when consent is revoked',
                ],
                'parameters' => [
                    'consent' => 'Model - GdprUserConsent',
                    'source' => 'string - 철회 발생 경로',
                ],
            ],
        ];
    }

    /**
     * 관리자 메뉴 정의
     *
     * 코어 PluginManager 는 모듈과 달리 plugin 의 getAdminMenus() 를 자동 호출하지 않으므로
     * 본 클래스의 activate()/deactivate()/uninstall() lifecycle hook 에서 직접 sync 한다.
     * 멱등성: helper.syncMenu 가 upsert 패턴이라 재활성화 시에도 row 중복 없이 그대로 복원.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        // 단독 평면 메뉴 — 본인인증 이력(admin-identity-logs)과 일관된 형태.
        // 「설정」은 코어「플러그인 관리 → 설정」 자동 경로 사용 (중복 제거)
        return [
            [
                'name' => ['ko' => 'GDPR 동의 이력', 'en' => 'GDPR Consent Log'],
                'slug' => 'sirsoft-gdpr-consent-log',
                'url' => '/admin/plugins/sirsoft-gdpr/consent-log',
                'icon' => 'fas fa-user-shield',
                'order' => 50,
            ],
        ];
    }

    /**
     * 플러그인 활성화 — 관리자 메뉴 자동 등록 + privacy 역할 권한 부여.
     *
     * 모듈은 ModuleManager 가 getAdminMenus() 를 자동 처리하지만 플러그인은
     * PluginManager 가 그 책임을 위임하므로 본 메서드에서 helper 를 직접 호출한다.
     *
     * helper.grantDefaultRoles 는 admin 역할에만 메뉴 권한을 부여하므로,
     * sirsoft-gdpr.privacy 역할에는 별도로 role_menus 피벗에 부여한다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);

        foreach ($this->getAdminMenus() as $menuData) {
            $menu = $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );

            $this->grantPrivacyRoleToMenuTree($menu);
        }

        return true;
    }

    /**
     * 플러그인 비활성화 — 관리자 메뉴 일괄 제거.
     *
     * cleanupStaleMenus 에 currentSlugs=[] 를 넘겨 본 플러그인 소속 메뉴 전체를 삭제한다.
     * 자식 메뉴 + role_menus 피벗 정리는 helper 가 cascade 처리.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool
    {
        app(ExtensionMenuSyncHelper::class)->cleanupStaleMenus(
            ExtensionOwnerType::Plugin,
            $this->getIdentifier(),
            currentSlugs: [],
        );

        return true;
    }

    /**
     * 플러그인 제거 — 메뉴 잔존 안전망 (정상 흐름은 deactivate 가 먼저 처리).
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool
    {
        $this->deactivate();

        return true;
    }

    /**
     * 메뉴 트리에 sirsoft-gdpr.privacy 역할의 read 권한을 재귀 부여한다.
     *
     * helper.grantDefaultRoles 는 admin 역할만 자동 부여하므로 privacy 역할은 별도 처리 필요.
     *
     * @param  Menu  $menu  대상 메뉴 (자식 포함)
     */
    private function grantPrivacyRoleToMenuTree(Menu $menu): void
    {
        $role = app(RoleRepositoryInterface::class)->findByIdentifier('sirsoft-gdpr.privacy');

        if ($role === null) {
            return;
        }

        $menu->roles()->syncWithoutDetaching([
            $role->id => ['permission_type' => MenuPermissionType::Read->value],
        ]);

        foreach ($menu->children as $child) {
            $this->grantPrivacyRoleToMenuTree($child);
        }
    }

    /**
     * 플러그인 권한 목록 반환 (계층 구조)
     *
     * PluginManager가 1레벨(플러그인 노드) → 2레벨(카테고리) → 3레벨(개별 권한) 트리로 등록.
     * 모든 권한은 admin·sirsoft-gdpr.privacy 두 역할에 매핑.
     *
     * 권한 분할 의도:
     * - view: 동의 이력·설정 조회 (감사관/모니터링 read-only)
     * - update: 쿠키 카테고리·정책 버전 등 설정 변경 (정책 설정자)
     *
     * @return array 권한 정의 배열 (categories 계층 구조)
     */
    public function getPermissions(): array
    {
        return [
            'name' => [
                'ko' => 'GDPR (일반 데이터 보호 규정)',
                'en' => 'GDPR (General Data Protection Regulation)',
            ],
            'description' => [
                'ko' => 'GDPR 플러그인이 제공하는 권한',
                'en' => 'Permissions provided by the GDPR plugin',
            ],
            'categories' => [
                [
                    'identifier' => 'privacy',
                    'name' => ['ko' => '개인정보 보호', 'en' => 'Privacy'],
                    'description' => [
                        'ko' => 'GDPR 도메인 권한 (조회·설정)',
                        'en' => 'GDPR domain permissions (view, update settings)',
                    ],
                    'permissions' => [
                        [
                            'action' => 'view',
                            'name' => ['ko' => '개인정보 조회', 'en' => 'View Privacy'],
                            'description' => [
                                'ko' => '동의 이력·GDPR 설정 조회 (감사·모니터링)',
                                'en' => 'View consent log and GDPR settings (audit / monitoring)',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-gdpr.privacy'],
                        ],
                        [
                            'action' => 'update',
                            'name' => ['ko' => '개인정보 설정 변경', 'en' => 'Update Privacy Settings'],
                            'description' => [
                                'ko' => '쿠키 카테고리·정책 버전 등 GDPR 플러그인 설정 변경',
                                'en' => 'Modify GDPR plugin settings (cookie categories, policy version, etc.)',
                            ],
                            'type' => 'admin',
                            'roles' => ['admin', 'sirsoft-gdpr.privacy'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 플러그인 역할 목록 반환
     *
     * @return array 역할 정의 배열
     */
    public function getRoles(): array
    {
        return [
            [
                'identifier' => 'sirsoft-gdpr.privacy',
                'name' => ['ko' => '개인정보 운영자', 'en' => 'Privacy Operator'],
                'description' => [
                    'ko' => 'GDPR 도메인 운영 권한 (설정·동의 이력 조회)',
                    'en' => 'Operational authority over GDPR domain (settings, consent log)',
                ],
            ],
        ];
    }

    /**
     * 플러그인이 동적으로 생성한 테이블 목록 반환
     *
     * 언인스톨 시 PluginManager가 일괄 삭제 (FK 순서대로)
     *
     * @return array 테이블명 배열
     */
    public function getDynamicTables(): array
    {
        return [
            'gdpr_user_consent_histories',
            'gdpr_user_consents',
            'gdpr_policy_versions',
        ];
    }

    /**
     * 설치·업데이트 시 실행할 시더 목록 반환
     *
     * 권한·역할은 PluginManager 가 getRoles()/getPermissions() 로 자동 등록·동기화하므로
     * 별도 시더가 불필요하다.
     *
     * @return array 시더 클래스 배열
     */
    public function getSeeders(): array
    {
        return [];
    }

    /**
     * 이 플러그인이 등록할 HTTP 미들웨어 선언을 반환합니다.
     *
     * functional cookie 게이팅 미들웨어(CookieConsentMiddleware)를 코어 self-gate 방식으로
     * 선언합니다. EDPB Guidelines 2/2023 §16(사전 차단) 충족을 위해 모든 web/api 응답을
     * 대상으로 하므로 targets=['everything'](광역 타게팅) + timing=before_core(코어 전처리 전
     * 응답 처리)로 지정합니다. 코어 ExtensionMiddlewareGate 가 요청 시점에 실행합니다.
     *
     * @return array<int, array{class: class-string, groups: array<int, string>, timing?: string, targets: array<int, string>}>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => CookieConsentMiddleware::class,
                'groups' => ['web', 'api'],
                'timing' => 'before_core',
                'targets' => ['everything'],
            ],
        ];
    }

    /**
     * 플러그인 설정 값 반환 (기본값)
     *
     * @return array 설정 값 배열
     */
    /**
     * strictly necessary 잠금 항목 — 운영자가 지울 수 없는 최소 집합.
     *
     * 설정에 넣지 않는다. 설정에 넣으면 API 로 지울 수 있어 잠금이 아니게 된다.
     * 세션 쿠키 이름은 `session.cookie` 가 정하는 런타임 값이라 상수로 둘 수 없다 —
     * 이름을 하드코딩하면 `SESSION_COOKIE` 를 지정한 사이트에서 그 항목이 죽는다.
     *
     * 이 정의는 `src/` 가 아니라 진입 파일에 둔다. `getConfigValues()` 는 신규 설치 흐름에서
     * 이 플러그인의 PSR-4 오토로드가 등록되기 전에 호출되므로, `src/` 클래스를 부르면
     * "Class not found" 로 설치가 중단된다 (업그레이드 경로는 기설치본 매핑이 있어 재현되지
     * 않는다). `Support\NecessaryAllowlist::locked()` 는 이 메서드에 위임한다.
     *
     * @return array<string, array<int, string>> 스코프 => 항목 배열
     */
    public static function lockedNecessaryStorage(): array
    {
        return [
            'localStorage' => ['auth_token'],
            'sessionStorage' => [],
            'cookie' => ['XSRF-TOKEN', (string) config('session.cookie', 'laravel_session'), 'gdpr_session'],
        ];
    }

    public function getConfigValues(): array
    {
        return [
            // 정책 메타데이터 — cookie_policy_version 은 gdpr_policy_versions 테이블이 SSoT
            // (마이그레이션 시 initial 행 자동 시드). 본 settings 에서는 보관하지 않음.
            'privacy_policy_slug' => 'privacy',
            'legal_entity_name' => '',
            'data_storage_location' => '',

            // 쿠키 배너 + 자동 차단 (F-01 / F-02) — banner_enabled 단일 토글로 통합 제어.
            // ON 시 배너 노출 + 동의 전 외부 추적 자동 차단이 함께 시작됨. 마이페이지 동의 관리
            // 카드는 이 토글과 무관 — GDPR Art.7(3) 철회 대칭성 보장을 위해 동의/철회 이력이
            // 있는 회원에게 배너 노출 여부와 무관하게 항상 노출된다 (resources/extensions/mypage_privacy_tab.json 참조).
            // 차단을 별도 토글로 제공하지 않는 이유: GDPR Art.6 "동의 전 처리 금지" 의 강제 메커니즘
            // 인 차단을 운영자가 단독 OFF 할 수 있으면 위반 조합 (배너 ON + 차단 OFF) 가능 → CNIL
            // Microsoft €60M / Amazon €35M / Google €100M 처벌 패턴과 동일. 단일 토글로 구조적 차단.
            //
            // 기본값 true: 플러그인 활성화 = 운영자의 GDPR 컴플라이언스 의사 표명. 시장 표준 CMP
            // (OneTrust / Cookiebot / Iubenda / Klaro) 모두 "활성화 = 즉시 작동" 패턴. 운영자가
            // 컴플라이언스 임시 비활성을 원할 때만 OFF.
            'banner_enabled' => true,
            'banner_position' => 'bottom_bar',

            // F-02 도메인 기반 차단 — 카테고리별 운영자 입력.
            // 게스트도 차단 동작해야 하므로 공개 응답에 노출 (defaults.json frontend_schema).
            // 신규 설치 시 카탈로그 도메인이 채워져 있어 운영자가 토글 ON 만으로
            // 즉시 GA·Meta 등 차단 시작 가능. 운영자는 칩 X 클릭으로 빼고 싶은 도메인
            // 제거 가능. 카탈로그 갱신 동기화는 관리자 UI 의 "(+ 카탈로그에서 도메인
            // 가져오기)" 링크가 클라이언트 측 Set 합집합으로 처리.
            'blocked_domains' => self::DEFAULT_BLOCKED_DOMAINS_CATALOG,

            // 필수 저장 항목 허용목록 — 운영자가 관리자 화면에서 편집하는 설정.
            // 여기 값은 **신규 설치 시드용 기본값**이며, 판정에 실제로 쓰이는 값은 저장된 설정이다.
            // 배열 그대로 둔다: 코어는 설정값을 json_decode 하지 않으므로 문자열로 선언하면
            // 브라우저 측 판독기가 그 값을 조용히 버린다.
            'necessary_storage_allowlist' => self::DEFAULT_NECESSARY_ALLOWLIST_CATALOG,

            // 잠금 항목 — 설정이 아니라 코드가 정한다. 스키마에 넣지 않으므로 저장 요청에
            // 섞여 와도 validated() 에서 배제되어 저장되지 않는다. 화면에는 읽기 전용으로
            // 표시하고, 판정은 언제나 '운영자 목록 ∪ 이 집합' 이다.
            'necessary_storage_locked' => self::lockedNecessaryStorage(),

            'cookie_categories' => json_encode([
                [
                    'key' => 'necessary',
                    'required' => true,
                    'label' => ['ko' => '필수 쿠키', 'en' => 'Strictly Necessary'],
                    'description' => [
                        // g7_locale·g7_color_scheme 은 ePrivacy Art.5(3) + WP29 Opinion 04/2012 §3.6 의 user-initiated preference
                        // 예외 (사용자가 화면에서 직접 고른 표시 환경) 로 strictly necessary 분류. 사용자 안내에 명시.
                        'ko' => '세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 직접 고른 언어 설정과 화면 테마, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.',
                        'en' => 'Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference and display theme, cookie consent record. Cannot be disabled.',
                    ],
                ],
                [
                    // Phase 1: functional 카테고리 신설 — ICO/CNIL 4분류 체계 부합.
                    // 자체 functional 키 (표시 통화 등) + 외부 functional 도구 (Crisp, Intercom 등) 분류 영역.
                    // Phase 2 에서 실제 게이팅 (Storage.prototype 가로채기 + cookie 가로채기 + Set-Cookie 미들웨어) 구현 예정.
                    'key' => 'functional',
                    'required' => false,
                    'label' => ['ko' => '기능 쿠키', 'en' => 'Functional'],
                    'description' => [
                        'ko' => '사용자 선호도(표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.',
                        'en' => 'Cookies that remember user preferences such as display currency. If declined, defaults are used on every visit.',
                    ],
                ],
                [
                    'key' => 'analytics',
                    'required' => false,
                    'label' => ['ko' => '분석 쿠키', 'en' => 'Analytics'],
                    'description' => [
                        'ko' => '방문자가 사이트를 어떻게 이용하는지 익명으로 측정해 더 나은 서비스를 만드는 데 사용됩니다. (예: Google Analytics, Hotjar)',
                        'en' => 'Used to anonymously measure how visitors use the site so we can improve it. (e.g. Google Analytics, Hotjar)',
                    ],
                ],
                [
                    'key' => 'marketing',
                    'required' => false,
                    'label' => ['ko' => '마케팅 쿠키', 'en' => 'Marketing'],
                    'description' => [
                        'ko' => '관심사에 맞는 광고를 보여주거나, 광고가 얼마나 효과적이었는지 측정하는 데 사용됩니다. SNS 영상 임베드 등도 포함됩니다. (예: Facebook 픽셀, Google 광고, YouTube 영상)',
                        'en' => 'Used to show ads relevant to your interests, measure ad performance, and embed social media content. (e.g. Facebook Pixel, Google Ads, YouTube embeds)',
                    ],
                ],
            ]),

            // Phase 2 단순화: 운영자 등록 표 (functional_storage_keys / functional_cookies /
            // functional_allow_user_initiated) 는 GDPR 원칙과 충돌하여 제거됨. 게이팅은
            // strictly necessary allowlist (코드 상수) 외 모든 비-필수 저장을 동의 시까지 차단하는
            // 4단계 단순화 규칙으로 처리. WP29 §3.6 user-initiated 면제는 항상 활성.
        ];
    }

    /**
     * 플러그인 설정 스키마 반환
     *
     * @return array 설정 스키마
     */
    public function getSettingsSchema(): array
    {
        return [
            // 정책 메타데이터 — cookie_policy_version 은 gdpr_policy_versions 테이블이 SSoT.
            // 운영자는 직접 입력하지 않고, Material 변경 (카테고리 추가/삭제 등) 시 시스템이 자동 발행.
            'privacy_policy_slug' => [
                'type' => 'string',
                'default' => 'privacy',
                'label' => ['ko' => '개인정보처리방침 페이지 슬러그', 'en' => 'Privacy Policy Page Slug'],
                'hint' => [
                    'ko' => 'sirsoft-page 모듈의 페이지 slug. 미설치 또는 페이지 부재 시 관련 링크는 자동 숨김됩니다.',
                    'en' => 'Slug of the sirsoft-page page. Links auto-hide when page is missing.',
                ],
                'required' => false,
            ],
            'legal_entity_name' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '운영 주체명', 'en' => 'Legal Entity Name'],
                'hint' => [
                    'ko' => '개인정보처리방침에 표시될 회사·서비스명. 예: "(주)홍길동컴퍼니"',
                    'en' => 'Company/service name shown in privacy policy.',
                ],
                'required' => false,
            ],
            'data_storage_location' => [
                'type' => 'string',
                'default' => '',
                'label' => ['ko' => '데이터 저장 위치', 'en' => 'Data Storage Location'],
                'hint' => [
                    'ko' => '개인정보가 저장되는 물리적 위치. 예: "한국 (서울 IDC)", "AWS 서울 리전"',
                    'en' => 'Physical location where personal data is stored.',
                ],
                'required' => false,
            ],

            // 쿠키 배너 + 자동 차단 단일 토글 (banner_enabled). 마이페이지 카드는 이 토글과 무관 (별도 절 참조).
            // 기본값 true — 플러그인 활성화 = 운영자의 GDPR 컴플라이언스 의사 표명.
            'banner_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'label' => ['ko' => '쿠키 배너 노출', 'en' => 'Show Cookie Banner'],
                'hint' => [
                    'ko' => 'ON 시 쿠키 동의 배너 노출 + 동의 전 외부 추적 자동 차단이 함께 시작됩니다. 정책 슬러그·카테고리 설정 후 켜는 것을 권장. 마이페이지 동의 관리 카드는 이 토글과 무관하게 동의/철회 이력이 있는 회원에게 항상 노출됩니다.',
                    'en' => 'When ON, the cookie consent banner and pre-consent auto-blocking are activated together. The MyPage consent management card is independent of this toggle and always shows for members with consent/withdrawal history.',
                ],
                'required' => false,
            ],
            'banner_position' => [
                'type' => 'string',
                'default' => 'bottom_bar',
                'label' => ['ko' => '배너 위치', 'en' => 'Banner Position'],
                'hint' => [
                    'ko' => 'bottom_bar(하단 바) / bottom_left_popup / bottom_right_popup / centered_modal',
                    'en' => 'Position of the cookie banner.',
                ],
                'required' => false,
            ],
            'blocked_domains' => [
                'type' => 'json',
                'default' => json_encode(self::DEFAULT_BLOCKED_DOMAINS_CATALOG),
                'label' => ['ko' => '추적 도메인 차단 목록', 'en' => 'Tracking Domain Block List'],
                'hint' => [
                    'ko' => '카테고리별 차단 도메인 목록(JSON 객체). 신규 설치 시 카탈로그 도메인이 채워져 있으며 운영자가 추가·삭제 가능. FQDN 만 지원하며 *.example.com 와일드카드 가능.',
                    'en' => 'Per-category blocked domain list (JSON object). Pre-filled with the catalog on fresh install; operators can add/remove domains. FQDN only; supports *.example.com wildcard.',
                ],
                'required' => false,
            ],
            'necessary_storage_allowlist' => [
                // json 이 아니라 array 로 선언한다 — 코어 설정 검증 규칙 생성기는 json 타입을
                // 모르므로 nullable 만 붙고 항목 형식이 전혀 검사되지 않는다.
                // 항목 형식·스코프 화이트리스트는 UpdateAdminSettingsRequest 가 검증한다.
                'type' => 'array',
                'default' => self::DEFAULT_NECESSARY_ALLOWLIST_CATALOG,
                'label' => ['ko' => '필수 저장 항목 허용목록', 'en' => 'Strictly Necessary Storage Allowlist'],
                'hint' => [
                    'ko' => '기능 쿠키 미동의 상태에서도 저장이 허용되는 항목(브라우저 저장소·세션 저장소·쿠키). 끝에 * 를 붙이면 앞부분이 같은 항목을 모두 포함합니다.',
                    'en' => 'Items allowed to persist even without functional consent (local storage, session storage, cookies). A trailing * matches every name with that prefix.',
                ],
                'required' => false,
            ],
            'cookie_categories' => [
                'type' => 'json',
                'default' => '[]',
                'label' => ['ko' => '쿠키 카테고리 정의', 'en' => 'Cookie Categories'],
                'hint' => [
                    'ko' => '카테고리는 4종 고정(necessary/functional/analytics/marketing). 운영자 편집 입력 없음.',
                    'en' => 'The four categories are fixed (necessary/functional/analytics/marketing). No operator-editable inputs.',
                ],
                'required' => false,
            ],

        ];
    }

    /**
     * 플러그인 메타데이터 반환
     *
     * @return array 메타데이터
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'Sirsoft',
            'license' => 'MIT',
            'homepage' => 'https://sir.kr',
            'keywords' => ['gdpr', 'privacy', 'consent', 'cookie', 'data-protection'],
        ];
    }

    /**
     * 훅 리스너 목록 반환
     *
     * PluginManager 가 활성화된 플러그인에 대해 자동으로
     * HookListenerRegistrar::register() 호출.
     *
     * @return array 훅 리스너 클래스 배열
     */
    public function getHookListeners(): array
    {
        return [
            GdprUserWithdrawListener::class,
            GdprUserDeleteListener::class,
            GdprAuthLogoutListener::class,
            GdprAuthConsentListener::class,
        ];
    }
}
