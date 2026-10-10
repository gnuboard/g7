# API 레퍼런스 문서 목차

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.
>
> **응답 문구**: `message` 의 언어와 개수에 따른 단수·복수(영어에서 1이면 단수, 0·2 이상이면 복수) 규칙은 코어와 같습니다 — [코어 API 안내 「로케일」](../../../../../docs/backend/api/README.md#로케일). 검증 오류(`errors`)·예외 문구 중 상한이 설정값인 것(첨부·이미지 개수 상한 등)도 그 값으로 고릅니다.

> **이슈 #524 주의**: `carts.md`·`user-addresses.md`·`wishlists.md` 행과 총계의 증분(+3문서 / +9엔드포인트)은
> **손으로 넣었습니다**. `api:docgen` 기본 경로가 파괴적 재생성 가드에 막혀(수기 서술 소실 방지) 목차를
> 다시 굽지 못하기 때문입니다. 세 문서는 각각 관리자 목록·단건 삭제·일괄 삭제 3엔드포인트이며, 다음에
> 생성기가 이 블록을 정상적으로 다시 구우면 같은 산식으로 덮여도 무방합니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 36 · **엔드포인트 수**: 248

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [addresses.md](addresses.md) | `addresses` | 6 |
| [admin-claims.md](admin-claims.md) | `admin-claims` | 17 |
| [brands.md](brands.md) | `brands` | 6 |
| [cart.md](cart.md) | `cart` | 11 |
| [carts.md](carts.md) | `carts` | 3 |
| [categories.md](categories.md) | `categories` | 14 |
| [category-image.md](category-image.md) | `category-image` | 1 |
| [claim-image.md](claim-image.md) | `claim-image` | 1 |
| [claim-payments.md](claim-payments.md) | `claim-payments` | 2 |
| [checkout.md](checkout.md) | `checkout` | 5 |
| [claim-reasons.md](claim-reasons.md) | `claim-reasons` | 8 |
| [claims.md](claims.md) | `claims` | 5 |
| [coupons.md](coupons.md) | `coupons` | 4 |
| [currency.md](currency.md) | `currency` | 2 |
| [dashboard.md](dashboard.md) | `dashboard` | 4 |
| [extra-fee-templates.md](extra-fee-templates.md) | `extra-fee-templates` | 10 |
| [guest.md](guest.md) | `guest` | 20 |
| [identity-policies.md](identity-policies.md) | `identity-policies` | 4 |
| [inquiries.md](inquiries.md) | `inquiries` | 10 |
| [mileage.md](mileage.md) | `mileage` | 3 |
| [mileage-transactions.md](mileage-transactions.md) | `mileage-transactions` | 5 |
| [options.md](options.md) | `options` | 3 |
| [orders.md](orders.md) | `orders` | 34 |
| [payments.md](payments.md) | `payments` | 1 |
| [presets.md](presets.md) | `presets` | 4 |
| [product-common-infos.md](product-common-infos.md) | `product-common-infos` | 6 |
| [product-image.md](product-image.md) | `product-image` | 1 |
| [product-labels.md](product-labels.md) | `product-labels` | 6 |
| [product-notice-templates.md](product-notice-templates.md) | `product-notice-templates` | 7 |
| [products.md](products.md) | `products` | 31 |
| [promotion-coupons.md](promotion-coupons.md) | `promotion-coupons` | 9 |
| [review-image.md](review-image.md) | `review-image` | 1 |
| [reviews.md](reviews.md) | `reviews` | 12 |
| [settings.md](settings.md) | `settings` | 10 |
| [shipping-carriers.md](shipping-carriers.md) | `shipping-carriers` | 7 |
| [shipping-country.md](shipping-country.md) | `shipping-country` | 2 |
| [shipping-policies.md](shipping-policies.md) | `shipping-policies` | 11 |
| [user-addresses.md](user-addresses.md) | `user-addresses` | 3 |
| [users.md](users.md) | `users` | 2 |
| [wishlist.md](wishlist.md) | `wishlist` | 3 |
| [wishlists.md](wishlists.md) | `wishlists` | 3 |

<!-- @generated:end -->

## 회원 이메일 노출 범위

응답에 실리는 회원 이메일(`author.email`, `user.email`, `reporter.email` 등)은 **관리자
엔드포인트 응답**과 **본인이 자기 정보를 조회할 때**만 값이 채워집니다. 그 밖의 경로에서는
필드가 그대로 있고 값만 `null` 이므로 응답 구조는 변하지 않습니다.

인증 없이 열람되는 목록·상세 응답에 회원 이메일을 실으면, 화면이 그 값을 그리지 않더라도
응답 본문에서 회원 주소록을 수집할 수 있기 때문입니다. 이메일이 필요한 화면은 관리자
엔드포인트를 사용하세요.

공통 규약: [코어 API 레퍼런스 진입점](../../../../../docs/backend/api/README.md#회원-이메일-노출-범위)


