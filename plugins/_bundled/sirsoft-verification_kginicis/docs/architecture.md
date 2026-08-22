# KG이니시스 본인인증 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
KG이니시스 본인확인을 코어 IDV(본인인증) 체계에 연결하는 Provider 입니다. 코어
`IdentityVerificationInterface`를 구현하는 것이 이 플러그인의 유일한 계약이며, 코어는
`core.identity.registered_providers` 필터로 등록된 provider 목록만 알고 어느 PG사의
구현인지는 모릅니다.

결제 PG 플러그인들과 달리 이 플러그인은 실제 PII 를 소유합니다(§data-model.md). 본인확인
결과(CI/DI 등)를 재확인 없이 판단하려면 그 결과를 어딘가 보관해야 하고, 그 보관 책임은
Provider 자신에게 있습니다 — 코어는 "본인확인 완료 여부"만 알면 되고 원본 PII 를 알 필요가
없습니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
Controller (Http/Controllers) → FormRequest (Http/Requests)
  → InicisIdentityProvider (IdentityVerificationInterface 구현 — verify/challenge 표준 진입점)
    → InicisGatewayInterface (외부 통신 + SEED 복호화)
    → InicisChallengeMappingRepositoryInterface (mTxId ↔ challenge_id)
    → InicisIdentityRecordRepositoryInterface (PII record)
    → CacheInterface (비로그인 verify PII 임시 stash)

Listener (RegisterInicisProviderListener 등)
  → 코어 identity/auth/user 훅에 등록 (컴파일 타임 결합 없음)
```

`InicisIdentityProvider`가 4개의 협력자(게이트웨이·Repository 2종·캐시)를 생성자 주입받는
것은 그 각각이 서로 다른 관심사(외부 API 통신, DB 영속, 임시 캐시)이기 때문입니다 — 하나로
합치면 단위 테스트에서 외부 API 를 모킹할 때 DB/캐시까지 함께 모킹해야 합니다.
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
