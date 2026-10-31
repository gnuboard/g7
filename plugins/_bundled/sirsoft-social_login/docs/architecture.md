# 소셜 로그인 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
본인 확인(제공자)과 로그인(코어)을 나눈다. 플러그인은 OAuth 왕복, 제공자 응답 정규화, 회원-제공자 연결을 소유하고, 세션과 토큰은 코어 로그인 인계로만 만든다. 제공자마다 다른 부분은 드라이버 하나에 모으고(주소, 응답 모양, 오류 형식), 공통 흐름(state, PKCE, 교환, 오류 매핑)은 `AbstractOAuth2Driver` 가 맡는다. 제공자가 토큰 발급 요청에 값을 더 요구하면(네이버의 `state`) 교환을 다시 쓰지 않고 `extraTokenParams()` 만 덮어쓴다 — 공통 값은 덮어쓸 수 없다. 캐시에 숫자를 그대로 두는 값(모의 게이트의 만료 시각)은 Redis 저장소가 문자열로 돌려주므로 읽는 쪽이 숫자 문자열도 받는다. 외부 의존성은 PHP openssl 확장뿐이다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
| 계층 | 클래스 |
|---|---|
| 웹·API 진입 | `OAuthController`(start, callback, testResult), `ProviderListController`, `SignupController`, `LinkExistingController`, `MyAccountsController`, `WebhookController`(카카오, 네이버, 페이스북 2종, 데이터 삭제 상태), `Admin\ProviderController`, `Admin\ProviderTestController`, `Admin\DiagnosticsController`, `Admin\StatsController`, `Admin\UserAccountsController`, `Mock\MockController` |
| 관리자 | `ProviderAdminService`(비밀값 마스킹 규칙, 일반 OIDC 추가 삭제, 순서, discovery, 영향), `ProviderTestService`(토큰 주소 거절 분류), `DiagnosticsService`, `StatsService`, `FailureStreakMonitor` |
| 웹훅·운영 | `WebhookReceiver`(검증, 중복, 기록) → `Jobs\ProcessWebhookEventJob` → `WebhookProcessor`(해제, 정책별 탈퇴), `WebhookStatusService`, `PruneService` ← `Console\Commands\PruneCommand` |
| 요청 검증 | `OAuthStartRequest`, `OAuthCallbackRequest`, `PendingSignupRequest`(← `CompleteSignupRequest`, `SignupEmailRequest`, `VerifyCodeRequest`, `LinkExistingRequest`), `ReauthStartRequest`, `ReauthClaimRequest` |
| 흐름 | `OAuthFlowService`(시작, 콜백, 결과 보관), `CallbackDispatcher`(의도별 분기), `StateStore`, `ProviderRegistry`, `IdTokenVerifier` |
| 로그인·가입·연결 | `SocialLoginService`(관리자 차단, 코어 로그인 인계), `SignupFlowService`·`PendingSignupStore`(가입 모달), `LinkExistingService`(같은 이메일 기존 계정), `AccountLinkService`(연결·해제), `SocialReauthService`·`Identity\SocialReauthIdentityProvider`(대체 이메일 회원 본인 재확인), `SignupSurgeMonitor`, `AttemptRecorder`, `AccountCredentialWriter`, `ProviderSettings` |
| 리스너 | 회원 삭제·탈퇴 시 연결 정리, 회원 응답에 연결 제공자 목록 주입, 관리자 회원 목록 가입경로 조건과 「소셜 연결」 열, 알림 치환 값, 활동 기록, 재확인 수단 등록, 모의 제공자 등록, 설정 검증 |
| 제공자 | `Contracts\ProviderDriverInterface` ← `AbstractOAuth2Driver` ← Kakao, Naver, Google(← Oidc), Facebook |
| 저장 | Repository 4종(제공자, 연결, 시도, 웹훅) → 모델 4종 |
| 보조 | `Support\Jwt\*`(JWK→PEM, RS256, 클레임), `Support\HtmlPage`, `Support\RsaKeyFactory` |
<!-- @intent END -->

## 디렉토리

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
