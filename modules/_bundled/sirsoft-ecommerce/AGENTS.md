# 그누보드7 이커머스 모듈 — 에이전트 가이드

> 이 문서는 이 모듈을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 모듈 (sirsoft-ecommerce) — 상품·주문·결제·배송·취소/반품/교환/환불(클레임)·쿠폰·마일리지·리뷰·문의 도메인. 관리자 CRUD + 공개 API 만 소유하고, 방문자 쇼핑 화면은 템플릿이 그린다
2. 확장 방식: 발행 훅 524개(도메인마다 before_*→filter_*_data→after_*→*_validation_rules 4종 반복). PG 는 플러그인이 카탈로그 능력 선언으로 붙고, 금액 개입은 `calculation.*` 18종
3. 건드리면 안 되는 것: `OrderCalculationService` 를 우회한 금액 재계산, 마일리지 잔액 캐시 기반 차감 판정, 과거 주문 표기에 현재 통화 설정 조회(`currency_snapshot` 이 SSoT), 클레임 수량 잠금(`ClaimQuantityGuard`) 우회, 금전 복원 훅의 `sync` 누락
4. 작업 위치: `modules/_bundled/sirsoft-ecommerce` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan module:update sirsoft-ecommerce --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
상품·주문·결제·배송·취소/반품/교환/환불(클레임)·쿠폰·마일리지·리뷰·문의를 소유하는 커머스 도메인 모듈입니다.
모델 47종·소유 테이블 51개·발행 훅 508종으로 번들 확장 중 가장 큰 표면을 갖습니다.

**소유 범위는 관리자 CRUD + 공개 API 까지입니다.** 레이아웃 206개가 전부 `admin` 그룹인 것이
그 증거입니다 — 방문자가 실제로 보는 상품 목록·상세·장바구니·주문서 화면은 이 모듈이 그리지
않고, 템플릿(`sirsoft-basic`)이 이 모듈의 공개 API 를 소비해 그립니다. 그래서 쇼핑 화면의
디자인 변경은 이 모듈이 아니라 템플릿 쪽 작업입니다.

**설계 원칙 넷**:

1. **PG 는 이 모듈이 알지 않는다.** 결제 연동 코드는 전부 플러그인(`pay_kginicis` ·
   `pay_nhnkcp` · `pay_nicepayments` · `tosspayments`)에 있고, 그 플러그인들이 이 모듈에
   의존합니다(역방향 아님). 새 PG 는 이 모듈을 고치지 않고 플러그인 추가만으로 붙습니다 —
   결제수단 카탈로그에 자기 능력(`needs_pg` / `pg_locked` / `pg_provider` /
   `auto_approve_refund`)을 선언하는 것이 그 접합면입니다. `auto_approve_refund` 는 "사람의
   개입 없이 환불이 집행되는가" 이고, 미선언 기본값만 `false` 로 기웁니다 — 자동으로 잘못
   완료하면 이중 환불이고 멈추면 운영자가 한 번 더 누르면 되기 때문입니다.
   `sirsoft-ecommerce.currency.registered_rate_providers` 로 등록된 목록만 받고, 운영자가 설정 키
   `language_currency.rate_provider` 로 고릅니다(모듈이 자동 선택하지 않습니다). 제공자 이름·링크·
   배지 문구는 전부 훅 페이로드에서 오므로 모듈의 `src`·`resources`·`config` 어디에도 특정 제공자
   식별자(`koreaexim` 등)를 적지 마세요 — 회귀 테스트가 0건을 단언합니다.
   접합면은 셋입니다. ⑴ 설정 화면 응답 최상위의 `available_rate_providers`(등록 목록, 항상 존재)와
   `active_rate_provider`(선택값 해석 결과, 없으면 `null`) — `abilities` 가 아니라 카탈로그입니다.
   ⑵ 환율을 실제로 쓰는 단 하나의 경로 `EcommerceSettingsService::applyExchangeRates($ratesByCode,
   $source)` — `$source` 가 선택된 제공자가 아니면 거부하므로 제공자가 둘 켜져 있어도 선택되지 않은
   쪽은 값을 덮어쓰지 못합니다. 통화 전량과 `default_currency` 를 함께 저장해야 tombstone 이
   오작동하지 않습니다. ⑶ 적용 직후 발행하는 **새 액션** `sirsoft-ecommerce.currency.after_apply_rates`
   — `settings.after_save` 를 서비스에서 발행하면 컨트롤러 전용 계약이 깨지고 그 훅을 구독하는
   제공자 플러그인과 무한 재귀합니다.
2. **금액은 계산기 하나만 지난다.** `OrderCalculationService` 의 9단계 계산이 상품 상세·
   장바구니·체크아웃·주문 생성·결제 완료 검증·부분 취소 **여섯 지점 전부**의 단일 출처입니다.
   화면마다 금액을 다시 계산하면 같은 장바구니가 화면마다 다른 값을 보이게 되고, 그 어긋남은
   결제 금액 검증에서야 예외로 드러납니다.
3. **통화는 설정이 정하고, 거래 시점에 동결된다.** 기본 통화(저장 기준)·표시 통화(구매자
   선택)·결제 통화(PG 청구)는 각각 따로 설정되며 셋이 모두 다를 수 있습니다. 주문이 생기면
   그 시점의 통화·소수 자릿수·절사 규칙·환산 분모가 `currency_snapshot` 에 박제되고, 이후
   운영자가 통화 설정을 바꿔도 과거 주문의 표기는 변하지 않습니다.
4. **마일리지는 원장이 SSoT 다.** `ecommerce_mileage_transactions` 가 원장이고
   `ecommerce_mileage_balances` 는 단방향 파생 캐시입니다. 차감·검증 같은 금전 판정은 캐시가
   아니라 원장 `FOR UPDATE` 재검증으로 하고, 캐시는 같은 트랜잭션 마지막 단계에서 재계산합니다.
   회원이 탈퇴해도 원장 행은 지우지 않고 남은 마일리지를 「탈퇴로 소멸」(`expired`, `metadata.reason=withdrawal`)
   거래로 0 처리하며, 회원 행을 하드 삭제하면 거래 행의 `user_id` 만 NULL 로 끊습니다. 탈퇴 뒤 도착한 적립·반환도
   기록한 직후 같은 방식으로 소멸합니다.

**의도적으로 하지 않는 것**: PG 통신 코드·실시간 브로드캐스트(채널 0개)·방문자 쇼핑 화면
레이아웃, 그리고 상품 문의 게시판의 **콘텐츠 저장소**입니다. 문의 본문은 게시판 모듈이 글로
보관하고 이 모듈은 상품↔글 피벗(`ecommerce_product_inquiries`)만 갖습니다. 그런데도 manifest
의존에는 게시판이 없습니다 — 연결이 코드 결합이 아니라 훅 구독이라 게시판이 없으면 문의 기능만
비고 나머지는 그대로 동작합니다.

**클레임(교환·반품·환불 신청서)은 1.3.0 에 들어온 다섯 번째 축입니다.** 취소가 "옵션을 주문에서
빼는 것" 하나였다면, 클레임은 **회수·재발송·추가결제·환불 실행**이 각각 시간차를 두고 일어나는
긴 라이프사이클입니다. 그래서 통합 polymorphic 테이블 대신 **유형별 신청서 테이블**
(`ecommerce_order_exchanges` · `ecommerce_order_returns` — 환불신청서는 기존
`ecommerce_order_refunds` 를 확장)을 두고, 공용 부속물(첨부 이미지 · 추가결제)만 polymorphic 으로
묶었습니다. 관리자가 보는 통합 목록은 저장소가 UNION 으로 합쳐 냅니다. 금액은 여기서도 계산기를
우회하지 않습니다 — 반품·교환의 환불 대상액은 `OrderAdjustmentService` 의 취소 계산을 그대로
쓰고(`ReturnAdjustment` / `ExchangeAdjustment` 가 `OrderAdjustment` 를 상속), 클레임 배송비만
`ClaimShippingFeeService` 가 주문 시점 배송정책 스냅샷에서 다시 산출합니다.
<!-- @intent END -->

## 2. 디렉토리 지도

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

## 3. 핵심 흐름

<!-- @intent START -->
**주문 생성 (장바구니 → 결제 완료)**: `User\CheckoutController` / `User\OrderController` →
FormRequest → `CheckoutDataService`(주문서에 필요한 배송지·쿠폰·마일리지·결제수단 조립) →
`OrderCalculationService::calculate()` 9단계로 최종 결제금액 산출 → `TempOrderService` 가
임시 주문(`ecommerce_temp_orders`)으로 그 계산 결과를 보관 → PG 결제창 왕복 →
`OrderProcessingService` 가 임시 주문을 실제 주문(`Order` + `OrderOption` + `OrderAddress` +
`OrderPayment` + `OrderShipping`)으로 변환합니다. 이때 **결제 직전 계산을 한 번 더 돌려
금액이 그대로인지 검증**하고(`OrderAmountChangedException` /
`PaymentAmountMismatchException`), 주문번호는 `SequenceService` 가 DB UNIQUE 제약으로 원자
채번합니다. 상태 흐름은 `pending_order → pending_payment → payment_complete → …`
(`OrderStatusEnum` 10 케이스)입니다.

**취소·환불**: 입구가 하나이고(`refund-requests`) 서버가 **상품 상태**로 전이 방식을 가릅니다.
결제된 주문의 취소·환불은 **언제나 환불신청서를 거칩니다** — `OrderRefundRequestService` 가
`OrderRefund` + `OrderRefundOption` 을 `requested` 로 만들고, 분기가 `auto` 면 `autoAdvance()` 가
`승인 → 완료` 를 실제로 밟습니다(단계를 건너뛰지 않아 활동 로그가 단계마다 남습니다). 종착점은
환불 수단(`RefundMethodEnum`: `pg`/`bank`/`points`)이 가릅니다 — PG·마일리지는 완료까지, 계좌는
승인에서 멈추고 운영자의 [환불하기]를 기다립니다. 「마일리지로 환불」(`points`)은 주문 때 쓴 마일리지를
먼저 반환하고 현금 환불액을 무기한 마일리지(`refund_credit`)로 지급하며, 결제행은 건드리지 않습니다 —
결제수단과 다른 수단(마일리지·계좌 송금)으로 돌려준 환불은 결제행 상태를 바꾸지 않고 취소 이력에만
수단·금액을 남깁니다. 돌려줄 현금이 남지 않은 주문(전액 마일리지)은 결제수단과 무관하게 `points` 로
기록되고, 계좌를 묻지 않으며, 결제수단 자동승인 게이트를 거치지 않습니다(`hasCashToReturn`).
입금 전 주문의 취소는 받은 돈이 없으므로 재계산 결과를 `total_paid_amount` 가 아니라 `total_due_amount`(받을 돈)로
보내고 결제행은 입금 대기(`ready`)로 둡니다 — 남은 상품의 금액으로 [입금확인] 을 할 수 있어야 하기 때문입니다.
단 [입금확인] 「기록만」으로 결제행에 입금이 기록된 결제 전 주문(`RefundExecutionService::hasRecordedDepositBeforePayment`)은
받은 돈이 있는 주문입니다 — 취소 접수가 헤더에 받은 돈을 먼저 올리고(`syncPaidHeaderFromSettledPayment`, 미리보기는 같은 값의 사본)
환불신청서를 만듭니다(S3-D7). 그 신청서가 열려 있는 동안 입금기한 만료 자동취소는 그 주문을 다시 집지 않습니다.

**취소신청서(`OrderCancel` + `OrderCancelOption`)는 환불이 집행되는 순간 생깁니다.** 만드는
지점은 `OrderCancellationService::recordCancellation()` **하나**이며, 그 메서드가 주문 장부
확정(합계 재계산 · 누적 컬럼 · 상품 상태 전이 · 결제행 정리)까지 함께 맡습니다. 취소 단위는
주문이 아니라 **옵션**입니다. 환불할 돈이 없는 주문(미결제·0원)만 환불신청서 없이 취소신청서가
즉시 생깁니다(입금이 기록된 결제 전 주문은 미결제가 아닙니다 — 위 S3-D7).

금액 계산은 `OrderAdjustmentService` 가 이미 적용된 쿠폰·마일리지의 안분을 되돌립니다
(`adjustment.filter_restore_promotions` 필터로 그 안분 규칙을 확장할 수 있습니다). 쿠폰 복원·
마일리지 복원 훅은 **호출자 트랜잭션과 함께 되돌아가야 하므로 `sync => true` 로 구독**합니다
(기본값인 큐 래핑은 커밋 뒤에 실행되어 예외를 던져도 롤백되지 않습니다).

환불 실행(`OrderRefundRequestService::executeRefund`)의 순서는 「되돌릴 수 있는 것은 모두 앞에, PG 는
맨 뒤에」입니다 — 마일리지 반환·지급 → 재고·쿠폰 복원 → 장부(`RefundExecutionService::prepareLedger`)
→ 신청서·상품 행·취소신청서·결제행 → PG. 마일리지·쿠폰·재고 복원 실패는 삼키지 않고 환불 전체를
롤백합니다(PG 는 부르지 않음). PG 뒤 코어 쓰기는 신청서 행 UPDATE 1회뿐이고, PG 승인 직후 커밋이
실패하면 critical 로그와 신청서 표식(`pg_transaction_id` + `LEDGER_COMMIT_FAILED`)을 남겨 다음
[환불하기]가 PG 를 다시 부르지 않게 합니다. 이 트랜잭션은 attempts 1 로만 엽니다.

**수동 결제 처리·재활성**: 관리자가 결제 전 주문을 결제완료 이후로 직접 올리거나(P) 취소 주문·상품을
되살릴 때(R) PG 승인·입금확인을 거치지 않으므로, 확인 창이 「재고 차감 · 사용 마일리지 차감 · 결제금액
기록」을 묻습니다(요청 키 `apply_stock_deduction`·`apply_mileage_deduction`·`record_paid_amount`, 생략하면 켬).
판정은 `OrderProcessingService::planManualPaidTransitionEffects()` 하나이고 미리보기 API(`…/manual-paid-preview`)와
실행(`applyManualPaidTransitionEffects()` — 단건·목록 일괄·`bulkUpdateStatus`·상품 일괄)이 같은 메서드를 씁니다.
재활성 마일리지는 `is_mileage_deducted` 가 아니라 원장 순계산(취소로 돌려준 합 − `metadata.reason=reactivation`
으로 다시 뺀 합)으로 회수하고, 돌려준 현금은 환불 누적에서 되돌려 `order_meta.reactivation_reclaim` 에 남깁니다
— 결제행은 PG 사실대로 두므로 그 몫의 환불 한도는 `CancellableAmount::METHOD_REACTIVATED_CASH` 로 따로 셉니다.
재활성은 `OrderReactivationService` 가 상품 행을 되살리고(취소 수량 카운터 반환, 재고 플래그가 다른 형제는
병합 안 함) 주문 합계를 취소 엔진과 같은 입력(최초 취소 직전 `order_meta.pre_cancel_snapshot`)으로 다시
계산합니다. 다른 주문이 이미 쓴 쿠폰이 있으면 막습니다(`ReactivationCouponConflictException`). 잔액·재고 부족도
422 로 막고 일괄은 해당 주문번호를 모두 알립니다(all-or-nothing). 행이 되살아나는 순간(병합 전)
`CancelReactivationRecorder` 가 그 수량을 되돌린 취소신청서에 귀속해 `order_cancels.reactivated_at` 을 씁니다 —
클레임 목록·취소 상세의 「재활성됨」 배지는 이 값만 읽습니다.

**재계산 결과의 행 배분**: 계산 엔진은 결과를 `product_option_id` 키로 합칩니다. 부분취소·재활성 재계산은
`OrderAdjustmentService::allocateRecalcToRows()` 가 재계산 입력의 `line_order_option_ids` 로 키 합계를 주문 행에
나눠 씁니다(같은 상품옵션의 활성 행이 둘이면 둘 다 합계를 받던 결함). 배송 행 금액은 「그 주문 행이 지금 부담하는
몫」이고 `OrderShippingAmountWriter` 가 분할이 끝난 뒤 배송 행을 다시 읽어 씁니다(취소된 행은 0). 매출 집계는
`quantity` 만 더합니다 — `cancelled_quantity` 는 이력 카운터라 빼면 부분취소 수량이 두 번 빠집니다. 헤더 합계
(`buildOrderUpdates`)는 행과 같은 재계산 결과로 쓰며, 적립 예정액(`total_earned_points_amount`)도 여기에 포함됩니다
— 빠지면 헤더만 취소 전 적립액을 들고 남습니다.

잔여 환불 한도는 `total_paid_amount − total_refunded_amount` 입니다. 환불이 집행된 경로는
환불 누적이 그 축을 이미 깎으므로 `recordCancellation()` 이 `total_paid_amount` 를 재계산값으로
덮어쓰지 않습니다 — 둘 다 쓰면 같은 금액이 두 번 빠져 **2차 부분환불이 0원**이 됩니다.

**상품 저장 → 색인·SEO**: `Admin\ProductController` → `StoreProductRequest`
(`product.create_validation_rules` 필터로 확장 지점 제공) → `ProductService`
(`before_create` → `filter_create_data` → `after_create`) → `ProductRepository`. 이후는 훅
리스너 레인입니다 — `SearchProductsListener`(검색 색인) · `SeoProductCacheListener`(봇 화면
캐시 무효화) · `ProductActivityLogListener`(활동 로그) · `SyncOptionGroupsListener` /
`SyncProductFromOptionListener`(옵션 ↔ 상품 대표값 동기화)가 각자 받아 처리하고, Service 는
이 부가효과를 알지 못합니다.

**클레임 (교환·반품·환불 신청서)**: `User\ClaimController` / `Guest\ClaimController`(비회원은
주문 조회 토큰) → FormRequest(`ExchangeRedeliveryCountryUnchanged` 등 유형별 규칙) →
`OrderExchangeService` / `OrderReturnService` / `OrderRefundRequestService` 가 신청서 헤더 + 상품
행 + 첨부를 **한 트랜잭션**에 만들고, 그 안에서 `ClaimQuantityGuard` 가 주문 행을 `findForUpdate`
로 잠근 채 "활성 클레임 수량 ≤ 잔여 수량" 을 검증합니다. 신청 접수 시점에 원 주문옵션이 코스
상태(`EXCHANGE_IN_PROGRESS` / `RETURN_IN_PROGRESS`)로 분할·전이되어 같은 수량이 취소나 다른
클레임으로 빠져나가지 못합니다. 청구액이 있으면 `ClaimPaymentService` 가 `CP` 채번의
`ClaimPayment` 를 만들고 결제 플러그인의 `claim_payment.approve` 필터로 승인받으며, 승인·전이·
완료는 `AdminClaimService` 가 `ExchangeStatusEnum` / `ReturnStatusEnum` 의 `canTransitionTo()`
게이트를 통과할 때만 수행합니다. 완료 처리(`completeReturn` / `completeExchange`)의 옵션(재고
복구 대상 · 환불신청서 접수 여부)은 **호출자가 명시 전달**하고, 환불 실행은
`CancellableAmountCalculator` 가 저장 시점과 실행 직전 두 번 같은 식으로 상한을 재계산한 뒤
마일리지 반환·지급(PG 앞) → 장부 누적 → PG 환불 순서로 실행하고, 현금영수증은 커밋 뒤에 맞춥니다
(취소 경로와 공용인 `RefundExecutionService`).

**주문 생성 검증의 국내/해외 갈래**: `Public\CreateOrderRequest` 는 배송지를 국내 폼(`zipcode` ·
`address` · `address_detail`)과 해외 폼(`address_line_1` · `intl_*`) 중 하나로 받습니다. 상세주소
(`shipping.address_detail`)는 국내 폼에만 있으므로 기본 주소와 같은 짝인
`required_without:shipping.address_line_1` 로 판정합니다 — 해외 폼은 주소 1·2행으로 대신하고 배송국가를
바꾸면 이 값이 비워지는데, 무조건 `required` 였을 때는 해외 배송지 주문이 「상세 주소를 입력해주세요.」로
결제에서 막혔습니다. 국내 필드를 새로 더할 때도 같은 짝을 따릅니다.

**비회원 주문의 리뷰 플래그**: 리뷰는 회원만 씁니다(리뷰 저장 API 는 로그인 필요, 비회원 주문 상세에는
리뷰 작성 창이 없음). 그래서 `OrderOptionResource::calculateCanWriteReview()` 는 비회원 주문의 품목이면
guest 역할의 권한과 무관하게 `can_write_review=false` 를 냅니다 — 비회원 주문 응답
(`GuestOrderResource`)이 품목 리소스에 `forGuestOrder()` 를 걸고, 주문 관계가 로드돼 있으면
`order.user_id === null` 로도 판정합니다. 이 판정이 빠지면 비회원 주문 상세에 눌러도 반응 없는
「리뷰 작성」 버튼이 보입니다.

**상품 옵션 판매가 일괄 변경**: `BulkUpdateProductsRequest` 의
`option_bulk_changes.price_adjustment.method` 는 `set` · `add` · `subtract` · `percent` 네 가지입니다
(`subtract` 는 판매가 일괄 변경 창의 「인하 + 금액」 — 허용값에 없어 422 로 저장되지 않던 것을 더했습니다).
옵션 판매가는 「상품 판매가 + 조정액(`price_adjustment`)」이므로 `ProductOptionService::applyPriceAdjustment()`
는 옵션을 상품과 함께 읽고(`findByIdWithProduct`) 조정액 하한을 `-상품 판매가` 로 둡니다 — 인하·음수 가산·
비율 변경 결과 옵션 판매가가 0 아래로 내려가지 않습니다.

**마일리지 유효기간 일괄 연장**: `POST …/admin/mileage-transactions/extend-expiry` 의 `user_id` 는
선택입니다. 생략하면 `UserMileageService::extendLotExpiry(null, …)` 가 고른 적립건이 여러 회원에 걸쳐
있어도 전부 연장하고, 잔액 캐시 재계산과 활동 로그(`mileage.extend_expiry`)는 **회원별로** 남깁니다.
지정하면 그 회원의 적립건만 연장합니다(`MileageTransactionRepositoryInterface::getRemainingLotsByIds(?int $userId, …)`).
관리자 마일리지 내역 화면은 적립건만 보내고 회원을 지정하지 않습니다 — 고른 것 중 첫 건의 회원을 뽑아
보내던 때는 나머지 회원의 적립건이 조용히 빠졌습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 593개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 221개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 46개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 17개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 3개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 12개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 55개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 508종은 도메인별로 같은 모양을 반복합니다 — `before_{동작}` (action) →
`filter_{동작}_data` (filter) → 실행 → `after_{동작}` (action), 그리고 FormRequest 쪽의
`{동작}_validation_rules` (filter). `brand` 19종을 한 번 읽으면 `product` 44 · `order` 35 ·
`coupon` 32 · `category` 28 · `cart` 25 · `shipping_policy` 24 에 그대로 적용됩니다. 도메인
CRUD 를 바꾸고 싶으면 이 4종 중 하나를 잡으면 되고, 이 모듈의 소스를 고칠 일은 없습니다.

그 규칙에서 벗어나는 것이 실제로 중요한 확장점입니다:

| 목적 | 잡을 훅 |
|---|---|
| 금액 계산 단계에 개입 | `calculation.after_item_subtotals` · `calculation.after_final_result` 등 `calculation.*` 18종 (9단계 사이사이) |
| 취소 시 쿠폰·마일리지 안분 되돌리기 규칙 변경 | `adjustment.filter_restore_promotions` |
| 새 PG·결제수단 추가 | 결제수단 카탈로그에 능력 선언(`needs_pg`·`pg_locked`·`refund_method`·`auto_approve_refund` 등) + `payment.*` 6종 (이 모듈 수정 불필요) |
| 그 수단의 환불을 운영자 승인 없이 자동 완료 | 카탈로그 `auto_approve_refund: true` 선언 — 운영자가 클레임 정책 탭에서 최종 결정 (`modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` "결제수단 능력 선언") |
| 결제 직전/취소/입금확인에 본인인증 강제 | `getIdentityPolicies()` 가 선언한 4개 정책의 target 훅 (`checkout.before_payment` · `payment.before_cancel` · `payment.before_approve` · `payment.before_confirm_deposit`) — 기본 `enabled: false` |
| 상품 문의를 다른 저장소로 | `inquiry.store_validation_rules` · `inquiry.update_validation_rules` + 게시판 측 `sirsoft-board.post.after_*` (`ProductInquiryBoardListener` 가 선례) |
| 재고 차감/복원 시점 개입 | `stock.*` 4종 |
| 클레임 신청서의 접수·전이·완료·철회·금액 수정에 개입 | `claim.exchange.*` · `claim.return.*` · `claim.refund.*` · `claim.cancel.*` (전이는 `status_changed` 한 축 + 승인·반려·철회·완료의 구체 훅이 함께 발화. `claim.refund.status_changed` 는 4번째 인수로 컨텍스트를 받아 자동 전이의 중간 알림만 억제한다) |
| 신청서의 상품 단위 전이에 개입 | `claim.{exchange,return,refund,cancel}.option_status_changed` — 헤더가 따라 움직이지 않는 조합이 있어 층이 다른 사실로 따로 낸다 |
| 주문 상태 동기화가 끌고 간 상품 전이·운송장 정정에 개입 | `order_option.after_sync_status` · `order_shipping.tracking_updated` — 둘 다 전이 훅을 지나지 않는 경로다 |
| 결제수단의 부분취소 지원 여부 선언 | `payment.supports_partial_cancel` 필터 — 제약을 아는 자리는 PG 플러그인이다 |
| 새 PG 로 클레임 추가결제(`CP` 채번) 승인·환급 | `claim_payment.approve` · `claim_payment.refund` 필터 — 결제 플러그인이 구현하며 계약의 단일 출처는 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` |
| 클레임 첨부 파일 검사·후처리 | `claim-image.before_upload`(저장 전 거절) · `claim-image.filter_upload_file` · `claim-image.after_upload` · `claim-image.after_delete` |

**금전이 움직이는 훅을 구독할 때는 `'sync' => true` 를 붙입니다.** 쿠폰 차감·복원, 마일리지
차감·복원이 여기 해당합니다 — 기본값(큐 래핑 + `afterCommit`)으로 두면 커밋 뒤에 실행되어
리스너가 예외를 던져도 주문은 이미 확정된 뒤입니다.

브로드캐스트 채널은 0개입니다. 실시간 반영이 필요한 화면은 이 모듈이 아니라 소비하는
템플릿·모듈 쪽에서 폴링·재조회로 해결합니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] 새 `.g7.html` 을 만들었다면 저작 트랙 종료 게이트(`LayoutMarkup` PHPUnit 스위트)의 코퍼스 목록에 등재하고, 파일 헤더·데이터소스·모달·액션 블록 주석(S21, `docs/frontend/layout-markup.md` §10.5)을 채운다 — 굽기·왕복이 초록이어도 이 스위트만 미등재·미주석을 잡는다(2026-09-17 develop-7.1.0 2차 병합 전수 보존 검사에서 이 확장의 미등재 레이아웃이 red; 재발 — 2026-09-26 1차 병합 판독에서 개인정보 조치 창·보유기간 탭이 다시 미주석으로 들어왔다).
- [ ] `_bundled` 에서만 수정하고 `php artisan module:update sirsoft-ecommerce --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 업그레이드 스텝·데이터 마이그레이션이 설정 파일을 새로 쓰면(`File::put`) 바로 뒤에 `FilePermissionHelper::inheritOwnershipFromParent($path)` — `sudo` 코어 업데이트 안에서 root 로 실행되어 그 파일이 root 소유로 남으면 이후 웹 프로세스의 설정 저장이 영구 실패한다. 번들 확장 `upgrades/**` 전수를 파일시스템에서 파생해 검사하는 패리티 테스트가 누락을 잡는다
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=module:sirsoft-ecommerce` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 금액 계산 경로를 건드렸다면 여섯 소비 지점(상품 상세·장바구니·체크아웃·주문 생성·결제 검증·부분 취소)에 같은 결과가 도달하는지 확인
- [ ] 통화가 관여하는 표시·기록을 추가할 때 다국어 문구는 `:amount` 로 중립, 과거 거래 표기는 `currency_snapshot` 경유
- [ ] 금전이 움직이는 훅(쿠폰·마일리지 차감/복원)을 구독·발행할 때 `'sync' => true` 확인
- [ ] 마일리지 원장에 기록하는 경로를 추가하면 같은 트랜잭션 마지막에 잔액 캐시 재계산 동반
- [ ] 목록 응답에 하위 컬렉션(옵션·이미지)을 실을 때 화면이 실제로 그리는 것만 — Repository 의 `relations:` 와 Resource 의 `whenLoaded` 를 함께 본다
- [ ] 결제수단·PG 관련 선언을 바꾸면 기설치본 `order_settings.json` 을 정정하는 업그레이드 스텝 동반 (자기 접두사만, 멱등)
- [ ] 새 관리자 화면을 추가하면 그 화면의 권한(`getPermissions()`)·메뉴(`getAdminMenus()`)·라우트 이름이 서로 가리키는 대상이 일치하는지 확인
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan module:update sirsoft-ecommerce --force`
- [ ] 개인정보 보유기간 핸들러(`src/Privacy/`)를 고칠 때는 건수(`preview()`)·대상 명단(`targets()`)·실행(`process()`)이 **같은 조건식**을 쓰는지, 명단 권한(`targetPermissions()` = 설정 읽기 + 주문 읽기)을 지키는지, 명단 행을 코어 값 객체 `App\Privacy\DTO\PrivacyRetentionTarget` 로 내는지 확인 — 계약 전체는 [코어 개인정보 보유기간 처리 가이드](../../../docs/backend/privacy-retention.md)

- [ ] 클레임 상태 Enum(`ExchangeStatusEnum` · `ReturnStatusEnum` · `RefundStatusEnum`)의 전이를 바꾸면 `canTransitionTo()` · 알림 정의(상태별 항목) · `ClaimActivityLogListener` · 화면의 `adminAssignableValues()` 를 함께 본다 — 한 곳만 고치면 전이는 되는데 알림·로그만 조용히 빠진다
- [ ] 클레임 추가결제 계약(`claim_payment.approve` / `.refund` 필터의 인자·반환 키)을 바꾸면 결제 플러그인 4종의 리스너와 그 플러그인들의 `dependencies.modules.sirsoft-ecommerce` 하한을 함께 올린다
- [ ] 클레임 금액 표시를 추가할 때 보조 통화 줄(`mc_*`)은 바로 위 기준 통화 줄과 같은 필드·같은 게이트로 — 서버가 만든 맵을 나열만 하고 화면에서 더하거나 곱하지 않는다
- [ ] 수정 라우트와 등록 라우트가 한 레이아웃을 공유하는 폼(`admin_ecommerce_product_form`·`admin_ecommerce_shipping_policy_form`)은 생성 조건 `init_actions` 에서 `form: null` 로 직전 레코드를 비운 뒤 기본값을 쓴다 — 엔진은 `layout_name` 이 바뀔 때만 `_local` 을 비운다. 생성 모드에서도 폼을 채우는 `initLocal` 데이터소스(복사 `copy_source` 등)는 문자열형이 아니라 `{"_merge":"replace","form":"{{data}}"}` 맵으로 둔다 — 문자열형은 엔진이 기존 form 과 깊은 병합해 잔존을 페이로드에 섞는다(트러블슈팅 state-global 사례 7)
## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 화면·서비스마다 금액을 다시 계산 (`합계 = 단가 × 수량` 재구현) | `OrderCalculationService::calculate()` 결과만 사용 | 계산이 여섯 지점에 흩어지면 화면 금액과 결제 금액이 갈라지고, 그 어긋남은 결제 완료 검증에서 `PaymentAmountMismatchException` 으로 뒤늦게 나타난다 |
| 마일리지 잔액을 `ecommerce_mileage_balances` 에서 읽어 차감 가능 여부 판정 | 원장(`ecommerce_mileage_transactions`) `FOR UPDATE` 재검증 | 캐시는 단방향 파생물이라 동시 요청에서 뒤처질 수 있다 — 캐시를 근거로 차감하면 잔액이 음수가 된다 |
| 마일리지 잔액 캐시를 서비스에서 직접 증감 | 원장에 기록한 뒤 같은 트랜잭션 마지막에 캐시 재계산 | 두 곳을 각각 갱신하면 원장 합계와 캐시가 어긋나고, 정합 교정 스케줄(`reconcile-mileage-balance`)이 매번 되돌린다 |
| 과거 주문 표시에 현재 통화 설정(`getDecimalPlaces($code)`)을 조회 | 주문의 `currency_snapshot` 을 함께 넘긴다 | 운영자가 그 통화를 삭제하면 폴백(2자리)이 적용되어 `¥14,835` 가 `¥14,835.00` 이 된다. 금액 계산은 스냅샷을 쓰는데 표기만 현재 설정을 따르면 같은 화면 안에서 근거가 갈린다 |
| 다국어 문구에 `:amount원` / `:amount円` 처럼 통화 기호를 박기 | 문구는 `:amount` 로 중립, 호출부가 `ecommerce_format_price($amount, $currency)` 로 포맷 | UI 언어가 통화를 결정하게 되어 기본 통화가 다른 상점에서 단위만 틀린 금액이 나간다 |
| 쿠폰·마일리지 복원 훅을 기본 설정(큐)으로 구독 | `'sync' => true` | 커밋 뒤 실행이라 예외를 던져도 롤백되지 않는다 — 오류 응답만 나가고 차감된 쿠폰은 그대로 남는다 |
| 특정 PG 이름을 이 모듈 코드에 분기로 넣기 (`if ($pg === 'tosspayments')`) | 결제수단 카탈로그 선언(`needs_pg`/`pg_locked`/`pg_provider`/`auto_approve_refund`)과 `payment.*` 훅 | PG 가 늘 때마다 이 모듈이 커지고, 플러그인만 설치하면 되는 구조가 깨진다 |
| 주문번호·상품코드를 `max(id)+1` 이나 타임스탬프 조합으로 직접 생성 | `SequenceService::generateCode()` | 채번은 DB UNIQUE 제약과 함께 원자적으로 수행된다 — 직접 생성은 동시 주문에서 중복을 만든다 |
| 취소·환불을 주문 단위로 처리 | 옵션 단위(`OrderCancelOption`/`OrderRefundOption`) | 부분 취소가 이 도메인의 기본이며, 주문 단위로 처리하면 남은 옵션의 안분 금액이 계산되지 않는다 |
| 요청의 `temp_key` · `image_temp_key` 를 형식 검증 없이 `products/temp/{키}` 경로에 넣거나, 임시 이미지가 없는데도 그 디렉토리를 `deleteDirectory` | `ValidatesTempKey`(UUID 만) + 조회된 임시 이미지가 있을 때만 이동·정리 | 순회 키가 정리 경로를 모듈 저장소 루트로 끌어올린다 (게시판 KVE-2026-2349 와 같은 패턴) |
| 임시 이미지를 본경로로 옮긴 뒤 임시 디렉토리 정리(`deleteDirectory('images', 'products/temp/{키}')`)의 예외를 그대로 던지게 두기 | `ProductImageService::linkTempImages()` 처럼 disk 별 정리 전체를 `try/catch` 로 감싸 경고 로그(`상품 임시 이미지 디렉토리 정리 실패`)를 남기고 연결 수는 그대로 반환 | 정리는 부수 작업이다. 막 옮긴 대용량 파일을 운영체제가 잠시 붙잡고 있으면 디렉토리 순회가 lstat 오류를 던져 상품 저장 전체가 500 으로 되돌아가고 파일·DB 경로가 어긋난다(#703 게시판 실측과 같은 구조). 정리에 실패해 남는 것은 빈 임시 디렉토리뿐이다 |
| 관리자 화면에서 코어 회원 검색(`GET /api/admin/users/search`)을 검색어 조건 없이, 또는 길이(`length > 0`)만 보고 부르기 | `if="{{String($args[0] ?? '').trim() !== ''}}"`(`onSearch` 인자) 또는 `if="{{String($event.target.value ?? '').trim() !== ''}}"`(입력 이벤트)로 비거나 공백뿐이면 부르지 않는다. 입력칸 아래에 결과 목록을 직접 그리는 창(마일리지 수동 지급/차감·쿠폰 직접 발급)은 목록 표시·비우기도 같은 `trim()` 조건을 쓴다 | 회원 검색은 빈 검색어를 422 로 거절한다(공백은 서버 요청 정리에서 지워져 빈 값이 된다). 조건이 없던 알림 템플릿 「특정 회원」 수신자·주문 목록 주문자 필터·쿠폰 목록 만든 사람 필터는 검색칸을 지울 때마다, 길이만 보던 마일리지 수동 지급/차감 창·쿠폰 직접 발급 창은 공백만 적으면 거절되어 결과가 비거나 오류 안내가 떴다(#703) |
| 레이아웃·TSX 에서 Lucide·Heroicons 식 아이콘 이름(`x-circle`·`refresh-cw`·`more-horizontal`·`alert-circle`·`chevrons-left`·`arrow-down-tray` 등) | Font Awesome 6.4.0 이름(`circle-xmark`·`arrows-rotate`·`ellipsis`·`circle-exclamation`·`angles-left`·`download`) | 관리자 템플릿 `Icon` 은 이름을 `fa-{이름}` 로만 바꾼다 — FA 에 없는 이름은 오류 없이 **빈칸**으로 그려진다. 가드: `tests/Unit/Extension/FontIconNameTest.php` |
| `OrderCancel` 을 `recordCancellation()` 밖에서 만들기 | 그 메서드 한 지점 | 만드는 지점이 둘이면 합계 재계산·누적 컬럼 갱신이 경로마다 갈리고, 같은 취소가 경로에 따라 다른 장부를 남긴다 |
| 최종 상태를 바로 INSERT 해 전이 단계를 건너뛰기 | `transition()` 으로 실제로 거치기 | 활동 로그가 한 줄만 남아 운영자가 승인 경위를 되짚을 수 없다. 한 트랜잭션 안이어도 단계를 밟는다 |
| PG 를 부르지 않은 취소에 `COMPLETED` + `refunded_at` 찍기 | `APPROVED`(수동 송금 대기) | 고객 카드는 그대로인데 장부만 환불완료가 된다. 완료는 [환불하기]가 찍는다 |
| 클레임 신청·완료 처리에서 주문 행 잠금 없이 잔여 수량을 읽어 검증 | `ClaimQuantityGuard` + `OrderRepository::findForUpdate()` 를 한 트랜잭션 안에서 | 두 요청이 같은 잔여를 보고 각각 통과해 신청 합계가 잔여 수량을 넘는다 |
| 취소 가능 금액을 화면이나 서비스에서 따로 계산 | `CancellableAmountCalculator::calculate()` 하나를 표시와 실행 가드가 함께 쓴다 | 화면이 예고한 금액과 실제 실행 상한이 갈리고, 어긋남은 환불이 나간 뒤에야 드러난다 |
| 완료 처리 옵션(재고 복구 · 환불신청서 접수)의 기본값을 서비스가 설정에서 읽어 채움 | 호출자가 `ClaimCompletionOptions` 를 명시 전달 (`claim.complete_defaults.*` 는 화면 초기 선택값 전용) | "요청에 없으면 설정대로" 가 되면 운영자가 고르지 않은 재고 복구·환불 접수가 조용히 실행된다 |
| 결제 플러그인 콜백에서 `CP` 결제번호를 주문번호로 조회 | `ClaimPayment::isClaimPaymentNumber()` 로 먼저 분기해 `ClaimPaymentCallback` 경로로 | 주문 경로를 타면 `order_not_found` 로 끝나 추가결제가 승인되지 않고, 실패 원인이 화면에 드러나지 않는다 |
| 환불이 동기화할 주문 헤더 컬럼 목록을 서비스마다 따로 들기 | `OrderRefundRequestService::TAX_SYNC_COLUMNS` 하나가 정본 — `RefundExecutionService` 는 받은 것을 그대로 쓴다 | 두 곳이 각자 목록을 들면 한쪽만 늘렸을 때 조용히 잘린다(S3-D17 실측: 실행 쪽 목록이 3개뿐이라 `total_cash_equivalent_amount` 가 빠져 전액 환불 주문의 현금영수증 발급 가능액이 62,000 으로 남았다) |
| 환불 경로에서 `total_paid_amount`·`total_amount` 를 함께 갱신 | 세금 분류 + 현금성 금액만 동기화한다 | 클레임 환불은 `total_refunded_amount` 로 누적한다 — 결제액까지 깎으면 잔여 한도(`paid - refunded`)가 같은 금액을 두 번 뺀다 |
| 마일리지 반환·지급, 쿠폰·재고 복원 실패를 `try/catch` 로 삼키기 | 예외(`OrderCancellationException`)로 환불·취소 전체 롤백 | 삼키면 카드는 취소되고 마일리지·쿠폰·재고는 돌아오지 않은 채 완료로 닫힌다(refund 274). 현금영수증만 커밋 뒤라 삼킨다 |
| PG 호출 뒤에 같은 트랜잭션에서 장부를 쓰기 | 되돌릴 수 있는 쓰기를 모두 끝낸 뒤 PG 를 마지막에 부르고, 완료는 그 행 UPDATE 1회 | PG 성공 뒤 쓰기가 실패하면 장부만 롤백되고 돈은 나간다 — NICE·KG·KCP 는 멱등키가 없어 재실행이 이중 환불이 된다 |
| `DB::transaction(..., attempts>1)` 로 환불 실행 감싸기 | attempts 1 | 교착 재시도가 클로저를 다시 돌려 PG 를 두 번 부른다 |
<!-- @intent END -->

### 환불 후 주문 헤더 동기화 계약 (S3-D17)

`CashReceiptService::calculateIssuableAmount()` 는 주문 헤더의 세금·현금성 컬럼을 **그대로**
읽고, 그 주석이 「**지금 이 주문이 얼마짜리인가**만 읽으면 된다」를 계약으로 명시한다.
따라서 **환불이 끝나면 헤더가 현재 상태를 반영해야 한다.**

전액취소 경로는 이미 0 으로 내린다(S1-D22). **반품·교환 발 환불도 같아야 한다** —
`OrderRefundRequestService` 가 `previewRefund()` 재계산에서 `TAX_SYNC_COLUMNS` 를 뽑아
`AdjustmentResult::orderUpdates` 에 실으면, `RefundExecutionService::accumulateRefundTotals()`
가 환불 누적과 **한 트랜잭션에서 함께** 저장한다.

**직접 환불신청만 `previewRefund()` 를 부른다**(`ownsOptionQuantities`) — 반품·교환 발
환불은 부르지 않으므로 `taxColumnUpdatesFor()` 가 그 경로에서 재계산을 다시 구한다.
이 분기를 없애면 반품·교환 축에 결함이 되돌아온다.

**실측(2026-09-16)**: 수정 전 주문 964 전액 환불 후 `tax 62,000 · issuable 62,000`,
수정 후 주문 973 전액 환불 후 `tax 0 · vat 0 · cash_eq 0 · issuable 0` ·
`total_paid_amount 62,000` 보존(이중 차감 없음).

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 684개 | `modules/_bundled/sirsoft-ecommerce/tests` |
| Vitest | 267개 | `vitest.config.ts` |
| Playwright | 72개 | `tests/Playwright` |
| 시나리오 매니페스트 | 359개 | `tests/scenarios` |

기저 TestCase: `tests/ModuleTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd modules/_bundled/sirsoft-ecommerce && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd modules/_bundled/sirsoft-ecommerce && npm run test:e2e -- specs/<대상>.spec.ts

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
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->

## 확장 조각의 CSS 클래스 규약

resources/extensions/ 아래 의 조각은 **다른 패키지의 화면 안에서** 그려진다. 그래서 여기 쓰는
클래스는 그 화면을 소유한 **템플릿의 CSS 에 실재해야** 한다.

두 가지를 지킨다.

1. **템플릿의 의미 클래스를 쓰지 않는다.** `flex-start` · `text-heading` · `section-heading-md`
   같은 이름의 규칙은 `templates/_bundled/sirsoft-admin_basic/src/styles/main.css` 의 `@layer components` 에만
   있다. 사용자 템플릿(`sirsoft-basic`)에는 그 규칙이 없어 `display:flex` 같은 기본 배치까지
   통째로 사라진다. 평범한 Tailwind 유틸로 쓴다(`flex items-start`).
   슬롯 확장(`extension-point=`)은 한 조각이 관리자·사용자 양쪽에 들어가므로, 어느 한쪽의
   어휘를 쓰면 **반드시** 다른 쪽이 깨진다.

2. **새 유틸은 safelist 에 실린다.** 두 템플릿 모두 Tailwind 가 레이아웃을 스캔하지 않는다 —
   `sirsoft-admin_basic` 은 `templates/_bundled/sirsoft-admin_basic/scripts/extract-safelist.cjs` 가(확장 조각 포함) 자동 생성하고,
   `sirsoft-basic` 은 `templates/_bundled/sirsoft-basic/src/styles/safelist.txt` 를 손으로 단다. 새 클래스를 쓴 뒤에는
   `php artisan template:build <템플릿>` 로 CSS 를 다시 만든다.

이 규약을 어겨도 **빌드는 성공하고 경고도 없으며 테스트도 전부 초록이다** — 사람이 브라우저를
열어야만 드러난다(2026-09-21, 마이페이지 「내 정보 수정」의 확장 카드에서 체크박스가 설명 위로
떨어져 있었다). 그래서 확장 조각이 쓰는 클래스가 대상 템플릿의 CSS 에 실제로 만들어졌는지를
정적 검사가 대조한다.
