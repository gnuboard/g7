# 인증 앱 2단계 인증 (TOTP) — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `issuer` | `string` | - | 발급자 표기 |
| `allow_mail_fallback` | `boolean` | `true` | 미등록자 메일 폴백 허용 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
설정이 두 개뿐인 것이 이 플러그인의 설계 결정입니다 — **열지 않은 것**이 연 것보다
많습니다.

**노출하지 않는 값**: 코드 자릿수(`Totp::DIGITS`=6) · 갱신 주기(`PERIOD`=30초) · 해시
알고리즘(`ALGORITHM`=sha1) · 시간 오차 허용 폭(`window`=1) · 백업 코드 개수·길이
(`BACKUP_CODE_COUNT`=10, `BACKUP_CODE_LENGTH`=10) · 시크릿 엔트로피(20바이트=160비트,
RFC 4226 §4 권장치)는 전부 클래스 상수이고 설정 스키마에 없습니다. 앞의 셋은 주요 인증
앱이 이 조합만 QR 로 정확히 읽기 때문이고 — 열어 두면 운영자가 바꾼 뒤 "앱이 만든 코드가
안 맞는다" 로만 드러나는 고장이 됩니다 — `window` 는 넓힐수록 탈취된 코드의 유효 시간이
함께 늘어나기 때문입니다. challenge 유효 시간과 시도 횟수는 코어 설정
(`settings.identity.challenge_ttl_minutes` 기본 15, `settings.identity.max_attempts`
기본 5)을 그대로 읽습니다. 수단마다 따로 두면 사이트의 인증 정책이 수단 수만큼 갈립니다.

**`issuer`**: `otpauth://totp/{issuer}:{account}?...&issuer=...` 의 발급자 표기입니다.
비어 있으면 `TotpEnrollmentController::resolveIssuer()` 가 `config('app.name')` 으로
폴백합니다 — 인증 앱 목록에서 어느 사이트인지 구분되지 않으면 사용자가 여러 계정을
섞습니다. 상한 64자. 이미 등록을 마친 사용자의 앱 표시는 바뀌지 않습니다(등록 시점의
otpauth URI 에 구워진 값). 계정 표기 쪽은 설정이 아니라
`TotpEnrollmentService::accountLabel()` 이 `email → nickname → user-{id}` 순으로
결정합니다 — 이메일이 없는 계정도 있기 때문입니다.

**`allow_mail_fallback`**: 이 값이 `MailFallbackAwareProviderInterface::allowsMailFallback()`
의 반환값이 되어 **코어 로그인 판정**을 직접 바꿉니다. 기본 `true` 는 미등록 관리자가
콜드스타트로 잠기는 경우를 구조적으로 배제하기 위한 값입니다. `false` 로 두면 코어
`AuthService::resolveTwoFactorProvider()` 가 메일 폴백을 건너뛰고, 미등록 사용자에게는
쓸 수단이 하나도 없으므로 **운영자의 결정으로 보고** `TwoFactorEnrollmentRequiredException`
으로 로그인을 막습니다(경고 로그 동반). 구제는 관리자 강제 해제가 아니라 — 해제할 등록이
없으므로 — 폴백을 다시 켜거나, 이미 로그인한 세션에서 등록을 마치는 것입니다. 폴백이
켜져 있는데도 쓸 수단이 없는 경우(메일 설정이 깨진 경우 등)만 운영자의 결정이 아니라서
경고 로그와 함께 2단계 인증 없이 통과시킵니다.

이 값과 `issuer` 는 「플러그인 관리 → 설정」 화면 아래쪽 「사이트 설정」 카드에서 고칩니다
(`PUT /api/admin/plugins/sirsoft-totp/settings`, `core.plugins.update` 권한).

**설정을 읽는 방법이 정해져 있습니다.** `RegisterTotpProviderListener::register()` 와
`TotpEnrollmentController::resolveIssuer()` 는 `PluginSettingsService::get('sirsoft-totp')`
로 매 요청 직접 조회합니다. 코어 `PluginSettingsService::save()` 가 인메모리 캐시만
비우므로 `config()` 로 읽으면 변경된 설정이 다음 요청에 반영되지 않습니다 (nhnkcp 선례와
같은 이유). provider 인스턴스에는 `withConfig()` / `makeWith(['config' => ...])` 로
주입됩니다.

`config/settings/defaults.json` 의 `frontend_schema` 는 두 키 모두 `expose: false` 입니다
— 프론트가 알 필요가 없고, `issuer` 는 어차피 발급 응답의 otpauth URI 에 실려 나갑니다.

`TotpIdentityProvider::getSettingsSchema()` 가 빈 배열인 것은 **의도**입니다. 설정은
플러그인 설정 화면이 소유하고, 관리자 「설정 > 본인인증」의 IDV 프로바이더 카드에는
중복 노출하지 않습니다 — 같은 값을 두 화면에서 고칠 수 있으면 어느 쪽이 실효인지 묻게
됩니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `credentials` | 인증 앱 자격증명 | `delete` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한이 하나뿐이고, 그 하나를 회원 관리 권한에서 **분리한 것**이 결정입니다.

`sirsoft-totp.credentials.delete` 는 코드 확인 없이 남의 2단계 인증을 푸는 권한입니다.
회원 정보를 볼 수 있다는 것과 그 사람의 2단계 인증을 해제할 수 있다는 것은 다른 신뢰
수준이므로, 회원 관리 권한으로 묶으면 회원 목록을 보는 운영자 전원이 이 권한을 함께
갖게 됩니다.

`roles` 는 `['admin', 'sirsoft-totp.credentials']` 입니다 — 최고 관리자는 기존대로
접근하고, 위임이 필요한 사이트는 전용 역할 「인증 앱 운영자」(`sirsoft-totp.credentials`)
를 부여합니다. `type` 은 `admin` 이라 관리자 권한 체계에서 다뤄집니다.

이 권한은 `src/routes/api.php` 의 `permission:admin,sirsoft-totp.credentials.delete`
미들웨어로 **라우트에서** 강제합니다. 컨트롤러 내부 검사에 맡기지 않는 것은 메서드가
늘어날 때 검사가 빠진 메서드가 생기지 않게 하기 위해서입니다. 위 표의 「라우트 키」가
비어 있는 것은 권한 선언과 라우트를 이름으로 묶지 않았기 때문이며, 실제 결합은 미들웨어
인자에 있습니다.

**권한으로 막히지 않는 경로가 하나 있습니다.** `php artisan totp:reset {email}` 은 웹
권한 체계를 거치지 않습니다 — 관리자 본인이 단말과 백업 코드를 모두 잃으면 관리자 화면에
들어갈 수 없어 API 경로를 쓸 수 없기 때문입니다. 서버 셸 접근 자체가 권한이라는 전제이며,
그래서 셸 접근 통제가 이 플러그인의 최종 방어선입니다.

본인 등록·해제 경로에는 권한을 두지 않았습니다. 대상이 항상 호출자 본인이고, 해제에도
현재 코드를 요구하므로 인증만으로 충분합니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
`/admin/plugins/sirsoft-totp/settings` 는 **관리자 본인의 등록 화면**입니다 — 회원
목록에서 남의 등록을 해제하는 화면이 아니라, 관리자가 자기 계정에 인증 앱을 등록하는
자리입니다. 회원 강제 해제는 권한을 가진 관리자가 API 로 수행합니다.

이 화면은 전용 메뉴가 아니라 코어 「플러그인 관리 → 설정」으로 들어갑니다(설계 확정
2026-09-18). `getAdminMenus()` 가 빈 배열인 이유이며, 선언에서 빠진 메뉴는 `activate()` 의
`cleanupStaleMenus(currentSlugs: [])` 가 회수합니다 — 이전 판본이 심은
`sirsoft-totp-security` 행이 실제로 그렇게 남아 죽은 경로를 가리켰습니다.

플러그인 설정(`issuer` · `allow_mail_fallback`)은 이 메뉴가 아니라 코어 「플러그인 관리 >
설정」 공통 진입점으로 들어갑니다 — 설정 화면마다 전용 메뉴를 만들면 플러그인 수만큼
메뉴가 난립합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-totp/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
라우트 파일이 `api` 하나뿐이고 web 라우트가 없는 것이 핵심입니다. 이 플러그인은 브라우저가
직접 타는 화면 경로(콜백·브리지)를 열지 않습니다 — 외부 인증기관을 왕복하지 않으므로
CSRF 면제가 필요한 외부 진입점 자체가 없습니다.

**로그인 코드 확인 경로를 열지 않습니다.** 2단계 인증 코드 제출은 코어
`POST /api/admin/login/two-factor` · `POST /api/auth/login/two-factor` 가 받습니다. 이
플러그인은 프로바이더로 등록될 뿐이라 로그인 경로를 따로 두지 않습니다 — 두 벌이 되면
잠금·시도 횟수 같은 코어 통제가 한쪽에만 걸립니다.

**엔드포인트 6종의 구성**

| 묶음 | 경로 | 미들웨어 |
|---|---|---|
| 본인 등록 관리 5종 | `/enrollment` (GET·POST·DELETE) · `/enrollment/confirm` · `/backup-codes/regenerate` | `auth:sanctum` |
| 관리자 강제 해제 1종 | `/admin/credentials/{user}` (DELETE) | `auth:sanctum` + `permission:admin,sirsoft-totp.credentials.delete` |

본인 경로에는 **사용자 식별자를 두지 않았습니다.** 대상은 항상 호출자 본인이므로, 남의
자격증명을 가리킬 수 있는 표면 자체를 만들지 않습니다. 반대로 관리자 경로는 대상이 남이라
`{user}` 를 받고 `->where('user', '[0-9]+')` 로 숫자만 통과시킵니다.

`/enrollment` 하나에 GET·POST·DELETE 를 겹쳐 둔 것은 셋이 같은 자원(본인의 자격증명)을
조회·생성·삭제하기 때문입니다. 반면 확정과 백업 코드 재발급은 자원 생성이 아니라 상태
전이라 별도 POST 경로를 씁니다.

라우트 이름은 코어가 `api.plugins.sirsoft-totp.` 접두사를 자동으로 붙이므로 파일에는
`enrollment.show` 처럼 꼬리만 적습니다. 엔드포인트별 파라미터·응답 상세는
[api/totp.md](api/totp.md) 를 보세요.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
의존 확장이 없는 것은 의도입니다. 2단계 인증은 어떤 확장이 설치돼 있든 동작해야 하는 코어
인증 흐름의 일부라, 다른 확장이 있어야 성립하는 수단이면 그 확장을 끄는 순간 로그인이
막힙니다.

**코어 의존은 있습니다** — 다만 확장 의존이 아니라 코어 버전 요구(`g7_version >=7.1.0`)로
표현됩니다. 이 플러그인이 구현하는 보조 계약 두 개
(`UserScopedIdentityProviderInterface` · `MailFallbackAwareProviderInterface`)가 코어
7.1.0 에서 열렸기 때문입니다. 그 이전 코어에 얹으면 인터페이스를 찾지 못해 부팅 자체가
실패합니다.

**같은 자리를 쓰는 다른 확장과의 관계**: `sirsoft-verification_nhnkcp` 같은 본인확인
플러그인도 `core.identity.registered_providers` 에 등록되지만, 이 플러그인과 경쟁하지
않습니다 — 목적(purpose)이 다릅니다. 이 플러그인은 `login` 만 지원하고
(`supportsPurpose()`), 본인확인 플러그인은 가입·성인인증 계열을 맡습니다. 운영자는 「설정 >
본인인증」에서 목적별로 수단을 고릅니다.

**login 목적에 다른 프로바이더가 이미 매핑돼 있으면 덮어쓰지 않습니다.**
`plugin.php::install()` 은 `identity.purpose_providers.login` 이 비어 있을 때만 `totp` 로
1회 초기화하고, `uninstall()` 은 그 값이 여전히 `totp` 일 때만 거둡니다. 운영자가 다른
프로바이더로 바꿔 두었다면 그 선택은 건드리지 않습니다.

**끄면 무엇이 사라지는가**: 플러그인을 비활성화하면 두 필터가 걸리지 않아 `totp`
프로바이더와 login 목적의 `app` 채널이 함께 사라집니다. 이미 등록한 회원들의 자격증명
행은 남지만(테이블을 드랍하지 않습니다) 로그인에서 쓰이지 않으며, 코어가 메일 폴백으로
2단계 인증을 처리합니다 — 인증 수단이 사라진 것은 오류로 드러나지 않으므로, 비활성화는
2단계 인증 정책을 함께 점검한 뒤에 하세요.
<!-- @intent END -->
