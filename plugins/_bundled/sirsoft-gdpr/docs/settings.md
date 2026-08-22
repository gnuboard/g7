# GDPR (일반 데이터 보호 규정) — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `privacy_policy_slug` | `string` | `privacy` | 개인정보처리방침 페이지 슬러그 |
| `legal_entity_name` | `string` | - | 운영 주체명 |
| `data_storage_location` | `string` | - | 데이터 저장 위치 |
| `banner_enabled` | `boolean` | `true` | 쿠키 배너 노출 |
| `banner_position` | `string` | `bottom_bar` | 배너 위치 |
| `blocked_domains` | `json` | `{"functional":["*.crisp.chat","client.crisp.chat","*.intercom.io","widget.intercom.io","*.tawk.to","embed.tawk.to","cdn.weglot.com","*.weglot.com","*.usercentrics.eu"],"analytics":["google-analytics.com","*.google-analytics.com","googletagmanager.com","*.googletagmanager.com","ssl.google-analytics.com","*.hotjar.com","static.hotjar.com","*.mixpanel.com","cdn.mxpnl.com","*.amplitude.com","cdn.amplitude.com","*.segment.io","*.segment.com","wcs.naver.net","wcs.naver.com","*.beusable.net"],"marketing":["facebook.net","connect.facebook.net","facebook.com","*.facebook.com","doubleclick.net","*.doubleclick.net","googleadservices.com","googlesyndication.com","ads.google.com","*.criteo.com","static.criteo.net","*.adnxs.com","*.taboola.com","cdn.taboola.com","*.outbrain.com","*.kakao.com","analytics.ad.daum.net","platform.twitter.com","*.twitter.com","platform.linkedin.com","*.linkedin.com"]}` | 추적 도메인 차단 목록 |
| `necessary_storage_allowlist` | `array` | `{"localStorage":["g7_locale","g7_color_scheme","g7_cache_version","g7_asset_url_mode*","g7_cart_key","g7-devtools-panel","g7_guest_order_token","g7_guest_order_number","g7_guest_order_expires_at","g7_devtools_*","g7_filters_*","g7_columns_*","g7_order_*","g7_admin_sidebar_collapsed","g7_filter_visibility_*","g7_dismissed_warnings","g7le.*","__sirsoftKginicisMobilePaymentReturnPending","g7.identity.redirectStash","sirsoft-verification_nhnkcp.formStash"],"sessionStorage":["g7:sirsoft-pay_kginicis:pendingClose","g7:sirsoft-pay_nhnkcp:pendingClose","g7:sirsoft-tosspayments:pendingClose","g7.identity.redirectStash","sirsoft-verification_nhnkcp.formStash","__sirsoftKginicisMobilePaymentReturnPending","g7le.*","g7_devtools_*","g7_filters_*","g7_columns_*","g7_order_*","g7_filter_visibility_*"],"cookie":["laravel_maintenance"]}` | 필수 저장 항목 허용목록 |
| `cookie_categories` | `json` | `[]` | 쿠키 카테고리 정의 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`blocked_domains` 가 카테고리별(functional/analytics/marketing) 배열을 담은 단일 JSON 컬럼인
것은, 카테고리 추가·삭제가 스키마 변경이 아니라 값 변경으로 끝나게 하기 위해서입니다 — 새
카테고리를 추가할 때 마이그레이션이 필요하지 않습니다(다만 배너 UI 의 카테고리 목록은 별도
동기화가 필요합니다, §AGENTS.md 수정 시 동반 의무). `cookie_categories` 가 기본값 `[]` 로
비어 있는 것은 4대 표준 카테고리(필수/기능/분석/마케팅)가 이미 코드/Enum(`CookieCategory`)에
고정돼 있어, 이 설정은 그 표준을 벗어나는 **추가** 카테고리를 위한 자리이기 때문입니다.

`necessary_storage_allowlist` 는 기능 쿠키에 동의하지 않은 방문자에게도 저장이 허용되는 항목
목록입니다. 위 표의 기본값은 **신규 설치 시 시드되는 출하 카탈로그**일 뿐이고, 실제 판정에는
저장된 운영자 값이 쓰입니다 — 관리자 환경설정의 「필수 저장 항목」 카드에서 저장소 구분
(`localStorage` / `sessionStorage` / `cookie`)별로 편집합니다. 항목 끝의 `*` 는 앞부분 매칭이며
(`g7_filters_*` 는 `g7_filters_orders_1` 을 포함), `*` 는 끝에만 쓸 수 있습니다. 이 구조 덕분에
새로 설치한 확장이 저장하는 항목을 이 플러그인 수정 없이 운영자가 직접 추가할 수 있습니다.

목록 밖의 항목은 방문마다 파기되는데 그 파기는 예외도 로그도 남기지 않습니다 — 운영자에게는
"설정이 저장되지 않는다" 는 증상만 보입니다. 그래서 화면의 안내 박스가 이 사실과 항목 이름을
찾는 방법을 함께 설명합니다.

`necessary_storage_locked`(`auth_token` · `XSRF-TOKEN` · 세션 쿠키 · `gdpr_session`)는 **설정이
아닙니다.** 스키마에 없으므로 저장 요청에 담아도 반영되지 않고, 코드가 판정 시점에 운영자
목록과 합쳐 적용합니다. 없으면 사이트가 서지 못하는 항목이라 지울 수 있으면 안 되기 때문이며,
세션 쿠키 이름은 `session.cookie` 설정에서 런타임 해석됩니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `privacy` | 개인정보 보호 | `view`, `update` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한이 `view`/`update` 하나씩만 있고 관리자 동의 이력·정책 버전 발행이 별도 권한으로 세분화
되지 않은 것은, 이 플러그인의 관리자 기능 전체가 "개인정보 보호 담당자"라는 하나의 역할
단위로 다뤄지기 때문입니다. 조회와 변경(설정 수정·정책 발행)을 분리해 둔 것은 감사 목적으로
"누가 정책을 발행했는지"와 "누가 그냥 보기만 했는지"를 구분할 필요가 있어서입니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-gdpr-consent-log` | GDPR 동의 이력 | `/admin/plugins/sirsoft-gdpr/consent-log` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
"GDPR 설정"(정책 버전 발행 포함) 화면은 별도 메뉴가 아니라 플러그인 공통 설정 화면
(`관리자 → 플러그인 → GDPR 설정`)에 얹혀 있고, "동의 이력" 조회만 독립 메뉴입니다 — 설정은
가끔 바꾸는 화면이라 플러그인 목록에서 진입해도 충분하지만, 동의 이력은 자주 확인하는
운영 화면이라 별도 메뉴로 빠르게 도달할 수 있어야 하기 때문입니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-gdpr/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`Public\GdprCookieConsentController`/`GdprSettingsController` 는 인증 없이 호출됩니다 —
게스트 방문자도 배너를 봐야 하고 동의를 기록해야 하므로, 공개 라우트로 두되 게스트 식별은
`gdpr_session` 서명 쿠키로 처리합니다. 반대로 관리자·마이페이지 라우트는 코어 인증/권한
미들웨어가 걸립니다 — 이 셋을 하나의 미들웨어 그룹으로 묶지 않고 컨트롤러 계층(Public/User/Admin)
으로 분리한 것이 인증 요구사항의 차이를 드러냅니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
formal `dependencies` 선언이 둘 다 "없음"인데도 README 는 `sirsoft-page`(소프트, 런타임 체크)와
`sirsoft-basic`(레이아웃 확장 주입 지점)을 명시합니다 — 둘 다 manifest 의존성 제약으로
선언할 만큼 강한 결합이 아니기 때문입니다. `sirsoft-page` 미설치는 기능 저하(링크 숨김)로
그치고, `sirsoft-basic` 미사용은 다른 템플릿이 같은 주입 지점을 제공하면 해소됩니다 — 둘 다
"없으면 설치가 막히는" 수준의 의존이 아니라 manifest 의존성 목록에 넣지 않습니다.
<!-- @intent END -->
