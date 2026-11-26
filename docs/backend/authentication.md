# 인증 및 세션 처리

> **관련 문서**: [README.md](README.md) | [middleware.md](middleware.md) | [password-policy.md](password-policy.md) (비밀번호 작성 규칙·재사용 금지·만료·로그인 변경 단계)

---

## TL;DR (5초 요약)

```text
1. Laravel Sanctum 토큰 전용 인증 (Bearer 토큰만 사용)
2. 세션은 /dev 대시보드 전용: StartApiSession 미들웨어로 로그인/로그아웃 라우트에서만 생성
3. 로그아웃: 토큰 삭제 + 세션 무효화 (세션 존재 시)
4. 슬라이딩 만료: 토큰 잔여시간 절반 미만 시 자동 갱신
5. 401 응답 시 세션 쿠키 자동 정리 (무조건적 forget cookie)
```

---

## 목차

1. [Laravel Sanctum 토큰 전용 인증](#laravel-sanctum-토큰-전용-인증)
2. [StartApiSession 미들웨어](#startapisession-미들웨어)
3. [외부 인증 로그인 · 로그인 인계](#외부-인증-로그인--로그인-인계-720)
4. [외부 인증 확장점](#외부-인증-확장점-720) (예제: 헤더 인증 확장)
5. [비밀번호 없는 회원](#비밀번호-없는-회원-720)
6. [토큰 슬라이딩 만료](#토큰-슬라이딩-만료)
7. [로그아웃 구현 원칙](#로그아웃-구현-원칙)
8. [401 응답 시 세션 쿠키 정리](응답-시-세션-쿠키-정리)
9. [Laravel Sanctum plainTextToken 구조](#laravel-sanctum-plaintexttoken-구조)
10. [세션 존재 확인](#세션-존재-확인)
11. [세션 무효화 3단계](#세션-무효화-3단계)
12. [완전한 로그아웃 구현 예시](#완전한-로그아웃-구현-예시)
13. [안티 패턴](#안티-패턴)
14. [운영 설정 (2단계 인증·IP 접근 제한·비밀번호 정책)](#운영-설정-2단계-인증ip-접근-제한비밀번호-정책)

---

## Laravel Sanctum 토큰 전용 인증

그누보드7 프로젝트는 SPA(Single Page Application)를 위한 **토큰 전용 인증 방식**을 사용합니다:

**특징**:
- **Bearer 토큰 전용**: API 인증은 `Authorization: Bearer {token}` 헤더로만 수행
- **`EnsureFrontendRequestsAreStateful` 미사용**: 세션 기반 Sanctum Guard를 사용하지 않음
- **`currentAccessToken()`**: 항상 `PersonalAccessToken` 반환 (TransientToken 없음)
- **세션은 /dev 대시보드 전용**: `StartApiSession` 미들웨어가 로그인/로그아웃 라우트에서만 세션 생성

**인증 가드 설정** (`bootstrap/app.php`):
```php
// stateful 미들웨어 없음 — Sanctum Guard는 토큰만 체크
$middleware->alias([
    'start.api.session' => \App\Http\Middleware\StartApiSession::class,
]);
```

---

## StartApiSession 미들웨어

로그인/로그아웃 라우트에서만 세션을 시작하는 전용 미들웨어입니다.

**목적**:
- `/dev` 대시보드(Telescope, Horizon 등)는 세션 기반 `web` guard 인증 사용
- API 로그인 시 `Auth::guard('web')->login($user)`으로 세션에 사용자 저장
- 로그아웃 시 세션 무효화

**동작 방식**:
```php
// StartApiSession은 세션 파이프라인만 실행 (CSRF 검증 없음, sanctum 속성 미설정)
EncryptCookies → AddQueuedCookiesToResponse → StartSession → Controller
```

**적용 라우트**:
```php
// 로그인 라우트 (세션 생성)
Route::post('login', ...)->middleware('start.api.session');

// 로그아웃 라우트 (세션 무효화)
Route::post('logout', ...)->middleware('start.api.session');
```

**핵심 차이** — `stateful` vs `start.api.session`:

| 항목 | `stateful` (제거됨) | `start.api.session` |
|------|---------------------|---------------------|
| 적용 범위 | 모든 API 라우트 | 로그인/로그아웃만 |
| Sanctum 세션 인증 | ✅ 활성화 | ❌ 비활성화 |
| CSRF 검증 | ✅ 포함 | ❌ 미포함 |
| `sanctum` 속성 설정 | ✅ 설정 | ❌ 미설정 |
| 세션 쿠키 발급 | 모든 API 응답 | 로그인/로그아웃 응답만 |

**구현 파일**: `app/Http/Middleware/StartApiSession.php`

---

## 서버 세션과 토큰의 짝 (7.2.0)

로그인(`/api/auth/login`·`/api/auth/admin/login`)·회원가입·토큰 갱신(`/api/user/auth/refresh`·`/api/admin/auth/refresh`) API 는
`start.api.session` 으로 서버 세션을 열고, 세션에 **그 로그인의 토큰 ID**(`g7.screen_token_id`)를 함께 저장한다(`ScreenAuth::pair()`·`repair()`).
같은 서버의 개발자 화면(`routes/custom.php`)은 `ScreenAuth::user()` 로 로그인 회원을 판정하는데, 세션 사용자에 더해
짝 토큰이 아직 살아 있는지(회수·만료되지 않았는지)를 확인한다. 그래서 다음이 성립한다.

- 토큰 회수(로그아웃·관리자 강제 로그아웃·비밀번호 변경)나 만료 뒤에는 세션이 남아 있어도 개발자 화면에서 비회원이다. 이때 웹 세션 로그인은 정식 로그아웃으로 끝낸다(라라벨 `Logout` 이벤트가 난다). 회원이 삭제된 경우는 로그아웃할 회원이 없어 세션의 표식만 지운다.
- 토큰 갱신은 짝을 새 토큰으로 바꾼다. 회원가입 직후에도 개발자 화면에서 로그인으로 보인다.
- 짝이 없는 세션(7.2.0 이전 로그인)은 비회원이다 — 다시 로그인하면 된다. 그누보드7 화면(Bearer 토큰)은 영향이 없다.
- 개발자 화면 요청도 토큰 만료를 연장한다(`TokenExpirySlider` — API 의 `RefreshTokenExpiration` 과 같은 규칙).
- 로그인 화면에서 로그인과 거의 동시에 나가는 공개 API(로그인 수단 묻기 `/api/auth/login/discover`·`/api/auth/admin/login/discover` 등)에는
  `start.api.session` 을 걸지 않는다. 브라우저 쿠키는 마지막에 도착한 응답의 것이 남으므로, 로그인 전 세션을 실은 응답이 로그인 응답보다
  늦게 오면 짝지은 세션 쿠키를 덮어 개발자 화면이 비회원이 된다. 세션을 여는 라우트는 로그인을 완료하거나 짝을 바꾸는 라우트로 한정한다.

사용법은 [개발자 화면 가이드](../custom-development.md#3-로그인-판정과-내부-api-도구).

---

## 외부 인증 로그인 · 로그인 인계 (7.2.0)

소셜 로그인처럼 **비밀번호가 아닌 수단으로 본인을 확인하는 확장**이 회원을 로그인시키는 공용 계약이다. 확장은 토큰을 직접
만들지 않는다(`createToken()`·`Auth::login()` 금지) — 그러면 계정 잠금·회원 상태·2단계 인증(인증 앱 포함)·비밀번호 변경·휴면 해제·
로그인 완료 훅(장바구니 병합 등)이 빠진다.

### 흐름

```text
확장: 본인 확인 완료(예: OAuth 콜백) → 회원 확정
  → LoginHandoffService::issue($user, ['login_method' => 'social:kakao', 'skip_two_factor' => false])
  → 302 /login?login_handoff={코드}&redirect={복귀 경로}
로그인 화면: 진입 시 엔진 액션 loginHandoff → POST /api/auth/login/handoff {code}
코어: 코드 소비(1회) → AuthService::loginVerifiedUser($user, $context)
  → 잠금 → 회원 상태 → 관리자 경로 판정 → 2단계 인증 → 비밀번호 변경(비밀번호 회원만) → 휴면 해제 + 토큰
  → 응답은 POST /api/auth/login 과 같은 세 형태(인증 완료 · two_factor_required · password_change_required)
```

| 항목 | 값 |
| --- | --- |
| 코드 | 32바이트 난수(base64url), **120초**, 한 번만 통한다. 캐시 키는 코드 원문이 아니라 해시다 |
| 브라우저 묶기 | 코드는 **발급한 브라우저에서만** 교환된다. `issue()` 가 HTTP 요청 안에서 불리면 묶음 쿠키 `g7_login_bind`(HttpOnly, SameSite=Lax, Path `/api/auth`, 120초, HTTPS 면 Secure)를 쿠키 대기열에 넣고, 교환 때 그 쿠키를 대조한다. 다르거나 없으면 코드를 소비하고 같은 422 — 가로챈 코드를 다른 브라우저에서 쓸 수 없다 |
| 발급 메서드 | web 라우트(콜백 → 302)는 `issue()`. **api 라우트에서 인계 코드를 응답으로 줄 때**는 `issueTicket()` 이 돌려주는 `HandoffTicket{code, cookie}` 를 받아 `->withCookie($ticket->cookie)` 로 직접 싣는다(api 그룹에는 쿠키 대기열 미들웨어가 없다). 묶지 않은 코드 `issueUnbound()` 는 개발·확인용 명령 `php artisan auth:handoff-issue {email} --guard=user\|admin`(운영 환경 거절)과 시험만 쓴다 |
| `context.login_method` | 로그인 수단 이름(**필수** — 예 `social:kakao`, 비면 `issue()`·`loginVerifiedUser()` 가 `InvalidArgumentException`). 로그인 완료 훅 `core.auth.after_login` 둘째 인자의 `login_method` 로 실린다 — 2단계 인증(인증번호 재발송 포함)·비밀번호 변경을 거쳐도 이어진다. 비밀번호 로그인은 `password` |
| `context.skip_two_factor` | `true` 면 2단계 인증 단계만 건너뛴다(운영자가 그 수단에 2단계 인증을 끈 경우). 잠금·상태·비밀번호 변경·휴면은 그대로 |
| `context.max_session_minutes` | 정수 1~10080. 이 로그인의 세션 수명 상한 — 토큰 만료가 상한을 넘지 않고, 사용 중 연장·토큰 갱신(`refresh`)도 넘지 못한다. 상한이 지난 토큰의 갱신은 401 |
| `context.external_ref` | 문자열 ≤100. 외부 세션 참조(IdP 세션 id 등). 로그인 완료 훅 문맥에 실린다(`token_id` 와 함께) |
| 실패 | 코드 없음·만료·재사용·다른 경로 코드·묶음 쿠키 불일치·회원 삭제 → 같은 **422** (`errors.code`, `auth.login_handoff_invalid`). 회원 상태 거절 → **401** + `errors.email`(상태 문구). 잠금 → **423** |
| 관리자 경로 | `issue($user, $context, 'admin')` → `/admin/login?login_handoff={코드}` → 관리자 로그인 화면이 `POST /api/auth/admin/login/handoff` 로 교환한다. 응답은 관리자 비밀번호 로그인과 같은 세 형태, 관리자가 아니면 403 `auth.admin_required`(휴면 상태 유지). 사용자 코드를 관리자 경로에(또는 반대로) 쓰면 422 |
| 문맥 값 | 범위 밖 값은 버린다(없는 것과 같다). 공개 정규화 `AuthService::normalizeLoginContext(array)` |

- 서버 쪽에서 바로 로그인 결과가 필요하면 `AuthService::loginVerifiedUser(User $user, array $context = [], string $guard = 'user')` 를
  부를 수 있다. 본인 확인 책임은 호출자에게 있다 — 이 메서드는 「이 회원이 본인이다」를 믿고 관문만 적용한다.
- 관리자 로그인 경로(`guard = admin`)는 관리자가 아닌 회원을 **세션을 열기 전에** 거절한다(휴면 해제·로그인 완료 훅·2단계 인증 메일보다 앞).
- 이메일이 없거나 받을 수 없는 대체 주소(`.invalid`)인 회원은 메일 2단계 인증 대상이 아니다(인증 앱을 등록했으면 인증 앱으로 진행).

## 외부 인증 확장점 (7.2.0)

회사 계정 로그인(SSO), 사내 디렉터리 로그인, 인증 프록시 헤더처럼 **사이트 밖에서 본인을 확인하는 확장**이 코어 관문을 고치지 않고
붙는 자리다. 코어는 수단 이름(SSO·SAML·LDAP 등)을 모른다 — 확장이 아래 계약에 답한다. 확장이 없으면 모두 지금 동작 그대로다.
훅 인자 표는 [훅 문서 — 외부 인증 확장점 훅](../extension/hooks.md#외부-인증-확장점-훅-720)에 있다.

| 확장점 | 무엇을 | 코어 동작 |
| --- | --- | --- |
| 로그인 인계 | 본인 확인을 마친 회원을 로그인시킨다 | 위 「외부 인증 로그인 · 로그인 인계」(사용자·관리자, 브라우저 묶기) |
| 비밀번호 인증 정책 `core.auth.password_auth_decision` | 회사 계정 회원의 비밀번호 로그인·재설정·설정을 막는다 | 식별자만으로 1차 판정(계정 잠금 판정·비밀번호 확인 전 — 막힌 시도는 실패 횟수에 들지 않는다), 비밀번호가 맞은 뒤 회원 기준 2차 판정(그 전에 세션을 닫는다). 막히면 로그인 **403**·재설정 **422** `errors.code = auth.password_auth_disabled` + `errors.method_label`·`errors.redirect_url`(같은 내용이 `data` 에도). 재설정 요청이 회원 기준으로 막히면 계정 열거를 막으려고 일반 성공 응답 + 액션 `core.auth.password_reset_blocked`. 허용 판정에 `requireTwoFactor` 를 주면 사이트 설정과 무관하게 2단계 인증을 탄다(수단이 없으면 422 `auth.two_factor_means_required`). 막힌 회원은 비밀번호 만료 요구에서도 빠진다. 비밀번호 설정 판정(`PasswordAuthPolicyService::decideSetPassword()` — 본인 `set_password`, 관리자가 남의 비밀번호를 정하면 `admin_set_password`)은 회원 응답에도 미리 실린다(마이페이지 `can_set_password`·`password_set_blocked_message`, 관리자 회원 상세 `password_status.can_set`·`set_blocked_message`) — 화면이 칸 대신 안내를 보인다 |
| 외부 자격 증명 검증자 `ExternalCredentialVerifierInterface` | 로그인 폼의 아이디·비밀번호를 사이트 밖(디렉터리 등)에서 검증한다 | 필터 `core.auth.registered_credential_verifiers` 로 등록. `login()` 맨 앞에서 등록 순서대로 `match()` → 잠긴 회원이면 `verify()` 를 부르지 않고 423 → `ExternalCredentialResult::authenticated()` 면 `loginVerifiedUser()` / `rejected($reasonKey, $countsAsFailure)` 면 비밀번호 실패와 같은 401(실패 횟수는 `countsAsFailure` 일 때, 비밀번호 없는 회원도 센다) / `stepRequired($path)` 면 409 `errors.code = auth.external_step_required` + `errors.redirect_url`(동의·연결 확인 화면). 일치하는 검증자가 없으면 비밀번호 경로. 아이디를 받는 검증자가 있으면 로그인 요청의 `email` 칸이 이메일 형식을 강제하지 않고, 화면에는 `appConfig.auth.login_identifier.{user,admin} = email_or_username` 으로 알린다 |
| 진입 판정 `core.auth.login_discovery` | 이메일 칸을 벗어날 때 「이 주소는 회사 계정으로」 안내 | `POST /api/auth/login/discover`·`/api/auth/admin/login/discover` `{identifier}` → `{method: password\|external, label, redirect_url}`. 회원 유무와 상관없이 같은 모양이 나가야 한다(식별자 모양으로만 판정) |
| 자동 이동 `core.auth.login_auto_redirect` | 로그인 화면에 오면 바로 회사 계정 로그인으로 | `appConfig.auth.auto_redirect.{user,admin}`. 화면은 `?local=1`(비상 계정 진입로)·`login_handoff`·`sso_error`·`sso_pending`·`sso_link` 쿼리가 있으면 이동하지 않는다(오류로 돌아온 화면이 다시 떠나면 무한 왕복) |
| 로그아웃 이동 `core.auth.logout_redirect_url` | 로그아웃 뒤 IdP 로그아웃 등으로 보낸다 | 두 로그아웃 API 의 `data.redirect_url`(같은 출처 경로만, 토큰 삭제 전에 계산). 엔진은 그 경로로, 없으면 로그인 화면으로 |
| 칸 잠금 `core.user.locked_fields` | 회사가 관리하는 이름·연락처 등을 회원·관리자 화면에서 못 바꾸게 | 응답 `locked_fields`, 다른 값이 오면 422 `user.field_locked`. 확장의 동기화(서비스 직접 호출)는 막지 않는다 |
| 가입 닫기 `core.auth.self_registration_open` | 직원 전용 사이트 | 환경설정 「일반 회원가입 받기」(`security.registration_enabled`)와 이 필터. 닫히면 공개 가입 API 403 `auth.registration_disabled`. 확장 내부 가입(`register()` 직접 호출 — 회사 계정 자동 생성·관리자 추가)은 막지 않는다. 화면에는 두 값을 따로 싣는다 — 실효값 `appConfig.auth.registration_open`(설정 + 이 필터, 가입 폼·가입 링크가 읽는다)과 원 설정값 `settings.security.registration_enabled`(관리자 스위치가 저장값을 보인다) |
| 확장 내부 가입 | 회사가 만드는 계정 | `AuthService::register($data, ['issue_token' => false, 'identity_policy' => 'skip', 'identity_policy_reason' => '…', 'record_consents' => false])` — 본인인증 정책 생략은 사유 필수(활동 로그 `user.register_identity_policy_skipped`). 공개 가입 API 는 이 옵션을 받지 않는다 |
| 회원 차단·강제 로그아웃 | 퇴사·계정 정지 | `UserService::blockUser($user, ['reason_key' => …, 'source' => …])`(이미 차단이어도 토큰 전량 회수)·`unblockUser()`·`revokeAllSessions($user, ?$actor)`. 최고관리자는 대상이 될 수 없다 |
| 시스템 작업 문맥 `SystemOperation::run($reason, $fn)` | 행위자 없는 동기화가 본인인증 정책(428)에 막히지 않게 | 안에서는 회원 수정·탈퇴·삭제 훅의 본인인증 정책을 건너뛰고 기록을 남긴다. **사람이 행위자인 요청에서는 쓰지 않는다** |

공통 규칙:

- **회원 상태는 공통 관문이 본다.** api 그룹 전체에 붙은 `EnsureActiveUser` 가 인증된 회원의 상태(차단·탈퇴·휴면·가입 후 인증 대기)를
  다시 본다 — `auth:sanctum` 라우트는 403, `optional.sanctum` 라우트는 손님으로 낮춰 통과. 확장 라우트에 상태 검사를 따로 붙이지 않는다.
  비활성 회원이 써야 하는 경로만 표식 `allow.inactive_user` 를 붙인다(코어는 `api.identity.*` 하나).
- 계정 잠금이 걸리는 순간 그 회원의 토큰을 모두 회수한다(다른 기기에 남은 로그인도 끝난다).
- 확장이 요청 필터로 최고관리자 지정(`is_super`)을 바꾸면 코어가 되돌리고 경고를 남긴다.
- 화면에 돌려주는 경로는 모두 같은 출처 경로만 남는다(`App\Support\SameOriginPath::normalize()`).

### 예제: 헤더 인증 확장

사내 인증 프록시가 본인을 확인한 뒤 `X-Remote-User` 헤더에 이메일을 실어 보내는 사이트에 붙이는 확장의 핵심이다. 국내 상용 SSO 에이전트처럼
헤더·쿠키로 신원을 넘기는 제품도 같은 모양이다. 이 코드는 `tests/Feature/Docs/HeaderAuthExampleTest` 가 그대로 돌린다(예제 클래스
`tests/Support/Docs/HeaderAuthExample.php`).

```php
// 서비스 프로바이더 boot() — 확장 웹 라우트는 화면 catch-all 이 비켜 가는 /plugins/{식별자}/… 아래에 둔다
Route::middleware('web')->get('/plugins/header-auth/login', [HeaderAuthController::class, 'login']);
HookManager::addFilter('core.auth.password_auth_decision', [HeaderAuthController::class, 'passwordAuthDecision'], 10, 2);

public function login(Request $request, UserRepositoryInterface $users, AuthService $auth, LoginHandoffService $handoff): RedirectResponse
{
    // ① 헤더는 신뢰 프록시(TRUSTED_PROXIES)가 붙인 것만 믿는다 — 직접 들어온 요청의 헤더는 누구나 위조할 수 있다
    abort_unless($request->isFromTrustedProxy(), 403);

    $email = mb_strtolower(trim((string) $request->header('X-Remote-User', '')));
    abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 401);

    // ② 회원을 찾거나 만든다 — 회사가 만드는 계정이라 본인인증 정책·동의 기록을 건너뛴다(사유 필수)
    $user = $users->findByEmail($email) ?? $auth->register(
        ['name' => strstr($email, '@', true), 'email' => $email],
        ['issue_token' => false, 'identity_policy' => 'skip', 'identity_policy_reason' => 'external:header', 'record_consents' => false],
    )['user'];

    // ③ 토큰을 직접 만들지 않는다 — 인계 코드를 받아 로그인 화면이 교환한다(잠금·상태·2단계 인증·휴면은 코어가 처리)
    $code = $handoff->issue($user, ['login_method' => 'external:header']);

    return redirect('/login?login_handoff='.urlencode($code));
}

// ④ 회사 도메인의 비밀번호 로그인·재설정을 막고 헤더 인증 진입으로 안내한다
public static function passwordAuthDecision(?PasswordAuthDecision $decision, array $subject): ?PasswordAuthDecision
{
    if ($decision !== null || ! str_ends_with(mb_strtolower((string) ($subject['identifier'] ?? '')), '@corp.example')) {
        return $decision;
    }

    return PasswordAuthDecision::deny(
        basis: PasswordAuthDecision::BASIS_IDENTIFIER,
        methodLabel: ['ko' => '회사 계정', 'en' => 'Company account'],
        redirectUrl: '/plugins/header-auth/login',
    );
}
```

- 콜백이 web 라우트이므로 `issue()` 가 묶음 쿠키를 쿠키 대기열에 넣고 302 응답에 실린다. api 라우트에서 코드를 준다면 `issueTicket()` + `withCookie()`.
- 확장이 GDPR 같은 쿠키 동의 확장과 함께 쓰여도 `g7_login_bind` 는 필수 쿠키로 다뤄진다(동의 확장의 잠금 목록). 확장이 따로 굽는 쿠키는 그 확장이 동의 확장의 계약으로 필수 여부를 알린다.

## 비밀번호 없는 회원 (7.2.0)

외부 인증으로만 가입한 회원은 `users.password` 가 `NULL` 이다. 판정은 `User::hasPassword()`(저장 속성을 직접 본다 — 접근자·
`hashed` 캐스트를 거치지 않는다). 응답에는 `has_password`(bool)만 싣고 값은 싣지 않는다.

| 면 | 동작 |
| --- | --- |
| 가입 | 확장이 `AuthService::register($data, ['issue_token' => false])` 로 비밀번호·토큰 없이 만든다(동의 기록·역할·가입 훅·본인인증 정책은 그대로). 공개 가입 API 는 비밀번호 필수 그대로 |
| 비밀번호 로그인 | 항상 실패(`invalid_credentials`). 틀린 시도를 세지 않아 계정이 잠기지 않는다 — 응답은 같아 비밀번호 부재가 드러나지 않는다 |
| 비밀번호 처음 설정 | 마이페이지 비밀번호 변경·정보 수정 API 는 현재 비밀번호 없이 받는다(보내면 그 값은 버린다 — 옛 화면 호환, 본인 확인은 본인인증 정책). 비밀번호 재설정 메일도 설정 경로로 쓰인다 |
| 비밀번호 만료 정책 | 비대상 — 판정·배치 후보·사전 안내·만료 처리(토큰 회수)·로그인 변경 관문·관리자 지정 변경 요구에서 빠진다 |
| 본인 재확인 | 아래 「민감 작업 재확인」 |

### 민감 작업 재확인

비밀번호 확인 관문(마이페이지 정보 수정 진입 `POST /api/me/verify-password` · 앱 키 재생성 `POST /api/admin/settings/regenerate-app-key` ·
관리자 본인 정보 수정 `PUT /api/admin/users/{본인}`)은 `ReauthenticationService` 를 함께 쓴다.

- 비밀번호 회원: 종전대로 `password`.
- 비밀번호 없는 회원: 코어 본인인증으로 확인한 토큰 `verification_token` — 화면은 `POST /api/identity/challenges`
  (`purpose: sensitive_action`, **`provider_id` 를 보내지 않는다**)로 확인을 시작하고, `POST /api/identity/challenges/{id}/verify` 로 확인해
  받은 토큰을 관문 요청에 싣는다. 토큰은 그 회원 본인 것·그 목적·미소비·미만료여야 하고, 관문을 통과하면 소비된다.
- 수단은 서버가 회원별로 고른다(7.2.0). 목적에 정해진 제공자가 그 회원에게 쓸 수 없으면(예: 메일 제공자인데 받을 수 없는 대체 이메일 회원)
  같은 목적을 지원하는 다음 제공자로 넘어간다 — 제공자가 `UserScopedIdentityProviderInterface::canChallengeUser(User)` 로 답한다.
  이 계약을 구현하지 않은 제공자(휴대폰 본인확인 등)는 폴백으로 고르지 않고, 운영자가 그 목적에 지정했을 때만 쓴다.
  응답 `render_hint=external_redirect` 면 화면은 `redirect_url`(회사 계정 다시 로그인 등)로 갔다가 `?verification_token=` 을 들고 돌아와 원래 요청을 다시 보낸다.
- 토큰이 무효(남의 것·이미 씀·만료·다른 목적)면 **422** `user.reauthentication_invalid`.

#### 비밀번호 칸으로 오는 옛 화면 (7.2.0)

비밀번호 없는 회원을 모르는 옛 화면(7.1.x 템플릿)은 비밀번호 칸에 무엇이든 넣어 보낸다. 서버는 그 값을 쓰지 않는다.

| 요청 | 비밀번호 없는 회원일 때 |
| --- | --- |
| `POST /api/me/verify-password` `{password}` | **428** 본인인증 요구 |
| `POST /api/admin/settings/regenerate-app-key` `{password}` | **428** 본인인증 요구 |
| `PUT /api/admin/users/{본인}` 에 `password` + 채운 `current_password`, `verification_token` 없음 | **428** 본인인증 요구(다른 입력 오류가 있으면 그 422 가 먼저). `current_password` 를 비워 보내면 종전대로 `verification_token` 필수 422 |
| `PUT /api/me/password` · `PUT /api/me` 의 `current_password` | 버린다(보내지 않은 것과 같다). 본인 확인은 본인인증 정책 `core.profile.password_change` |

428 의 `verification` 은 목적 `sensitive_action`, 정책 키 `core.reauthentication`(정책 표의 행이 아니다 — challenge 요청에 실려 와도 무시된다),
`provider_id` 없음(수단은 challenge 를 시작할 때 서버가 회원별로 고른다), `return_request` = 원 요청(경로+쿼리)이다. 엔진의 본인인증 가로채기가
템플릿 인증 창을 열고, 확인이 끝나면 `?verification_token=` 을 붙여 같은 본문으로 원 요청을 다시 보낸다 — 관문은 위 토큰 규칙(본인·목적·1회)으로
통과시킨다. 비밀번호 있는 회원과 새 화면(7.2.0)의 계약은 바뀌지 않는다.

---

## 토큰 슬라이딩 만료

G7은 **슬라이딩 만료(Sliding Expiration)** 방식으로 토큰 만료 시간을 자동 갱신합니다.

### 동작 원리

```
[로그인] ───────────────────────────────────────> [만료]
   0분                                            60분
          ↑
        30분에 API 요청 발생 (절반 미만 남음)
          │
          └─────────────────────────────────────> [만료]
         30분                                      90분
```

### 갱신 조건

토큰 만료 시간은 다음 조건을 **모두** 만족할 때 갱신됩니다:

| 조건 | 설명 |
|------|------|
| 인증된 요청 | `$request->user()`가 존재 |
| PersonalAccessToken | 세션이 아닌 토큰 인증 |
| 만료 시간 설정됨 | `expires_at`이 null이 아님 (무한대 토큰 제외) |
| 절반 미만 남음 | 잔여 시간 < 설정값의 50% |

### 설정값

환경설정의 `security.auth_token_lifetime` 값을 사용합니다:
- **기본값**: 30분
- **범위**: 0~3600분 (0은 무한대)

### 연장은 조건부 UPDATE 로만 (7.1.6)

미들웨어는 컨트롤러가 실행된 **뒤**에 만료 시각을 늘립니다. 그 사이 컨트롤러가 같은 토큰을 지웠다면(로그아웃·토큰 갱신)
그 토큰 객체를 `save()` 하면 Eloquent 가 지워진 행을 INSERT 로 되살립니다 — 로그아웃한 옛 토큰이 계속 통하던
KVE-2026-3187 의 원인입니다. 그래서 연장은 `UserRepositoryInterface::extendTokenExpiration($tokenId, $expiresAt)`
(`WHERE id = ?` UPDATE, 0행이면 `false`)로만 하고, 메모리의 토큰이 이미 지워졌으면(`! $token->exists`) 건너뜁니다.
연장에 성공했을 때만 메모리 값과 세션 수명을 맞춥니다. 요청 뒤에 토큰을 고치는 다른 코드도 `save()` 대신 조건부 갱신을 씁니다.

### 토큰을 폐기하면 web 세션도 지운다 (7.1.6)

모든 기기 로그아웃, 비밀번호 변경 기록 지점(`PasswordChangeService::recordChange()` — 본인 변경·관리자 지정·메일 재설정·
터미널 재설정 공통), Active 외 상태 전환(정지·휴면·탈퇴·삭제), 비밀번호 만료의 강제 폐기는 토큰 삭제 옆에서
`UserRepositoryInterface::deleteSessionsByUserIds($ids, $exceptSessionId)` 로 그 회원의 web 세션도 지웁니다. 본인이
비밀번호를 바꾸며 현재 토큰을 남기는 경우는 현재 세션을 제외합니다.

- 세션 드라이버가 `database` 일 때만 `sessions` 표에서 지웁니다. 다른 드라이버(`file`·`redis`·`cookie` 등)는 회원별로
  세션을 찾을 수 없어 0 을 돌려주고 debug 로그만 남깁니다 — 그런 사이트는 세션 수명(`session.lifetime`)이 끝날 때까지
  web 세션이 남습니다.
- API 그룹은 `auth:sanctum` 이 세션보다 먼저 판정하므로 남은 세션으로 API 가 열리지는 않습니다. 남은 세션이 쓰이는 곳은
  web 그룹(`Auth::user()` 를 읽는 미들웨어 등)입니다.

### 로그인 2단계 오답·재발송 규칙 (7.1.6)

- 2단계 코드 오답(`INVALID_CODE`·`MAX_ATTEMPTS`)은 `core.auth.login_failed` 를 `stage = 'two_factor'` 문맥(`user_id`·
  `challenge_id` 포함)으로 발행해 **비밀번호 오답과 같은 로그인 실패 횟수**에 더합니다. 그래서 같은 잠금 기준·잠김 안내(423)·
  「비밀번호 찾기로 해제」가 그대로 적용됩니다. 잠긴 계정은 코드를 확인하기 전에 423 입니다. 활동 로그 문구는
  `activity_log.description.auth_two_factor_failed`.
- 실패 횟수는 `UserRepository::incrementFailedAttempts()` 의 단일 UPDATE(`failed_login_attempts = CASE … + 1 END`)로
  셉니다 — 읽고 +1 해 저장하던 방식은 동시 오답에서 횟수를 잃어 잠금을 피할 수 있었습니다.
- 재발송(`…/login/two-factor/resend`): 수단이 코드를 보내지 않으면(`ChallengeDeliveryAwareProviderInterface::deliversChallengeCode()`
  가 `false`, 또는 challenge 채널이 `app`) 422 `auth.two_factor_resend_not_supported`, 메일은 직전 발송 뒤 60초 안이면 429
  `auth.two_factor_resend_cooldown`(`Retry-After`)입니다.
- 현재 비밀번호 재확인(`/api/me/verify-password`, 확장의 비밀번호 재확인)은 `PasswordReconfirmationService::attempt()` 를
  씁니다 — 회원 단위 키 `password-reconfirm:{userId}`, 상한 `security.max_login_attempts`, 대기 `security.login_lockout_time` 분.
  상한을 넘으면 비밀번호를 계산하기 전에 `PasswordReconfirmThrottledException`(429)입니다.

### 구현 파일

- **미들웨어**: `app/Http/Middleware/RefreshTokenExpiration.php`
- **등록 위치**: `bootstrap/app.php` (`api` 그룹에 등록)
- **테스트**: `tests/Feature/Api/Auth/TokenExpirationTest.php`

---

## 로그아웃 구현 원칙

```
필수: 토큰 삭제 + 세션 무효화 (세션이 존재하는 경우)
필수: currentAccessToken() 삭제 → 세션 존재 시 세션 무효화
```

토큰 전용 인증에서는 `currentAccessToken()`이 항상 `PersonalAccessToken`을 반환합니다:

```php
// 1. 토큰 삭제
$token = $user->currentAccessToken();
if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
    $token->delete();
}

// 2. 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
}
```

**특징**:

- `stateful` 미들웨어 제거로 `TransientToken` 케이스가 없어짐
- `currentAccessToken()`이 항상 `PersonalAccessToken` 반환
- 세션 무효화는 `start.api.session` 미들웨어가 적용된 로그아웃 라우트에서만 실행됨

---

## 401 응답 시 세션 쿠키 정리

API 요청에서 `AuthenticationException` 발생 시 (토큰 만료, 미인증 등), 잔존 세션 쿠키를 만료시켜 보안 공백을 방지합니다.

**구현 위치**: `bootstrap/app.php` `withExceptions()`

```php
$exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
    if ($request->expectsJson()) {
        return response()->json(['message' => __('auth.unauthenticated')], 401)
            ->withCookie(cookie()->forget(config('session.cookie')));
    }
});
```

**동작 방식**:

- **무조건적 forget**: 세션 쿠키 존재 여부와 관계없이 항상 `Set-Cookie` 만료 헤더 전송
- 클라이언트에 해당 쿠키가 없으면 `Set-Cookie` 헤더는 무시됨 (무해)
- 클라이언트에 잔존 세션 쿠키가 있으면 즉시 삭제됨

**테스트 파일**: `tests/Feature/Api/Auth/SessionCleanupOnUnauthorizedTest.php`

---

## Laravel Sanctum plainTextToken 구조

**형식**: `{tokenId}|{actualToken}`

**예시**:
```
9|iHzLc0VcFxEKi8dJlyqaPQoMBKBgs1ocpZpWKQzj0aaddebe
↓           ↓
DB ID    SHA256 해시 후 DB 저장
```

**DB 저장 방식**:
- `|` 앞부분 (tokenId): DB의 `id` 컬럼에 저장
- `|` 뒷부분 (actualToken): SHA256 해시 후 `token` 컬럼에 저장

**파싱 방법**:
```php
// ✅ DO: explode()로 분리 후 뒷부분만 해시
[, $token] = explode('|', $bearerToken, 2);
$hashedToken = hash('sha256', $token);

// ❌ DON'T: 전체 문자열을 해시
$hashedToken = hash('sha256', $bearerToken); // 잘못된 해시값
```

---

## 세션 존재 확인

세션 무효화 전 반드시 세션 존재 여부를 확인해야 합니다:

```php
if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
    // 세션 무효화 로직
}
```

**체크 항목**:
| 메서드 | 설명 |
|--------|------|
| `hasSession()` | 세션이 바인딩되어 있는지 확인 |
| `isStarted()` | 세션이 실제로 시작되었는지 확인 |
| `Auth::guard('web')->check()` | 세션에 인증된 사용자가 있는지 확인 |

---

## 세션 무효화 3단계

```php
Auth::guard('web')->logout();           // 1. 세션에서 인증 정보 제거
request()->session()->invalidate();     // 2. 세션 ID 무효화 및 새 세션 생성
request()->session()->regenerateToken(); // 3. CSRF 토큰 재생성
```

**각 단계 설명**:

| 단계 | 메서드 | 역할 |
|------|--------|------|
| 1 | `logout()` | 세션에서 사용자 인증 정보 제거 |
| 2 | `invalidate()` | 세션 ID를 무효화하고 새 세션 ID 생성 |
| 3 | `regenerateToken()` | CSRF 토큰을 재생성 |

**regenerateToken()을 하는 이유**:
- 로그아웃 후에도 사용자가 같은 브라우저를 계속 사용할 수 있음
- 이전 CSRF 토큰을 무효화하고 새로운 CSRF 토큰 발급
- 로그아웃 이후의 공개 페이지 요청(로그인 폼 등)을 위한 새로운 보안 토큰 제공

---

## 완전한 로그아웃 구현 예시

```php
<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    /**
     * 사용자를 로그아웃시키고 현재 디바이스의 인증 토큰만 삭제합니다.
     */
    public function logout(User $user): void
    {
        // Hook 발생 (로그아웃 시작)
        HookManager::doAction('core.auth.before_logout', $user);

        // 토큰 삭제 처리 (토큰 전용 인증이므로 항상 PersonalAccessToken)
        $token = $user->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        // 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        // Hook 발생 (로그아웃 완료)
        HookManager::doAction('core.auth.logout', $user);
    }
}
```

---

## 안티 패턴

```php
// ❌ DON'T: EnsureFrontendRequestsAreStateful 사용 (제거됨)
$middleware->api(prepend: [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);

// ❌ DON'T: 세션 존재 확인 없이 무효화 (에러 발생 가능)
Auth::guard('web')->logout(); // 세션이 없으면 에러

// ❌ DON'T: 토큰 삭제 없이 세션만 무효화
request()->session()->invalidate(); // 토큰이 살아있으면 재인증 가능

// ❌ DON'T: 전체 plainTextToken 해시 (DB 값과 불일치)
$hashedToken = hash('sha256', $bearerToken); // 잘못됨
```

---

## 운영 설정 (2단계 인증·IP 접근 제한·비밀번호 정책)

7.1.0 에서 추가된 로그인·접근 보안 설정이다. 모두 관리자 환경설정 > 보안(`security` 탭)에 있고 **기본 꺼짐**이다.

### 2단계 인증 적용 범위

| 설정 키 | 값 | 동작 |
| --- | --- | --- |
| `security.two_factor_auth` | boolean (기본 false) | 로그인 2단계 인증 사용 |
| `security.two_factor_scope` | `all`(기본) · `admin` | 대상 — 전체 회원 / 관리자만. 7.1.0 전에 2단계 인증을 켠 사이트는 그대로 `all` 로 동작한다 |

판정은 `AuthService::resolveTwoFactorProviderId()` — 범위 밖 회원은 2단계 인증 없이 로그인하고, 범위 안 회원은 쓸 수 있는 수단을 해석한다.

### 인증 앱(OTP) 2단계 인증

번들 플러그인 `sirsoft-totp`(인증 앱 2단계 인증)를 설치하면 인증 앱이 만드는 6자리 코드(RFC 6238 TOTP)로 2단계 인증을 받는다.

- 앱을 등록한 회원은 앱 코드로, 등록하지 않은 회원은 종전처럼 메일 인증번호로 받는다 — 미등록이라는 이유로 로그인이 막히지 않는다.
- 플러그인 설정에서 메일 폴백(`allow_mail_fallback`)을 끄면 미등록 회원은 `401 auth.two_factor_enrollment_required` 로 막힌다. 구제는 관리자의 등록 해제 또는 `php artisan totp:reset <이메일>`.
- 인증 앱 수단은 코드를 보내지 않으므로 재발송이 없다(422 `auth.two_factor_resend_not_supported`).
- 응답 모양·수단 선택: [api/auth.md](api/auth.md)

### 관리자 IP 접근 제한

`security.ip_restriction_enabled`(마스터 토글) · `admin_allowed_ips`(관리자 영역 허용 IP·CIDR) · `ip_restriction_rules`(경로별 규칙, 사용 중인 첫 매칭 규칙 하나만 적용).

- 관리자 영역은 관리자 화면·코어 관리자 API·관리자 로그인 API·확장 관리자 API 다. 허용 범위 밖이면 접속 IP 를 보여 주는 403 을 받고 활동 로그에 남는다.
- 결제 승인·입금 통보·웹훅·본인인증 콜백처럼 외부 서버가 보내는 요청은 경로 규칙에 걸리지 않는다. 확장은 `core.security.ip_restriction_exempt_paths` 필터 훅으로 자기 수신 경로를 더한다. 관리자 영역은 어떤 예외 경로로도 빠지지 않는다.
- 저장하는 관리자 자신이 막히는 설정은 저장되지 않는다. 그래도 잠겼으면 서버에서 끈다 — 목록·규칙은 남는다.

```bash
php artisan security:ip-restriction            # 상태 보기 (--status 와 같음)
php artisan security:ip-restriction --disable  # IP 접근 제한 끄기 (관리자 잠금 구제)
```

- 판정 IP 는 신뢰 프록시 해석 뒤의 값이다 — 리버스 프록시 뒤에서 `TRUSTED_PROXIES` 를 적지 않으면 모든 방문자가 프록시 주소 하나로 보인다([reverse-proxy.md](reverse-proxy.md)).
- 판정 순서·키 형식: [api/settings.md 「IP 접근 제한」](api/settings.md#ip-접근-제한-security-탭) · 미들웨어 위치: [middleware.md](middleware.md)

### 비밀번호 정책

| 규칙 | 요약 |
| --- | --- |
| 작성 규칙 | 영문·숫자·특수문자 중 2종류 이상, 아이디(이메일 앞부분) 포함 금지, 같은 문자 연속 반복 금지, 연속 문자(1234·abcd·qwer) 금지 — 가입·재설정·변경·관리자 등록/수정에 똑같이 적용 |
| 재사용 금지 | 최근 N개(1~10, 지금 비밀번호 포함) 재사용 불가 |
| 만료 | 사용 기간(30~730일)이 지나면 로그인할 때 새 비밀번호를 정한다 — 「변경해야 로그인」(`force`) 또는 「다음에 변경 허용」(`remind`), 범위 `all`·`admin`·`selected`. 매일 사전 안내·만료 처리는 `auth:process-password-expiry` |
| 로그인 변경 단계 | 비밀번호·2단계 인증을 통과한 뒤 바꿔야 하면 토큰 대신 변경 단계를 돌려준다 — `POST /api/auth/login/password-change`·`POST /api/auth/login/password-change/postpone` (관리자는 `/api/auth/admin/login/password-change`·`…/password-change/postpone`) |

키·판정·배치 상세: [password-policy.md](password-policy.md) · 설정 API: [api/settings.md](api/settings.md)

---

## 참고 자료

- **구현 파일**: [app/Services/AuthService.php](../../app/Services/AuthService.php)
- **테스트 파일**: [tests/Feature/Api/Admin/AdminAuthTest.php](../../tests/Feature/Api/Admin/AdminAuthTest.php)

---

## 관련 문서

- [README.md](README.md) - 백엔드 가이드 인덱스
- [middleware.md](middleware.md) - 미들웨어 등록 규칙 (OptionalSanctumMiddleware 포함)
- [custom-development.md](../custom-development.md) - 개발자 화면의 로그인 판정(`ScreenAuth`)·내부 API 도구
