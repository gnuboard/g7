# 그누보드7 소셜 로그인 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-social_login) — 외부 제공자(카카오, 네이버, 구글, 페이스북, OIDC)로 본인을 확인하는 OAuth 왕복과 회원-제공자 연결
2. 확장 방식: 필터 훅 `sirsoft-social_login.provider.filter_registered` 로 제공자 드라이버를 더한다(내장 키 덮어쓰기는 무시)
3. 건드리면 안 되는 것: 토큰 직접 발급(`createToken`, `Auth::login`) 금지 — 로그인은 코어 로그인 인계로만
4. 작업 위치: `plugins/_bundled/sirsoft-social_login` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-social_login --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
**해결하는 문제**: 회원이 비밀번호 대신 외부 제공자 계정으로 로그인하고 가입하게 한다.

**설계 원칙**

- 본인 확인은 제공자가, 로그인은 코어가 한다. 이 플러그인은 제공자 왕복을 끝내고 회원을 정한 뒤 코어 `AuthService::loginVerifiedUser()` 로 일회용 인계 코드를 받아 `/login?login_handoff={코드}` 로 보낸다. 잠금, 2단계 인증, 비밀번호 변경, 휴면 해제 같은 관문은 비밀번호 로그인과 똑같이 코어가 처리한다.
- 제공자 응답은 드라이버가 한 모양(`NormalizedProfile`)으로 맞춘다. 이메일 검증 여부는 세 값(true, false, 알 수 없음)이고, 마스킹 이메일은 버린다.
- id_token 서명은 RS256 만 받고 헤더의 alg 를 믿지 않는다. 외부 JWT 라이브러리 없이 openssl 로 검증한다.
- state 는 서버 캐시에 한 번만 쓰는 값으로 두고, 복귀 주소는 같은 출처 상대 경로만 받는다.

**의도적으로 하지 않는 것**

- 토큰 발급, 세션 생성을 직접 하지 않는다.
- 제공자 비밀값을 설정 키(사이트 설정 파일)에 두지 않는다 — 제공자 테이블에 암호화해 둔다.
- 제공자 비밀값을 화면이나 응답에 평문으로 돌려주지 않는다 — 관리자 응답은 마스크(`SensitiveSettingMask::MASK`)만 싣고, 마스크를 그대로 돌려받으면 유지, `null`(빈 문자열 포함)은 삭제로 읽는다.
- 제공자 웹훅은 회원 탈퇴를 직접 결정하지 않는다 — 연결을 끊는 것이 기본이고, 탈퇴는 운영자가 정책(`webhook_unlink_policy=withdraw_user`)으로 고른 때만 코어 `UserService::withdrawUser` 로 한다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-social_login --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-social_login --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-social_login --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-social_login --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-social_login --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-social_login --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-social_login --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**관리자 연결 테스트** (`ProviderTestController@testLogin` → `OAuthFlowService::start(test)` → `StateStore::create` → 드라이버 `authorizeUrl`)
→ 관리자 브라우저가 제공자 동의 → `OAuthController@callback`(`OAuthCallbackRequest`) → `OAuthFlowService::callback` → `StateStore::pull`(1회성) → 드라이버 `exchangeCode` → `fetchProfile` → 필터 `profile.filter_normalized` → 결과를 캐시에 일회용 코드로 저장 → `OAuthController@testResult` 가 한 번 보여 주고 지운다. 시도는 `AttemptRepository`, 제공자 진단 값은 `ProviderRepository::recordOutcome` 이 남긴다.

**로그인 시작** (`OAuthController@start`, `OAuthStartRequest`) → 제공자 행이 켜져 있는지 확인 → 인가 화면으로 302.

**콜백 분기** (`OAuthFlowService::callback` → `CallbackDispatcher::dispatch`, state 의 의도별) — 로그인: 연결된 회원이면 `SocialLoginService::loginLinked`(관리자 차단 확인 → 코어 `LoginHandoffService::issue`)로 `/login?login_handoff=` 인계, 없으면 `SignupFlowService::begin` 이 가입 대기 토큰을 만들어 `/register?social_pending=` 가입 모달로(같은 제공자 계정으로 다시 시작하면 앞 대기 토큰은 지운다 — `PendingSignupStore`). 연결: `AccountLinkService::completeLink` → 마이페이지. 재확인: `SocialReauthService::complete` → 코어 본인인증 확인 후 1회용 수령 코드로 돌아갈 주소.

**가입 모달** (`SignupController`, `LinkExistingController`) → `SignupFlowService::complete` 가 코어 `AuthService::register(…, ['issue_token' => false])` 로 비밀번호 없는 회원을 만들고 `AccountLinkService::attach` → 인계 주소. 같은 이메일의 기존 계정은 `LinkExistingService`(비밀번호 또는 메일 인증번호, 5회 한도)로 연결한다. 플러그인은 토큰을 직접 만들지 않는다. 가입 창이 받는 회원 항목은 요청의 `fields_version` 으로 갈린다. 가입 창이 코어 가입 화면과 같은 항목(환경설정 > 회원 > 가입 항목)을 그렸으면 받은 정의 버전을 `fields_version` 으로 싣는다 — `CompleteSignupRequest` 가 규칙을 「고정 규칙 + `FieldRuleFactory`(가입 표면)」로 만들고(코어 가입 필터 `core.auth.register_validation_rules` 도 탄다), 버전이 지금과 다르면 코어 가입과 같은 409(`fields_changed`)로 답하며, `complete` 는 고정 키가 아닌 검증된 입력(연락처, `custom_fields.*`, 확장이 선언한 항목)을 가입 데이터에 실어 코어 가입이 저장하게 한다. `fields_version` 이 없는 요청(이 플러그인에 든 원본 창, 오버라이드가 없는 템플릿)은 종전 고정 규칙만 걸고 `required_fields => 'defer'` 옵션으로 넘긴다 — 코어가 `users.required_fields_deferred_at` 을 채우고, 그 회원이 처음 로그인하면 추가 정보 입력 화면으로 보낸다. 가입 창은 항목을 코어 `GET /api/auth/register/fields` 에서 받으며 `pending_source=sirsoft-social_login`, `pending_token` 을 넘긴다 — `PrefillSignupFieldsListener`(필터 `core.field.filter_definitions`)가 가입 대기의 제공자 이름과 닉네임을 그 항목의 초기값으로 채운다. `view` 의 `missing` 에는 가입 필수 항목 가운데 제공자 값으로 채울 수 없는 항목의 키가 든다. 닉네임은 사이트의 닉네임 정책(중복 금지, 금지어)을 따른다. 가입 창에 적은 닉네임은 `CompleteSignupRequest` 가 `NicknamePolicy::rules()` 로, 제공자가 준 닉네임은 코어 가입이 판정한다. 이 경로는 사람이 가입 창을 보고 있으므로 `nickname_policy => 'skip_field'` 를 넘기지 않는다 — 걸리면 닉네임 칸의 검증 오류(422)가 나고 가입 대기는 남아 다른 닉네임으로 다시 보낼 수 있다.

**제공자 목록** (`ProviderRegistry::all`) → `ProviderRepository::all` 행을 `driver` 로 드라이버 인스턴스화 → 필터 `provider.filter_registered`(모의 제공자 리스너 포함) → 요청당 한 번 캐시.

**관리자 제공자 설정** (`Admin\ProviderController`, `UpdateProviderRequest` · `CreateProviderRequest`) → `ProviderAdminService` 가 비밀값 규칙(없음·마스크는 유지, `null` 은 삭제, 그 밖은 교체)으로 행을 고치고 `provider.after_update` 훅에 바뀐 칸 이름만 넘긴다. 자격증명 없이 켜면 422 `credentials_required`. 연결 테스트(`ProviderTestService::run`)는 없는 인가 코드로 토큰 주소를 불러 거절 사유를 정상·클라이언트 오류·콜백 주소 불일치·도달 불가로 나눈다(OIDC 는 서명 키 목록 도달을 먼저 본다). 앱 키가 바뀌어 비밀값을 풀 수 없으면 드라이버는 그 칸을 비운 채 만들어져(목록과 화면은 그대로 열리고 카드에 「다시 입력」 안내), 비밀값이 필요한 순간 `clientSecret()` 이 `credentials_unreadable` 로 멈춘다 — 비밀값 없이 토큰을 요청하지 않는다. 연결 테스트도 같은 코드로 알린다. 안내대로 비밀값을 다시 입력하면 풀 수 없는 옛 암호문을 덮어 저장한다 — 두 모델(`SocialLoginProvider`, `SocialAccount`)은 `Concerns\SavesOverUnreadableEncryptedValues` 로 풀 수 없는 원본을 「바뀐 값」으로 본다(Eloquent 가 바뀐 칸을 가리려고 옛 암호문을 풀다 멈추지 않게). 같은 이유로 연결된 회원이 다시 로그인할 때 토큰·원본 프로필 갱신도 멈추지 않는다.

**제공자 웹훅** (`WebhookController` → 제공자별 FormRequest → 드라이버 `verifyWebhook`) → `WebhookReceiver::receive` 가 `event_key` 로 중복을 걸러 `social_login_webhook_events` 에 남기고(검증 실패는 `ignored`) `ProcessWebhookEventJob` 을 커밋 뒤에 보낸다 → `WebhookProcessor::process` 가 연결을 `UnlinkReason::Webhook` 으로 끊고(권한 회수는 하지 않는다 — 제공자가 이미 끊었다), 정책이 `withdraw_user` 면 탈퇴, 마지막 로그인 수단을 잃은 회원에게 알림 훅. 제공자에는 정해진 응답(카카오 200, 네이버 204, 페이스북 400 또는 확인 코드)을 준다.

**관리자 회원 표면** — 목록 가입경로 필터는 코어 확장 필터 통로(`ext_filters[]=sirsoft-social_login.provider={키}`, `UserListSocialProviderFilter`), 「소셜 연결」 열은 `core.user.filter_list_rows`(`UserListSocialProvidersColumn`, 1쿼리). 둘 다 회원 연결 열람 권한일 때만 적용된다. 상세 탭 강제 해제(`UserAccountsController@destroy` → `AccountLinkService::adminUnlink`)는 마지막 수단 가드를 보지 않고 `account.unlinked_by_admin` 훅으로 회원에게 알린다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 18개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 21개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 12개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 11개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 5개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
- 새 제공자: `ProviderDriverInterface` 를 구현하고 필터 `sirsoft-social_login.provider.filter_registered` 에서 새 키로 더한다. 키 형식은 `^[a-z][a-z0-9_]{1,49}$`, 내장 키(kakao, naver, google, facebook)는 바꿀 수 없다.
- 프로필 가공: 필터 `sirsoft-social_login.profile.filter_normalized` 에서 `NormalizedProfile` 을 바꿔 돌려준다(제공자 사용자 ID 를 비우면 실패 처리된다).
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-social_login --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-social_login` 재실행 + `docs/api/**` 갱신
- [ ] 로그인한 회원이 부르는 쓰기 라우트(POST, PUT, PATCH, DELETE)를 더할 때 코어 필터 `core.required_fields.filter_exempt_routes` 에 보탤지 판단한다. 코어는 추가 정보를 아직 입력하지 않은 회원의 쓰기 요청을 403 `required_fields_pending` 으로 막는다. 그런 회원도 반드시 할 수 있어야 하는 요청(계정 연결과 해제, 본인 재확인처럼 로그인 수단을 다루는 요청)이면 `src/Listeners/ExemptRequiredFieldsRoutesListener.php` 의 `ROUTES` 에 라우트 이름을 더하고, 사용자 콘텐츠를 만드는 요청이면 더하지 않는다
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 클래스는 자기 앞글자(`sl:` 접두 유틸리티 · `sl-*`)로만 쓰고, 새 클래스를 쓰면 `php artisan plugin:build sirsoft-social_login --production` 으로 `dist/css/plugin.css` 를 다시 만든다(템플릿 CSS 에 기대지 않는다)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 가입 창 조각(`resources/extensions/auth_register_social_modals.g7.html`)을 고치면 기본 사용자 템플릿의 판본(`templates/_bundled/sirsoft-basic/extensions/sirsoft-social_login/auth_register_social_modals.g7.html`)도 함께 고친다 — 그 템플릿은 이 조각을 통째로 대체해 회원 항목을 그리므로 원본만 고치면 기본 템플릿 사이트에는 반영되지 않는다. 플러그인 버전을 마이너 이상 올리면 판본의 `version-constraint`(지금 `~1.0.0`)도 함께 넓힌다(벗어나면 코어가 원본 창으로 되돌린다). 가입 완료 요청의 규칙을 바꿀 때는 `fields_version` 이 있는 요청과 없는 요청 둘 다 받아들여지는지 `tests/Feature/SignupFieldSetTest.php` 로 확인한다
- [ ] 로그인 관련 변경은 코어 로그인 인계 계약(`docs/backend/authentication.md` 「외부 인증 로그인」)과 맞는지 확인하고, 플러그인이 토큰을 직접 만들지 않는지 `grep -rnE "createToken\(|Auth::login\(" src` 로 0 을 확인
- [ ] 브라우저가 만드는 주소는 `resources/js/apiUrl.ts` 의 `apiUrl()`, `sitePath()`, `routePath()` 만 쓴다 — 이동, 링크, 요청 주소는 `sitePath()`/`apiUrl()` 로 접두사를 붙이고, `location.pathname` 을 화면 판정에 쓸 때는 `routePath()` 로 벗긴다. 이 파일은 번들 확장 전체가 바이트 동일한 사본으로 갖는다. 어기면 하위 폴더 설치(`https://host/g7/`)에서만 오류 없이 로그인/가입 버튼이 사이트 밖 시작 주소로 간다. 제공자 인가 주소(https 절대 주소)는 대상이 아니므로 그 줄 위에 예외 사유 주석을 단다

## 6. 금지 패턴

<!-- @intent START -->
이 확장에서 실제로 막아 둔 오용입니다.

| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 플러그인 코드에서 `createToken()`, `Auth::login()` 호출 | 코어 `AuthService::loginVerifiedUser()` → 로그인 인계 | 잠금, 2단계 인증, 휴면 같은 코어 관문을 우회한다 |
| 콜백 주소를 `url()`, `route()` 절대 주소로 만들기 | `SiteUrl::absolute(route(…, false))` | 요청 Host 를 따라 제공자 콘솔 등록값과 어긋난다 |
| id_token 헤더의 alg 를 보고 검증 방식 고르기 | `Rs256Verifier` (RS256 고정) | alg=none, HS256 다운그레이드 위조 |
| state 를 읽고 나서 지우지 않거나 잠금 없이 지우기 | `StateStore::pull()` | 같은 콜백 재사용(인가 코드 재사용) |
| 마스킹 이메일(`*` 포함)로 회원 찾기 | `NormalizedProfile::email()` 이 null 로 거른다 | 다른 회원과 잘못 연결 |
| 관리자 응답·훅 인자에 비밀값 싣기 | `ProviderAdminService::present()`(마스크), 훅에는 요약과 바뀐 칸 이름만 | 화면·로그·리스너로 자격증명 유출 |
| 웹훅 검증 실패에 4xx·5xx 로 답하기(카카오·네이버) | `ignored` 로 기록하고 정해진 성공 응답 | 제공자가 재시도를 쌓거나 웹훅을 끈다 |
| 웹훅 요청 안에서 연결 해제·탈퇴 처리 | `ProcessWebhookEventJob`(커밋 뒤, 처음 시도를 포함해 5번 — 다시 시도 4번) | 제공자 응답 시간 초과와 중복 처리 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 47개 | `plugins/_bundled/sirsoft-social_login/tests` |
| Vitest | 9개 | `vitest.config.ts` |
| Playwright | 8개 | `tests/Playwright` |
| 시나리오 매니페스트 | 20개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-social_login/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-social_login && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-social_login && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
