# NHN KCP 휴대폰 본인확인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-verification_nhnkcp) — NHN KCP 휴대폰 본인확인 IDV Provider. 코어 `IdentityVerificationInterface` 구현, PII 레코드 소유 (`sirsoft-verification_kginicis`와 같은 부류·다른 벤더)
2. 확장 방식: `RegisterKcpProviderListener` 로 코어 `core.identity.registered_providers` 필터에 등록 — 코어는 이 플러그인의 존재를 모른다
3. 건드리면 안 되는 것: 데스크톱 팝업/모바일 리다이렉트 분기(`isMobileEnvironment()`) 우회, 라이브 사이트코드 `SM` 프리픽스 정책값 상수(`LIVE_SITE_CD_PREFIX`) 미참조, 중복가입 차단(`AssertNoDuplicateKcpIdentity`) 우회
4. 작업 위치: `plugins/_bundled/sirsoft-verification_nhnkcp` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-verification_nhnkcp --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
NHN KCP 휴대폰 본인확인을 코어 본인인증(IDV) 체계에 연결하는 Provider 입니다.
`sirsoft-verification_kginicis`와 같은 부류(코어 `IdentityVerificationInterface` 12메서드
구현)지만 다른 벤더이며, 운영자는 둘 중 하나 또는 둘 다 설치해 사용할 수 있습니다 —
코어는 등록된 provider ID로만 구분합니다.

**이 플러그인만의 차이**: 인증 화면 진입 방식이 기기별로 갈립니다 — 데스크톱은
`sirsoft-verification_kginicis`와 동일하게 사용자 클릭 컨텍스트 안에서 빈 팝업을 열고
그 안에서 인증을 진행하지만, 모바일은 팝업 대신 **전체 페이지 리다이렉트**로 KCP 인증
화면으로 이동한 뒤 콜백으로 복귀합니다(`isMobileEnvironment()` 분기) — 모바일 브라우저는
팝업 UX 가 나쁘고 앱 전환(문자 인증 등)이 얽히면 팝업 컨텍스트 자체가 끊기기 쉽기
때문입니다. 리다이렉트 복귀 시 원래 상태를 되살리기 위해 `sessionStorage`에
`g7.identity.redirectStash` 키로 복귀 정보를 임시 저장합니다.

**설계 원칙**: 이 플러그인도 실제 PII 를 소유합니다(§data-model.md — `kginicis`와 같은
구조: 완료된 확인 결과 레코드 + 진행 중인 인증 거래 매핑을 별도 테이블로 분리).

**의도적으로 하지 않는 것**: `kginicis`와 동일하게, 비로그인 사용자의 PII 는 확인 즉시
DB 에 쓰지 않고 임시 보관했다가 가입 완료 시에만 레코드로 흡수합니다. 사용자 탈퇴/삭제
시 관련 PII 를 자동 정리합니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-verification_nhnkcp --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**본인확인 시작~완료(데스크톱)**: 코어 428 응답 → `startAuth`가 사용자 클릭 컨텍스트
안에서 `window.open`으로 빈 팝업 생성 → KCP 인증 폼 제출 → 인증 완료 후
`KcpCertTransactionRepository`가 거래 매핑을 저장 → 팝업 종료 감지로 결과 회수 →
`KcpIdentityProvider::verify()`가 결과를 반환. 성인인증(`nhnkcp.adult_verification`
purpose)으로 발행된 challenge 는 만 19세 이상만 통과시킵니다.

**본인확인 시작~완료(모바일)**: `isMobileEnvironment()`가 참이면 팝업 대신 전체 페이지
리다이렉트로 KCP 인증 화면으로 이동 → 복귀 정보를 `sessionStorage`(`g7.identity.redirectStash`)에
저장 → 인증 완료 후 콜백 URL로 복귀 → 저장된 stash 로 원래 challenge 컨텍스트를 복원.

**비로그인 사용자 처리 / 중복가입 차단 / 사용자 삭제·탈퇴 시 정리**:
`sirsoft-verification_kginicis`와 동일한 3단계 패턴입니다 — `verify()` 시 Cache
stash(`nhnkcp:pending_record:`) → `core.auth.after_register`에서 흡수,
`core.auth.before_register`에서 `AssertNoDuplicateKcpIdentity`가 중복 차단,
`core.user.before_delete`/`after_withdraw`에서 PII 정리.

**설정 저장 시 검증 + 라이브 사이트코드 정규화**: 관리자가 설정 저장 요청 → FormRequest
검증 단계에서 `core.plugin_settings.update_validation_rules` 훅 →
`ValidateKcpSettingsListener::addLiveModeRules()`가 라이브 모드일 때 `live_site_cd`/
`live_enc_key`를 필수로 강제(값이 비어있지 않은지만 확인 — 아직 프리픽스는 보지 않음) →
검증 통과 후 `PluginSettingsService`가 실제 저장하는 단계에서
`core.plugin_settings.filter_save_data` 훅 → 같은 리스너의 `normalizeLiveSiteCd()`가
`live_site_cd`에 `SM` 프리픽스가 없으면 자동으로 붙여 저장. 두 훅이 분리된 이유는 코어의
FormRequest 검증과 Service 저장이 서로 다른 파이프라인 단계이기 때문입니다 — "필수값이
비어있지 않은가"는 검증 단계에서, "저장될 값의 형식을 교정"은 저장 단계에서 처리합니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 0개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 7개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 6개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅이 0개인 것은 `sirsoft-verification_kginicis`와의 실제 차이입니다 — kginicis 쪽
3건은 리스너 docblock 의 코어 호출 예시 주석을 소스 자동 감지가 오인한 결과였는데(§data-model.md
계열의 동일 패턴), 이 플러그인의 리스너 docblock 은 그런 예시 서술 방식을 쓰지 않아
오탐이 없습니다. `core.identity.registered_providers`는 코어가 등록된 IDV provider 목록을
모으는 필터 훅입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-verification_nhnkcp --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-verification_nhnkcp` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] PII 컬럼을 다루는 코드 변경 시 GDPR 삭제/탈퇴 정리 리스너(`CleanKcpRecordOnUserDelete`/`CleanKcpRecordOnUserWithdraw`)가 여전히 그 컬럼을 정리하는지 확인
- [ ] 데스크톱/모바일 분기(`isMobileEnvironment()`)를 고칠 때 양쪽 복귀 경로(팝업 종료 감지 / `redirectStash` 복원)를 함께 테스트
- [ ] `duplicate_field`/`duplicate_block_enabled` 로직을 고치면 `KcpDuplicateField` Enum 과 `AssertNoDuplicateKcpIdentity`를 함께 갱신
- [ ] `normalizeLiveSiteCd()`/`addLiveModeRules()`를 고칠 때 두 훅(`filter_save_data`/`update_validation_rules`)의 실행 순서(검증 먼저, 정규화는 저장 시점)를 유지

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 비로그인 사용자의 PII 를 verify 즉시 DB 에 저장 | Cache 에 stash(`nhnkcp:pending_record:` 접두) 후 가입 완료 시 흡수 | 가입을 완료하지 않은 방문자의 PII 를 DB 에 영구 저장하면 불필요한 개인정보 보유가 된다 |
| 사용자 삭제/탈퇴 리스너 없이 PII 컬럼 추가 | `CleanKcpRecordOnUserDelete`/`CleanKcpRecordOnUserWithdraw`에 정리 로직 동반 | 정리 누락 시 탈퇴한 사용자의 PII 가 무기한 남는다 |
| `LIVE_SITE_CD_PREFIX` 상수를 참조하지 않고 `'SM'`을 문자열로 재작성 | `KcpIdentityProvider::LIVE_SITE_CD_PREFIX` 참조 | KCP 사이트코드 정책이 바뀌면 상수 1곳만 갱신해야 런타임 로직 전체에 반영된다 |
| 모바일 리다이렉트 복귀 시 `redirectStash`를 검증 없이 신뢰 | 복귀 정보의 challenge 컨텍스트를 서버측 상태와 대조 후 사용 | `sessionStorage`는 클라이언트가 임의로 조작할 수 있는 저장소다 |
| 팝업을 사용자 클릭 이벤트 핸들러 밖에서 `window.open` | 사용자 제스처 컨텍스트 안에서 직접 호출 (데스크톱 경로) | Chrome 등 브라우저는 사용자 제스처 없이 열리는 팝업을 자동 차단한다 |
| 라이브 암호화 키를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 본인확인 API 를 위조 호출할 수 있다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 20개 | `plugins/_bundled/sirsoft-verification_nhnkcp/tests` |
| Vitest | 7개 | `vitest.config.ts` |
| Playwright | 2개 | `tests/Playwright` |
| 시나리오 매니페스트 | 14개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-verification_nhnkcp/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-verification_nhnkcp && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (Bash)
npx playwright test plugins/_bundled/sirsoft-verification_nhnkcp/tests/Playwright/specs/<대상>.spec.ts

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
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
