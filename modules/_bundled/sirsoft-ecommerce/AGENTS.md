# 이커머스 — 에이전트 가이드

> 이 문서는 이 모듈을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 모듈 (sirsoft-ecommerce) — 상품·주문·결제·배송·취소/환불·쿠폰·마일리지·리뷰·문의 도메인. 관리자 CRUD + 공개 API 만 소유하고, 방문자 쇼핑 화면은 템플릿이 그린다
2. 확장 방식: 발행 훅 508개(도메인마다 before_*→filter_*_data→after_*→*_validation_rules 4종 반복). PG 는 플러그인이 카탈로그 능력 선언으로 붙고, 금액 개입은 `calculation.*` 18종
3. 건드리면 안 되는 것: `OrderCalculationService` 를 우회한 금액 재계산, 마일리지 잔액 캐시 기반 차감 판정, 과거 주문 표기에 현재 통화 설정 조회(`currency_snapshot` 이 SSoT), 금전 복원 훅의 `sync` 누락
4. 작업 위치: `modules/_bundled/sirsoft-ecommerce` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan module:update sirsoft-ecommerce --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
상품·주문·결제·배송·취소/환불·쿠폰·마일리지·리뷰·문의를 소유하는 커머스 도메인 모듈입니다.
모델 47종·소유 테이블 51개·발행 훅 508종으로 번들 확장 중 가장 큰 표면을 갖습니다.

**소유 범위는 관리자 CRUD + 공개 API 까지입니다.** 레이아웃 206개가 전부 `admin` 그룹인 것이
그 증거입니다 — 방문자가 실제로 보는 상품 목록·상세·장바구니·주문서 화면은 이 모듈이 그리지
않고, 템플릿(`sirsoft-basic`)이 이 모듈의 공개 API 를 소비해 그립니다. 그래서 쇼핑 화면의
디자인 변경은 이 모듈이 아니라 템플릿 쪽 작업입니다.

**설계 원칙 넷**:

1. **PG 는 이 모듈이 알지 않는다.** 결제 연동 코드는 전부 플러그인(`pay_kginicis` ·
   `pay_nhnkcp` · `pay_nicepayments` · `tosspayments`)에 있고, 그 플러그인들이 이 모듈에
   의존합니다(역방향 아님). 새 PG 는 이 모듈을 고치지 않고 플러그인 추가만으로 붙습니다 —
   결제수단 카탈로그에 자기 능력(`needs_pg` / `pg_locked` / `pg_provider`)을 선언하는 것이
   그 접합면입니다.
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

**의도적으로 하지 않는 것**: PG 통신 코드·실시간 브로드캐스트(채널 0개)·방문자 쇼핑 화면
레이아웃, 그리고 상품 문의 게시판의 **콘텐츠 저장소**입니다. 문의 본문은 게시판 모듈이 글로
보관하고 이 모듈은 상품↔글 피벗(`ecommerce_product_inquiries`)만 갖습니다. 그런데도 manifest
의존에는 게시판이 없습니다 — 연결이 코드 결합이 아니라 훅 구독이라 게시판이 없으면 문의 기능만
비고 나머지는 그대로 동작합니다.
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
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update sirsoft-ecommerce --force` (빌드 불필요) |
| `resources/routes/` | 라우트 → 레이아웃 매핑 (분할) | `php artisan module:update sirsoft-ecommerce --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-ecommerce --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan module:update sirsoft-ecommerce --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-ecommerce --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약) | `php artisan module:update sirsoft-ecommerce --force` |
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

**취소·환불**: `Admin\OrderCancelController` / `User\OrderController` → FormRequest →
`OrderCancellationService`(취소 단위는 주문이 아니라 **옵션**입니다 — `OrderCancel` +
`OrderCancelOption`) → `OrderAdjustmentService` 가 이미 적용된 쿠폰·마일리지의 안분을 되돌리고
(`adjustment.filter_restore_promotions` 필터로 그 안분 규칙을 확장할 수 있습니다) →
`OrderRefund` + `OrderRefundOption` 생성 → 환불 수단(`RefundMethodEnum`: `pg`/`bank`/`points`)
에 따라 PG 플러그인 또는 마일리지 원장으로 실제 반환이 나갑니다. 쿠폰 복원·마일리지 복원 훅은
**호출자 트랜잭션과 함께 되돌아가야 하므로 `sync => true` 로 구독**합니다(기본값인 큐 래핑은
커밋 뒤에 실행되어 예외를 던져도 롤백되지 않습니다).

**상품 저장 → 색인·SEO**: `Admin\ProductController` → `StoreProductRequest`
(`product.create_validation_rules` 필터로 확장 지점 제공) → `ProductService`
(`before_create` → `filter_create_data` → `after_create`) → `ProductRepository`. 이후는 훅
리스너 레인입니다 — `SearchProductsListener`(검색 색인) · `SeoProductCacheListener`(봇 화면
캐시 무효화) · `ProductActivityLogListener`(활동 로그) · `SyncOptionGroupsListener` /
`SyncProductFromOptionListener`(옵션 ↔ 상품 대표값 동기화)가 각자 받아 처리하고, Service 는
이 부가효과를 알지 못합니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 508개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 142개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 33개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 12개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 3개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 9개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 10개 | [알림 정의](docs/extension-points.md#알림-정의) |
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
| 새 PG·결제수단 추가 | 결제수단 카탈로그에 능력 선언 + `payment.*` 6종 (이 모듈 수정 불필요) |
| 결제 직전/취소/입금확인에 본인인증 강제 | `getIdentityPolicies()` 가 선언한 4개 정책의 target 훅 (`checkout.before_payment` · `payment.before_cancel` · `payment.before_approve` · `payment.before_confirm_deposit`) — 기본 `enabled: false` |
| 상품 문의를 다른 저장소로 | `inquiry.store_validation_rules` · `inquiry.update_validation_rules` + 게시판 측 `sirsoft-board.post.after_*` (`ProductInquiryBoardListener` 가 선례) |
| 재고 차감/복원 시점 개입 | `stock.*` 4종 |

**금전이 움직이는 훅을 구독할 때는 `'sync' => true` 를 붙입니다.** 쿠폰 차감·복원, 마일리지
차감·복원이 여기 해당합니다 — 기본값(큐 래핑 + `afterCommit`)으로 두면 커밋 뒤에 실행되어
리스너가 예외를 던져도 주문은 이미 확정된 뒤입니다.

브로드캐스트 채널은 0개입니다. 실시간 반영이 필요한 화면은 이 모듈이 아니라 소비하는
템플릿·모듈 쪽에서 폴링·재조회로 해결합니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan module:update sirsoft-ecommerce --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=module:sirsoft-ecommerce` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 금액 계산 경로를 건드렸다면 여섯 소비 지점(상품 상세·장바구니·체크아웃·주문 생성·결제 검증·부분 취소)에 같은 결과가 도달하는지 확인
- [ ] 통화가 관여하는 표시·기록을 추가할 때 다국어 문구는 `:amount` 로 중립, 과거 거래 표기는 `currency_snapshot` 경유
- [ ] 금전이 움직이는 훅(쿠폰·마일리지 차감/복원)을 구독·발행할 때 `'sync' => true` 확인
- [ ] 마일리지 원장에 기록하는 경로를 추가하면 같은 트랜잭션 마지막에 잔액 캐시 재계산 동반
- [ ] 목록 응답에 하위 컬렉션(옵션·이미지)을 실을 때 화면이 실제로 그리는 것만 — Repository 의 `relations:` 와 Resource 의 `whenLoaded` 를 함께 본다
- [ ] 결제수단·PG 관련 선언을 바꾸면 기설치본 `order_settings.json` 을 정정하는 업그레이드 스텝 동반 (자기 접두사만, 멱등)
- [ ] 새 관리자 화면을 추가하면 그 화면의 권한(`getPermissions()`)·메뉴(`getAdminMenus()`)·라우트 이름이 서로 가리키는 대상이 일치하는지 확인

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
| 특정 PG 이름을 이 모듈 코드에 분기로 넣기 (`if ($pg === 'tosspayments')`) | 결제수단 카탈로그 선언(`needs_pg`/`pg_locked`/`pg_provider`)과 `payment.*` 훅 | PG 가 늘 때마다 이 모듈이 커지고, 플러그인만 설치하면 되는 구조가 깨진다 |
| 주문번호·상품코드를 `max(id)+1` 이나 타임스탬프 조합으로 직접 생성 | `SequenceService::generateCode()` | 채번은 DB UNIQUE 제약과 함께 원자적으로 수행된다 — 직접 생성은 동시 주문에서 중복을 만든다 |
| 취소·환불을 주문 단위로 처리 | 옵션 단위(`OrderCancelOption`/`OrderRefundOption`) | 부분 취소가 이 도메인의 기본이며, 주문 단위로 처리하면 남은 옵션의 안분 금액이 계산되지 않는다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 400개 | `modules/_bundled/sirsoft-ecommerce/tests` |
| Vitest | 140개 | `vitest.config.ts` |
| Playwright | 42개 | `tests/Playwright` |
| 시나리오 매니페스트 | 91개 | `tests/scenarios` |

기저 TestCase: `tests/ModuleTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd modules/_bundled/sirsoft-ecommerce && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (Bash)
npx playwright test modules/_bundled/sirsoft-ecommerce/tests/Playwright/specs/<대상>.spec.ts

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
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
