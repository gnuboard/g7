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
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙). `removed_default_currencies` 는 관리자가 삭제한 기본 제공 통화 코드 목록으로, 서버가 저장 시점에 도출해 기록한다 (관리자 응답 전용 — 공개 설정에는 노출되지 않음) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| privacy_retention | object | `{"enabled":false,"retention_years":5,"mode":"anonymize"}` | 종결 주문 개인정보 보유기간 설정(이슈 #636) — 사용 여부(기본 false)·보유기간(년, 최소 5)·처리 방식(`anonymize`/`delete`) |
| claim | object | `{"exchange_reasons":[…],"return_reasons":[…],"refund_reaso…` | 클레임 설정. **정책 스칼라**(신청 가능 상태 4축·신청 기간·허용 청구 방식/결제수단·자동 승인 3종·첨부 상한·완료 처리 기본값)와 **DB 관리 대상인 사유 카탈로그 3종**(`exchange_reasons`·`return_reasons`·`refund_reasons` — 각 항목은 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[{"id":"kginicis","name_key":"sirsoft-pay_kginicis::provi…` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |
| available_cash_receipt_providers | array | `[]` | 설치된 플러그인이 훅으로 등록한 현금영수증 발급 제공자 목록 (id·name_key — 미등록 시 빈 배열이며 신청 폼이 노출되지 않음) |
| available_public_asset_disks | array | `[{"id":"none","label":{"ko":"사용 안 함 (스트리밍)","en":"Disabl…` | 공개 자산 직접 URL 서빙 디스크 선택지 (코어 DriverRegistryService 카탈로그 — none/public/s3 + 플러그인 훅 등록분). 기본정보 탭의 공개 자산 디스크 Select 옵션 소스 |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| _meta | object | `{"limits":{"auto_cancel_days_min":1,"auto_cancel_days_max…` | 설정 화면 전용 메타. `limits` 는 숫자 입력의 경계값 맵(`config('sirsoft-ecommerce.limits')`)으로, 저장 규칙(FormRequest)과 같은 출처다 — 화면이 리터럴 경계를 들면 규칙 변경을 따라가지 못해 "화면은 받는데 저장은 422" 가 되므로 입력 min/max 는 이 값을 바인딩한다 |

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
            "exchange_requestable_statuses": [
                "delivered",
                "confirmed"
            ],
            "return_requestable_statuses": [
                "delivered",
                "confirmed"
            ],
            "instant_cancel_statuses": [
                "pending_order",
                "pending_payment",
                "payment_complete"
            ],
            "refund_request_statuses": [
                "preparing",
                "shipping_ready",
                "shipping"
            ],
            "allowed_charge_methods": [
                "pay_on_request",
                "pay_on_approval",
                "deduct_from_refund"
            ],
            "charge_payment_methods": [
                "card",
                "point"
            ],
            "auto_approve_zero_charge": false,
            "auto_approve_after_payment": false,
            "auto_approve_refund_request": false,
            "auto_approve_refund_statuses": [
                "payment_complete",
                "preparing"
            ],
            "max_images": 5,
            "image_max_size_kb": 2048,
            "request_period_days": 7,
            "complete_defaults": {
                "create_refund_request": true,
                "restore_stock": true
            },
            "exchange_reasons": [
                {
                    "id": 11,
                    "type": "exchange",
                    "code": "size_mismatch",
                    "name": {
                        "ko": "사이즈가 맞지 않음",
                        "en": "Size Does Not Fit"
                    },
                    "localized_name": "사이즈가 맞지 않음",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                }
            ,
              "... (총 8건 중 1건 표시)"
            ],
            "return_reasons": [
                {
                    "id": 21,
                    "type": "return",
                    "code": "defective_product",
                    "name": {
                        "ko": "상품 불량/파손",
                        "en": "Defective or Damaged Product"
                    },
                    "localized_name": "상품 불량/파손",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                }
            ,
              "... (총 9건 중 1건 표시)"
            ],
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
        },
        "_meta": {
            "limits": {
                "claim_max_images_min": 1,
                "claim_max_images_max": 20,
                "claim_image_max_size_kb_min": 128,
                "claim_image_max_size_kb_max": 20480,
                "claim_request_period_days_min": 1,
                "claim_request_period_days_max": 365
            }
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

> `_meta.limits` 는 모듈의 경계값 설정을 **통째로** 내려줍니다. 위 예시는 클레임 축 6키만 발췌한 것이고, 실제 응답에는 `auto_cancel_days_min`·`max_images_max`(리뷰 축) 등 다른 탭의 경계값도 함께 실립니다 — 항목이 늘어도 화면은 이 맵에서 자기가 쓸 키만 읽으면 됩니다.

**설명** 관리자가 이커머스 모듈의 전체 환경설정을 카테고리별로 묶어 한 번에 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, `EcommerceSettingsService::getAllSettings()`로 JSON 설정을 읽은 뒤 DB 관리 대상(배송사·배송유형·클레임 사유 카탈로그 3종·마일리지 알림 채널)과 등록된 PG 목록을 병합해 반환합니다. `basic_info`·`shipping`·`order_settings`·`claim`·`mileage` 등 관리자 설정 화면 전 탭의 초기 데이터를 이 한 응답으로 채웁니다. 응답의 `abilities.can_update` 로 수정 권한 보유 여부도 함께 내려갑니다. 저장된 `basic_info.public_asset_disk` 가 공개 자산 디스크 카탈로그(`available_public_asset_disks`)에 없으면(그 디스크를 등록하던 확장이 꺼진 경우) 실제 동작인 `none`(스트리밍)으로 바꿔 싣고, 사라진 디스크 이름을 `basic_info.public_asset_disk_unavailable` 로 함께 내려 화면이 안내를 띄웁니다.


### PUT /api/modules/sirsoft-ecommerce/admin/settings
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | `basic_info`, `language_currency`, `seo`, `order_settings`, `claim`, `shipping`, `review_settings`, `notification_definitions`, `notifications`, `inquiry`, `mileage`, `privacy_retention` | 저장할 설정 탭(카테고리) 지정 (탭별 부분 저장 식별용) |
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
| basic_info.public_asset_disk | body | string | 아니오 | max 100 | 공개 자산 디스크 오버라이드 (빈값=코어 설정 따름, none=강제 스트리밍, 그 외=카탈로그 디스크 id — 존재하지 않는 디스크는 스트리밍으로 안전 폴백) |
| language_currency | body | array | 아니오 | — | 통화 설정 섹션 (기본 통화·통화 목록: 코드·다국어명·환율·반올림 규칙·통화별 로케일) |
| language_currency.default_currency | body | string | 아니오 | max 10 | 쇼핑몰 기본(base) 통화 코드. 상품/주문이 1건이라도 생성된 뒤에는 변경 불가 |
| language_currency.currencies | body | array | 아니오 | — | 등록 통화 목록. 항목별 `code`(ISO 4217 3자리 대문자, 필수)·`name`(다국어 배열, 필수)·`symbol`·`exchange_rate`·`base_unit`·`rounding_unit`·`rounding_method`(`floor`\|`round`\|`ceil`)·`decimal_places`·`is_default`·`locales` |
| language_currency.removed_default_currencies | body | array | — | — | 서버 관리 필드. 요청에 실어 보내도 무시되며, 제출된 `currencies` 와 기본 제공 통화 목록의 차집합으로 서버가 재계산한다. `currencies` 를 보내지 않은 저장은 기존 값을 그대로 이월한다 |
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

#### 고객이 고를 수 없는 결제수단 (`customer_selectable: false`)

`point`(마일리지) · `deposit`(예치금) · `free`(무료)는 **코어에 결제 실행 경로가 없다**. 응답의
결제수단 항목은 `customer_selectable: false` 로 그 사실을 싣고, 관리자 설정 화면은 그 값을 읽어
활성 토글을 잠근다.

켜 두면 고객 주문서에는 뜨는데 주문 생성이 `422` 로 거절되고(`CreateOrderRequest`), 통과하더라도
결제를 완료할 방법이 없어 주문이 `pending_order` 에 영구 정박한다 — 오류 없이 주문만 쌓인다.

- **예치금으로 결제받으려면 예치금 플러그인이 필요하다.** 코어는 예치금 **차감** 구현 자체가
  없어 `total_deposit_used_amount` 가 항상 0 이고, 따라서 환불 분해·복원·한도 축도 없다.
  그 축은 차감을 소유한 플러그인이 함께 갖는다.
- **마일리지는 주문의 일부 금액을 상쇄하는 용도로만 쓴다** — 결제수단으로 고르는 것이 아니라
  주문서에서 사용액을 입력하는 축이다.
- `free` 는 **0원 주문에서 시스템이 자동으로 고르는 경로**만 남는다.

확장이 `customer_selectable` 을 선언하면 그 값이 우선한다 — 결제 실행을 제공하는 플러그인은
자기 수단을 선택 가능으로 열 수 있다.
| order_settings.banks | body | array | 아니오 | — | 무통장입금용 은행 목록. 항목별 `code`(max 10, 필수)·`name`(다국어 배열 — 사이트 기본 언어(`app.site_locale`) 이름 필수, 요청 언어와 무관. 은행 행에는 `id` 가 없어 클레임 사유·배송사 같은 '기존 행 면제' 가 **없다**: 사이트 기본 언어 이름이 빈 은행이 있으면 그 이름을 채워야 저장된다) |
| order_settings.bank_accounts | body | array | 아니오 | — | 무통장 입금 계좌 목록. 항목별 `bank_code`·`account_number`·`account_holder`(모두 필수)·`is_active`·`is_default`. 계좌가 있으면 최소 1건은 사용+기본 상태여야 함 |
| order_settings.auto_cancel_expired | body | boolean | 아니오 | — | 입금대기 상태 주문의 자동취소 사용 여부 |
| order_settings.auto_cancel_days | body | integer | 아니오 | min 0, max 30 | 자동취소 기한(일). 주문일 포함 이 일수 경과 시 입금대기 주문을 자동 취소 |
| order_settings.pending_order_expire_minutes | body | integer | 아니오 | min 0, max 20160 | 결제 미완료 주문 만료 기준(분). 결제창까지 갔으나 결제가 성립하지 않은 주문을 이 시간 경과 후 자동 취소. `0` 이면 그 부류를 정리하지 않음 |
| order_settings.cart_expiry_days | body | integer | 아니오 | min 1, max 365 | 장바구니 보관기간(일). 경과 시 담긴 상품 자동 삭제 |
| order_settings.stock_restore_on_cancel | body | boolean | 아니오 | — | 주문 취소 시 차감된 재고 자동 복구 여부 (반품/교환에도 적용) |
| order_settings.cancellable_statuses | body | array | 아니오 | — | 자동 전이 대상 주문옵션(상품) 상태 목록 — `claim.instant_cancel_statuses` 와 같은 값의 별칭. 허용 `pending_order`·`pending_payment`·`payment_complete`·`shipping_hold`·`preparing`·`shipping_ready`·`shipping`·`delivered`·`confirmed` (기본 `pending_order`, `pending_payment`, `payment_complete`). 빈 배열은 자동 전이 비활성화, `null`은 422. 회원·비회원 주문 취소와 클레임 신청 자격이 같은 값을 사용하며, 주문 설정 탭과 클레임 정책 탭 어느 쪽에서 저장해도 두 키가 함께 갱신 |
| order_settings.confirmable_statuses | body | array | 아니오 | — | 사용자가 구매확정할 수 있는 주문 옵션 상태 목록 (`payment_complete`, `shipping_hold`, `preparing`, `shipping_ready`, `shipping`, `delivered` 중 선택) |
| claim | body | array | 아니오 | — | 클레임 설정 섹션 (정책 스칼라 + 사유 카탈로그 3종. 카탈로그는 DB 동기화 대상으로 분리 저장) |
| claim.exchange_requestable_statuses | body | array | 아니오 | — | 교환을 신청할 수 있는 **주문옵션(상품) 상태** 목록. 허용 `payment_complete`·`preparing`·`shipping_ready`·`shipping`·`delivered`·`confirmed` (기본 `delivered`, `confirmed`). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.return_requestable_statuses | body | array | 아니오 | — | 반품을 신청할 수 있는 **주문옵션(상품) 상태** 목록. 허용 `payment_complete`·`preparing`·`shipping_ready`·`shipping`·`delivered`·`confirmed` (기본 `delivered`, `confirmed`). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.instant_cancel_statuses | body | array | 아니오 | — | **자동 전이 대상 주문옵션(상품) 상태** 목록. 이 상태의 상품도 환불신청서를 만들고, 승인·환불완료까지 자동으로 진행한다(계좌 환불 수단은 승인에서 멈춘다). 허용 `pending_order`·`pending_payment`·`payment_complete`·`shipping_hold`·`preparing`·`shipping_ready`·`shipping`·`delivered`·`confirmed` (기본 `pending_order`, `pending_payment`, `payment_complete`). `order_settings.cancellable_statuses`의 호환 별칭이라 어느 설정 탭에서 저장해도 두 키가 함께 갱신되고, 두 키를 동시에 다르게 보내면 주문 설정이 우선. 읽기 시 저장값을 변경하지 않음 |
| claim.refund_request_statuses | body | array | 아니오 | — | 환불신청서로 접수되는 **주문옵션(상품) 상태** 목록. 허용 `payment_complete`·`shipping_hold`·`preparing`·`shipping_ready`·`shipping` (기본 `preparing`, `shipping_ready`, `shipping`). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.allowed_charge_methods | body | array | 아니오 | — | 고객이 신청서에서 고를 수 있는 청구 방식. 허용 `pay_on_request`·`pay_on_approval`·`deduct_from_refund`. 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.charge_payment_methods | body | array | 아니오 | — | 클레임 추가결제에 쓸 수 있는 결제수단 (기본 `card`, `point`). 허용값은 기본 결제수단 + 결제 플러그인이 등록한 확장 결제수단 ID — 일반 주문의 결제수단 목록과 같은 출처다. **주문 카탈로그의 활성 여부(`is_active`)는 보지 않는다** — 이 키는 주문 결제와 별개 축이라 주문에서 내려 둔 수단도 클레임 추가결제로는 쓸 수 있고, 서버 검증(`PaymentMethodResolver::allValidIds()`)도 활성 여부를 따지지 않는다. 관리자 화면의 선택지도 같은 규칙을 따른다(공급이 끊긴 `_orphaned` 항목만 제외). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.auto_approve_zero_charge | body | boolean | 아니오 | — | 추가 청구액이 0원인 교환·반품 신청을 접수 즉시 승인할지 여부 |
| claim.auto_approve_after_payment | body | boolean | 아니오 | — | 추가결제 완료 시 승인 전이를 자동 수행할지 여부 |
| claim.auto_approve_refund_request | body | boolean | 아니오 | — | 접수된 환불신청서를 자동 승인할지 여부 (환불 실행은 별도) |
| claim.auto_approve_refund_statuses | body | array | 아니오 | — | 자동 승인이 켜졌을 때 **어느 상품 상태**를 승인할지. 허용 `payment_complete`·`shipping_hold`·`preparing`·`shipping_ready`·`shipping` (기본 `payment_complete`, `preparing`). 신청서의 상품이 하나라도 목록 밖이면 전건을 운영자가 승인한다 — 섞인 신청서를 부분 승인할 수단이 없으므로, "하나라도 대상이면 승인" 은 대상 밖 상품까지 함께 승인해 버린다. 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.max_images | body | integer | 아니오 | min 1, max 20 | 신청서 1건당 첨부 가능한 이미지 수 (1~20). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.image_max_size_kb | body | integer | 아니오 | min 128, max 20480 | 첨부 이미지 1개의 최대 용량(KB, 128~20480). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.request_period_days | body | integer | 아니오 | min 1, max 365 | 배송완료(또는 구매확정) 후 교환·반품을 신청할 수 있는 기간(일, 1~365). 키를 보내면 값이 있어야 한다(`null` 은 422) |
| claim.complete_defaults | body | array | 아니오 | — | 완료 처리 모달의 초기 선택값 묶음 (실제 실행값은 모달에서 건별로 확정된다). **맵을 보내면 `create_refund_request`·`restore_stock` 두 키가 모두 있어야 한다** — 빈 맵 `{}` 과 부분 맵은 422 (키가 빠지면 저장본에서 사라져 모달이 '켜짐' 기본값으로 돌아간다) |
| claim.complete_defaults.create_refund_request | body | boolean | 아니오 | — | 완료 처리 모달의 "환불신청서 지금 접수" 초기 선택값. `complete_defaults` 를 보내면 **필수**(`null` 도 422) |
| claim.complete_defaults.restore_stock | body | boolean | 아니오 | — | 완료 처리 모달의 건별 재고 복구 체크 초기 선택값. `complete_defaults` 를 보내면 **필수**(`null` 도 422) |
| claim.exchange_reasons | body | array | 아니오 | — | 교환 사유 목록. 항목별 `id`·`code`(소문자·언더스코어, 필수)·`name`(다국어 배열 — **신규 행**은 현재 로케일 필수, `id` 가 있는 기존 행은 길이·형식만 검사한다: 다른 로케일에서 만든 행 때문에 탭 전체가 막히지 않게 하기 위함)·`fault_type`(`customer`\|`seller`\|`carrier`, 필수)·`is_user_selectable`·`is_active`(행을 보내면 두 플래그 모두 **필수** — 키가 빠지면 422. 빠진 키가 `true` 로 채워져 내려 둔 사유가 되살아나는 것을 막는다)·`sort_order`. 코드 중복 불가. `code` 형식은 [claim-reasons.md](claim-reasons.md) 의 "코드 형식 규칙" 과 같고 이미 저장된 코드는 면제된다. DB 동기화 대상이라 JSON 설정에는 저장되지 않으며, 목록에서 빠진 사유는 삭제된다 — 그 유형에서 사용 중인 사유는 삭제도 `code` 변경도 400 으로 거절된다(사유명·귀책·노출 여부는 계속 수정 가능). `id` 는 **그 유형에 실재하는 행**이어야 한다(없는 id·다른 유형의 id 는 422 — 기존 행 면제의 유일한 근거이므로). 기존 행끼리 `code` 를 맞바꾸는 저장은 422 (`code_swap`) — 한쪽을 먼저 다른 코드로 저장한 뒤 바꾼다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용. |
| claim.return_reasons | body | array | 아니오 | — | 반품 사유 목록. 항목별 `id`·`code`(소문자·언더스코어, 필수)·`name`(다국어 배열 — **신규 행**은 현재 로케일 필수, `id` 가 있는 기존 행은 길이·형식만 검사한다: 다른 로케일에서 만든 행 때문에 탭 전체가 막히지 않게 하기 위함)·`fault_type`(`customer`\|`seller`\|`carrier`, 필수)·`is_user_selectable`·`is_active`(행을 보내면 두 플래그 모두 **필수** — 키가 빠지면 422. 빠진 키가 `true` 로 채워져 내려 둔 사유가 되살아나는 것을 막는다)·`sort_order`. 코드 중복 불가. `code` 형식은 [claim-reasons.md](claim-reasons.md) 의 "코드 형식 규칙" 과 같고 이미 저장된 코드는 면제된다. DB 동기화 대상이라 JSON 설정에는 저장되지 않으며, 목록에서 빠진 사유는 삭제된다 — 그 유형에서 사용 중인 사유는 삭제도 `code` 변경도 400 으로 거절된다(사유명·귀책·노출 여부는 계속 수정 가능). `id` 는 **그 유형에 실재하는 행**이어야 한다(없는 id·다른 유형의 id 는 422 — 기존 행 면제의 유일한 근거이므로). 기존 행끼리 `code` 를 맞바꾸는 저장은 422 (`code_swap`) — 한쪽을 먼저 다른 코드로 저장한 뒤 바꾼다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용. |
| claim.refund_reasons | body | array | 아니오 | — | 환불(취소) 사유 목록. 항목별 `id`·`code`(소문자·언더스코어, 필수)·`name`(다국어 배열 — **신규 행**은 현재 로케일 필수, `id` 가 있는 기존 행은 길이·형식만 검사한다: 다른 로케일에서 만든 행 때문에 탭 전체가 막히지 않게 하기 위함)·`fault_type`(`customer`\|`seller`\|`carrier`, 필수)·`is_user_selectable`·`is_active`(행을 보내면 두 플래그 모두 **필수** — 키가 빠지면 422. 빠진 키가 `true` 로 채워져 내려 둔 사유가 되살아나는 것을 막는다)·`sort_order`. 코드 중복 불가. `code` 형식은 [claim-reasons.md](claim-reasons.md) 의 "코드 형식 규칙" 과 같고 이미 저장된 코드는 면제된다. DB 동기화 대상이라 JSON 설정에는 저장되지 않으며, 목록에서 빠진 사유는 삭제된다 — 그 유형에서 사용 중인 사유는 삭제도 `code` 변경도 400 으로 거절된다(사유명·귀책·노출 여부는 계속 수정 가능). `id` 는 **그 유형에 실재하는 행**이어야 한다(없는 id·다른 유형의 id 는 422 — 기존 행 면제의 유일한 근거이므로). 기존 행끼리 `code` 를 맞바꾸는 저장은 422 (`code_swap`) — 한쪽을 먼저 다른 코드로 저장한 뒤 바꾼다. 같은 저장에서 지워지는 행의 코드를 물려받는 것은 허용. |
| review_settings | body | array | 아니오 | — | 리뷰 정책 섹션 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| review_settings.write_deadline_days | body | integer | 아니오 | min 1, max 365 | 구매 확정 후 리뷰를 작성할 수 있는 기간(일) |
| review_settings.max_images | body | integer | 아니오 | min 0, max 20 | 리뷰 1건당 첨부 가능한 이미지 수 (0 = 이미지 첨부 불가) |
| review_settings.max_image_size_mb | body | integer | 아니오 | min 1, max 50 | 리뷰 이미지 파일 1개의 최대 용량(MB) |
| privacy_retention | body | array | 아니오 | — | 종결 주문 개인정보 보유기간 섹션(이슈 #636). 「개인정보 보유기간」 탭 저장이 `_tab: privacy_retention` 과 함께 보낸다. 코어 보유기간 커맨드(`privacy:process-retention`)가 핸들러 `sirsoft-ecommerce.order` 를 통해 이 값을 읽어 매일 실행한다 |
| privacy_retention.enabled | body | boolean | 아니오 | — | 보유기간 처리 사용 여부. **기본 false** — 되돌릴 수 없는 처리라 운영자가 직접 켜야 하며, 핸들러가 실행 시점에 false 폴백으로 다시 읽는다 |
| privacy_retention.retention_years | body | integer | 아니오 | min 5, max 20 | 주문 종결(구매확정·취소, 진행 중 환불·교환·반품 없음) 후 보유기간(년, 기본 5). 하한 5년은 전자상거래법 §6 보존기간이라 서버가 강제하고, 판독부도 5년 바닥값을 둔다. 한계값은 `config('sirsoft-ecommerce.limits')` |
| privacy_retention.mode | body | string | 아니오 | `anonymize`, `delete` | 처리 방식(기본 `anonymize`). `anonymize` 는 주문자·수령인 이름·연락처·주소(NOT NULL 칸은 `***`, nullable 칸은 NULL)·구매자·입금·환불 계좌 명의·IP·관리자 메모와 교환·반품·환불 신청서의 환불 계좌·상세 사유·관리자 메모·교환 재배송지를 지우고 주문번호·금액·상태는 남긴다. `delete` 는 주문과 결제·배송·취소·환불·현금영수증·세금계산서·옵션·주소와 교환·반품·클레임 결제·클레임 첨부(파일 포함)를 명시 삭제하고 그 주문의 상품 리뷰를 이미지째 지우며, 쿠폰 발급·마일리지 거래는 주문·클레임 결제·환불 연결만 끊는다 |
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
| shipping.carriers | body | array | 아니오 | — | 배송사 목록(DB 동기화 대상). 항목별 `id`·`code`(소문자/숫자/하이픈, 필수)·`name`(다국어 배열 — **신규 행**은 현재 로케일 필수, `id` 가 있는 기존 행은 길이·형식만 검사한다: 다른 로케일에서 만든 행 때문에 탭 전체가 막히지 않게 하기 위함)·`type`(`domestic`\|`international`, 필수)·`tracking_url`(`{tracking_number}` 치환)·`is_active`·`sort_order`. 코드 중복 불가. **기존 배송사끼리 코드를 맞바꾸는 저장은 422**(`shipping.carriers.N.code` — `code_swap`): 파일 쓰기 뒤 DB unique 에서 죽어 반쪽 상태가 남는 것을 막는다. 같은 저장에서 지워지는 배송사의 코드를 물려받는 것은 허용 |
| shipping.types | body | array | 아니오 | — | 배송유형 목록(DB 동기화 대상). 항목별 `id`·`code`(소문자/숫자/하이픈, 필수)·`name`(다국어 배열 — **신규 행**은 현재 로케일 필수, `id` 가 있는 기존 행은 길이·형식만 검사한다: 다른 로케일에서 만든 행 때문에 탭 전체가 막히지 않게 하기 위함)·`category`(`domestic`\|`international`\|`other`, 필수)·`is_active`·`sort_order`. 코드 중복 불가. **기존 배송유형끼리 코드를 맞바꾸는 저장은 422**(`shipping.types.N.code` — `code_swap`): 파일 쓰기 뒤 DB unique 에서 죽어 반쪽 상태가 남는 것을 막는다. 같은 저장에서 지워지는 배송유형의 코드를 물려받는 것은 허용 |

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
    "order_settings.pending_order_expire_minutes": 1440,
    "order_settings.cart_expiry_days": 1,
    "order_settings.stock_restore_on_cancel": true,
    "order_settings.confirmable_statuses": [
        "예시값"
    ],
    "claim": [
        "예시값"
    ],
    "claim.exchange_requestable_statuses": [
        "delivered",
        "confirmed"
    ],
    "claim.return_requestable_statuses": [
        "delivered",
        "confirmed"
    ],
    "claim.instant_cancel_statuses": [
        "pending_order",
        "pending_payment",
        "payment_complete"
    ],
    "claim.refund_request_statuses": [
        "preparing",
        "shipping_ready",
        "shipping"
    ],
    "claim.allowed_charge_methods": [
        "pay_on_request",
        "pay_on_approval",
        "deduct_from_refund"
    ],
    "claim.charge_payment_methods": [
        "card",
        "point"
    ],
    "claim.auto_approve_zero_charge": false,
    "claim.auto_approve_after_payment": false,
    "claim.auto_approve_refund_request": false,
    "claim.auto_approve_refund_statuses": [
        "payment_complete",
        "preparing"
    ],
    "claim.max_images": 5,
    "claim.image_max_size_kb": 2048,
    "claim.request_period_days": 7,
    "claim.complete_defaults": [
        "예시값"
    ],
    "claim.complete_defaults.create_refund_request": true,
    "claim.complete_defaults.restore_stock": true,
    "claim.exchange_reasons": [
        "예시값"
    ],
    "claim.return_reasons": [
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
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙). `removed_default_currencies` 는 관리자가 삭제한 기본 제공 통화 코드 목록으로, 서버가 저장 시점에 도출해 기록한다 (관리자 응답 전용 — 공개 설정에는 노출되지 않음) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| privacy_retention | object | `{"enabled":false,"retention_years":5,"mode":"anonymize"}` | 종결 주문 개인정보 보유기간 설정(이슈 #636) — 사용 여부(기본 false)·보유기간(년, 최소 5)·처리 방식(`anonymize`/`delete`) |
| claim | object | `{"exchange_reasons":[…],"return_reasons":[…],"refund_reaso…` | 클레임 설정. **정책 스칼라**(신청 가능 상태 4축·신청 기간·허용 청구 방식/결제수단·자동 승인 3종·첨부 상한·완료 처리 기본값)와 **DB 관리 대상인 사유 카탈로그 3종**(`exchange_reasons`·`return_reasons`·`refund_reasons` — 각 항목은 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[{"id":"kginicis","name_key":"sirsoft-pay_kginicis::provi…` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |
| available_cash_receipt_providers | array | `[]` | 설치된 플러그인이 훅으로 등록한 현금영수증 발급 제공자 목록 (id·name_key — 미등록 시 빈 배열이며 신청 폼이 노출되지 않음) |
| available_public_asset_disks | array | `[{"id":"none","label":{"ko":"사용 안 함 (스트리밍)","en":"Disabl…` | 공개 자산 직접 URL 서빙 디스크 선택지 (코어 DriverRegistryService 카탈로그 — none/public/s3 + 플러그인 훅 등록분). 기본정보 탭의 공개 자산 디스크 Select 옵션 소스 |

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
            "exchange_requestable_statuses": [
                "delivered",
                "confirmed"
            ],
            "return_requestable_statuses": [
                "delivered",
                "confirmed"
            ],
            "instant_cancel_statuses": [
                "pending_order",
                "pending_payment",
                "payment_complete"
            ],
            "refund_request_statuses": [
                "preparing",
                "shipping_ready",
                "shipping"
            ],
            "allowed_charge_methods": [
                "pay_on_request",
                "pay_on_approval",
                "deduct_from_refund"
            ],
            "charge_payment_methods": [
                "card",
                "point"
            ],
            "auto_approve_zero_charge": false,
            "auto_approve_after_payment": false,
            "auto_approve_refund_request": false,
            "auto_approve_refund_statuses": [
                "payment_complete",
                "preparing"
            ],
            "max_images": 5,
            "image_max_size_kb": 2048,
            "request_period_days": 7,
            "complete_defaults": {
                "create_refund_request": true,
                "restore_stock": true
            },
            "exchange_reasons": [
                {
                    "id": 11,
                    "type": "exchange",
                    "code": "size_mismatch",
                    "name": {
                        "ko": "사이즈가 맞지 않음",
                        "en": "Size Does Not Fit"
                    },
                    "localized_name": "사이즈가 맞지 않음",
                    "fault_type": "customer",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                }
            ,
              "... (총 8건 중 1건 표시)"
            ],
            "return_reasons": [
                {
                    "id": 21,
                    "type": "return",
                    "code": "defective_product",
                    "name": {
                        "ko": "상품 불량/파손",
                        "en": "Defective or Damaged Product"
                    },
                    "localized_name": "상품 불량/파손",
                    "fault_type": "seller",
                    "is_user_selectable": true,
                    "is_active": true,
                    "sort_order": 0
                }
            ,
              "... (총 9건 중 1건 표시)"
            ],
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
| 500 | Internal Server Error | 서버 내부 오류 — 도메인 규칙 위반이 아닌 예외(인프라 장애·코드 결함)는 4xx 로 뭉개지 않고 500 으로 구분한다 |

<!-- @generated:end -->

**추가 에러 응답** (생성기가 관리하는 위 표 밖에 둔다 — 재생성 시 사라지지 않게)

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | DB 동기화 대상이 도메인 규칙에 걸린 경우 — 주문에서 사용 중인 배송유형·배송사를 payload 에서 빼 삭제하려 한 경우, 사용 중인 **배송유형의 `code` 를 바꾸려** 한 경우, 또는 신청서에서 사용 중인 클레임 사유를 payload 에서 **빼 삭제**하거나 그 사유의 **`code` 를 바꾸려** 한 경우. `message` 에 대상 이름과 사용 건수가 담긴다. 세 카탈로그 모두 **설정 파일 쓰기 전에** 판정하므로, 이 400 을 받았을 때 같은 요청의 다른 설정도 저장되지 않았다 |

**삭제 차단 사유의 구분** `shipping.types`·`shipping.carriers` 와 클레임 사유 카탈로그 3종(`claim.exchange_reasons`·`claim.return_reasons`·`claim.refund_reasons`)은 payload 에서 항목을 빼는 방식으로 삭제합니다. 그 항목이 사용 중이면 삭제가 거부되는데, 이때는 **400** 과 함께 사유(대상 이름·사용 건수)가 `message` 로 전달됩니다. 저장 자체가 서버 결함으로 실패한 경우의 500(일반 저장 오류 문구)과 구분되므로, 운영자는 화면 문구만으로 "내가 고칠 수 있는 일인지"를 판별할 수 있습니다.

세 카탈로그(배송유형·배송사·클레임 사유) 모두 **설정 파일을 쓰기 전에** 판정합니다. 설정 저장은 JSON 파일 쓰기라 트랜잭션으로 되돌릴 수 없어, 뒤에서 거절되면 스칼라 설정만 반영된 반쪽 상태가 남기 때문입니다. 따라서 이 400 을 받았을 때 **같은 요청의 다른 설정도 저장되지 않았습니다**.

**차단 사유는 삭제만이 아닙니다.** 같은 판정이 **사용 중인 사유의 `code` 변경**도 거절합니다 — 접수된 신청서는 사유를 `code` 문자열로만 기억하므로(`reason_id` 외래키가 없습니다) 오타 교정처럼 평범한 수정 한 번으로 그 사유로 접수된 전건이 사유 이름을 잃고 내부 코드가 화면에 드러납니다. 삭제를 막아 방지하려던 피해와 결과가 같아 같은 강도로 막습니다.

같은 이유로 **사용 중인 `shipping.types` 항목의 `code` 변경**도 거절합니다. 주문 배송행은 배송유형을 `shipping_type` 코드 문자열로만 기억하므로, 코드를 바꾸면 기존 주문의 배송방법 표기가 비고 그 코드가 실린 목록 URL 이 422 가 되며, 새 코드 기준 사용 건수가 0 이 되어 삭제 차단마저 우회됩니다. 배송사(`shipping.carriers`)는 주문이 `id` 로 참조하므로 이 제약이 없습니다.

조치는 어느 쪽이냐에 따라 다릅니다.

- **삭제하려던 경우**: 그 사유를 payload 에 되돌려 두고 다시 저장합니다.
- **`code` 를 바꾸려던 경우**: `code` 를 원래 값으로 되돌리고 나머지 필드만 저장합니다. **사유명·귀책 구분·노출/활성/정렬은 사용 중에도 계속 수정할 수 있습니다.** 코드 체계를 정말 바꿔야 한다면 새 코드로 사유를 추가하고 기존 사유를 비노출·비활성으로 내리십시오 — 기존 신청서의 라벨이 보존됩니다.

사용 건수는 그 사유의 **유형에 맞는 표 전수**를 셉니다. 환불 유형은 환불신청(`order_refunds`)과 즉시취소(`order_cancels`) 두 표를 합산하므로, 즉시취소로만 쓰인 사유도 사용 중으로 판정됩니다.

**설명** 관리자가 이커머스 환경설정을 저장합니다. `permission:sirsoft-ecommerce.settings.update` 권한이 필요하며, `_tab` 으로 저장할 카테고리를 지정하고 각 섹션(`basic_info`·`shipping`·`claim` 등)을 배열로 전달합니다. `EcommerceSettingsService::saveSettings()`가 JSON 설정을 저장하되, DB 관리 대상인 `shipping.carriers`·`shipping.types` 와 클레임 사유 카탈로그 3종(`claim.exchange_reasons`·`claim.return_reasons`·`claim.refund_reasons`)은 분리해 각 Service 의 sync 메서드로 동기화합니다. 사유 카탈로그의 삭제 가드는 그 사유의 **유형에 맞는 표 전수**를 세므로, 교환·반품에서 사용 중인 사유는 이 경로로도 지워지지 않습니다. 저장 성공 시 `sirsoft-ecommerce.settings.after_save` 훅을 발화하고, 관리자 UI 상태 갱신을 위해 병합된 전체 설정을 다시 반환합니다(쓸 수 없게 된 공개 자산 디스크 정리는 조회와 같습니다). `basic_info.public_asset_disk_unavailable` 는 조회 응답의 표시용 값이라 함께 보내도 저장되지 않습니다. 배송 탭에 새로 넣는 배송사·배송유형 행(`id` 없음)의 다국어 이름에서 사이트 기본 언어(환경설정 「기본 언어」) 값을 비우면 422 `shipping.carriers.*.name`·`shipping.types.*.name` 오류가 칸 이름과 언어 이름을 함께 알립니다(「배송사명의 한국어(ko) 값은 필수입니다.」·「배송유형명의 한국어(ko) 값은 필수입니다.」). 기존 행은 이 검사에서 빠집니다.

**섹션은 통째로 교체됩니다 (부분 병합 아님)** 저장 범위를 정하는 것은 **payload 에 실린 섹션 키**입니다 — `_tab` 은 저장 대상을 고르지 않으며(검증된 카테고리 목록에서 제외되고 저장 단계에서도 `_` 로 시작하는 키는 건너뜁니다) 화면이 어느 탭에서 보냈는지를 기록하는 메타 값입니다. 그리고 보낸 섹션 안에서는 **보낸 키만 남습니다**. 저장은 카테고리 단위 JSON 파일 쓰기이며 기존 저장본과 키 단위로 병합하지 않으므로, 요청에서 빠진 키는 저장 파일에서 사라지고 조회 시 `defaults.json` 의 기본값으로 되돌아갑니다. 응답은 **200** 이고 오류도 경고도 없습니다.

- 예: `{"_tab":"claim","claim":{"max_images":10}}` 로 저장하면 운영자가 앞서 지정한 `allowed_charge_methods`·`auto_approve_*` 등 나머지 클레임 축이 기본값으로 되돌아갑니다. `instant_cancel_statuses`는 주문 취소 설정의 별칭이므로 주문 설정을 따릅니다
- `{"_tab":"claim","claim":{…},"shipping":{…}}` 처럼 두 섹션을 함께 보내면 **둘 다 저장됩니다**. `_tab` 이 `claim` 이라고 해서 shipping 이 무시되지 않으며, 그 shipping 섹션도 보낸 키만 남아 나머지는 기본값으로 되돌아갑니다
- 따라서 클라이언트는 **그 섹션 전체를 다시 실어 보내야 합니다**. 관리자 화면은 조회 응답을 폼 상태로 들고 있다가 섹션 전체를 전송합니다
- 파라미터 표의 `필수` 열이 "아니오" 인 것은 *그 섹션을 저장하지 않을 때 생략 가능* 하다는 뜻이지, 보낸 섹션 안에서 개별 키를 생략해도 보존된다는 뜻이 아닙니다
- DB 관리 대상(`shipping.carriers`·`shipping.types` 와 클레임 사유 카탈로그 3종)은 이 규칙 밖입니다 — 키 자체를 보내지 않으면 동기화가 일어나지 않아 기존 목록이 유지됩니다. 다만 **빈 배열을 보내면 "전부 삭제" 요청**으로 해석되므로, 조회 응답에 그 키가 없다고 해서 `[]` 로 채워 보내면 안 됩니다

**취소 정책 별칭의 예외** `order_settings.cancellable_statuses` 또는 `claim.instant_cancel_statuses`를 저장하면 두 카테고리의 다른 저장값을 보존하면서 두 키를 함께 갱신합니다. 별칭 없는 일반 섹션 저장은 위의 교체 규칙을 따릅니다. 내부 개별 설정 메서드(`updateSetting`, 현재 HTTP 라우트 없음)도 같은 값 검증·숫자 정규화를 거치며 DB 카탈로그는 전체 저장의 동기화와 사용 중 삭제 검사를 공유합니다. 개별 설정 키가 저장 규칙이 다스리지 않는 키(예: `claim.not_a_real_key`)이면 원시값을 저장하지 않고 `key` 오류로 **422** 를 돌려줍니다. `claim.auto_approve_refund_payment_methods` 를 개별 저장해도 전체 저장과 같은 운영자 결정 이력이 남습니다.

**서버 전용 결정 이력** `claim.auto_approve_refund_payment_methods_decided`(환불 자동 승인 결제수단의 운영자 결정 이력)는 서버가 `claim.auto_approve_refund_payment_methods` 저장 직전에 채우는 값입니다. 요청에 값을 실으면 **422**(`prohibited`)이고, 개별 설정 키로 지정해도 **422** 입니다. 관리자 조회 응답(`GET /admin/settings`, `GET /admin/settings/claim`)에는 이 키가 실리지 않으므로, 조회한 claim 섹션을 그대로 되보내도 저장됩니다.

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
| banks | body | array | 아니오 | — | 무통장입금용 은행 목록 (은행 코드·다국어 은행명 — 사이트 기본 언어 은행명 필수, 요청 언어와 무관) |

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
| language_currency | object | `{"default_currency":"KRW","currencies":[{"code":"KRW","na…` | 통화 설정 (기본 통화 + 등록 통화 목록: 코드·다국어명·환율·기호·국기·반올림 규칙). `removed_default_currencies` 는 관리자가 삭제한 기본 제공 통화 코드 목록으로, 서버가 저장 시점에 도출해 기록한다 (관리자 응답 전용 — 공개 설정에는 노출되지 않음) |
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료·현금영수증 발급 제공자·자진발급·배송비 과세 방식 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":"inquiry"}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| privacy_retention | object | `{"enabled":false,"retention_years":5,"mode":"anonymize"}` | 종결 주문 개인정보 보유기간 설정(이슈 #636) — 사용 여부(기본 false)·보유기간(년, 최소 5)·처리 방식(`anonymize`/`delete`) |

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


### GET /api/modules/sirsoft-ecommerce/admin/settings/privacy-retention-preview
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.settings.privacy-retention-preview -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.settings.privacy-retention-preview`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController@privacyRetentionPreview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/settings/privacy-retention-preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| orders | integer | `42` | 보유기간이 지난 미처리 종결 주문 수(휴지통 포함). **꺼져 있어도 센다**(저장된 기간값 기준) |
| enabled | boolean | `false` | 보유기간 처리가 켜져 있는지 — 「꺼짐」과 「대상 0건」을 이 값으로 구분한다 |
| can_view_targets | boolean | `true` | 요청 관리자가 「더보기」 명단을 볼 권한(`sirsoft-ecommerce.settings.read` + `sirsoft-ecommerce.orders.read`)이 있는지. 명단은 코어 `GET /api/admin/privacy/retention-targets/sirsoft-ecommerce.order` |
| mode | string | `anonymize` | 저장된 처리 방식(`anonymize`/`delete`). 알 수 없는 값은 `anonymize` 로 떨어진다 |
| retention_years | integer | `5` | 판독부 바닥값(5년)을 적용한 보유기간(년) |

**응답 예시**

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "orders": 42,
        "enabled": false,
        "mode": "anonymize",
        "retention_years": 5,
        "can_view_targets": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |
| 500 | Internal Server Error | 건수 조회 중 오류가 발생한 경우 (`messages.settings.fetch_failed`) |

<!-- @generated:end -->

**설명** 종결 주문 개인정보 보유기간 처리 대상 건수를 미리 조회합니다(이슈 #636). 「개인정보 보유기간」 탭의 대상 건수 뱃지가 쓰며, 코어 보유기간 커맨드의 `--dry-run` 과 **같은 조건식**(`OrderRetentionHandler::preview()`)을 지나 화면 숫자와 실제 처리 건수가 같습니다. **저장된 설정 기준**이라 값을 바꾼 뒤 저장하기 전에는 이전 설정의 건수입니다. **꺼져 있어도 건수를 셉니다** — 켜기 전에 규모를 확인하는 자리입니다. 「꺼짐」과 「대상 0건」은 `enabled` 로 구분합니다. 주문 명단은 코어의 공통 대상 목록 API(`/api/admin/privacy/retention-targets/sirsoft-ecommerce.order`)가 이 모듈 핸들러를 식별자로 찾아 내려주며, 주문 읽기 권한이 없으면 403 입니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| category | string | `"basic_info"` | 조회한 설정 카테고리 (path 파라미터를 그대로 반향) |
| settings | object | `{"shop_name":"","route_path":"shop","no_route":false}` | 해당 카테고리의 설정값. 구조는 카테고리마다 다르며 index 응답의 동명 최상위 키와 동일하다 (`order_settings` 조회 시 결제수단은 병합된 목록 — 공급 확장이 빠진 고아 항목 포함) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 조회했습니다.",
    "data": {
        "category": "basic_info",
        "settings": {
            "shop_name": "",
            "route_path": "shop",
            "no_route": false
        },
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 단일 설정 카테고리만 골라 조회합니다. `permission:sirsoft-ecommerce.settings.read` 권한이 필요하며, path의 `category`(예 `basic_info`)에 해당하는 섹션을 반환합니다. 전체 조회와 동일하게 DB의 배송사·배송유형·클레임 사유 및 알림 채널을 보강합니다. 따라서 `claim` 응답에도 사유 카탈로그 3종이 포함됩니다. 응답은 `category`·`settings`·`abilities.can_update`를 포함합니다.


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
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 체크아웃용 주문/결제 설정 (기본 PG·활성 결제수단·무통장 계좌 등). `payment_methods` 는 현재 제공 가능한 결제수단만 포함하며, 공급 확장이 더 이상 제공하지 않는 결제수단(관리자 화면의 고아 항목)은 `is_active` 가 참이어도 제외된다 |

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
| order_settings | object | `{"default_pg_provider":null,"cash_receipt_provider":"toss…` | 공개 가능한 결제 설정 (활성 결제수단·무통장 은행명 매핑 포함, 민감 정보 제외). `payment_methods` 는 현재 제공 가능한 결제수단만 포함하며, 공급 확장이 더 이상 제공하지 않는 결제수단(관리자 화면의 고아 항목)은 `is_active` 가 참이어도 제외된다. **지정된 PG 사가 현재 등록되어 있지 않은 결제수단도 같은 이유로 제외된다** — 수단 자체는 카탈로그에 남아 있지만 주문 시 PG 라우팅이 매칭에 실패해 결제창 없이 주문이 완료되기 때문이다. 유효 PG 판정은 결제수단의 `pg_provider` 가 비어 있을 때만 `default_pg_provider` 로 폴백하며(런타임 라우팅과 동일), 양쪽 모두 미설정이면 PG 비경유 수단으로 종전대로 노출된다. `default_pg_provider` 와 `cash_receipt_provider` 도 등록되지 않은 값이면 `null` 로 정규화된다. 관리자 응답(`GET admin/settings`)은 이 필터를 적용하지 않고 `_orphaned` / `_orphaned_pg` 플래그를 그대로 실어 운영자가 확인·수정할 수 있게 한다 |

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



## 환율 제공자 계약 (1.3.0)

설정 조회·일괄 저장 응답은 `data.available_rate_providers`(등록 제공자 배열)와 `data.active_rate_provider`(선택된 등록 제공자 또는 null)를 포함합니다. 제공자는 `id`, `name`, `managed_codes`, `unsupported_codes`, `settings_url`, `last_updated_at`, `last_updated_at_formatted`(사이트 시간대의 표시용 시각), `notice_key` 등을 전달합니다. 등록만으로 자동 선택하지 않습니다.

`language_currency.rate_provider`는 null 또는 등록된 제공자 ID(최대 50자)입니다. 비활성화된 기존 선택값은 변경하지 않는 경우 보존할 수 있습니다. 관리 중인 기존 통화의 환율 변경은 422로 거부하며 숫자 문자열과 수치의 차이는 허용오차 1e-9로 비교합니다. 통화 추가·삭제·기본 통화 변경과 기타 필드는 계속 허용합니다. 단건 설정 저장의 `key=language_currency` 및 하위 키에도 같은 검증이 적용됩니다.
