# KG이니시스 본인인증 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-verification_kginicis) — KG이니시스 본인확인(reqSvcCd=03) IDV Provider. 코어 `IdentityVerificationInterface` 12메서드 구현, PII 레코드 소유 (payment 플러그인과 달리 소유 테이블 있음)
2. 확장 방식: `RegisterInicisProviderListener` 로 코어 `core.identity.registered_providers` 필터에 등록 — 코어는 이 플러그인의 존재를 모른다
3. 건드리면 안 되는 것: 비로그인 사용자 PII 캐시 stash(`inicis:pending_record:` 접두) 로직 우회, 라이브 MID `SRB` 프리픽스 정책값 상수(`LIVE_MID_PREFIX`) 미참조, 중복가입 차단(`AssertNoDuplicateInicisIdentity`) 우회
4. 작업 위치: `plugins/_bundled/sirsoft-verification_kginicis` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-verification_kginicis --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
KG이니시스 본인확인(휴대폰 인증, `reqSvcCd=03`)을 코어 본인인증(IDV) 체계에 연결하는
Provider 입니다. 코어 `IdentityVerificationInterface`(표준 12메서드)를 구현해, 회원가입·
비밀번호 찾기·민감작업 등 코어가 정의한 모든 IDV 강제 지점에서 이메일 인증 대신 이니시스
팝업이 대신 동작하게 합니다. `sirsoft-verification_nhnkcp`도 같은 인터페이스를 구현하며,
운영자는 둘 중 어느 것이든(또는 둘 다) 설치해 사용할 수 있습니다 — 코어는 등록된
provider ID로만 구분하고 어느 PG사인지 모릅니다.

**결제 PG 플러그인과의 결정적 차이**: 이 플러그인은 실제 PII(개인식별정보) 레코드를
소유합니다(§data-model.md — `inicis_identity_records` 테이블, `InicisIdentityRecord`
모델). 결제 플러그인들이 "상태는 남의 것, 절차만 내 것"이었던 것과 달리, 본인확인은 그
확인 결과(이름·생년월일·성별·CI/DI 등)를 이 플러그인이 직접 보관해야 이후 재확인 없이
"본인확인 완료 여부"를 판단할 수 있습니다.

**의도적으로 하지 않는 것**: 비로그인 사용자(예: 회원가입 도중)의 PII는 확인 즉시
DB 에 쓰지 않고 Cache 에 임시 저장(`inicis:pending_record:` 접두)했다가, 가입이 실제로
완료된 뒤(`core.auth.after_register` 훅)에야 레코드로 흡수합니다 — 가입을 완료하지 않은
방문자의 PII 를 DB 에 영구 저장하지 않기 위함입니다. 사용자가 탈퇴하거나 계정이 삭제되면
`core.user.after_withdraw`/`core.user.before_delete` 훅에서 관련 레코드를 정리합니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-verification_kginicis --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**본인확인 시작~완료**: 코어가 IDV 를 요구하는 지점(회원가입 등)에서 428 응답 →
프론트 `startAuth` 핸들러가 사용자 클릭 컨텍스트 안에서 `window.open`으로 빈 팝업 생성
(Chrome popup blocker 회피 — 자동 호출은 차단되지만 클릭 직후 호출은 통과) → 코어
challenge 시작 응답의 `mid`/`mtxid`/`authHash`로 팝업에 이니시스 인증 폼 제출 → 이니시스
인증 완료 후 `InicisChallengeMappingRepository`가 mTxId ↔ challenge_id 매핑을 저장 →
인증 결과는 postMessage 또는 팝업 종료 감지로 회수 → `InicisIdentityProvider::verify()`가
SEED 복호화 후 결과를 반환. 성인인증(`inicis.adult_verification` purpose)으로 발행된
challenge 는 만 19세 이상만 통과시킵니다.

**비로그인 사용자(회원가입 도중) 처리**: `verify()` 시점에 로그인 사용자가 없으면 PII 를
Cache 에 stash(`inicis:pending_record:{key}`) → 회원가입 완료 → `core.auth.after_register`
훅 → `CompleteInicisRecordAfterRegister`가 같은 캐시 키로 PII 를 회수해
`InicisIdentityRecord`로 흡수.

**중복가입 차단**: `core.auth.before_register` 훅 → `AssertNoDuplicateInicisIdentity`가
`duplicate_block_enabled` 설정이 켜져 있으면 `duplicate_field`(DI 또는 CI) 기준으로
`InicisIdentityLogQueryRepository`를 조회해 이미 가입된 동일인이 있는지 확인 → 있으면
가입을 차단.

**사용자 삭제/탈퇴 시 PII 정리**: `core.user.before_delete`/`core.user.after_withdraw` 훅 →
`CleanInicisRecordOnUserDelete`/`CleanInicisRecordOnUserWithdraw`가 해당 사용자의
`inicis_identity_records`를 정리.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 3개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 6개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 6개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 2개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 0개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
`core.identity.registered_providers` 는 코어가 등록된 IDV provider 목록을 모으는 필터
훅입니다 — 새 IDV PG 를 추가하려는 확장은 이 훅에 자기 provider 를 등록하면 됩니다
(`sirsoft-verification_nhnkcp`가 동일 패턴). `core.plugin_settings.update_validation_rules`는
`ValidateInicisSettingsListener`가 `is_test_mode=false`(라이브 모드) 진입 시
`live_mid`/`live_api_key`에 `required` 규칙을 동적으로 부여하는 자리입니다 — 코어
`UpdatePluginSettingsRequest`의 정적 스키마는 "테스트 모드일 땐 선택, 라이브 모드일 땐
필수" 같은 조건부 검증을 표현할 수 없기 때문입니다. `live_mid`의 `SRB` 프리픽스는 이
필터가 아니라 `InicisIdentityProvider::buildLiveMid()`가 그 값을 실제로 쓸 때(요청 조립
시점) 동적으로 부착하므로 별도 형식 검증을 두지 않습니다 — DB 에는 운영자가 입력한 원본
값이 그대로 저장됩니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-verification_kginicis --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-verification_kginicis` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] PII 컬럼(이름·생년월일·성별·CI/DI 등)을 다루는 코드 변경 시 GDPR 삭제/탈퇴 정리 리스너(`CleanInicisRecordOnUserDelete`/`CleanInicisRecordOnUserWithdraw`)가 여전히 그 컬럼을 정리하는지 확인
- [ ] 팝업 기반 인증 흐름(`startAuth`)을 고칠 때 `window.open`을 사용자 클릭 컨텍스트 밖으로 옮기지 않는다 — Chrome popup blocker 회피가 깨진다
- [ ] `duplicate_field`/`duplicate_block_enabled` 로직을 고치면 `InicisDuplicateField` Enum 과 `AssertNoDuplicateInicisIdentity`를 함께 갱신

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 비로그인 사용자의 PII 를 verify 즉시 DB 에 저장 | Cache 에 stash(`inicis:pending_record:` 접두) 후 가입 완료 시 흡수 | 가입을 완료하지 않은 방문자의 PII 를 DB 에 영구 저장하면 불필요한 개인정보 보유가 된다 |
| 사용자 삭제/탈퇴 리스너 없이 PII 컬럼 추가 | `CleanInicisRecordOnUserDelete`/`CleanInicisRecordOnUserWithdraw`에 정리 로직 동반 | 정리 누락 시 탈퇴한 사용자의 PII 가 무기한 남는다 |
| `LIVE_MID_PREFIX` 상수를 참조하지 않고 `'SRB'`를 문자열로 재작성 | `InicisIdentityProvider::LIVE_MID_PREFIX` 참조 | 이니시스 프리픽스 정책이 바뀌면 상수 1곳만 갱신해야 런타임 로직 전체에 반영된다 — 문자열 재작성은 사각을 만든다 |
| 팝업을 사용자 클릭 이벤트 핸들러 밖(비동기 콜백 등)에서 `window.open` | 사용자 제스처 컨텍스트 안에서 직접 호출 | Chrome 등 브라우저는 사용자 제스처 없이 열리는 팝업을 자동 차단한다 |
| 라이브 API 키를 로그·에러 메시지에 노출 | 운영 키는 항상 마스킹하거나 로그 대상에서 제외 | 노출되면 제3자가 본인확인 API 를 위조 호출할 수 있다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 22개 | `plugins/_bundled/sirsoft-verification_kginicis/tests` |
| Vitest | 8개 | `vitest.config.ts` |
| Playwright | 0개 | — |
| 시나리오 매니페스트 | 8개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-verification_kginicis/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-verification_kginicis && powershell -Command "npm run test:run -- <대상>"

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
