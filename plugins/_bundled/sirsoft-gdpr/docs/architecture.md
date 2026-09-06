# GDPR (일반 데이터 보호 규정) — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
"동의 전 처리 금지"를 소스 하나가 아니라 **서버(쿠키)·클라이언트(스크립트/저장소) 두 표면
모두**에서 강제하는 것이 이 플러그인의 핵심 설계입니다. 서버 표면만 막으면 클라이언트에서
직접 실행되는 추적 스크립트를 막지 못하고, 클라이언트 표면만 막으면 백엔드가 심는 분석용
쿠키를 막지 못합니다. 두 표면은 서로 다른 코드 경로(미들웨어 vs 프론트 차단 로직)이지만
같은 동의 판정 소스(`GdprConsentService`)를 공유해야 판정이 갈리지 않습니다.

동의 "상태"와 "이력"을 분리 보존하는 것도 설계 결정입니다 — 상태(mutable)는 지금 게이팅
판정에 쓰이고, 이력(immutable append-only)은 감사 대응(Art.7(1))에 쓰입니다. 회원탈퇴·완전삭제
두 이벤트에서 서로 다른 처리(철회 vs 익명화)를 하는 것도 이 분리 때문에 가능합니다 — 하나의
테이블이었다면 "지금 상태를 지울까 이력을 지울까"를 매번 다시 판단해야 했을 것입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
Http/Controllers (Admin/ 관리자 설정·동의이력·정책버전, Public/ 배너 API, User/ 마이페이지)
        │
        ▼
Services (GdprConsentService/GdprSettingsService/GdprPolicyVersionService/
          GdprConsentLogService/CookieCategoryService)
        │
        ├──▶ Models (GdprUserConsent 상태 · GdprUserConsentHistory 이력 · GdprPolicyVersion)
        │
        └──▶ 훅 발행 (consent.granted/revoked) ──▶ 다른 확장 리스너

CookieConsentMiddleware (web/api 그룹 prepend)
        │
        └──▶ GdprConsentService::getCurrentCookieConsents() 조회 후 응답 Set-Cookie 게이팅

core.user.after_withdraw / before_delete 훅
        │
        └──▶ GdprUserWithdrawListener(철회 처리) / GdprUserDeleteListener(이력 익명화)
```

미들웨어는 위 Service 계층과 별도 레인에서 **매 요청마다** 동작하고, 회원탈퇴/삭제 리스너는
코어 회원 도메인 이벤트에 반응하는 또 다른 별도 레인입니다. 세 레인 모두 같은
`GdprConsentService`/모델을 공유하므로 그 계층 하나만 잘 유지하면 나머지는 일관됩니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-gdpr --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-gdpr --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-gdpr --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-gdpr --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-gdpr --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-gdpr --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
