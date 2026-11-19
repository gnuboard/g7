# API 레퍼런스 문서 목차

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 아래 표는 자동 생성됩니다. 각 문서를 열면 엔드포인트별 파라미터·응답·예시를 볼 수 있습니다.
>
> **응답 문구**: `message` 의 언어와 개수에 따른 단수·복수(영어에서 1이면 단수, 0·2 이상이면 복수) 규칙은 코어와 같습니다 — [코어 API 안내 「로케일」](../../../../../docs/backend/api/README.md#로케일). 검증 오류(`errors`)·예외 문구 중 상한이 설정값인 것(첨부·이미지 개수 상한 등)도 그 값으로 고릅니다.

> **이슈 #524 주의**: `carts.md`·`user-addresses.md`·`wishlists.md` 행과 총계의 증분(+3문서 / +9엔드포인트)은
> **손으로 넣었습니다**. `api:docgen` 기본 경로가 파괴적 재생성 가드에 막혀(수기 서술 소실 방지) 목차를
> 다시 굽지 못하기 때문입니다. 세 문서는 각각 관리자 목록·단건 삭제·일괄 삭제 3엔드포인트이며, 다음에
> 생성기가 이 블록을 정상적으로 다시 구우면 같은 산식으로 덮여도 무방합니다.
> 내보내기(`exports.md`, 12엔드포인트 — 일회용 파일 주소 포함) 행과 그 증분도 같은 이유로 손으로 넣었습니다.

<!-- @generated:start:api-readme-index -->
- **문서 수**: 41 · **엔드포인트 수**: 326

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [addresses.md](addresses.md) | `addresses` | 6 |
| [brands.md](brands.md) | `brands` | 6 |
| [cart.md](cart.md) | `cart` | 11 |
| [carts.md](carts.md) | `carts` | 3 |
| [categories.md](categories.md) | `categories` | 14 |
| [category-image.md](category-image.md) | `category-image` | 1 |
| [checkout.md](checkout.md) | `checkout` | 5 |
| [content-media.md](content-media.md) | `content-media` | 3 |
| [claim-image.md](claim-image.md) | `claim-image` | 1 |
| [claim-payments.md](claim-payments.md) | `claim-payments` | 2 |
| [claim-reasons.md](claim-reasons.md) | `claim-reasons` | 8 |
| [claims.md](claims.md) | `claims` | 27 |
| [coupons.md](coupons.md) | `coupons` | 4 |
| [currency.md](currency.md) | `currency` | 2 |
| [dashboard.md](dashboard.md) | `dashboard` | 4 |
| [exports.md](exports.md) | `exports` | 12 |
| [extra-fee-templates.md](extra-fee-templates.md) | `extra-fee-templates` | 10 |
| [guest.md](guest.md) | `guest` | 20 |
| [identity-policies.md](identity-policies.md) | `identity-policies` | 4 |
| [inquiries.md](inquiries.md) | `inquiries` | 12 |
| [mileage.md](mileage.md) | `mileage` | 3 |
| [mileage-transactions.md](mileage-transactions.md) | `mileage-transactions` | 5 |
| [options.md](options.md) | `options` | 3 |
| [orders.md](orders.md) | `orders` | 41 |
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
| [settings.md](settings.md) | `settings` | 11 |
| [shipping-carriers.md](shipping-carriers.md) | `shipping-carriers` | 7 |
| [shipping-country.md](shipping-country.md) | `shipping-country` | 2 |
| [shipping-policies.md](shipping-policies.md) | `shipping-policies` | 13 |
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

## 회원 이름과 표시 이름

응답의 회원 이름 키에는 회원의 이름 원본이 아니라 그 자리의 **표시 이름**이 실립니다. 운영자가 환경설정의 회원 탭(또는 쇼핑몰 환경설정의 기능별 표시 이름)에서 정한 방식에 따라 이름, 닉네임, 가린 이름, 가린 아이디, 회원 추가 항목의 값 가운데 하나가 됩니다. 탈퇴한 회원은 방식과 무관하게 탈퇴 문구입니다.

| 영역 | 쓰는 자리 | 기본값 |
| --- | --- | --- |
| `sirsoft-ecommerce:review` | 사용자 화면의 상품 후기 작성자 | 사이트 기본을 따름 |
| `sirsoft-ecommerce:inquiry` | 사용자 화면의 상품 문의 작성자 | 가린 이름 고정 (홍길동 → 홍*동) |
| `sirsoft-ecommerce:admin` | 관리자 엔드포인트의 회원 이름, 새로 남기는 기록(활동 로그, 내보내기 요청자) | 관리자 화면을 따름 |

같은 응답 모양을 사용자 엔드포인트와 관리자 엔드포인트가 함께 쓰면 관리자 엔드포인트는 관리자 영역으로 계산합니다. 설정을 바꾸지 않은 사이트의 응답 값은 종전과 같습니다.

**실명 보조 키**: 실명은 이름 키에 싣지 않고 별도 키로만 싣습니다. 아래를 모두 만족할 때만 키가 있고, 하나라도 어긋나면 **키 자체가 없습니다**(값이 `null` 인 것이 아닙니다).

1. 관리자 엔드포인트 응답이다.
2. 요청한 관리자에게 회원 조회 권한(`core.users.read`)이 있다. 권한의 범위가 본인이나 소유 역할로 좁혀져 있으면 자기 자신의 실명만 실린다.
3. 탈퇴한 회원이 아니다.
4. 실명이 비어 있지 않고 같은 응답의 표시 이름과 다르다.

| 응답 | 이름 키 | 실명 보조 키 |
| --- | --- | --- |
| 장바구니 관리, 찜 관리, 회원 배송지 관리, 상품 문의 관리 목록 | `member.name` | `member.real_name` |
| 주문 목록, 주문 상세 | `user.name` | `user.real_name` |
| 상품 후기 (관리, 상품 상세) | `author_name`, `user.name`, `reply_admin.name` | `author_real_name`, `user.real_name`, `reply_admin.real_name` |
| 상품 문의 (상품 상세) | `author_name` | 없음 |
| 쿠폰 발급 내역 | `user_name` | `user_real_name` |
| 쿠폰 | `created_by_name`, `creator.name` | `created_by_real_name`, `creator.real_name` |
| 마일리지 내역 | `user_name`, `granted_by_name` | `user_real_name`, `granted_by_real_name` |
| 브랜드, 배송사, 클레임 사유 | `creator.name`, `updater.name` | `creator.real_name`, `updater.real_name` |
| 클레임 첨부 이미지 | `uploader_name` | `uploader_real_name` |
| 대시보드의 최근 후기, 미답변 문의 | `author_name` | 없음 |

**표시 이름을 쓰지 않는 값** (적힌 그대로이거나 실명입니다)

- 주문의 주문자 이름과 수령인(`orderer_name`, `recipient_name`, `address.*`): 주문서에 적힌 값입니다.
- 내보내기 이력의 요청자와 받은 사람(`requester.name`, 다운로드 이력의 `user.name`): 요청하거나 받은 때 남긴 이름 그대로입니다. 새 기록부터 그 시점의 관리자 영역 표시 이름을 남기고, 이미 남은 기록은 설정이나 회원 이름이 바뀌어도 그대로입니다.
- 내보낸 파일의 회원 칸(마일리지 내역의 회원과 지급자, 주문의 주문자): 실명입니다. 이름이 없는 회원은 빈 칸입니다.

**검색**: 관리자 엔드포인트의 회원 검색(`search_field=member` 등)은 이름과 닉네임을 모두 찾습니다. 종전에 이메일도 함께 찾던 목록은 이메일도 계속 찾습니다.

공통 규약: [표시 이름](../../../../../docs/extension/display-name.md)

## 숫자 번호 자리

경로의 번호 자리(`{id}`·`{brand}`·`{category}` 등)는 숫자만 받습니다. 숫자가 아닌 값은 라우트가 없는 것과 같아 **404** 입니다(7.1.6 까지는 찜 삭제·브랜드/카테고리 수정·삭제·적립금 연결 내역이 500 서버 오류였습니다).

## 다국어 값 필드

상품·카테고리·브랜드·배송방법·페이지 제목처럼 `{"ko": …, "en": …}` 원본에서 한 언어를 골라 주는 필드는 코어 규약을 따릅니다 — 요청 언어 → 사이트 기본 언어 → 영어 순으로 비어 있지 않은 값, 셋 다 없으면 저장된 첫 값(`App\Support\LocalizedValue::pick`, 7.1.1). 자세한 내용은 코어 [API 레퍼런스 공통 규약 「로케일」](../../../../../docs/backend/api/README.md#로케일)을 보세요.
