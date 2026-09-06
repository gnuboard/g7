# NHN KCP — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
NHN KCP 표준결제(Standard Pay)를 `sirsoft-ecommerce` 에 연결하는 어댑터입니다. 다른 PG
플러그인(`sirsoft-pay_kginicis`, `sirsoft-tosspayments` 등)이 전부 HTTP API 로 승인을
받는 것과 달리, 이 플러그인의 PC 결제 승인은 **서버에서 KCP CLI 바이너리를 실행**하는
방식입니다 — KCP 가 표준결제 승인 로직을 컴파일된 실행파일로만 배포하기 때문입니다. 이
차이가 데이터 모델(§data-model.md — Repository 조차 없는 이유), 확장점(CLI 실행 권한
점검 API), 금지 패턴(CLI 인자 injection 방어) 전체에 스며 있습니다.

이 플러그인도 결제 상태 자체는 소유하지 않습니다 — 주문·결제 테이블은 `sirsoft-ecommerce`
소유이고, 이 플러그인은 "그 상태를 KCP CLI/SOAP API 와 어떻게 주고받는가"만 책임집니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```text
Controller (PaymentCallbackController / EscrowCommonNotifyController / MobileApprovalController)
  → NhnKcpApiService (CLI 실행 · SOAP 호출 · 인자 사전검증)
  → sirsoft-ecommerce 의 Order/OrderPayment 모델 (직접 참조 — 이 플러그인 소유 모델 없음)

Listener (RegisterPgProviderListener 등)
  → sirsoft-ecommerce 의 필터 훅에 등록 (컴파일 타임 결합 없음)
```

이 플러그인에는 FormRequest 계층이 얕습니다 — 결제 승인·통보 콜백은 사용자가 채운 폼이
아니라 KCP 가 보내는 고정 스키마이므로, 검증의 대부분은 `NhnKcpApiService`의
`assertSafeCliValue()`(CLI 인자 안전성)와 `PreventsReplayCallback`(중복 콜백 방지)이
담당합니다. 일반 CRUD 플러그인의 "Controller → FormRequest → Service → Repository → Model"
5단 계층과 다른 이유가 여기 있습니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_nhnkcp --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_nhnkcp --force` |
| `bin/` | 확장이 실행하는 외부 바이너리·인증서 | 교체 시 OS별 파일과 권한을 함께 확인 (비면 해당 기능 정지) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
