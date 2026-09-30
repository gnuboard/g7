# GDPR (일반 데이터 보호 규정) — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 6종 / 호출 지점 16곳. 이 중 2종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다. 훅 이름이 상수·변수로 조립된 호출이 13곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.analytics.consent_revoked` | action | — | `src/Listeners/GdprAnalyticsConsentListener.php:146` |
| `core.privacy.notice_sections` | filter | — | `src/Services/GdprAnnualNoticeService.php:168` |
| `sirsoft-gdpr.consent.granted` | action | 동의 부여 시 발화 | 선언 (호출 위치 미확인) |
| `sirsoft-gdpr.consent.revoked` | action | 동의 철회 시 발화 | 선언 (호출 위치 미확인) |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | 확장이 쿠키 미동의 방문자에게도 필요한 자기 저장 키를 제공 (strictly necessary 허용목록에 합쳐진다) | 선언 (호출 위치 미확인) |
| `sirsoft-gdpr.privacy.annual_notice_due` | action | 개인정보 연간 통지 접수 후 발화 | `src/Services/GdprAnnualNoticeService.php:189` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
위 표의 `core.privacy.notice_sections` 는 **이 플러그인의 훅이 아닙니다.** 코어가 소유한 필터이고
계약 정본은 코어 `docs/extension/hooks.md` 의 「개인정보 통지 섹션 계약」 절입니다. 이 플러그인은
통지문을 조립하는 **소비자**로서 그 필터를 호출할 뿐이고, 표에 실린 것은 위 머리말이 밝힌 대로
소스의 호출 지점을 자동 감지한 결과입니다. 자기 섹션을 기여하려는 확장은 gdpr 이 아니라 **코어
문서의 계약**을 보고 `addFilter` 하면 되며, gdpr 설치 여부와 무관하게 구독할 수 있습니다.

동의 granted/revoked 두 훅 모두 `GdprUserConsent $consent, string $source` 를 인자로 넘깁니다. `$source` 값은
`banner`/`mypage`/`mypage_renew_all`/`register`/`withdraw` 중 하나이며, 어떤 화면에서 동의가
바뀌었는지 구분해야 하는 리스너(예: 배너 동의만 특정 방식으로 처리하고 싶은 경우)는 이 값으로
분기합니다. `revoked` 는 명시적 철회(마이페이지)뿐 아니라 회원탈퇴로 인한 일괄 철회
(`source=withdraw`)에서도 발화됩니다 — "동의 취소" 이벤트를 하나로 통일해 구독자가 두 경로를
따로 처리하지 않아도 되게 합니다.

**필수 저장 키 제공 계약 — `sirsoft-gdpr.necessary_storage.filter_provided_keys` (filter)**

이 플러그인은 쿠키 미동의 방문자의 브라우저에서 허용목록 밖의 저장 키를 부팅마다 지웁니다. 어떤
확장이 설치돼 있는지 모르므로, 동의 전에도 필요한 키(결제창 복귀 기록 · 본인인증 입력 보관 · 비회원
장바구니·주문 조회 토큰 등)는 **그 키를 쓰는 확장이 이 필터로 제공**합니다.

- 인자: `array $keys` — `['localStorage' => string[], 'sessionStorage' => string[], 'cookie' => string[]]`.
  앞선 리스너가 덧붙인 값이 들어 있으므로 **지우지 말고 덧붙여** 돌려줍니다.
- 반환: 같은 모양의 배열. 항목은 운영자 입력과 같은 형식 규칙을 통과해야 받습니다(영숫자·`_ . : @ + -`,
  끝의 `*` 하나만 앞부분 매칭, `*` 단독 불가, 128자 이하). 규칙을 벗어난 항목과 모르는 저장소 종류는 버립니다.
- 시점: 판정(서버 쿠키 게이트)·인라인 페이로드 때마다 요청당 한 번 당겨 모읍니다. 설정에 저장하지 않으므로
  확장을 켜고 끄면 다음 요청부터 반영됩니다.
- 이 플러그인의 클래스를 참조하지 마십시오. 훅 이름과 배열 모양만이 계약이며, 이 플러그인이 없으면 훅이
  발행되지 않을 뿐 제공 확장은 그대로 동작합니다.
- 제공 키는 저장 코드가 실제로 쓰는 저장소 종류에 넣습니다 — 다른 종류에 넣으면 제공해도 지켜지지 않습니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.analytics.consent_session_id` | filter | `GdprAnalyticsConsentListener` | `resolveConsentSessionId` | 10 |
| `core.analytics.tracking_permission` | filter | `GdprAnalyticsConsentListener` | `answerTrackingPermission` | 10 |
| `core.analytics.visitor_has_consent` | filter | `GdprAnalyticsConsentListener` | `answerVisitorHasConsent` | 10 |
| `core.auth.logout` | action (미선언) | `GdprAuthLogoutListener` | `forgetGdprCookies` | 10 |
| `core.auth.record_consents` | action (미선언) | `GdprAuthConsentListener` | `recordRegisterConsents` | 10 |
| `core.notification.after_channel_send` | action (미선언) | `GdprAnnualNoticeListener` | `sent` | - |
| `core.notification.channel_send_failed` | action (미선언) | `GdprAnnualNoticeListener` | `failed` | - |
| `core.notification.filter_default_definitions` | filter | `GdprAnnualNoticeListener` | `defaults` | 20 |
| `core.privacy.manual_run_targets` | filter | `GdprAnnualNoticeListener` | `addManualRunTarget` | 20 |
| `core.user.after_withdraw` | action (미선언) | `GdprUserWithdrawListener` | `handleWithdraw` | 10 |
| `core.user.before_delete` | action (미선언) | `GdprUserDeleteListener` | `cascadePluginData` | 10 |
| `sirsoft-gdpr.consent.revoked` | action (미선언) | `GdprAnalyticsConsentListener` | `relayConsentRevoked` | 10 |
| `sirsoft-gdpr.notification.extract_data` | filter | `GdprAnnualNoticeListener` | `extract` | - |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
`core.auth.record_consents` 는 회원가입 폼에서 받은 동의 값을 코어가 이 플러그인에 **위임**하는
자리입니다 — 회원가입 컨트롤러는 동의 저장 로직을 몰라도 되고, 이 플러그인이 폼 데이터에서
동의 관련 키만 추출해 회원가입과 같은 트랜잭션에서 기록합니다. 나머지 3개
(`logout`/`after_withdraw`/`before_delete`)는 전부 회원 생명주기 이벤트에 반응하는 정리 로직이며,
§핵심 흐름(AGENTS.md)에서 다룬 대로 탈퇴와 완전삭제는 반드시 구분해서 처리합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `GdprAnalyticsConsentListener` | 4개 | 명시 등록 | ✅ | `src/Listeners/GdprAnalyticsConsentListener.php` |
| `GdprAnnualNoticeListener` | 5개 | 명시 등록 | ✅ | `src/Listeners/GdprAnnualNoticeListener.php` |
| `GdprAuthConsentListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/GdprAuthConsentListener.php` |
| `GdprAuthLogoutListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/GdprAuthLogoutListener.php` |
| `GdprUserDeleteListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/GdprUserDeleteListener.php` |
| `GdprUserWithdrawListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/GdprUserWithdrawListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
4개 리스너가 전부 훅 1개씩만 구독하는 것은 각자 트리거가 회원 생명주기의 서로 다른 순간
(로그인 로그아웃/동의 기록/탈퇴/완전삭제)이라 합쳐도 이득이 없기 때문입니다.
`GdprAuthLogoutListener` 는 로그아웃 시 `gdpr_session` 게스트 쿠키를 폐기합니다 — 로그인
후에는 신원이 회원으로 바뀌므로, 로그아웃 시 남아 있는 게스트 세션 쿠키가 다음 방문자와
뒤섞이지 않도록 정리하는 것입니다.

`gdpr_session` 은 쿠키 암호화 대상에서 빠져 있습니다(`GdprServiceProvider::boot()` 의
`EncryptCookies::except`). 이 쿠키는 API 응답이 서명값 그대로 심는데, 화면 요청 쪽의 쿠키 암호화가
그 값을 복호화하다 버리면 화면 요청에서는 동의한 게스트도 「식별 불가 = 미동의」로 읽힙니다 —
코어 방문자 통계의 서버 기록이 동의자의 첫 화면을 빠뜨리던 원인입니다. 위변조는 서명
(`IssuesGuestSessionCookie`)으로 막습니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/cookie_banner.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `user_global_overlay` |
| `resources/extensions/mypage_privacy_tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
`cookie_banner.g7.html` 은 사이트 전역에 배너를 띄우는 조각(코어/템플릿 공용 확장 지점에 주입)이고,
`mypage_privacy_tab.g7.html` 은 마이페이지에 "개인정보/동의 관리" 탭을 추가하는 조각입니다. 둘 다
`sirsoft-basic` 템플릿의 확장 지점에 의존하므로, 다른 사용자 템플릿을 쓰려면 그 템플릿에도
같은 지점이 있어야 두 UI 가 정상 노출됩니다(README "다른 확장과의 연동" 참고).
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `CookieConsentMiddleware` | `everything` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
대상이 `everything`(모든 요청)인 이유는 functional 미동의 상태에서 어느 응답이 쿠키를
심으려 하는지 이 플러그인이 미리 알 수 없기 때문입니다 — 특정 라우트만 골라 부착하면 그
목록에서 빠진 응답의 쿠키는 게이팅되지 않습니다. 등록은 `GdprServiceProvider::boot()` 에서
Laravel 커널의 `prependMiddlewareToGroup('web'|'api')` 로 이뤄지며, 코어의 미들웨어
self-gate 규정(대상 명시)에 대한 근거가 바로 이 전면 적용 필요성입니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
동의 상태 변경은 실시간 브로드캐스트 대상이 아닙니다 — 같은 방문자가 여러 탭을 열어둔 상태를
동기화해야 할 만큼 시급한 이벤트가 아니고, 다음 페이지 요청 시 미들웨어가 최신 상태를 다시
평가하므로 자연스럽게 수렴합니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `sirsoft-gdpr:send-annual-notices --scheduled` | `daily` | 개인정보 연간 통지 |
<!-- @generated:schedules END -->

<!-- @intent START -->
동의 이력은 영구 보존이 원칙(Art.30)이라 배치로 정리할 대상이 없습니다. 게스트 세션 데이터의
만료·정리는 코어 세션 메커니즘에 위임하며, 이 플러그인이 별도 정리 스케줄을 두지 않습니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `annual_privacy_notice` | `mail`, `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
동의 부여/철회는 방문자 본인의 조작 결과이므로 본인에게 알림을 보낼 이유가 없고, 정책 버전
발행처럼 운영자가 이미 인지하고 수행한 조작도 마찬가지입니다. 관리자에게 알려야 할 이벤트가
생기면(예: 대량 철회 급증 같은 이상 신호) 그때 코어 알림 정의를 신설합니다.
<!-- @intent END -->
