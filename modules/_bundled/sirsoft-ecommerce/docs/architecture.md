# 이커머스 — 아키텍처

> 설계 의도와 계층 구조 · 진입점: [AGENTS.md](../AGENTS.md)

## 설계 의도

<!-- @intent START -->
이 모듈의 설계는 "커머스 도메인에서 **변하는 것**과 **변하지 않는 것**을 갈라 두는 것"에
집중되어 있습니다. 변하지 않는 것은 금액 계산 규칙·주문 상태 전이·원장 기록이고, 변하는 것은
결제사·화면 디자인·나라별 배송 규칙·프로모션 정책입니다. 변하는 축은 전부 이 모듈 **밖**으로
빼거나 데이터로 내려서, 새 결제사·새 나라·새 화면이 추가될 때 이 모듈의 소스를 고치지 않아도
되게 했습니다.

- **결제사**: 플러그인이 이 모듈에 의존하지, 이 모듈이 플러그인을 알지 않습니다. 코어
  `PaymentMethodEnum` 도 확장 결제수단 ID 를 모르므로, 능력(`needs_pg`/`pg_locked`/
  `pg_provider`)은 등록하는 플러그인이 카탈로그에 선언하고 화면과 서버는 그 선언만 읽습니다.
- **화면**: 레이아웃 206개가 전부 관리자 화면입니다. 방문자 상점 화면은 템플릿이 공개 API 를
  소비해 그리므로, 상점 디자인이 여러 벌 필요해도 이 모듈은 하나로 유지됩니다.
- **나라·통화·배송**: `ShippingPolicy` + `ShippingPolicyCountrySetting` 조합으로 국가별 요금
  규칙을 데이터로 표현하고(`ChargePolicyEnum` 14종), 통화는 설정에서 읽습니다. 코드에 통화
  코드나 국가 코드를 박지 않는 것이 규칙입니다.
- **프로모션**: 쿠폰의 대상 범위(`CouponTargetScope`)·대상 금액(`CouponTargetType`)·할인 방식
  (`CouponDiscountType`)·발급 방식(`CouponIssueMethod`)이 전부 Enum + 데이터 조합이라, 새
  프로모션 유형 대부분은 코드 없이 관리자 화면에서 만들어집니다.

그 대가로 **계산기 하나가 무거워집니다.** `OrderCalculationService` 는 9단계(옵션 금액 → 상품·
카테고리 쿠폰 → 배송비 → 배송비 쿠폰 → 주문금액 쿠폰 → 적립 마일리지 → 결제금액 → 마일리지
사용 → 최종 지불금액)를 한 번에 수행하며, 상품 상세·장바구니·체크아웃·주문 생성·결제 완료
검증·부분 취소 여섯 지점이 모두 이 하나를 부릅니다. 이 집중은 의도된 것입니다 — 계산이 흩어지면
화면 금액과 청구 금액이 갈라지고, 그 어긋남은 결제가 끝난 뒤에야 예외로 드러납니다.

**의도적으로 하지 않는 것**: 실시간 브로드캐스트(채널 0개)·PG 통신·방문자 화면 소유·문의 본문
저장. 문의는 게시판 모듈이 글로 보관하고 이 모듈은 상품↔글 피벗만 갖는데, manifest 의존에는
게시판이 없습니다. 연결이 코드 결합이 아니라 훅 구독이라 게시판이 없으면 문의 기능만 비고
나머지는 그대로 동작합니다.

**클레임(교환·반품·환불)은 취소의 확장이 아니라 별도 축입니다.** 취소는 옵션을 주문에서 빼는
한 번의 사건이지만 클레임은 신청 → (추가결제) → 승인 → 회수 → 재발송 → 완료 → 환불 실행이
시간차를 두고 이어집니다. 그래서 유형별 신청서 테이블(교환 · 반품 · 환불)을 두고 상태머신을
각 Enum 의 `canTransitionTo()` 로 고정했으며, 유형을 가로지르는 것(첨부 · 추가결제)만 polymorphic
으로 묶었습니다. 금액은 여전히 계산기 하나를 지납니다 — 반품·교환의 환불 대상액은 취소와 같은
`OrderAdjustmentService` 경로이고, 취소 가능 금액의 상한은 `CancellableAmountCalculator` 가
도메인 환불 대상액과 결제수단 잔여 한도의 최솟값으로 정합니다. 결제사와의 접합면도 같은 원칙을
따릅니다 — 추가결제(`CP` 채번)의 승인·환급은 이 모듈이 발행하는 `claim_payment.approve` /
`claim_payment.refund` 필터를 결제 플러그인이 구현하는 방식이라, 이 모듈은 여기서도 PG 를
알지 않습니다.

환불신청서의 수단은 **저장값과 실효 수단**이 다를 수 있습니다. 결제 플러그인이 꺼진 동안 접수된 카드
신청서는 계좌 환불로 폴백되고, 플러그인이 돌아오면 운영자가 수단을 고르지 않은 한 실행 때 PG 로
되돌아갑니다. 화면(신청서 상세)·저장·실행이 모두 `OrderRefundRequestService::effectiveRefundMethod()`
하나를 읽고, 운영자 선택 표지(`refund_method_overridden`)는 **실효 수단과 다른 값을 고른 때만** 붙습니다 —
화면은 고치지 않은 수단도 실행 본문에 싣기 때문입니다. 반대 방향(PG → 계좌)은 바꾸지 않으며, 꺼진
플러그인의 PG 건은 저장·실행 모두 「결제 플러그인이 꺼져 있습니다」 사유(`pg_provider_inactive`)로 막습니다.

주문 하나에 여러 유형의 클레임이 걸릴 때 **어느 유형을 먼저 보여 줄 것인가**는 서비스 상수
`UserClaimService::ORDER_LIST_TYPE_PRIORITY` 가 정합니다 — 접수 건이 있는 첫 유형을 고르되
순서는 교환 → 반품 → 취소 → 환불입니다(설계 결정 2026-09-09). 화면 서브탭의 배열 순서와
다른 것은 의도입니다: 결제완료 주문의 취소·환불 신청은 즉시취소로 분기해 취소만 남는 주문이
흔한데, 늘 교환 탭부터 열면 취소 1건인 주문에서 빈 안내가 먼저 보입니다. 회원·비회원 주문 상세와
관리자 주문상세 클레임 내역(`claimHistoryActiveType`)이 같은 상수를 씁니다.
<!-- @intent END -->

## 계층 지도

<!-- @intent START -->
```
Http/Controllers (Admin/ 관리자, User/ 구매자, Guest/ 비회원 주문조회)
        │
        ▼
FormRequest (검증 + *_validation_rules 필터 훅으로 확장 지점 제공)
        │
        ▼
Services 48종
        │  ├─ 계산 레인: OrderCalculationService(9단계) · CurrencyConversionService
        │  │              · ShippingPolicyResolver · OrderAdjustmentService
        │  ├─ 흐름 레인: CheckoutDataService → TempOrderService → OrderProcessingService
        │  │              → OrderCancellationService
        │  └─ 도메인 CRUD 레인: Product/Category/Brand/Coupon/Review/... Service
        │       before_* → filter_*_data → 실행 → after_*  (도메인 공통 3단 훅 패턴)
        ▼
Repositories (Interface 경유 — 목록은 컬럼 프루닝·정렬 화이트리스트)
        │
        ▼
Models 47종 (Order/OrderOption/Product/... — 주문 계열은 SoftDeletes)
```

Support 클래스(`src/Support/`)는 계층이 아니라 **규칙의 단일 출처**입니다 — `VatCalculator`
(과세/면세 안분) · `MileageRounding`(적립 절사) · `ShippingPolicySnapshot`(주문 시점 배송정책
동결 — 표시용 금액 문자열은 `forViewer()` 가 응답 시점 언어로 다시 적는다) · `CurrencySettingsCache`(통화 설정 조회) · `ReviewWritePolicy`(리뷰 작성 가능 판정) ·
`ShopPathResolver`(상점 경로). 같은 규칙을 서비스마다 다시 구현하지 않기 위한 자리이므로,
새 서비스가 반올림·안분·경로 조립을 직접 하고 있으면 여기로 올려야 하는 신호입니다.

Listeners 33종은 이 흐름과 **별도 레인**입니다. Service 가 발행한 훅을 받아 활동 로그·검색
색인·SEO 캐시 무효화·카테고리 트리 캐시·마일리지 적립·현금영수증 발행·알림 데이터 추출·장바구니
병합을 수행하며, Service 자신은 이 부가효과를 알지 못합니다. 그래서 새 부가효과는 Service 를
건드리지 않고 리스너 추가만으로 끝납니다 — 단, 금전이 되돌아가야 하는 리스너(쿠폰·마일리지
복원)는 `'sync' => true` 로 구독해야 호출자 트랜잭션과 함께 롤백됩니다.

클레임은 흐름 레인에 **한 줄이 더 있는 셈**입니다 — `User|Guest\ClaimController` →
`OrderExchangeService` / `OrderReturnService` / `OrderRefundRequestService`(신청·전이·완료) →
`ClaimPaymentService`(추가결제) → `RefundExecutionService`(환불 실행 — 취소와 공용). 그 옆에
규칙의 단일 출처가 셋 더 있습니다: `ClaimQuantityGuard`(수량 잠금 검증) · `ClaimShippingFeeService`
(클레임 배송비) · `CancellableAmountCalculator`(취소 가능 금액 상한). 새 클레임 유형이나 새 청구
방식을 더할 때 이 셋을 우회해 서비스 안에서 다시 계산하면 취소 경로와 규칙이 갈립니다.
<!-- @intent END -->

## 디렉토리

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `module.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `module.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan module:update sirsoft-ecommerce --force` (빌드 불필요) |
| `resources/routes/` | 라우트 → 레이아웃 매핑 (분할) | `php artisan module:update sirsoft-ecommerce --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-ecommerce --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan module:update sirsoft-ecommerce --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-ecommerce --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan module:update sirsoft-ecommerce --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan module:update sirsoft-ecommerce --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->
