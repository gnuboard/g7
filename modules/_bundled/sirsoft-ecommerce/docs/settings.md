# 이커머스 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

기본값 파일: `config/settings/defaults.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`getSettingsSchema()` 선언이 없는 것은 누락이 아닙니다. 이 모듈의 설정은 코드가 아니라
`config/settings/defaults.json` 이 SSoT 이며, 그 파일 하나가 세 가지를 함께 담습니다:

| 키 | 역할 |
|---|---|
| `_meta.categories` | 설정 그룹 9개의 목록과 순서 (`basic_info` · `language_currency` · `order_settings` · `shipping` · `seo` · `review_settings` · `inquiry` · `notifications` · `mileage`) |
| `defaults` | 그룹별 기본값. 설치 시 `storage/app/settings/` 로 동기화되어 `module_setting()` 이 읽는 값이 됩니다 |
| `frontend_schema` | 관리자 화면이 자동으로 그리는 입력 폼 정의 |

**`frontend_schema` 는 8개 그룹뿐이고 `mileage` 가 없습니다.** 자동 생성 폼으로는 표현할 수 없는
입력(통화별 적립 규칙 표 등)이 있어서 마일리지 탭만 레이아웃 JSON 으로 직접 그리기 때문입니다
(`resources/layouts/admin/partials/admin_ecommerce_settings/_tab_mileage*.json` 6개). 새 그룹을
추가할 때는 이 셋 중 어디까지 손댈지를 먼저 정합니다 — `_meta.categories` 에만 넣고 `defaults`
를 빠뜨리면 그 그룹은 화면에 뜨지만 저장할 값이 없습니다.

설정 값을 코드에서 읽을 때는 `EcommerceSettingsService` 를 거칩니다. 통화 설정처럼 요청마다
여러 번 읽히는 값은 `CurrencySettingsCache` 가 따로 캐시하며, 설정 저장 후 캐시를 비우는 것은
`core.module_settings.after_save` 훅을 받는 리스너들입니다 — 설정을 직접 파일에서 읽으면 그
무효화 경로를 타지 않아 화면과 서버가 서로 다른 값을 봅니다.

결제수단 목록(`order_settings.payment_methods`)만은 성격이 다릅니다. **저장값과 플러그인이
등록한 카탈로그의 병합**이라, 플러그인을 삭제·비활성화하면 저장값은 남아 있는데 카탈로그에서
사라지는 고아 항목이 생깁니다. 공개 응답은 고아 항목을 걸러 내보내고 관리자 응답은 그대로
노출하는 것이 규칙입니다 — 운영자는 그것을 보고 지워야 하기 때문입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `products` | 상품 관리 | `read`, `create`, `update`, `delete` | `product` |
| `orders` | 주문 관리 | `read`, `update` | `order` |
| `categories` | 카테고리 관리 | `read`, `create`, `update`, `delete` | - |
| `brands` | 브랜드 관리 | `read`, `create`, `update`, `delete` | `brand` |
| `product-notice-templates` | 상품정보제공고시 관리 | `read`, `create`, `update`, `delete` | - |
| `product-common-infos` | 공통정보 관리 | `read`, `create`, `update`, `delete` | - |
| `settings` | 환경설정 | `read`, `update` | - |
| `promotion-coupon` | 쿠폰 관리 | `read`, `create`, `update`, `delete` | `coupon` |
| `shipping-policies` | 배송정책 관리 | `read`, `create`, `update`, `delete` | `shippingPolicy` |
| `product-labels` | 상품 라벨 관리 | `read`, `create`, `update`, `delete` | - |
| `identity.policies` | 이커머스 본인인증 정책 | `read`, `update` | - |
| `reviews` | 리뷰 관리 | `read`, `update`, `delete` | `review` |
| `inquiries` | 문의 관리 | `update`, `delete` | - |
| `dashboard` | 대시보드 | `view` | - |
| `user-products` | 사용자 상품 | `read` | - |
| `user-orders` | 사용자 주문 | `create`, `cancel`, `confirm` | - |
| `user-reviews` | 사용자 리뷰 | `write` | - |
| `mileage` | 마일리지 관리 | `read`, `manage` | `mileage-transaction` |
| `user-currency` | 회원 결제 통화 관리 | `manage` | - |
| `user-shipping-country` | 회원 배송국가 관리 | `manage` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한 20종은 세 무리로 갈립니다.

- **관리자 CRUD 12종** (`products` · `orders` · `categories` · `brands` ·
  `product-notice-templates` · `product-common-infos` · `promotion-coupon` ·
  `shipping-policies` · `product-labels` · `reviews` · `inquiries` · `mileage`): 관리자 화면과
  1:1 대응하며, 라우트 키가 있는 것은 그 라우트에 스코프 미들웨어가 걸립니다.
- **사용자 측 5종** (`user-products` · `user-orders` · `user-reviews` · `user-currency` ·
  `user-shipping-country`): 구매자가 자기 자원에 대해 갖는 권한입니다. 관리자 권한과 이름이
  겹치지 않도록 `user-` 접두사를 씁니다.
- **횡단 3종** (`settings` · `dashboard` · `identity.policies`): 화면 하나에 대응합니다.

`orders` 에 `create`/`delete` 가 없는 것은 의도입니다 — 주문은 구매자 결제로 생기고
(`user-orders.create`), 삭제 대신 취소·환불로 처리합니다. `inquiries` 에 `read` 가 없는 것도
같은 성격입니다: 문의 **본문은 게시판 모듈이 소유**하므로 읽기 권한은 그 게시판의 권한이
정하고, 이 모듈은 답변·삭제만 관장합니다.

새 관리자 화면을 추가하면 권한·메뉴·라우트 이름 셋이 서로를 정확히 가리키는지 확인합니다.
권한만 추가하고 메뉴를 빠뜨리면 화면에 도달할 길이 없고, 반대면 눌러도 403 입니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-ecommerce` | 이커머스 | - | 11개 |
<!-- @generated:menus END -->

<!-- @intent START -->
최상위 `sirsoft-ecommerce` 아래 11개 하위 메뉴가 붙습니다 — 환경설정 · 상품 · 카테고리 ·
브랜드 · 상품정보제공고시 · 공통정보 · 주문 · 쿠폰 · 배송정책 · 리뷰 · 마일리지 내역.

메뉴는 **권한과 짝을 이룰 때만 보입니다.** 운영자에게 역할이 부여되어도 그 역할에 해당 권한이
없으면 메뉴가 렌더되지 않으므로, 새 화면을 추가할 때는 `getPermissions()` 와 `getAdminMenus()`
를 함께 바꿉니다.

권한 표에는 있는데 메뉴가 없는 것들(`dashboard` · `identity.policies` · `product-labels` 등)은
독립 메뉴가 아니라 다른 화면 안에 들어 있기 때문입니다 — 대시보드는 코어 관리자 첫 화면에
레이아웃 조각으로 주입되고, 본인인증 정책은 코어 IDV 설정 화면에서 함께 다뤄지며, 상품 라벨은
상품 관리 화면 안에 있습니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/modules/sirsoft-ecommerce/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
라우트 239개가 파일 하나(`src/routes/api.php`)에 모여 있고 전부 `/api/modules/sirsoft-ecommerce/`
아래로 나갑니다. 화면용 라우트는 없습니다 — 관리자 화면은 레이아웃 JSON 이 이 API 를 호출해
그리고, 방문자 화면은 템플릿이 같은 API 를 씁니다.

대상별로 셋으로 갈립니다:

| 무리 | 인증 | 비고 |
|---|---|---|
| `admin.*` | 관리자 인증 + 권한 스코프 | 라우트 키가 선언된 권한이 여기에 걸립니다 |
| `user.*` · 공개 조회 | Sanctum(일부는 `optional.sanctum`) | 비로그인도 상품·카테고리는 봅니다 |
| `guest.orders.*` | `VerifyGuestOrderToken` | 비회원 주문 조회·취소. 토큰이 곧 신원이므로 **새 라우트를 추가하면 미들웨어 선언(`getMiddleware()`)에도 그 이름을 반드시 추가**합니다 |

라우트를 추가·변경한 뒤에는 라우트 캐시를 다시 구워야 합니다. 확장 라우트는 **활성 상태인
확장의 것만** 등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 그대로 404 가 됩니다.

모든 라우트에 `name()` 이 필요합니다 — 이름이 없으면 미들웨어 self-gate 의 `targets` 패턴과
IDV 정책의 라우트명 인덱스가 그 라우트를 찾지 못해, 보호가 걸린 것처럼 보이지만 실제로는
통과합니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `sirsoft-pay_kginicis` | 플러그인 | `>=1.1.0` |
| `sirsoft-pay_nhnkcp` | 플러그인 | `>=1.1.0` |
| `sirsoft-pay_nicepayments` | 플러그인 | `>=1.1.0` |
| `sirsoft-tosspayments` | 플러그인 | `>=1.1.0` |
| `sirsoft-basic` | 템플릿 | `>=1.1.0` |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈은 **아무 확장에도 의존하지 않습니다.** 코어만 있으면 동작하며, 관계는 전부 한 방향으로
들어옵니다 — 결제 플러그인 4종과 템플릿 `sirsoft-basic` 이 이 모듈을 요구합니다.

그 방향이 뒤집히지 않게 유지하는 것이 이 모듈 설계의 핵심입니다. PG 이름을 이 모듈 코드에 넣는
순간 의존이 양방향이 되고, 새 PG 를 붙일 때마다 이 모듈을 고쳐야 합니다.

manifest 에는 없지만 **실제로 맞물리는 확장이 둘 더** 있습니다:

| 확장 | 무엇으로 연결되는가 | 없으면 |
|---|---|---|
| `sirsoft-board` | 훅 3종 구독 (문의 글 삭제·복원·일괄삭제 시 피벗 정리) + 설정 `inquiry.board_slug` | 상품 문의 기능만 비고 나머지는 정상 |
| `sirsoft-ckeditor5` | 훅 1종 (편집기가 참조할 이커머스 리소스 목록 제공) | 편집기에서 상품 링크를 고를 수 없을 뿐 |

훅 구독은 상대가 없으면 발화하지 않으므로 이 둘을 manifest 의존으로 올리지 않는 것이 맞습니다.
다만 그 대가로 **상대 확장이 훅 이름을 바꾸면 예외 없이 조용히 연동이 끊깁니다** — 상대의
`docs/extension-points.md` 를 함께 확인해야 하는 이유입니다.

이 모듈의 공개 표면(Service·Repository·Contracts·라우트·발행 훅)을 바꿀 때는 위 4+1 개 확장의
`dependencies` 최소 버전 상향이 필요한지 검토합니다.
<!-- @intent END -->
