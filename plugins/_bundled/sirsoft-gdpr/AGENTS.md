# 그누보드7 GDPR (일반 데이터 보호 규정) 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-gdpr) — 쿠키 동의 배너·동의 이력·GDPR/개인정보보호법 대응을 소유
2. 확장 방식: `sirsoft-gdpr.consent.granted`/`revoked` 훅 구독, `data-gdpr-category` HTML 속성으로 자체 호스팅 자원 등록
3. 건드리면 안 되는 것: 필수 허용목록의 **잠금 항목**(`Support\NecessaryAllowlist::locked()`), 동의 이력(immutable append-only) 직접 수정
4. 작업 위치: `plugins/_bundled/sirsoft-gdpr` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-gdpr --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
GDPR(EU) 및 한국 개인정보보호법이 요구하는 "동의 전 처리 금지" 원칙을 서버·클라이언트 양쪽에서
강제하는 플러그인입니다. 두 계층이 서로 다른 것을 막습니다 — 서버 계층(`CookieConsentMiddleware`)
은 백엔드가 심으려는 Set-Cookie 헤더를, 클라이언트 계층(자동 차단 스크립트)은 외부 추적
스크립트·iframe·1st-party 저장소(localStorage/sessionStorage) 접근을 각각 동의 전까지 막습니다.
"동의했다"는 사실 자체도 상태(`gdpr_user_consents`, mutable)와 이력(`gdpr_user_consent_histories`,
immutable append-only)으로 이중 기록합니다 — 지금 상태 조회와 "언제 무엇에 동의했었는가" 입증
(Art.7(1))은 서로 다른 질문이라 하나로 합칠 수 없습니다.

**설계 원칙**: 정책 버전 발행은 수동입니다 — 정책 본문이 바뀌었다고 자동으로 전 회원 재동의를
트리거하지 않습니다. 운영자가 "이 변경이 재동의가 필요한 변경인가"를 판단해 명시적으로 발행
버튼을 눌러야 합니다(README "사용 방법" 표 참고). 자동화하면 사소한 오탈자 수정에도 전 회원이
재동의 화면을 보게 되어 UX 를 해칩니다.

**의도적으로 하지 않는 것**: 게스트 → 회원 동의 자동 승계(§README 소개 참고), 그리고 운영자가
등록한 "허용" functional 쿠키 화이트리스트도 두지 않습니다 — functional 미동의 시 필수 허용목록
밖의 **모든** 쿠키를 차단합니다. EDPB Guidelines 2/2023 §16 원칙이 "비필수는 동의 전 전면
차단"이지 "등록된 것만 차단"이 아니기 때문입니다.

**필수 허용목록은 코드 상수가 아니라 운영자 설정입니다**(`necessary_storage_allowlist`).
관리자 환경설정의 「필수 저장 항목」 카드에서 저장소 구분(브라우저 저장소 / 세션 저장소 / 쿠키)
셋을 각각 편집하며, 끝의 `*` 는 앞부분 매칭입니다. `plugin.php` 의
`DEFAULT_NECESSARY_ALLOWLIST_CATALOG` 는 **신규 설치 시드용 출하 기본값이자 화면 추천 목록**일
뿐 판정 목록이 아닙니다 — 새 확장이 저장 키를 도입해도 이 플러그인을 고칠 필요가 없습니다.

그래서 **판정 목록은 하나**입니다. 서버(`CookieConsentMiddleware`)와 클라이언트 셋
(`storageInterceptor` · `cookieInterceptor` · `functionalCleaner`)이 같은 설정을 같은 매칭 규칙
(`Support\NecessaryAllowlist` ↔ `resources/js/necessaryAllowlist.ts`)으로 읽습니다. 예전에는
쿠키 목록과 저장소 목록이 따로 있었고, 그 둘이 어긋나도 아무 신호가 없었습니다.

**잠금 항목만 설정 밖입니다** — `auth_token` · `XSRF-TOKEN` · 세션 쿠키(런타임 해석) ·
`gdpr_session` 넷은 없으면 사이트가 서지 못하므로 코드가 정하고, 판정에는 언제나 운영자 목록과
합집합으로 얹힙니다. 설정에 담으면 저장 요청 한 번으로 지워져 잠금이 아니게 됩니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
**동의 부여**: `Public\GdprCookieConsentController` → `GdprConsentService::grantConsent()` —
회원이면 `user_id`, 게스트면 서명된 `gdpr_session` 쿠키로 식별한 뒤 `GdprUserConsent`(현재
상태, upsert)와 `GdprUserConsentHistory`(이력, insert-only) 를 같은 트랜잭션에서 함께 기록하고
`sirsoft-gdpr.consent.granted` 훅을 발행합니다. 이 흐름은 배너·마이페이지·회원가입·전체
재동의 4개 진입점이 전부 공유합니다 — 진입점마다 다른 저장 로직을 만들지 않습니다.

**요청마다 반복되는 게이팅**: `CookieConsentMiddleware`(web/api 그룹에 prepend)가 응답 직전
`GdprConsentService::getCurrentCookieConsents()` 로 현재 functional 동의 여부를 조회하고,
미동의면 strictly-necessary 4종을 제외한 모든 Set-Cookie 헤더를 응답에서 제거합니다. 이
서버측 게이팅과 별개로, 클라이언트에서는 `data-gdpr-category` 속성이 붙은 스크립트/iframe 과
분석/마케팅 카테고리 도메인 매칭 리소스가 동의 전까지 로드되지 않습니다.

**회원탈퇴와 완전삭제는 다른 훅, 다른 처리**입니다 — `GdprUserWithdrawListener`
(`core.user.after_withdraw`)는 코어가 user 행 자체를 보존하는 "탈퇴"에 반응해 활성 동의를
전부 철회 처리(UPDATE + `source=withdraw` revoked 이력 INSERT)할 뿐 신원 정보는 그대로
남깁니다 — 탈퇴는 "의사 표시 종료"이지 신원 삭제가 아니기 때문입니다. 반대로
`GdprUserDeleteListener`(`core.user.before_delete`, 완전 삭제/hard delete)는 이력 행의
`user_id`/IP/User-Agent 만 NULL 로 **익명화**하고 행 자체는 남깁니다(Art.17 삭제권과 Art.7(1)
입증 의무 양립). 두 훅을 헷갈리면 탈퇴 시점에 신원이 조기 삭제되거나, 완전삭제 시점에 입증
자료 행까지 통째로 사라집니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 2개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 4개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 4개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 1개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
다른 확장이 이 플러그인 없이는 몰랐을 사실(방문자가 방금 분석 카테고리에 동의/철회했다)을
알아야 할 때 `sirsoft-gdpr.consent.granted`/`revoked` 훅을 잡습니다 — 예: 분석 SDK 초기화를
"페이지 로드 시 무조건"이 아니라 "동의 부여 시에만" 하고 싶은 확장. 반대로 자체 호스팅
추적 자원을 이 플러그인의 자동 차단·복원 대상에 포함시키고 싶다면 훅이 아니라 HTML 속성
(`data-gdpr-category="analytics"`)을 붙이는 쪽이 맞습니다 — 이 플러그인이 그 속성을 스캔해
차단/복원을 대신 수행하므로 소비 측 코드가 필요 없습니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-gdpr --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 업그레이드 스텝·데이터 마이그레이션이 설정 파일을 새로 쓰면(`File::put`) 바로 뒤에 `FilePermissionHelper::inheritOwnershipFromParent($path)` — `sudo` 코어 업데이트 안에서 root 로 실행되어 그 파일이 root 소유로 남으면 이후 웹 프로세스의 설정 저장이 영구 실패한다. 번들 확장 `upgrades/**` 전수를 파일시스템에서 파생해 검사하는 패리티 테스트가 누락을 잡는다
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-gdpr` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] `gdpr_user_consent_histories` 는 append-only — UPDATE/DELETE 로 기존 행을 고치지 않는다 (완전삭제 시 익명화 UPDATE 예외는 `GdprUserDeleteListener` 단일 지점에서만 수행)
- [ ] 새 자동 차단 카테고리(기능/분석/마케팅 외)를 추가하면 배너 UI·`blocked_domains` 스키마·차단 스크립트 3곳 동기화
- [ ] 잠금 집합(`NecessaryAllowlist::locked()`)이나 출하 카탈로그에 항목을 늘릴 때는 ePrivacy Art.5(3) 면제 항목인지 먼저 검토 — 임의로 늘리면 동의 전 차단 원칙이 무력화된다
- [ ] **필수 허용목록에 항목이 필요하면 코드가 아니라 관리자 화면에서 추가한다** — 운영자 설정(`necessary_storage_allowlist`)이 판정 목록이다. `plugin.php` 의 `DEFAULT_NECESSARY_ALLOWLIST_CATALOG` 를 고치는 것은 **신규 설치 기본값과 화면 추천 목록**을 바꾸는 일이며, 이미 설치된 사이트에는 반영되지 않는다
- [ ] 그 카탈로그를 고쳤다면 **동의 안내 문구도 함께** 고친다 — 항목이 어느 카테고리에 속하는지 사용자에게 말하는 자리가 `plugin.php`(설치 시드) · `src/Services/CookieCategoryService.php`(런타임 폴백) · `resources/lang/{ko,en}.json`(관리자 안내) · `editor-spec.json`(편집기 샘플) 넷이다. 한 곳만 고치면 동의 고지가 실제 동작과 어긋난 채 남는다
- [ ] 판정 규칙(매칭·잠금 집합·스코프 어휘)을 고치면 PHP(`src/Support/NecessaryAllowlist.php`)와 TS(`resources/js/necessaryAllowlist.ts`)를 **함께** 고친다 — 한쪽만 고치면 그 항목이 서버에서만(또는 브라우저에서만) 살아 있고, 그 어긋남은 예외도 로그도 남기지 않는다 (`__tests__/necessaryAllowlistCoverage.test.ts` 가 대조한다)
- [ ] 설정 키를 새로 노출할 때는 `config/settings/defaults.json` 의 `frontend_schema` 에 `expose: true` 를 **함께** 넣는다 — 빠지면 저장도 화면도 정상인데 브라우저 인라인 페이로드에만 값이 오지 않아 인터셉터가 빈 목록으로 선다
- [ ] 그 문구를 고쳤으면 기설치본의 **저장된** 안내도 정정하는 업그레이드 스텝을 동반한다 — 카테고리 정의는 설치 시점에 시드되고 이후 갱신되지 않는다 (선례: `upgrades/data/1.0.4/migrations/01_RetagThemeAsStrictlyNecessary.php`)
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan plugin:update sirsoft-gdpr --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| `gdpr_user_consent_histories` 행을 UPDATE/DELETE 로 직접 정정 | 정정이 필요하면 새 이력 행을 INSERT | 이력은 시점별 스냅샷이 생명 — 과거 행을 고치면 Art.7(1) 입증 자료로서 효력을 잃는다 |
| 회원탈퇴(`after_withdraw`)에서 신원 정보(user_id 등)를 제거 | 활성 동의만 철회 처리, 신원은 완전삭제(`before_delete`) 시점에만 익명화 | 두 이벤트를 섞으면 탈퇴 회원의 재가입·이력 조회가 깨진다 |
| 운영자가 등록하지 않은 functional 쿠키를 화이트리스트에 추가 | 필수 허용목록(운영자 설정) ∪ 잠금 집합만 예외 | GDPR 은 "동의 전 전면 차단"이 원칙이지 "등록된 것만 차단"이 아니다 |
| 소비자(인터셉터·정리기·미들웨어)가 자기 목록 사본을 들고 판정 | 넷 다 같은 설정을 같은 함수로 읽는다 | 사본은 갈라지는데 그 어긋남은 "그 항목만 안 되는" 상태로만 나타난다 |
| 잠금 항목을 설정(`necessary_storage_allowlist`)에 넣기 | 코드(`NecessaryAllowlist::locked()`)가 정하고 판정에서 합집합 | 설정에 있으면 저장 요청 한 번으로 지워져 잠금이 아니게 된다 |
| 세션 쿠키 이름을 `'laravel_session'` 으로 하드코딩 | `config('session.cookie')` 런타임 해석 | `SESSION_COOKIE` 를 지정한 사이트에서 그 항목이 죽고, 그 사실이 어디에도 드러나지 않는다 |
| 새 확장의 저장 키를 플러그인이 관측·수집해 자동 등재 | 운영자가 화면에서 직접 추가 | 저장 키 관측은 그 자체가 추적이다 — 동의 없이 하는 관측을 이 플러그인이 할 수는 없다 |
| 정책 버전 발행을 코드/배치로 자동화 | 운영자가 매번 명시적으로 "+ 새 버전 발행" 클릭 | 자동화하면 사소한 문구 수정에도 전 회원이 재동의 화면을 보게 된다 |
| 저장소 허용목록만 고치고 동의 안내 문구는 그대로 두기 | 목록·문구 4곳·업그레이드 스텝을 한 작업 단위로 | 안내가 "이 항목은 기능 쿠키이고 거부하면 저장되지 않는다" 라고 말하는데 실제로는 항상 저장되면, 고지 자체가 사실과 달라진다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 25개 | `plugins/_bundled/sirsoft-gdpr/tests` |
| Vitest | 13개 | `vitest.config.ts` |
| Playwright | 4개 | `tests/Playwright` |
| 시나리오 매니페스트 | 6개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-gdpr/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-gdpr && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-gdpr && npm run test:e2e -- specs/<대상>.spec.ts

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
