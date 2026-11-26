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

**1인 1계정 판정은 두 단계입니다(1.1.4).** 가입 전 리스너(`AssertNoDuplicateInicisIdentity`)는 record·선점표를 조회해
이미 가입한 명의를 먼저 안내하지만, 가입 트랜잭션 밖이라 같은 명의로 동시에 가입하면 둘 다 통과할 수 있습니다. 그래서
최종 판정은 코어가 가입 트랜잭션 안에서 발행하는 `core.auth.registering` 의 동기 구독자(`ClaimInicisIdentityOnRegistering`)가
**선점표(`inicis_identity_claims`, `(hash_type, hash)` 유니크)에 자리를 잡는 것**으로 합니다 — 자리를 못 잡으면 예외로 가입
전체(회원·동의 이력)가 롤백되고, 「어느 식별값이 겹쳤는지」 감사 기록만 롤백 뒤에 남깁니다. 선점표는 탈퇴·삭제 리스너가
같은 트랜잭션에서 비워 탈퇴 후 재가입은 그대로 허용됩니다. 본인확인 콜백의 완료 전이는 코어 로그 저장소의 조건부 갱신
(`markVerifiedIfPending`)이라, 같은 콜백이 동시에 두 번 와도 인증 결과는 하나만 만들어집니다(두 번째는 `ALREADY_CONSUMED`).
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
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-verification_kginicis --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-verification_kginicis --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 마이페이지 카드 라우트의 상태 게이트

카드 조회 라우트는 `auth:sanctum` + `check.user_status` 를 씁니다. 종전 표기 `check.user_status:active` 의
`:active` 는 **아무 일도 하지 않았습니다** — 이 미들웨어는 파라미터를 받지 않고 Laravel 이 초과 인자를
조용히 버립니다. 「이 라우트만 active 로 좁혔다」는 거짓 신호였으므로 제거했습니다.

통과 어휘는 미들웨어가 단독으로 소유합니다(활성만 통과 / 비활성·차단·**휴면**·탈퇴는 403). 라우트별로
상태 어휘를 갈라 두면 휴면처럼 상태가 새로 생길 때 게이트 강도가 라우트마다 어긋납니다 — 그래서 이
플러그인은 상태 판정을 재선언하지 않습니다.

## 본인인증 값을 회원 항목에 내주기 (코어 7.2.0)

`InicisIdentityProvider` 는 코어의 선택 계약 `App\Contracts\Extension\ProvidesVerifiedClaimsInterface` 를 구현합니다.
코어는 확인을 마친 요청 행의 인증 값을 받아, 운영자가 환경설정 > 회원에서 「본인인증 연결」을 건 회원 항목을 채우고
「인증 값으로 채우고 잠금」을 켠 항목을 잠급니다. 코어 쪽 동작은 코어 문서 「본인인증 정책」의 「본인인증 값을 회원 항목에
채우기」 절에 있습니다.

| 인증 값 키 | 원본 (이니시스 응답) | 바꾸는 방법 |
| --- | --- | --- |
| `name` | `userName` | 앞뒤 공백만 뗀다 |
| `mobile` | `userPhone` | 숫자만 남긴다 |
| `birth_date` | `userBirthday` (`YYYYMMDD`) | `YYYY-MM-DD`. 여덟 자리 숫자가 아니거나 없는 날짜면 주지 않는다 |
| `gender` | `userGender` 의 첫 글자 | `M` 은 `male`, `F` 는 `female`. 그 밖의 값은 주지 않는다 |

값을 꺼내는 곳은 요청 행의 상태에 따라 다릅니다.

| 상황 | 값의 원천 |
| --- | --- |
| 가입 전 (요청 행에 회원이 없음) | 인증 직후 넣어 둔 임시 저장 값 `inicis:pending_record:{요청 ID}`. 요청 만료까지만 남고, 가입이 끝나면 지워진다 |
| 회원이 있음 (가입을 마친 뒤, 로그인 회원의 본인인증) | 그 회원의 `inicis_identity_records` 한 줄. `latest_log_id` 가 그 요청 행일 때만 |

- 임시 저장 키는 요청 ID 로 만들어지므로 다른 요청의 값이 섞이지 않습니다.
- 회원 기록은 회원당 한 줄이고 다시 인증하면 덮어씁니다. 그래서 옛 요청 행으로 물으면 값을 주지 않습니다(다른 인증의 값을
  그 요청의 값인 것처럼 주지 않습니다).
- 요청 행이 다른 회원의 것이면 주지 않습니다.
- 저장 값을 복호화할 수 없으면(암호화 키 교체 등) 값을 주지 않고 요청 ID 만 로그에 남깁니다.
- 코어는 받은 값을 회원 항목 값으로만 씁니다. 이 플러그인도 인증 값을 새로 저장하지 않습니다(이미 있는 기록과 임시 저장 값을
  읽기만 합니다).
