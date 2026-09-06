# KG 이니시스 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
결제 프로토콜(PC/모바일/CBT)마다 별도 컨트롤러·서비스 경로를 두면서도, 이커머스 쪽에는
`sirsoft-ecommerce.payment.registered_pg_providers`/`registered_cash_receipt_providers`
필터로 등록하는 하나의 진입점만 노출합니다. 이 경계 덕분에 KG 이니시스가 프로토콜을 바꾸거나
새 결제수단을 추가해도 이커머스 모듈 코드는 건드리지 않습니다 — 변경은 이 플러그인 안에서만
일어납니다. 반대로 이 플러그인이 소유 테이블을 두지 않는 것도 같은 경계 원칙입니다: 결제
"사실"(성공/실패/금액/취소)은 이커머스가 소유하고, 이 플러그인은 그 사실을 만드는 절차만
소유합니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
Controllers (PaymentCallbackController 등 — PG 콜백 수신, 화이트리스트·재처리 방지 검증)
        │
        ▼
Services (KgInicisApiService — 승인/취소/CBT API 호출, 서명·해시 생성)
        │
        ├──▶ Repositories (CbtCvsOperationsRepository/CbtReconciliationRepository
        │     — sirsoft-ecommerce 의 Order 모델을 조회, 자체 테이블 없음)
        │
        └──▶ 훅 발행 (before/after_authorize·cancel·cbt_refund) ──▶ 다른 확장 리스너

Listeners (RegisterPgProviderListener 등 — 이커머스 레지스트리 등록,
           레이아웃 확장 주입, 설정 검증)
```

미들웨어(`InicisNotifyIpWhitelist`)는 이 흐름과 별도 레인에서 가상계좌/CBT 편의점 입금통보
라우트 앞단을 지킵니다 — Service 계층이 아니라 라우팅 계층에서 걸러야 신뢰할 수 없는 발신자의
요청이 애초에 비즈니스 로직에 닿지 않습니다.
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
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan plugin:update sirsoft-pay_kginicis --force` (빌드 불필요) |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan plugin:update sirsoft-pay_kginicis --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->
