# Settings API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/settings
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| basic_info | object | `{"shop_name":"","route_path":"shop","no_route":false,"com…` | 쇼핑몰 기본 정보 (쇼핑몰명·라우트 경로·상호·사업자번호·주소·연락처·이메일 등) |
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| claim | object | `{"refund_reasons":[{"id":1,"type":"refund","code":"order_…` | 클레임 설정 (DB 관리 대상인 환불 사유 목록: 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[{"id":"kginicis","name_key":"sirsoft-pay_kginicis::provi…` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |
| available_cash_receipt_providers | array | `[]` | 설치된 플러그인이 훅으로 등록한 현금영수증 발급 제공자 목록 (id·name_key — 미등록 시 빈 배열이며 신청 폼이 노출되지 않음) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| _meta | object | `{"limits":{"auto_cancel_days_min":1,"auto_cancel_days_max…` | <!-- TODO: 설명 --> |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "basic_info": {
            "shop_name": "111",
            "route_path": "shop",
            "no_route": false,
            "company_name": null,
            "business_number": "",
            "...": "(13개 키 생략, 총 18개)"
        },
        "language_currency": {
            "default_currency": "JPY",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "USD",
                    "name": {
                        "en": "Dollar"
                    },
                    "is_default": false,
                    "decimal_places": 2,
                    "base_unit": 1,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ja": "円"
                    },
                    "is_default": true,
                    "decimal_places": 0,
                    "base_unit": 100,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                }
            ]
        },
        "order_settings": {
            "default_pg_provider": null,
            "payment_methods": [
                {
                    "id": "card",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                "... (총 25건 중 5건 표시)"
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false
                }
            ],
            "auto_cancel_expired": true,
            "...": "(5개 키 생략, 총 10개)"
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States"
                    },
                    "is_active": true
                }
            ],
            "international_shipping_enabled": true,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "...": "(8개 키 생략, 총 13개)"
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "...": "(7개 키 생략, 총 12개)"
        },
        "review_settings": {
            "write_deadline_days": 90,
            "max_images": 5,
            "max_image_size_mb": 10
        },
        "inquiry": {
            "board_slug": null
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 2
                }
            ]
        },
        "mileage": {
            "enabled": false,
            "default_earn_rate": 1,
            "earn_trigger": "confirmed",
            "earn_delay_days": "0",
            "currency_rules": [
                {
                    "currency_code": "KRW",
                    "point_value": 1,
                    "min_use_amount": 1000,
                    "use_unit": 10,
                    "max_use_type": "fixed",
                    "max_use_percent": 30,
                    "max_use_value": 50000,
                    "earn_rounding_unit": "1",
                    "earn_rounding_method": "floor"
                }
            ],
            "expiry_enabled": true,
            "expiry_days": 365,
            "expiry_notification_enabled": true,
            "expiry_notification_days_before": 7,
            "notification_channels": [
                "mail",
                "database"
            ]
        },
        "claim": {
            "refund_reasons": [
                {
                    "id": 1,
                    "type": "refund",
                    "code": "order_mistake",
                    "name": {
                        "ko": "주문 실수",
                        "en": "Order Mistake"
                    },
                    "localized_name": "주문 실수",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 8,
                    "type": "refund",
                    "code": "apidoc_sample",
                    "name": {
                        "ko": "API 문서 샘플 사유",
                        "en": "API Doc Sample Reason"
                    },
                    "localized_name": "API 문서 샘플 사유",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 2,
                    "type": "refund",
                    "code": "changed_mind",
                    "name": {
                        "ko": "단순 변심",
                        "en": "Changed Mind"
                    },
                    "localized_name": "단순 변심",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": 3,
                    "type": "refund",
                    "code": "reorder_other",
                    "name": {
                        "ko": "다른 상품으로 재주문",
                        "en": "Reorder with Different Product"
                    },
                    "localized_name": "다른 상품으로 재주문",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 2
                },
                {
                    "id": 4,
                    "type": "refund",
                    "code": "delayed_delivery",
                    "name": {
                        "ko": "배송 지연",
                        "en": "Delayed Delivery"
                    },
                    "localized_name": "배송 지연",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 3
                },
                {
                    "id": 5,
                    "type": "refund",
                    "code": "product_info_different",
                    "name": {
                        "ko": "상품 정보 상이",
                        "en": "Product Info Different"
                    },
                    "localized_name": "상품 정보 상이",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 4
                },
                {
                    "id": 6,
                    "type": "refund",
                    "code": "admin_cancel",
                    "name": {
                        "ko": "관리자 취소",
                        "en": "Admin Cancel"
                    },
                    "localized_name": "관리자 취소",
                    "fault_type": "seller",
                    "is_user_selectable": false,
                    "is_active": true,
                    "sort_order": 5
                },
                {
                    "id": 7,
                    "type": "refund",
                    "code": "etc",
                    "name": {
                        "ko": "기타",
                        "en": "Etc"
                    },
                    "localized_name": "기타",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 6
                }
            ]
        },
        "available_pg_providers": [],
        "abilities": {
            "can_update": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 이커머스 모듈의 전체 환경설정을 카테고리별로 묶어 한 번에 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `EcommerceSettingsService::getAllSettings()`로 JSON 설정을 읽은 뒤 DB 관리 대상(배송사·배송유형·클레임 사유·마일리지 알림 채널)과 등록된 PG 목록을 병합해 반환합니다. `basic_info`·`shipping`·`order_settings`·`claim`·`mileage` 등 관리자 설정 화면 전 탭의 초기 데이터를 이 한 응답으로 채웁니다. 응답의 `abilities.can_update` 로 수정 권한 보유 여부도 함께 내려갑니다.


### PUT /api/modules/sirsoft-ecommerce/admin/settings
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | `basic_info`, `language_currency`, `seo`, `order_settings`, `claim`, `shipping`, `review_settings`, `notification_definitions`, `notifications`, `inquiry`, `mileage` | 저장할 설정 탭(카테고리) 지정 (탭별 부분 저장 식별용) |
| notifications | body | array | 아니오 | — | 알림 채널 설정 배열 (채널 ID·활성 여부·정렬 순서) |
| notifications.channels | body | array | 아니오 | — | 알림 채널 목록. 항목별 `id`(문자열, max 50, 필수), `is_active`(boolean, 필수), `sort_order`(integer, min 0) |
| basic_info | body | array | 아니오 | — | 쇼핑몰 기본 정보 섹션 (쇼핑몰명·라우트 경로·상호·사업자번호·주소·연락처 등) |
| basic_info.shop_name | body | string | 아니오 | max 255 | 쇼핑몰 이름 (`basic_info` 전달 시 필수) |
| basic_info.route_path | body | string | 아니오 | max 100 | 쇼핑몰 라우트 경로 (예: `shop`). `no_route: true` 이면 검증에서 제외 |
| basic_info.no_route | body | boolean | 아니오 | — | 라우트 경로를 사용하지 않음 (쇼핑몰만 단독 운영하는 경우) |
| basic_info.company_name | body | string | 아니오 | max 255 | 상호(회사명) |
| basic_info.business_number_1 | body | string | 아니오 | max 3 | 사업자등록번호 앞 3자리 |
| basic_info.business_number_2 | body | string | 아니오 | max 2 | 사업자등록번호 가운데 2자리 |
| basic_info.business_number_3 | body | string | 아니오 | max 5 | 사업자등록번호 뒤 5자리 |
| basic_info.ceo_name | body | string | 아니오 | max 100 | 대표자 이름 |
| basic_info.business_type | body | string | 아니오 | max 100 | 업태 (예: 도매, 소매) |
| basic_info.business_category | body | string | 아니오 | max 255 | 종목 (예: 전자상거래) |
| basic_info.zipcode | body | string | 아니오 | max 10 | 사업장 우편번호 |
| basic_info.base_address | body | string | 아니오 | max 500 | 사업장 기본주소 |
| basic_info.detail_address | body | string | 아니오 | max 255 | 사업장 상세주소 |
| basic_info.phone_1 | body | string | 아니오 | max 4 | 대표 전화번호 첫 번째 마디 |
| basic_info.phone_2 | body | string | 아니오 | max 4 | 대표 전화번호 두 번째 마디 |
| basic_info.phone_3 | body | string | 아니오 | max 4 | 대표 전화번호 세 번째 마디 |
| basic_info.fax_1 | body | string | 아니오 | max 4 | 대표 팩스번호 첫 번째 마디 |
| basic_info.fax_2 | body | string | 아니오 | max 4 | 대표 팩스번호 두 번째 마디 |
| basic_info.fax_3 | body | string | 아니오 | max 4 | 대표 팩스번호 세 번째 마디 |
| basic_info.email_id | body | string | 아니오 | max 100 | 대표 E-mail 의 아이디 부분 (`@` 앞) |
| basic_info.email_domain | body | string | 아니오 | max 100 | 대표 E-mail 의 도메인 부분 (`@` 뒤) |
| basic_info.privacy_officer | body | string | 아니오 | max 100 | 개인정보 보호책임자 이름 |
| basic_info.privacy_officer_email | body | email | 아니오 | max 255 | 개인정보 보호책임자 E-mail |
| basic_info.mail_order_number | body | string | 아니오 | max 100 | 통신판매업 신고번호 |
| basic_info.telecom_number | body | string | 아니오 | max 100 | 부가통신 사업자번호 |
| language_currency | body | array | 아니오 | — | 통화 설정 섹션 (기본 통화·통화 목록: 코드·다국어명·환율·반올림 규칙·통화별 로케일) |
| language_currency.default_currency | body | string | 아니오 | max 10 | 쇼핑몰 기본(base) 통화 코드. 상품/주문이 1건이라도 생성된 뒤에는 변경 불가 |
| language_currency.currencies | body | array | 아니오 | — | 등록 통화 목록. 항목별 `code`(ISO 4217 3자리 대문자, 필수)·`name`(다국어 배열, 필수)·`symbol`·`exchange_rate`·`base_unit`·`rounding_unit`·`rounding_method`(`floor`\|`round`\|`ceil`)·`decimal_places`·`is_default`·`locales` |
| seo | body | array | 아니오 | — | SEO 메타 설정 섹션 (페이지 유형별 메타 타이틀/설명·SEO 활성 토글) |
| seo.meta_category_title | body | string | 아니오 | max 500 | 카테고리 페이지 메타 Title (`{commerce_name}`·`{category_name}` 등 변수 사용 가능) |
| seo.meta_category_description | body | string | 아니오 | max 1000 | 카테고리 페이지 메타 Description |
| seo.meta_search_title | body | string | 아니오 | max 500 | 검색 결과 페이지 메타 Title (`{keyword_name}` 변수 사용 가능) |
| seo.meta_search_description | body | string | 아니오 | max 1000 | 검색 결과 페이지 메타 Description |
| seo.meta_product_title | body | string | 아니오 | max 500 | 상품 상세 페이지 메타 Title (`{product_name}` 변수 사용 가능) |
| seo.meta_product_description | body | string | 아니오 | max 1000 | 상품 상세 페이지 메타 Description |
| seo.meta_shop_index_title | body | string | 아니오 | max 500 | 쇼핑몰 메인 페이지 메타 Title |
| seo.meta_shop_index_description | body | string | 아니오 | max 1000 | 쇼핑몰 메인 페이지 메타 Description |
| seo.seo_category | body | boolean | 아니오 | — | 카테고리 페이지에 검색엔진 친화적 페이지 제공 여부 |
| seo.seo_search_result | body | boolean | 아니오 | — | 검색결과 페이지에 검색엔진 친화적 페이지 제공 여부 |
| seo.seo_product_detail | body | boolean | 아니오 | — | 상품 상세페이지에 검색엔진 친화적 페이지 제공 여부 |
| seo.seo_shop_index | body | boolean | 아니오 | — | 쇼핑몰 메인 페이지에 검색엔진 친화적 페이지 제공 여부 |
| inquiry | body | array | 아니오 | — | 문의 연동 설정 섹션 (문의 게시판 slug) |
| inquiry.board_slug | body | string | 아니오 | max 255 | 상품 1:1 문의를 연동할 게시판 slug (게시판 모듈 활성화 시에만 선택 가능) |
| order_settings | body | array | 아니오 | — | 주문/결제 설정 섹션 (기본 PG·결제수단·은행/무통장 계좌·자동취소·장바구니 만료 등) |
| order_settings.default_pg_provider | body | string | 아니오 | max 50 | 결제 처리에 사용할 기본 PG사 ID (결제수단별 `pg_provider` 미지정 시 적용) |
| order_settings.cash_receipt_provider | body | string | 아니오 | max 50 | 현금영수증 발급 프로바이더 ID. 결제 PG 와 독립 선택하며 빈 문자열이면 미사용 |
| order_settings.cash_receipt_self_issue | body | boolean | 아니오 | — | 현금영수증 자진발급 사용 (구매자가 신청하지 않은 무통장 입금 건도 자동 발급) |
| order_settings.shipping_fee_tax_policy | body | string | 아니오 | `proportional`, `taxable`, `follow_main_item` | 현금영수증의 배송비 과세 방식 (안분 / 전액 과세 / 주된 재화 기준) |
| order_settings.payment_methods | body | array | 아니오 | — | 결제수단 목록. 항목별 `id`(필수)·`pg_provider`·`sort_order`(min 1)·`is_active`·`min_order_amount`(min 0)·`stock_deduction_timing`(`order_placed`\|`payment_complete`\|`none`)·`mileage_deduction_timing`(`order_placed`\|`payment_complete`). 최소 1개는 활성이어야 하며, PG 가 필요한 결제수단을 활성화하려면 PG사 지정 필수 |
| order_settings.banks | body | array | 아니오 | — | 무통장입금용 은행 목록. 항목별 `code`(max 10, 필수)·`name`(다국어 배열, 현재 로케일 필수) |
| order_settings.bank_accounts | body | array | 아니오 | — | 무통장 입금 계좌 목록. 항목별 `bank_code`·`account_number`·`account_holder`(모두 필수)·`is_active`·`is_default`. 계좌가 있으면 최소 1건은 사용+기본 상태여야 함 |
| order_settings.auto_cancel_expired | body | boolean | 아니오 | — | 입금대기 상태 주문의 자동취소 사용 여부 |
| order_settings.auto_cancel_days | body | integer | 아니오 | min 0, max 30 | 자동취소 기한(일). 주문일 포함 이 일수 경과 시 입금대기 주문을 자동 취소 |
| order_settings.cart_expiry_days | body | integer | 아니오 | min 1, max 365 | 장바구니 보관기간(일). 경과 시 담긴 상품 자동 삭제 |
| order_settings.stock_restore_on_cancel | body | boolean | 아니오 | — | 주문 취소 시 차감된 재고 자동 복구 여부 (반품/교환에도 적용) |
| order_settings.confirmable_statuses | body | array | 아니오 | — | 사용자가 구매확정할 수 있는 주문 옵션 상태 목록 (`payment_complete`, `shipping_hold`, `preparing`, `shipping_ready`, `shipping`, `delivered` 중 선택) |
| claim | body | array | 아니오 | — | 클레임 설정 섹션 (환불 사유 목록, DB 동기화 대상으로 분리 저장) |
| claim.refund_reasons | body | array | 아니오 | — | 환불 사유 목록. 항목별 `id`·`code`(소문자·언더스코어, 필수)·`name`(다국어 배열, 현재 로케일 필수)·`fault_type`(`customer`\|`seller`\|`carrier`, 필수)·`is_user_selectable`·`is_active`·`sort_order`. 코드 중복 불가 |
| review_settings | body | array | 아니오 | — | 리뷰 정책 섹션 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| review_settings.write_deadline_days | body | integer | 아니오 | min 1, max 365 | 구매 확정 후 리뷰를 작성할 수 있는 기간(일) |
| review_settings.max_images | body | integer | 아니오 | min 0, max 20 | 리뷰 1건당 첨부 가능한 이미지 수 (0 = 이미지 첨부 불가) |
| review_settings.max_image_size_mb | body | integer | 아니오 | min 1, max 50 | 리뷰 이미지 파일 1개의 최대 용량(MB) |
| mileage | body | array | 아니오 | — | 마일리지 설정 섹션 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림) |
| mileage.enabled | body | boolean | 아니오 | — | 마일리지 적립·사용 기능 사용 여부 (`mileage` 전달 시 필수) |
| mileage.default_earn_rate | body | number | 아니오 | min 0, max 100 | 기본 적립률(%). 상품 등록 시 기본값으로 사용되며, `enabled: true` 인 경우 0 초과여야 함 |
| mileage.earn_trigger | body | string | 아니오 | `delivered`, `confirmed` | 마일리지 적립 시점 (배송완료 / 구매확정) |
| mileage.earn_delay_days | body | integer | 아니오 | min 0, max 365 | 적립 시점 이후 실제 적립까지의 지연일 |
| mileage.currency_rules | body | array | 아니오 | — | 통화별 사용 규칙. 항목별 `currency_code`(ISO 4217, 필수)·`point_value`(1점당 금액, min 0.001)·`min_use_amount`·`use_unit`(min 1)·`max_use_type`(`percent`\|`fixed`)·`max_use_percent`·`max_use_value`. 첫 행은 기본 통화여야 하고, 등록 통화만 허용하며 중복 불가 |
| mileage.expiry_enabled | body | boolean | 아니오 | — | 마일리지 유효기간 사용 여부 (경과 시 미사용 마일리지 자동 소멸) |
| mileage.expiry_days | body | integer | 아니오 | min 1, max 3650 | 마일리지 유효기간(일) |
| mileage.expiry_notification_enabled | body | boolean | 아니오 | — | 소멸 예정 알림 발송 여부 |
| mileage.expiry_notification_days_before | body | integer | 아니오 | min 1, max 365 | 유효기간 만료 며칠 전에 소멸 예정 알림을 발송할지 |
| shipping | body | array | 아니오 | — | 배송 설정 섹션 (기본 국가·배송 가능 국가·무료배송·배송사(carriers)·배송유형(types) — carriers/types는 DB 동기화 대상으로 분리 저장) |
| shipping.default_country | body | string | 아니오 | max 10 | 기본 배송 국가 코드. 새 배송정책 등록 시 기본으로 추가되며, `available_countries` 에 존재해야 함 |
| shipping.available_countries | body | array | 아니오 | — | 배송 가능 국가 목록. 항목별 `code`(max 10, 필수)·`name`(다국어 배열, 필수)·`is_active`. 코드 중복 불가 |
| shipping.international_shipping_enabled | body | boolean | 아니오 | — | 해외배송 기능 사용 여부 |
| shipping.free_shipping_threshold | body | integer | 아니오 | min 0 | 무료배송 기준 금액 (이 금액 이상 주문 시 무료배송) |
| shipping.free_shipping_enabled | body | boolean | 아니오 | — | 무료배송 기준금액 적용 여부 |
| shipping.address_validation_enabled | body | boolean | 아니오 | — | 주소 검증(주소찾기 API) 사용 여부 |
| shipping.address_api_provider | body | string | 아니오 | max 50 | 주소찾기 API 제공자 식별자 (기본값 `kakao`) |
| shipping.carriers | body | array | 아니오 | — | 배송사 목록(DB 동기화 대상). 항목별 `id`·`code`(소문자/숫자/하이픈, 필수)·`name`(다국어 배열, 현재 로케일 필수)·`type`(`domestic`\|`international`, 필수)·`tracking_url`(`{tracking_number}` 치환)·`is_active`·`sort_order`. 코드 중복 불가 |
| shipping.types | body | array | 아니오 | — | 배송유형 목록(DB 동기화 대상). 항목별 `id`·`code`(소문자/숫자/하이픈, 필수)·`name`(다국어 배열, 현재 로케일 필수)·`category`(`domestic`\|`international`\|`other`, 필수)·`is_active`·`sort_order`. 코드 중복 불가 |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "_tab": "basic_info",
    "notifications": [
        "예시값"
    ],
    "notifications.channels": [
        "예시값"
    ],
    "basic_info": [
        "예시값"
    ],
    "basic_info.shop_name": "예시 이름",
    "basic_info.route_path": "예시값",
    "basic_info.no_route": true,
    "basic_info.company_name": "예시 이름",
    "basic_info.business_number_1": "예시값",
    "basic_info.business_number_2": "예시값",
    "basic_info.business_number_3": "예시값",
    "basic_info.ceo_name": "예시 이름",
    "basic_info.business_type": "예시값",
    "basic_info.business_category": "예시값",
    "basic_info.zipcode": "06234",
    "basic_info.base_address": "서울특별시 강남구 테헤란로 1",
    "basic_info.detail_address": "서울특별시 강남구 테헤란로 1",
    "basic_info.phone_1": "010-1234-5678",
    "basic_info.phone_2": "010-1234-5678",
    "basic_info.phone_3": "010-1234-5678",
    "basic_info.fax_1": "예시값",
    "basic_info.fax_2": "예시값",
    "basic_info.fax_3": "예시값",
    "basic_info.email_id": "user@example.com",
    "basic_info.email_domain": "user@example.com",
    "basic_info.privacy_officer": "예시값",
    "basic_info.privacy_officer_email": "user@example.com",
    "basic_info.mail_order_number": "예시값",
    "basic_info.telecom_number": "예시값",
    "language_currency": [
        "예시값"
    ],
    "language_currency.default_currency": "예시값",
    "language_currency.currencies": [
        "예시값"
    ],
    "seo": [
        "예시값"
    ],
    "seo.meta_category_title": "예시 제목",
    "seo.meta_category_description": "예시 내용입니다.",
    "seo.meta_search_title": "예시 제목",
    "seo.meta_search_description": "예시 내용입니다.",
    "seo.meta_product_title": "예시 제목",
    "seo.meta_product_description": "예시 내용입니다.",
    "seo.meta_shop_index_title": "예시 제목",
    "seo.meta_shop_index_description": "예시 내용입니다.",
    "seo.seo_category": true,
    "seo.seo_search_result": true,
    "seo.seo_product_detail": true,
    "seo.seo_shop_index": true,
    "inquiry": [
        "예시값"
    ],
    "inquiry.board_slug": "example-key",
    "order_settings": [
        "예시값"
    ],
    "order_settings.default_pg_provider": "예시값",
    "order_settings.cash_receipt_provider": "예시값",
    "order_settings.cash_receipt_self_issue": true,
    "order_settings.shipping_fee_tax_policy": "proportional",
    "order_settings.payment_methods": [
        "예시값"
    ],
    "order_settings.banks": [
        "예시값"
    ],
    "order_settings.bank_accounts": [
        "예시값"
    ],
    "order_settings.auto_cancel_expired": true,
    "order_settings.auto_cancel_days": 1,
    "order_settings.cart_expiry_days": 1,
    "order_settings.stock_restore_on_cancel": true,
    "order_settings.confirmable_statuses": [
        "예시값"
    ],
    "claim": [
        "예시값"
    ],
    "claim.refund_reasons": [
        "예시값"
    ],
    "review_settings": [
        "예시값"
    ],
    "review_settings.write_deadline_days": 1,
    "review_settings.max_images": 1,
    "review_settings.max_image_size_mb": 1,
    "mileage": [
        "예시값"
    ],
    "mileage.enabled": true,
    "mileage.default_earn_rate": 1,
    "mileage.earn_trigger": "delivered",
    "mileage.earn_delay_days": 1,
    "mileage.currency_rules": [
        "예시값"
    ],
    "mileage.expiry_enabled": true,
    "mileage.expiry_days": 1,
    "mileage.expiry_notification_enabled": true,
    "mileage.expiry_notification_days_before": 1,
    "shipping": [
        "예시값"
    ],
    "shipping.default_country": "KR",
    "shipping.available_countries": [
        "KR"
    ],
    "shipping.international_shipping_enabled": true,
    "shipping.free_shipping_threshold": 1,
    "shipping.free_shipping_enabled": true,
    "shipping.address_validation_enabled": true,
    "shipping.address_api_provider": "서울특별시 강남구 테헤란로 1",
    "shipping.carriers": [
        "예시값"
    ],
    "shipping.types": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| basic_info | object | `{"shop_name":"","route_path":"shop","no_route":false,"com…` | 쇼핑몰 기본 정보 (쇼핑몰명·라우트 경로·상호·사업자번호·주소·연락처·이메일 등) |
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| claim | object | `{"refund_reasons":[{"id":1,"type":"refund","code":"order_…` | 클레임 설정 (DB 관리 대상인 환불 사유 목록: 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[{"id":"kginicis","name_key":"sirsoft-pay_kginicis::provi…` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |
| available_cash_receipt_providers | array | `[]` | 설치된 플러그인이 훅으로 등록한 현금영수증 발급 제공자 목록 (id·name_key — 미등록 시 빈 배열이며 신청 폼이 노출되지 않음) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정이 저장되었습니다.",
    "data": {
        "basic_info": {
            "shop_name": "111",
            "route_path": "shop",
            "no_route": false,
            "company_name": null,
            "business_number": "",
            "...": "(13개 키 생략, 총 18개)"
        },
        "language_currency": {
            "default_currency": "JPY",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "USD",
                    "name": {
                        "en": "Dollar"
                    },
                    "is_default": false,
                    "decimal_places": 2,
                    "base_unit": 1,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ja": "円"
                    },
                    "is_default": true,
                    "decimal_places": 0,
                    "base_unit": 100,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                }
            ]
        },
        "order_settings": {
            "default_pg_provider": null,
            "payment_methods": [
                {
                    "id": "card",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                "... (총 25건 중 5건 표시)"
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false
                }
            ],
            "auto_cancel_expired": true,
            "...": "(5개 키 생략, 총 10개)"
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States"
                    },
                    "is_active": true
                }
            ],
            "international_shipping_enabled": true,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "...": "(8개 키 생략, 총 13개)"
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "...": "(7개 키 생략, 총 12개)"
        },
        "review_settings": {
            "write_deadline_days": 90,
            "max_images": 5,
            "max_image_size_mb": 10
        },
        "inquiry": {
            "board_slug": null
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 2
                }
            ]
        },
        "mileage": {
            "enabled": false,
            "default_earn_rate": 1,
            "earn_trigger": "confirmed",
            "earn_delay_days": "0",
            "currency_rules": [
                {
                    "currency_code": "KRW",
                    "point_value": 1,
                    "min_use_amount": 1000,
                    "use_unit": 10,
                    "max_use_type": "fixed",
                    "max_use_percent": 30,
                    "max_use_value": 50000,
                    "earn_rounding_unit": "1",
                    "earn_rounding_method": "floor"
                }
            ],
            "expiry_enabled": true,
            "expiry_days": 365,
            "expiry_notification_enabled": true,
            "expiry_notification_days_before": 7,
            "notification_channels": [
                "mail",
                "database"
            ]
        },
        "claim": {
            "refund_reasons": [
                {
                    "id": 1,
                    "type": "refund",
                    "code": "order_mistake",
                    "name": {
                        "ko": "주문 실수",
                        "en": "Order Mistake"
                    },
                    "localized_name": "주문 실수",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 8,
                    "type": "refund",
                    "code": "apidoc_sample",
                    "name": {
                        "ko": "API 문서 샘플 사유",
                        "en": "API Doc Sample Reason"
                    },
                    "localized_name": "API 문서 샘플 사유",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 2,
                    "type": "refund",
                    "code": "changed_mind",
                    "name": {
                        "ko": "단순 변심",
                        "en": "Changed Mind"
                    },
                    "localized_name": "단순 변심",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": 3,
                    "type": "refund",
                    "code": "reorder_other",
                    "name": {
                        "ko": "다른 상품으로 재주문",
                        "en": "Reorder with Different Product"
                    },
                    "localized_name": "다른 상품으로 재주문",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 2
                },
                {
                    "id": 4,
                    "type": "refund",
                    "code": "delayed_delivery",
                    "name": {
                        "ko": "배송 지연",
                        "en": "Delayed Delivery"
                    },
                    "localized_name": "배송 지연",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 3
                },
                {
                    "id": 5,
                    "type": "refund",
                    "code": "product_info_different",
                    "name": {
                        "ko": "상품 정보 상이",
                        "en": "Product Info Different"
                    },
                    "localized_name": "상품 정보 상이",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 4
                },
                {
                    "id": 6,
                    "type": "refund",
                    "code": "admin_cancel",
                    "name": {
                        "ko": "관리자 취소",
                        "en": "Admin Cancel"
                    },
                    "localized_name": "관리자 취소",
                    "fault_type": "seller",
                    "is_user_selectable": false,
                    "is_active": true,
                    "sort_order": 5
                },
                {
                    "id": 7,
                    "type": "refund",
                    "code": "etc",
                    "name": {
                        "ko": "기타",
                        "en": "Etc"
                    },
                    "localized_name": "기타",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 6
                }
            ]
        },
        "available_pg_providers": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 이커머스 환경설정을 저장합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, `_tab` 으로 저장할 카테고리를 지정하고 각 섹션(`basic_info`·`shipping`·`claim` 등)을 배열로 전달합니다. `EcommerceSettingsService::saveSettings()`가 JSON 설정을 저장하되, DB 관리 대상인 `shipping.carriers`·`shipping.types`·`claim.refund_reasons` 는 분리해 각 Service 의 sync 메서드로 동기화합니다. 저장 성공 시 `sirsoft-ecommerce.settings.after_save` 훅을 발화하고, 관리자 UI 상태 갱신을 위해 병합된 전체 설정을 다시 반환합니다.

**숫자 필드 정규화** 검증 규칙에 `integer` / `numeric` 이 선언된 모든 필드는 검증 직전에 숫자 타입으로 캐스트되어 저장됩니다(중첩 배열의 와일드카드 경로 포함 — 예: `mileage.currency_rules.*.use_unit`). HTML `number` 입력의 값은 문자열(`"5"`)로 전송되고 Laravel 의 `integer` 규칙은 숫자 문자열을 통과시키되 캐스트하지 않으므로, 정규화가 없으면 문자열이 그대로 설정 파일에 영속되어 이후 날짜/수치 연산에서 타입 오류를 유발합니다.

- 정규화 대상: 정수 표기 문자열(`"5"`, `"05"`) → `int`, 소수 표기 문자열(`"1.5"`) → `float`(단, `numeric` 필드에 한함)
- 정규화 제외: 비숫자 문자열(`"abc"`), 빈 문자열, `null`, 불리언, 그리고 `integer` 필드에 전달된 소수 문자열(`"3.7"`) — 검증을 느슨하게 만들지 않기 위해 캐스트하지 않고 그대로 검증 실패시킵니다
- 조회(`GET`) 응답도 `defaults.json` 스키마의 숫자 타입으로 정규화되어 반환되므로, 저장 시점의 표현 형태와 무관하게 숫자로 수신됩니다

**마일리지 적립 절사 기준** `mileage.currency_rules.*` 의 두 필드가 적립 포인트 산출·안분의 절사 기준을 정합니다.

| 필드 | 타입 | 허용값 | 기본값 | 설명 |
| --- | --- | --- | --- | --- |
| `earn_rounding_unit` | string | `1` · `10` · `100` | `1` | 적립 포인트를 맞출 단위(점). 마일리지는 원장에 정수로 확정되므로 소수 단위는 허용하지 않습니다 |
| `earn_rounding_method` | string | `floor` · `round` · `ceil` | `floor` | 단위에 맞출 방식(버림 / 반올림 / 올림) |

- 적용 지점: 옵션 정액 적립 · 옵션 정률 적립 · 기본 적립률 세 갈래 전부, 그리고 부분취소로 주문옵션이 분할될 때의 적립액 안분
- 기준 통화 선택: 마일리지는 표시 통화가 아니라 기준 통화(`currency_snapshot.base_currency`)로 적립·정산되므로, 그 통화의 규칙을 사용하고 없으면 첫 규칙(기본 통화)으로 폴백합니다
- 주문 시점 고정: 주문 생성 시 `mileage_policy_snapshot.rule` 에 함께 기록되며, 부분취소·추가결제 재계산은 현재 설정이 아니라 이 스냅샷을 사용합니다. 그렇지 않으면 이후 설정 변경이 과거 주문에 소급돼, 취소하지 않은 잔여분의 적립액이 취소 처리만으로 달라집니다
- 값이 없는 경우(이 필드 도입 이전 설치본·주문): 기본값 `1` / `floor` 로 해석되며 이는 도입 이전 동작과 동일한 금액을 산출합니다
- 통화 환산 절사(`language_currency.currencies.*.rounding_unit` / `rounding_method`)와는 별개입니다 — 그쪽은 외화 표시 환산에만 적용되어 기본 통화에는 적용되지 않으므로 적립 규칙으로 쓸 수 없습니다

### PUT /api/modules/sirsoft-ecommerce/admin/settings/banks
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.store-banks -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.store-banks`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@storeBanks`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| banks | body | array | 아니오 | — | 무통장입금용 은행 목록 (은행 코드·다국어 은행명) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/settings/banks HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "banks": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| basic_info | object | `{"shop_name":"","route_path":"shop","no_route":false,"com…` | 쇼핑몰 기본 정보 (쇼핑몰명·라우트 경로·상호·사업자번호·주소·연락처·이메일 등) |
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정이 저장되었습니다.",
    "data": {
        "basic_info": {
            "shop_name": "111",
            "route_path": "shop",
            "no_route": false,
            "company_name": null,
            "business_number": "",
            "...": "(13개 키 생략, 총 18개)"
        },
        "language_currency": {
            "default_currency": "JPY",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "USD",
                    "name": {
                        "en": "Dollar"
                    },
                    "is_default": false,
                    "decimal_places": 2,
                    "base_unit": 1,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ja": "円"
                    },
                    "is_default": true,
                    "decimal_places": 0,
                    "base_unit": 100,
                    "...": "(3개 키 생략, 총 8개)"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "...": "(6개 키 생략, 총 11개)"
                }
            ]
        },
        "order_settings": {
            "default_pg_provider": null,
            "payment_methods": [
                {
                    "id": "card",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                "... (총 25건 중 5건 표시)"
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false
                }
            ],
            "auto_cancel_expired": true,
            "...": "(5개 키 생략, 총 10개)"
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States"
                    },
                    "is_active": true
                }
            ],
            "international_shipping_enabled": true,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "...": "(2개 키 생략, 총 7개)"
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "...": "(7개 키 생략, 총 12개)"
        },
        "review_settings": {
            "write_deadline_days": 90,
            "max_images": 5,
            "max_image_size_mb": 10
        },
        "inquiry": {
            "board_slug": null
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 2
                }
            ]
        },
        "mileage": {
            "enabled": false,
            "default_earn_rate": 1,
            "earn_trigger": "confirmed",
            "earn_delay_days": "0",
            "currency_rules": [
                {
                    "currency_code": "KRW",
                    "point_value": 1,
                    "min_use_amount": 1000,
                    "use_unit": 10,
                    "max_use_type": "fixed",
                    "max_use_percent": 30,
                    "max_use_value": 50000,
                    "earn_rounding_unit": "1",
                    "earn_rounding_method": "floor"
                }
            ],
            "expiry_enabled": true,
            "expiry_days": 365,
            "expiry_notification_enabled": true,
            "expiry_notification_days_before": 7
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 무통장입금용 은행 목록만 별도로 저장합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, `banks` 배열을 받아 `EcommerceSettingsService::saveBanks()`가 저장합니다. 전체 설정 저장(`store`)과 분리된 전용 엔드포인트로, 결제 설정 화면에서 은행 목록만 관리할 때 사용합니다. 저장 성공 시 갱신된 전체 설정을 반환합니다.


### POST /api/modules/sirsoft-ecommerce/admin/settings/clear-cache
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.clear-cache -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.clear-cache`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/settings/clear-cache HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cleared | boolean | `true` | 이커머스 설정 캐시 + SEO 렌더 캐시 초기화 성공 여부 (성공 시 항상 `true`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.cache_clear_success",
    "data": {
        "cleared": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 이커머스 설정 캐시와 SEO 렌더 캐시를 초기화합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, `EcommerceSettingsService::clearCache()`로 설정 캐시를 비우고 `SeoCacheManagerInterface::clearAll()`로 SEO 페이지 캐시까지 전부 삭제합니다. 설정 변경이 화면에 즉시 반영되지 않을 때 캐시를 강제로 비우는 용도로, 성공 시 `{cleared: true}` 를 반환합니다.


### GET /api/modules/sirsoft-ecommerce/admin/settings/seo-cache-info
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.seo-cache-info -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.seo-cache-info`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@seoCacheInfo`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/settings/seo-cache-info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `0` | 캐시된 SEO 페이지 URL 개수 |
| size_bytes | integer | `0` | 캐시된 SEO 페이지의 지원 로케일별 HTML 총 바이트 |
| size_formatted | string | `0 B` | `size` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "count": 0,
        "size_bytes": 0,
        "size_formatted": "0 B"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 현재 캐시된 SEO 페이지의 개수와 총 용량을 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `SeoCacheManagerInterface::getCachedUrls()`로 캐시된 URL 을 열거하고 지원 로케일별 HTML 바이트를 합산합니다. 응답은 캐시 페이지 수(`count`)·총 바이트(`size_bytes`)·사람이 읽기 쉬운 크기(`size_formatted`, 예 `1.5 MB`)를 담습니다. 설정 화면에서 SEO 캐시 현황을 표시하고 캐시 초기화 여부를 판단하는 근거로 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/settings/{category}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category | path | string | 예 | — | 분류 필터 (해당 분류의 항목만 조회) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/settings/{category} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 단일 설정 카테고리만 골라 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, path 의 `category`(예 `basic_info`)로 `EcommerceSettingsService::getSettings()`를 호출해 해당 섹션만 반환합니다. 전체 설정을 내려받는 index 와 달리 특정 탭 데이터만 필요할 때 사용하며, 응답은 `category`·`settings`·`abilities.can_update` 를 포함합니다.


### GET /api/modules/sirsoft-ecommerce/settings/checkout
<!-- @generated:start:api.modules.sirsoft-ecommerce.settings.checkout -->
- **라우트명**: `api.modules.sirsoft-ecommerce.settings.checkout`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController@checkout`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/settings/checkout HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 체크아웃용 배송 설정 (기본 국가·배송 가능 국가·무료배송·배송유형 등) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 체크아웃용 주문/결제 설정 (기본 PG·활성 결제수단·무통장 계좌 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States"
                    },
                    "is_active": true
                }
            ],
            "international_shipping_enabled": true,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "...": "(2개 키 생략, 총 7개)"
        },
        "order_settings": {
            "default_pg_provider": null,
            "payment_methods": [
                {
                    "id": "card",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                "... (총 25건 중 5건 표시)"
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false
                }
            ],
            "auto_cancel_expired": true,
            "...": "(5개 키 생략, 총 10개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 500 | Internal Server Error | 설정 조회 중 예외 발생 (`messages.settings.fetch_failed`) |

<!-- @generated:end -->

**설명** 인증 없이 접근 가능한 공개 엔드포인트로, 체크아웃 화면이 필요로 하는 배송·결제 설정을 한 번에 반환합니다. `EcommerceSettingsService::getSettings()`로 `shipping` 과 `order_settings` 두 섹션을 함께 조회하며, 개별 shipping/payment 엔드포인트를 두 번 호출하지 않도록 묶어줍니다. 비회원·회원 모두 접근하고, `logApiUsage('settings.checkout')`로 사용 로그를 남깁니다.


### GET /api/modules/sirsoft-ecommerce/settings/payment
<!-- @generated:start:api.modules.sirsoft-ecommerce.settings.payment -->
- **라우트명**: `api.modules.sirsoft-ecommerce.settings.payment`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController@payment`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/settings/payment HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 공개 가능한 결제 설정 (활성 결제수단·무통장 은행명 매핑 포함, 민감 정보 제외) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "order_settings": {
            "default_pg_provider": null,
            "payment_methods": [
                {
                    "id": "card",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 1,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "...": "(10개 키 생략, 총 15개)"
                },
                "... (총 25건 중 5건 표시)"
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false,
                    "...": "(1개 키 생략, 총 6개)"
                }
            ],
            "auto_cancel_expired": true,
            "...": "(5개 키 생략, 총 10개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 500 | Internal Server Error | 설정 조회 중 예외 발생 (`messages.settings.fetch_failed`) |

<!-- @generated:end -->

**설명** 인증 없이 접근 가능한 공개 엔드포인트로, 체크아웃에서 필요한 결제 설정을 반환합니다. `EcommerceSettingsService::getPublicPaymentSettings()`가 활성화된 결제 수단과 무통장입금 설정 등 공개 가능한 항목만 추려 `order_settings` 로 내려줍니다. 관리자 전용 민감 정보는 제외되며, 비회원·회원 모두 접근하고 `logApiUsage('settings.payment')`로 사용 로그를 남깁니다.


### GET /api/modules/sirsoft-ecommerce/settings/review
<!-- @generated:start:api.modules.sirsoft-ecommerce.settings.review -->
- **라우트명**: `api.modules.sirsoft-ecommerce.settings.review`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController@review`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/settings/review HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 공개 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "review_settings": {
            "write_deadline_days": 90,
            "max_images": 5,
            "max_image_size_mb": 10
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 500 | Internal Server Error | 설정 조회 중 예외 발생 (`messages.settings.fetch_failed`) |

<!-- @generated:end -->

**설명** 인증 없이 접근 가능한 공개 엔드포인트로, 리뷰 작성 화면이 필요로 하는 리뷰 정책을 반환합니다. `EcommerceSettingsService::getSettings('review_settings')`로 리뷰 이미지 최대 개수(`max_images`)·최대 용량(`max_image_size_mb`)·작성 기한(`write_deadline_days`) 등을 `review_settings` 로 내려줍니다. 프론트가 이미지 업로드 제한과 작성 가능 기간을 판단하는 데 사용하며, `logApiUsage('settings.review')`로 사용 로그를 남깁니다.


### GET /api/modules/sirsoft-ecommerce/settings/shipping
<!-- @generated:start:api.modules.sirsoft-ecommerce.settings.shipping -->
- **라우트명**: `api.modules.sirsoft-ecommerce.settings.shipping`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController@shipping`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/settings/shipping HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 공개 배송 설정 (기본 국가·배송 가능 국가·국제배송 활성 여부·배송유형·무료배송 설정) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States"
                    },
                    "is_active": true
                }
            ],
            "international_shipping_enabled": true,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 500 | Internal Server Error | 설정 조회 중 예외 발생 (`messages.settings.fetch_failed`) |

<!-- @generated:end -->

**설명** 인증 없이 접근 가능한 공개 엔드포인트로, 체크아웃에서 필요한 배송 설정을 반환합니다. `EcommerceSettingsService::getSettings('shipping')`로 기본 배송 국가·이용 가능한 국가 목록·국제 배송 활성화 여부·배송 타입·무료 배송 설정 등을 `shipping` 으로 내려줍니다. 프론트가 배송지 선택과 배송비 안내를 구성하는 데 사용하며, `logApiUsage('settings.shipping')`로 사용 로그를 남깁니다.



---

## 결제수단 카탈로그 (`order_settings.payment_methods[]`)

결제수단 카탈로그는 코어 8종(builtin)과 PG 플러그인이 훅으로 등록한 확장 결제수단을 함께 담는다.
설정 조회/저장 응답의 `order_settings.payment_methods` 배열 각 항목이 이 구조를 따른다.

아래 능력(capability) 필드는 **정의(builtin 은 코어, 확장은 플러그인 선언)가 SSoT** 이며 저장된 값으로
덮이지 않는다 — 관리자가 편집하는 값이 아니라 결제수단의 성격이기 때문이다.

| 이름 | 타입 | 용도 |
| --- | --- | --- |
| id | string | 결제수단 ID. builtin(`card` / `vbank` / `dbank` / `bank` / `phone` / `point` / `deposit` / `free`) 또는 확장 ID(예: `nhnkcp_naverpay`, `kginicis_lpay`) |
| needs_pg | boolean | PG 결제창이 필요한 수단인지. `false` 면 관리자 화면에 "PG 불필요" 로 표시되고 주문도 PG 를 거치지 않는다 (무통장·포인트·예치금·무료) |
| pg_locked | boolean | PG 가 특정 대행사로 고정된 수단인지. 간편결제처럼 특정 PG 전용인 수단은 `true` 이며, 관리자가 PG 를 바꿀 수 없고 화면에는 "PG 고정" 배지로 표시된다. `true` 일 때 `pg_provider` 는 저장값 대신 플러그인 선언값이 강제된다 |
| refund_method | string | 환불 수단 분류 (`pg` / `bank` / `points`). 주문 취소 시 PG 취소를 호출할지 결정한다 |
| pg_provider | string\|null | 이 결제수단을 처리할 PG. `pg_locked=true` 면 플러그인이 선언한 PG 로 고정되고, 아니면 관리자가 선택한다 (미선택 시 `default_pg_provider` 로 폴백) |
| is_active | boolean | 주문서에 노출할지 여부 |
| min_order_amount | number | 이 결제수단을 쓸 수 있는 최소 주문금액 |
| stock_deduction_timing | string | 재고 차감 시점 (`order_placed` / `payment_complete` / `none`) |
| mileage_deduction_timing | string | 마일리지 차감 시점 |
| sort_order | number | 주문서 노출 순서 |
| _cached_name / _cached_description | object | 다국어 표시명/설명 (locale => 문자열) |
| _cached_icon | string | 표시 아이콘 |
| _cached_source | string | 제공 주체 (`builtin` 또는 `plugin:{식별자}`) |

확장 결제수단(간편결제)은 코어의 `card` 로 치환되지 않고 자기 ID 그대로 저장·조회된다.
주문 생성 시 `payment_method` 로 확장 ID 를 그대로 보내면 되며, 서버는 이 카탈로그를 화이트리스트로
사용해 검증한다.


