# 나이스페이먼츠 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
나이스페이먼츠 표준결제를 `sirsoft-ecommerce` 에 연결하는 어댑터입니다. 이 플러그인의
고유한 설계 축은 승인이 **인증→승인 2단계**로 나뉜다는 점입니다 — 결제창이 먼저 인증
결과만 보내고, 서버가 그 결과를 별도 승인 API 호출로 확정합니다. `sirsoft-pay_kginicis`처럼
결제창 콜백 하나로 승인까지 끝나는 구조와 달리, 이 플러그인은 "인증은 됐지만 아직 승인
전"이라는 중간 상태를 다뤄야 합니다(§AGENTS.md "의도적으로 하지 않는 것").

이 플러그인도 결제 상태 자체는 소유하지 않습니다 — 주문·결제 테이블은 `sirsoft-ecommerce`
소유이고, 이 플러그인은 그 상태를 나이스페이먼츠 API 와 어떻게 주고받는가만 책임집니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
Controller (PaymentCallbackController / AdminEscrowController / AdminTransactionController)
  → NicePaymentsApiService (인증 검증 · 승인/취소/단건조회 API 호출)
  → sirsoft-ecommerce 의 Order/OrderPayment 모델 (직접 참조 — 이 플러그인 소유 모델 없음)

Listener (RegisterPgProviderListener 등)
  → sirsoft-ecommerce 의 필터 훅에 등록 (컴파일 타임 결합 없음)
```

`PaymentCallbackController`가 인증 결과(`AuthResultCode`)를 먼저 판정하고 실패 시 승인
API 호출 없이 조기 반환하는 것이 이 플러그인 계층 구조의 특징입니다 — 일반적인 "Controller
→ FormRequest → Service" 흐름과 달리, 이 판정 자체가 Controller 안에 있는 이유는 그
판정 결과에 따라 아예 다른 응답(silent redirect vs `?error=`)을 골라야 하기 때문입니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_nicepayments --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_nicepayments --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
