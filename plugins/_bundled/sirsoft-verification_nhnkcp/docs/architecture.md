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

날짜 기준도 두 플러그인이 같습니다. 성인 판정(`KcpIdentityProvider::isAdult()`)의 만 나이는
한국 법상 나이라 생일과 오늘을 모두 `Asia/Seoul` 날짜로 셉니다(서버 UTC 날짜로 세면 한국 시간
0~9시에 생일을 맞은 사람이 하루 늦게 성인이 됩니다). 마이페이지 카드의 `verified_at` 은 보는 사람
시간대 문자열입니다.
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
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-verification_nhnkcp --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-verification_nhnkcp --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 마이페이지 카드 라우트의 상태 게이트 (이슈 #636 S4)

카드 조회 라우트는 `auth:sanctum` + `check.user_status` 를 씁니다. 종전 표기 `check.user_status:active` 의
`:active` 는 **아무 일도 하지 않았습니다** — 이 미들웨어는 파라미터를 받지 않고 Laravel 이 초과 인자를
조용히 버립니다. 「이 라우트만 active 로 좁혔다」는 거짓 신호였으므로 제거했습니다.

통과 어휘는 미들웨어가 단독으로 소유합니다(활성만 통과 / 비활성·차단·**휴면**·탈퇴는 403). 라우트별로
상태 어휘를 갈라 두면 휴면처럼 상태가 새로 생길 때 게이트 강도가 라우트마다 어긋납니다 — 그래서 이
플러그인은 상태 판정을 재선언하지 않습니다.

## 본인인증 값을 회원 항목에 내주기 (코어 7.2.0)

`KcpIdentityProvider` 는 코어의 선택 계약 `App\Contracts\Extension\ProvidesVerifiedClaimsInterface` 를 구현합니다.
코어는 확인을 마친 요청 행의 인증 값을 받아, 운영자가 환경설정 > 회원에서 「본인인증 연결」을 건 회원 항목을 채우고
「인증 값으로 채우고 잠금」을 켠 항목을 잠급니다. 코어 쪽 동작은 코어 문서 「본인인증 정책」의 「본인인증 값을 회원 항목에
채우기」 절에 있습니다.

| 인증 값 키 | 원본 (KCP 응답) | 바꾸는 방법 |
| --- | --- | --- |
| `name` | `user_name` | 앞뒤 공백만 뗀다 |
| `mobile` | `phone_no` | 숫자만 남긴다 |
| `birth_date` | `birth_day` (`YYYYMMDD`) | `YYYY-MM-DD`. 여덟 자리 숫자가 아니거나 없는 날짜면 주지 않는다 |
| `gender` | `sex_code` (`01` 남, `02` 여) | `male`, `female`. 그 밖의 값은 주지 않는다 |

KCP 테스트 서버는 생년월일과 성별을 빈 값으로 줍니다. 테스트 모드에서는 그 둘을 주지 않고, 줄 수 있는 값 목록
(`supportedClaims()`)에서도 뺍니다. 목록에 없는 키에 연결된 회원 항목은 잠기지 않으므로, 테스트 모드에서 회원이 직접 넣은
생년월일이 인증 값처럼 잠기지 않습니다.

값을 꺼내는 곳은 요청 행의 상태에 따라 다릅니다.

| 상황 | 값의 원천 |
| --- | --- |
| 가입 전 (요청 행에 회원이 없음) | 인증 직후 넣어 둔 임시 저장 값 `nhnkcp:pending_record:{요청 ID}`. 요청 만료까지만 남고, 가입이 끝나면 지워진다 |
| 회원이 있음 (가입을 마친 뒤, 로그인 회원의 본인인증) | 그 회원의 `nhnkcp_identity_records` 한 줄. `latest_log_id` 가 그 요청 행일 때만 |

- 임시 저장 키는 요청 ID 로 만들어지므로 다른 요청의 값이 섞이지 않습니다.
- 회원 기록은 회원당 한 줄이고 다시 인증하면 덮어씁니다. 그래서 옛 요청 행으로 물으면 값을 주지 않습니다(다른 인증의 값을
  그 요청의 값인 것처럼 주지 않습니다).
- 요청 행이 다른 회원의 것이면 주지 않습니다.
- 저장 값을 복호화할 수 없으면(암호화 키 교체 등) 값을 주지 않고 요청 ID 만 로그에 남깁니다.
- 코어는 받은 값을 회원 항목 값으로만 씁니다. 이 플러그인도 인증 값을 새로 저장하지 않습니다(이미 있는 기록과 임시 저장 값을
  읽기만 합니다).
