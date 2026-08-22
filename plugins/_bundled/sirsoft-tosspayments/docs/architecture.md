# 토스페이먼츠 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
토스페이먼츠 통합결제창을 `sirsoft-ecommerce`에 연결하는 어댑터입니다. 다른 PG
플러그인(iframe 팝업, CLI 실행, SOAP)과 달리 이 플러그인은 순수 리다이렉트 기반입니다 —
결제창이 브라우저를 콜백 URL로 돌려보내고, 서버는 그 쿼리 파라미터로 승인을 확인합니다.
이 단순함의 대가로 콜백 파라미터(특히 `amount`)를 신뢰하지 않고 서버가 재검증해야
합니다(§AGENTS.md "의도적으로 하지 않는 것").

가상계좌 웹훅 검증도 다른 PG 와 다릅니다 — IP 화이트리스트가 아니라 결제 승인 응답에만
실리는 secret 값 대조입니다. 토스가 notify IP 목록이나 서명을 제공하지 않기 때문입니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
Controller (PaymentCallbackController / WebhookController)
  → TossPaymentsApiService (승인 확인 · 취소 API 호출)
  → sirsoft-ecommerce 의 Order/OrderPayment 모델 (직접 참조 — 이 플러그인 소유 모델 없음)

Listener (RegisterPgProviderListener / RegisterTossPaymentMethodsListener / ValidateTossSettingsListener 등)
  → sirsoft-ecommerce 의 필터 훅 + 코어 설정 저장 훅에 등록 (컴파일 타임 결합 없음)
```

`ValidateTossSettingsListener`가 `core.plugin_settings.before_save`(코어 설정 저장 훅)를
구독하는 것은 이 플러그인만의 계층 특징입니다 — 결제 도메인 훅(`sirsoft-ecommerce.payment.*`)
뿐 아니라 코어 설정 저장 경로에도 개입해 저장 시점에 값을 검증합니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-tosspayments --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-tosspayments --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-tosspayments --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-tosspayments --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
