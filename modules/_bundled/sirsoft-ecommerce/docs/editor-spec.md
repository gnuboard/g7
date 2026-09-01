# 이커머스 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `modules/_bundled/sirsoft-ecommerce/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 레이아웃 편집기 스펙 — 이커머스 모듈 도메인 sampleData/sampleGlobal/states. admin 레이아웃 data_source ID 전수 스캔 기반 도메인 ID 28종(상품·주문·브랜드·쿠폰·배송정책·정산·설정 등) byDataSourceId + 사용자 페이지(템플릿 렌더) byEndpointPattern. 공용 인프라(roles/availableChannels/identityProviders/ecommerceIdentity*/ecommerceNotificationDefinitions)는 admin 템플릿 스펙·코어 프리셋 폴백이 커버.
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
이커머스는 저장소에서 가장 큰 모듈이지만 편집기 스펙은 여전히 단일 파일입니다. 스펙
분량을 키우는 것은 팔레트·컨트롤·컴포넌트 역량인데 그 셋은 템플릿이 소유하기 때문입니다.
모듈 쪽에 남는 것은 도메인 데이터라 라우트 239개·레이아웃 206개 규모에도 한 파일에
들어갑니다.

이 사실이 곧 설계 원칙입니다 — 확장이 커진다고 편집기 스펙이 따라 커지지 않습니다.
커진다면 그 확장이 템플릿의 일을 하고 있다는 신호입니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 51 | `editor-spec.json (인라인)` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 7 | `editor-spec.json (인라인)` |
| `sampleGlobal` | `_global.*` 프리뷰 baseline 시드 | 12 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 17 | `editor-spec.json (인라인)` |
| `actionRecipes` | 친화 명칭 → 액션 JSON 레시피 | 1 | `editor-spec.json (인라인)` |
| `actionChipCandidates` | 동작 데이터 칩 컨텍스트 후보 | 1 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
`actionRecipes` 와 `actionChipCandidates` 를 각 1건씩 둔 것이 다른 모듈과 다른
지점입니다. 이커머스에는 운영자가 편집기에서 직접 조립하기 어려운 동작(장바구니·주문
흐름에 얽힌 것)이 있어, 친화 명칭으로 미리 만들어 둔 레시피가 필요합니다.

나머지 네 블록은 게시판과 같은 원리입니다 — admin 레이아웃 `data_source` ID 51종을
전수로 덮고, 사용자 페이지 7종은 호출 주소로 덮습니다. `sampleGlobal` 12종은 통화·로케일
같은 값이 `_global` 에 없으면 상품 카드가 통째로 깨지기 때문에 baseline 으로 박아
둔 것입니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
컴포넌트를 만드는 것은 템플릿의 일입니다. 모듈·플러그인은 레이아웃 JSON 에서 템플릿이
제공하는 컴포넌트를 **쓰기만** 하므로, 편집기 팔레트에 새로 얹을 것이 없습니다. 그래서 이
확장의 스펙은 `componentPalette`·`controls`·`componentCapabilities`·`nesting` 을 비우고
**도메인 데이터**(`sampleData`·`states`)만 담습니다.

팔레트에 무언가를 추가하고 싶다면 그것은 이 확장이 아니라 활성 템플릿
(`sirsoft-admin_basic` / `sirsoft-basic`)의 스펙에 가야 합니다. 여기에 팔레트를 선언하면
템플릿 선언과 같은 자리를 두고 다투게 되고, 어느 쪽이 이기는지가 합본 순서에 좌우됩니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 51 | `transactions` · `products` · `product` · `categories` · `orders` · `order` · `reviews` · `brands` · `carriers` · `active_carriers` · `coupons` · `coupon` … 외 39개 |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 7 | `/api/modules/sirsoft-ecommerce/products*` · `/api/modules/sirsoft-ecommerce/cart*` · `/api/modules/sirsoft-ecommerce/checkout*` · `/api/modules/sirsoft-ecommerce/wishlist*` · `/api/modules/sirsoft-ecommerce/user/orders*` · `/api/modules/sirsoft-ecommerce/user/addresses*` · `/api/modules/sirsoft-ecommerce/user/inquiries*` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 17 | `/*?/products/:product_code` · `/*?/products` · `/*?/cart` · `/*?/checkout` · `/*?/orders/:id/complete` · `/*?/reorder/:id` · `*/admin/ecommerce/products/:itemCode/edit` · `*/admin/ecommerce/promotion-coupons/:id/edit` · `*/admin/ecommerce/shipping-policies/:id/edit` · `*/admin/ecommerce/settings` · `*/admin/ecommerce/mileage-deposit-settings` · `/*?/guest/orders` … 외 5개 |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`states.groups` 17종은 이커머스에서 상태가 실제로 화면을 가르는 자리입니다 — 품절
상품, 빈 장바구니, 비회원 주문 조회, 재주문 등입니다. 상태를 늘리는 기준은 "그 상태에서
운영자가 화면을 따로 손봐야 하는가" 입니다. 값만 다르고 구조가 같은 경우는 변종을
만들지 않습니다.

`sampleGlobal` 에 통화 관련 값을 둘 때는 특정 통화를 정답으로 박지 않도록 주의합니다.
기본 통화는 설정이 정하므로, 샘플이 특정 통화를 전제하면 편집기 프리뷰만 그 통화로
고정되어 다른 통화 상점의 운영자에게 잘못된 화면을 보여 줍니다.
<!-- @intent END -->

## 수정 시 동반 의무

<!-- @generated:editor-spec-obligations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 이런 변경을 했다면 | 편집기 스펙에서 함께 할 일 |
|---|---|
| 컴포넌트를 새로 만들었다 | `componentPalette` 에 항목 추가 · `componentCapabilities` 에 편집 역량 선언 · `nesting` 에 담길 자리 규정 |
| 레이아웃에 `data_sources` 를 추가했다 | `sampleData` 에 같은 ID 로 프리뷰 응답 추가 (없으면 편집기 캔버스만 빈 화면) |
| `_global.*` 을 새로 읽는다 | `sampleGlobal` 에 baseline 값 추가 |
| 빈 목록·오류 같은 화면 변종을 추가했다 | `states` 에 변종 추가 · `stateLabels` 에 친화 명칭 |
| 새 액션·조건 패턴을 도입했다 | `actionRecipes` / `conditionRecipes` 에 친화 명칭 등록 |

편집기 스펙은 JSON 이므로 빌드가 필요 없습니다. 다만 편집기 서빙은 **활성 디렉토리만** 읽으므로(`_bundled` 폴백 없음) 편집 후 반드시 반영합니다:

```bash
php artisan module:update sirsoft-ecommerce --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
위 표는 "무엇을 함께 고치는가" 만 말합니다. 실제로 놓치는 자리는 **반영 절차**입니다 —
편집기가 읽는 것은 활성 디렉토리이고 `_bundled` 폴백이 없으므로, `_bundled` 에서 스펙을
고치고 update 커맨드를 돌리지 않으면 편집기에는 **직전 내용이 그대로 보입니다.** 파일은
고쳤는데 화면이 안 바뀌었다면 거의 이 경우입니다.

또 하나는 검증 시점입니다. 편집기 스펙은 스키마 검증을 통과해도 "레이아웃이 실제로 쓰는
ID 와 맞는가" 는 확인해 주지 않습니다. 그 어긋남은 편집기 캔버스에서만 빈 화면으로
나타나고 실제 화면은 정상이므로, 위 "샘플 데이터와 페이지 상태" 절의 미커버 목록이 유일한
통로입니다.

이커머스는 `sampleGlobal` 이 12종으로 가장 많습니다. 레이아웃이 `_global.*` 을 새로
읽기 시작했는데 baseline 을 안 넣으면 그 값이 `undefined` 가 되어, 표현식이 통째로
falsy 로 떨어지며 **영역 전체가 사라집니다.** 값 하나가 비는 것보다 알아채기 어렵습니다.
<!-- @intent END -->
