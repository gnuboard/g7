# NHN KCP 휴대폰 본인확인 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
NHN KCP 휴대폰 본인확인을 코어 IDV 체계에 연결하는 Provider 입니다.
`sirsoft-verification_kginicis`와 계약(코어 `IdentityVerificationInterface`)·PII 소유
구조는 같지만, 인증 화면 진입 방식이 기기별로 갈립니다 — 데스크톱은 팝업, 모바일은 전체
페이지 리다이렉트입니다. 이 분기가 이 플러그인 아키텍처 전반(프론트 상태 복원,
`sessionStorage` 사용)에 스며 있습니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
Controller (Http/Controllers) → FormRequest (Http/Requests)
  → KcpIdentityProvider (IdentityVerificationInterface 구현 — verify/challenge 표준 진입점)
    → KcpCertClientInterface (외부 통신 + 암호화/복호화)
    → KcpCertTransactionRepositoryInterface (진행 중인 인증 거래)
    → KcpIdentityRecordRepositoryInterface (완료된 PII record)
    → IdentityVerificationLogRepositoryInterface (코어 IDV 로그 조회)
    → KcpDuplicateIdentityChecker (중복가입 판정 로직)
    → CacheInterface (비로그인 verify PII 임시 stash)

Listener (RegisterKcpProviderListener 등)
  → 코어 identity/auth/user/settings 훅에 등록 (컴파일 타임 결합 없음)
```

`KcpDuplicateIdentityChecker`가 별도 협력자로 분리된 것은 `sirsoft-verification_kginicis`와의
작은 차이입니다 — kginicis 는 그 판정 로직을 `AssertNoDuplicateInicisIdentity` 리스너
안에 두는 반면, 이 플러그인은 Provider 자신도 같은 판정 로직을 재사용할 수 있도록 별도
클래스로 뽑았습니다.

`sirsoft-verification_kginicis`와 계층 구조가 거의 동일한 것은 둘 다 같은 코어 계약을
구현하기 때문입니다 — 새 IDV provider 를 추가할 때 이 두 플러그인을 참조 구현으로 삼을 수
있습니다.
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
