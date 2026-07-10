# Settings API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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
| order_settings | object | `{"default_pg_provider":null,"payment_methods":[{"id":"car…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":null}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| claim | object | `{"refund_reasons":[{"id":1,"type":"refund","code":"order_…` | 클레임 설정 (DB 관리 대상인 환불 사유 목록: 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[{"id":"kginicis","name_key":"sirsoft-pay_kginicis::provi…` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
    "data": {
        "basic_info": {
            "shop_name": "",
            "route_path": "shop",
            "no_route": false,
            "company_name": "",
            "business_number": "",
            "ceo_name": "",
            "business_type": "",
            "business_category": "",
            "zipcode": "",
            "base_address": "",
            "detail_address": "",
            "phone": "",
            "fax": "",
            "email": "",
            "privacy_officer": "",
            "privacy_officer_email": "",
            "mail_order_number": "",
            "telecom_number": ""
        },
        "language_currency": {
            "default_currency": "KRW",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)",
                        "fr": "KRW (원)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": true,
                    "locales": [
                        "ko"
                    ],
                    "flag": "🇰🇷"
                },
                {
                    "code": "USD",
                    "name": {
                        "ko": "USD (달러)",
                        "en": "USD (Dollar)",
                        "fr": "USD (달러)"
                    },
                    "symbol": "$",
                    "exchange_rate": 0.85,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇺🇸"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ko": "JPY (엔)",
                        "en": "JPY (Yen)",
                        "fr": "JPY (엔)"
                    },
                    "symbol": "¥",
                    "exchange_rate": 115,
                    "base_unit": 100,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇯🇵"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)",
                        "fr": "CNY (위안)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇨🇳"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)",
                        "fr": "EUR (유로)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇪🇺"
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
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "신용카드",
                        "en": "Credit Card",
                        "fr": "신용카드"
                    },
                    "_cached_description": {
                        "ko": "신용카드로 안전하게 결제",
                        "en": "Pay securely with credit card",
                        "fr": "신용카드로 안전하게 결제"
                    },
                    "_cached_icon": "credit-card",
                    "_cached_source": "builtin"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "가상계좌",
                        "en": "Virtual Account",
                        "fr": "가상계좌"
                    },
                    "_cached_description": {
                        "ko": "가상계좌로 입금",
                        "en": "Pay via virtual account",
                        "fr": "가상계좌로 입금"
                    },
                    "_cached_icon": "money-check",
                    "_cached_source": "builtin"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "무통장입금",
                        "en": "Bank Transfer",
                        "fr": "무통장입금"
                    },
                    "_cached_description": {
                        "ko": "지정 계좌로 직접 입금",
                        "en": "Direct bank transfer",
                        "fr": "지정 계좌로 직접 입금"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "bank",
                    "pg_provider": null,
                    "sort_order": 4,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "계좌이체",
                        "en": "Account Transfer",
                        "fr": "계좌이체"
                    },
                    "_cached_description": {
                        "ko": "실시간 계좌이체",
                        "en": "Real-time bank transfer",
                        "fr": "실시간 계좌이체"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "phone",
                    "pg_provider": null,
                    "sort_order": 5,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "휴대폰결제",
                        "en": "Mobile Payment",
                        "fr": "휴대폰결제"
                    },
                    "_cached_description": {
                        "ko": "휴대폰 소액결제",
                        "en": "Mobile phone payment",
                        "fr": "휴대폰 소액결제"
                    },
                    "_cached_icon": "mobile-screen-button",
                    "_cached_source": "builtin"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 6,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "포인트결제",
                        "en": "Points",
                        "fr": "포인트결제"
                    },
                    "_cached_description": {
                        "ko": "적립 포인트로 결제",
                        "en": "Pay with points",
                        "fr": "적립 포인트로 결제"
                    },
                    "_cached_icon": "coins",
                    "_cached_source": "builtin"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 7,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "예치금결제",
                        "en": "Store Credit",
                        "fr": "예치금결제"
                    },
                    "_cached_description": {
                        "ko": "예치금으로 결제",
                        "en": "Pay with store credit",
                        "fr": "예치금으로 결제"
                    },
                    "_cached_icon": "wallet",
                    "_cached_source": "builtin"
                },
                {
                    "id": "free",
                    "pg_provider": null,
                    "sort_order": 8,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "무료",
                        "en": "Free",
                        "fr": "무료"
                    },
                    "_cached_description": {
                        "ko": "결제 없이 주문 완료",
                        "en": "Order without payment",
                        "fr": "결제 없이 주문 완료"
                    },
                    "_cached_icon": "gift",
                    "_cached_source": "builtin"
                }
            ],
            "banks": [
                {
                    "code": "004",
                    "name": {
                        "ko": "국민은행",
                        "en": "Kookmin Bank"
                    }
                },
                {
                    "code": "088",
                    "name": {
                        "ko": "신한은행",
                        "en": "Shinhan Bank"
                    }
                },
                {
                    "code": "020",
                    "name": {
                        "ko": "우리은행",
                        "en": "Woori Bank"
                    }
                },
                {
                    "code": "081",
                    "name": {
                        "ko": "하나은행",
                        "en": "Hana Bank"
                    }
                },
                {
                    "code": "003",
                    "name": {
                        "ko": "IBK기업은행",
                        "en": "IBK Industrial Bank"
                    }
                },
                {
                    "code": "011",
                    "name": {
                        "ko": "NH농협은행",
                        "en": "NH Nonghyup Bank"
                    }
                },
                {
                    "code": "071",
                    "name": {
                        "ko": "우체국",
                        "en": "Korea Post"
                    }
                },
                {
                    "code": "031",
                    "name": {
                        "ko": "DGB대구은행",
                        "en": "DGB Daegu Bank"
                    }
                },
                {
                    "code": "032",
                    "name": {
                        "ko": "BNK부산은행",
                        "en": "BNK Busan Bank"
                    }
                },
                {
                    "code": "034",
                    "name": {
                        "ko": "광주은행",
                        "en": "Kwangju Bank"
                    }
                },
                {
                    "code": "035",
                    "name": {
                        "ko": "제주은행",
                        "en": "Jeju Bank"
                    }
                },
                {
                    "code": "037",
                    "name": {
                        "ko": "전북은행",
                        "en": "Jeonbuk Bank"
                    }
                },
                {
                    "code": "039",
                    "name": {
                        "ko": "BNK경남은행",
                        "en": "BNK Kyongnam Bank"
                    }
                },
                {
                    "code": "045",
                    "name": {
                        "ko": "새마을금고",
                        "en": "MG Community Credit Cooperatives"
                    }
                },
                {
                    "code": "048",
                    "name": {
                        "ko": "신협",
                        "en": "KFCC"
                    }
                },
                {
                    "code": "090",
                    "name": {
                        "ko": "카카오뱅크",
                        "en": "Kakao Bank"
                    }
                },
                {
                    "code": "092",
                    "name": {
                        "ko": "토스뱅크",
                        "en": "Toss Bank"
                    }
                }
            ],
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
            "auto_cancel_days": 3,
            "cart_expiry_days": 30,
            "stock_restore_on_cancel": true,
            "cancellable_statuses": [
                "payment_complete"
            ],
            "confirmable_statuses": [
                "shipping",
                "delivered"
            ]
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea",
                        "fr": "대한민국"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States",
                        "fr": "미국"
                    },
                    "is_active": false
                },
                {
                    "code": "JP",
                    "name": {
                        "ko": "일본",
                        "en": "Japan",
                        "fr": "일본"
                    },
                    "is_active": false
                },
                {
                    "code": "CN",
                    "name": {
                        "ko": "중국",
                        "en": "China",
                        "fr": "중국"
                    },
                    "is_active": false
                },
                {
                    "code": "SG",
                    "name": {
                        "ko": "싱가포르",
                        "en": "Singapore",
                        "fr": "싱가포르"
                    },
                    "is_active": false
                },
                {
                    "code": "HK",
                    "name": {
                        "ko": "홍콩",
                        "en": "Hong Kong",
                        "fr": "홍콩"
                    },
                    "is_active": false
                },
                {
                    "code": "TW",
                    "name": {
                        "ko": "대만",
                        "en": "Taiwan",
                        "fr": "대만"
                    },
                    "is_active": false
                },
                {
                    "code": "VN",
                    "name": {
                        "ko": "베트남",
                        "en": "Vietnam",
                        "fr": "베트남"
                    },
                    "is_active": false
                },
                {
                    "code": "TH",
                    "name": {
                        "ko": "태국",
                        "en": "Thailand",
                        "fr": "태국"
                    },
                    "is_active": false
                },
                {
                    "code": "MY",
                    "name": {
                        "ko": "말레이시아",
                        "en": "Malaysia",
                        "fr": "말레이시아"
                    },
                    "is_active": false
                }
            ],
            "international_shipping_enabled": false,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao",
            "carriers": [
                {
                    "id": 13,
                    "code": "apidoc",
                    "name": {
                        "ko": "API 문서 샘플 배송사",
                        "en": "API Doc Sample Carrier"
                    },
                    "type": "domestic",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 1,
                    "code": "cj",
                    "name": {
                        "ko": "CJ대한통운",
                        "en": "CJ Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": 2,
                    "code": "hanjin",
                    "name": {
                        "ko": "한진택배",
                        "en": "Hanjin Express"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}",
                    "is_active": true,
                    "sort_order": 2
                },
                {
                    "id": 3,
                    "code": "lotte",
                    "name": {
                        "ko": "롯데택배",
                        "en": "Lotte Global Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}",
                    "is_active": true,
                    "sort_order": 3
                },
                {
                    "id": 4,
                    "code": "logen",
                    "name": {
                        "ko": "로젠택배",
                        "en": "Logen Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.ilogen.com/web/personal/trace/{tracking_number}",
                    "is_active": true,
                    "sort_order": 4
                },
                {
                    "id": 5,
                    "code": "ups",
                    "name": {
                        "ko": "UPS",
                        "en": "UPS"
                    },
                    "type": "international",
                    "tracking_url": "https://www.ups.com/track?tracknum={tracking_number}",
                    "is_active": true,
                    "sort_order": 5
                },
                {
                    "id": 6,
                    "code": "ems",
                    "name": {
                        "ko": "EMS",
                        "en": "EMS"
                    },
                    "type": "international",
                    "tracking_url": "https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}",
                    "is_active": true,
                    "sort_order": 6
                },
                {
                    "id": 7,
                    "code": "dhl",
                    "name": {
                        "ko": "DHL",
                        "en": "DHL"
                    },
                    "type": "international",
                    "tracking_url": "https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}",
                    "is_active": true,
                    "sort_order": 7
                },
                {
                    "id": 8,
                    "code": "fedex",
                    "name": {
                        "ko": "FedEx",
                        "en": "FedEx"
                    },
                    "type": "international",
                    "tracking_url": "https://www.fedex.com/fedextrack/?tracknumbers={tracking_number}",
                    "is_active": true,
                    "sort_order": 8
                },
                {
                    "id": 9,
                    "code": "sf",
                    "name": {
                        "ko": "SF Express",
                        "en": "SF Express"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 9
                },
                {
                    "id": 10,
                    "code": "yamato",
                    "name": {
                        "ko": "야마토운수",
                        "en": "Yamato Transport"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 10
                },
                {
                    "id": 11,
                    "code": "sagawa",
                    "name": {
                        "ko": "사가와익스프레스",
                        "en": "Sagawa Express"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 11
                },
                {
                    "id": 12,
                    "code": "other",
                    "name": {
                        "ko": "기타",
                        "en": "Other"
                    },
                    "type": "domestic",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 99
                }
            ],
            "types": [],
            "api_request_fields": [
                {
                    "value": "policy_id",
                    "label": "배송정책 ID"
                },
                {
                    "value": "country_code",
                    "label": "국가 코드"
                },
                {
                    "value": "items",
                    "label": "주문 항목"
                },
                {
                    "value": "group_total",
                    "label": "그룹 합계 금액"
                },
                {
                    "value": "total_quantity",
                    "label": "총 수량"
                }
            ],
            "api_http_methods": [
                {
                    "value": "GET",
                    "label": "GET"
                },
                {
                    "value": "POST",
                    "label": "POST"
                }
            ],
            "api_auth_types": [
                {
                    "value": "none",
                    "label": "인증 없음"
                },
                {
                    "value": "bearer",
                    "label": "Bearer 토큰"
                },
                {
                    "value": "custom_header",
                    "label": "커스텀 헤더"
                }
            ],
            "api_response_types": [
                {
                    "value": "json",
                    "label": "JSON"
                },
                {
                    "value": "text",
                    "label": "텍스트"
                }
            ]
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "meta_product_description": "",
            "meta_shop_index_title": "{commerce_name}",
            "meta_shop_index_description": "",
            "seo_category": true,
            "seo_search_result": true,
            "seo_product_detail": true,
            "seo_shop_index": true
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
                    "max_use_value": 50000
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
| basic_info | body | array | 아니오 | — | 쇼핑몰 기본 정보 섹션 (쇼핑몰명·라우트 경로·상호·사업자번호·주소·연락처 등) |
| language_currency | body | array | 아니오 | — | 통화 설정 섹션 (기본 통화·통화 목록: 코드·다국어명·환율·반올림 규칙·통화별 로케일) |
| seo | body | array | 아니오 | — | SEO 메타 설정 섹션 (페이지 유형별 메타 타이틀/설명·SEO 활성 토글) |
| inquiry | body | array | 아니오 | — | 문의 연동 설정 섹션 (문의 게시판 slug) |
| order_settings | body | array | 아니오 | — | 주문/결제 설정 섹션 (기본 PG·결제수단·은행/무통장 계좌·자동취소·장바구니 만료 등) |
| claim | body | array | 아니오 | — | 클레임 설정 섹션 (환불 사유 목록, DB 동기화 대상으로 분리 저장) |
| review_settings | body | array | 아니오 | — | 리뷰 정책 섹션 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| mileage | body | array | 아니오 | — | 마일리지 설정 섹션 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림) |
| shipping | body | array | 아니오 | — | 배송 설정 섹션 (기본 국가·배송 가능 국가·무료배송·배송사(carriers)·배송유형(types) — carriers/types는 DB 동기화 대상으로 분리 저장) |

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
    "basic_info": [
        "예시값"
    ],
    "language_currency": [
        "예시값"
    ],
    "seo": [
        "예시값"
    ],
    "inquiry": [
        "예시값"
    ],
    "order_settings": [
        "예시값"
    ],
    "claim": [
        "예시값"
    ],
    "review_settings": [
        "예시값"
    ],
    "mileage": [
        "예시값"
    ],
    "shipping": [
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
| order_settings | object | `{"default_pg_provider":null,"payment_methods":[{"id":"car…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":null}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |
| claim | object | `{"refund_reasons":[{"id":1,"type":"refund","code":"order_…` | 클레임 설정 (DB 관리 대상인 환불 사유 목록: 코드·다국어명·귀책 유형·노출/활성 여부) |
| available_pg_providers | array | `[]` | 설치된 PG 플러그인이 훅으로 등록한 PG 제공자 목록 (id·name_key·지원 결제수단) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.save_success",
    "data": {
        "basic_info": {
            "shop_name": "",
            "route_path": "shop",
            "no_route": false,
            "company_name": "",
            "business_number": "",
            "ceo_name": "",
            "business_type": "",
            "business_category": "",
            "zipcode": "",
            "base_address": "",
            "detail_address": "",
            "phone": "",
            "fax": "",
            "email": "",
            "privacy_officer": "",
            "privacy_officer_email": "",
            "mail_order_number": "",
            "telecom_number": ""
        },
        "language_currency": {
            "default_currency": "KRW",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)",
                        "fr": "KRW (원)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": true,
                    "locales": [
                        "ko"
                    ],
                    "flag": "🇰🇷"
                },
                {
                    "code": "USD",
                    "name": {
                        "ko": "USD (달러)",
                        "en": "USD (Dollar)",
                        "fr": "USD (달러)"
                    },
                    "symbol": "$",
                    "exchange_rate": 0.85,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇺🇸"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ko": "JPY (엔)",
                        "en": "JPY (Yen)",
                        "fr": "JPY (엔)"
                    },
                    "symbol": "¥",
                    "exchange_rate": 115,
                    "base_unit": 100,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇯🇵"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)",
                        "fr": "CNY (위안)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇨🇳"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)",
                        "fr": "EUR (유로)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇪🇺"
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
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "신용카드",
                        "en": "Credit Card",
                        "fr": "신용카드"
                    },
                    "_cached_description": {
                        "ko": "신용카드로 안전하게 결제",
                        "en": "Pay securely with credit card",
                        "fr": "신용카드로 안전하게 결제"
                    },
                    "_cached_icon": "credit-card",
                    "_cached_source": "builtin"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "가상계좌",
                        "en": "Virtual Account",
                        "fr": "가상계좌"
                    },
                    "_cached_description": {
                        "ko": "가상계좌로 입금",
                        "en": "Pay via virtual account",
                        "fr": "가상계좌로 입금"
                    },
                    "_cached_icon": "money-check",
                    "_cached_source": "builtin"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "무통장입금",
                        "en": "Bank Transfer",
                        "fr": "무통장입금"
                    },
                    "_cached_description": {
                        "ko": "지정 계좌로 직접 입금",
                        "en": "Direct bank transfer",
                        "fr": "지정 계좌로 직접 입금"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "bank",
                    "pg_provider": null,
                    "sort_order": 4,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "계좌이체",
                        "en": "Account Transfer",
                        "fr": "계좌이체"
                    },
                    "_cached_description": {
                        "ko": "실시간 계좌이체",
                        "en": "Real-time bank transfer",
                        "fr": "실시간 계좌이체"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "phone",
                    "pg_provider": null,
                    "sort_order": 5,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "휴대폰결제",
                        "en": "Mobile Payment",
                        "fr": "휴대폰결제"
                    },
                    "_cached_description": {
                        "ko": "휴대폰 소액결제",
                        "en": "Mobile phone payment",
                        "fr": "휴대폰 소액결제"
                    },
                    "_cached_icon": "mobile-screen-button",
                    "_cached_source": "builtin"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 6,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "포인트결제",
                        "en": "Points",
                        "fr": "포인트결제"
                    },
                    "_cached_description": {
                        "ko": "적립 포인트로 결제",
                        "en": "Pay with points",
                        "fr": "적립 포인트로 결제"
                    },
                    "_cached_icon": "coins",
                    "_cached_source": "builtin"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 7,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "예치금결제",
                        "en": "Store Credit",
                        "fr": "예치금결제"
                    },
                    "_cached_description": {
                        "ko": "예치금으로 결제",
                        "en": "Pay with store credit",
                        "fr": "예치금으로 결제"
                    },
                    "_cached_icon": "wallet",
                    "_cached_source": "builtin"
                },
                {
                    "id": "free",
                    "pg_provider": null,
                    "sort_order": 8,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "무료",
                        "en": "Free",
                        "fr": "무료"
                    },
                    "_cached_description": {
                        "ko": "결제 없이 주문 완료",
                        "en": "Order without payment",
                        "fr": "결제 없이 주문 완료"
                    },
                    "_cached_icon": "gift",
                    "_cached_source": "builtin"
                }
            ],
            "banks": [
                {
                    "code": "004",
                    "name": {
                        "ko": "국민은행",
                        "en": "Kookmin Bank"
                    }
                },
                {
                    "code": "088",
                    "name": {
                        "ko": "신한은행",
                        "en": "Shinhan Bank"
                    }
                },
                {
                    "code": "020",
                    "name": {
                        "ko": "우리은행",
                        "en": "Woori Bank"
                    }
                },
                {
                    "code": "081",
                    "name": {
                        "ko": "하나은행",
                        "en": "Hana Bank"
                    }
                },
                {
                    "code": "003",
                    "name": {
                        "ko": "IBK기업은행",
                        "en": "IBK Industrial Bank"
                    }
                },
                {
                    "code": "011",
                    "name": {
                        "ko": "NH농협은행",
                        "en": "NH Nonghyup Bank"
                    }
                },
                {
                    "code": "071",
                    "name": {
                        "ko": "우체국",
                        "en": "Korea Post"
                    }
                },
                {
                    "code": "031",
                    "name": {
                        "ko": "DGB대구은행",
                        "en": "DGB Daegu Bank"
                    }
                },
                {
                    "code": "032",
                    "name": {
                        "ko": "BNK부산은행",
                        "en": "BNK Busan Bank"
                    }
                },
                {
                    "code": "034",
                    "name": {
                        "ko": "광주은행",
                        "en": "Kwangju Bank"
                    }
                },
                {
                    "code": "035",
                    "name": {
                        "ko": "제주은행",
                        "en": "Jeju Bank"
                    }
                },
                {
                    "code": "037",
                    "name": {
                        "ko": "전북은행",
                        "en": "Jeonbuk Bank"
                    }
                },
                {
                    "code": "039",
                    "name": {
                        "ko": "BNK경남은행",
                        "en": "BNK Kyongnam Bank"
                    }
                },
                {
                    "code": "045",
                    "name": {
                        "ko": "새마을금고",
                        "en": "MG Community Credit Cooperatives"
                    }
                },
                {
                    "code": "048",
                    "name": {
                        "ko": "신협",
                        "en": "KFCC"
                    }
                },
                {
                    "code": "090",
                    "name": {
                        "ko": "카카오뱅크",
                        "en": "Kakao Bank"
                    }
                },
                {
                    "code": "092",
                    "name": {
                        "ko": "토스뱅크",
                        "en": "Toss Bank"
                    }
                }
            ],
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
            "auto_cancel_days": 3,
            "cart_expiry_days": 30,
            "stock_restore_on_cancel": true,
            "cancellable_statuses": [
                "payment_complete"
            ],
            "confirmable_statuses": [
                "shipping",
                "delivered"
            ]
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea",
                        "fr": "대한민국"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States",
                        "fr": "미국"
                    },
                    "is_active": false
                },
                {
                    "code": "JP",
                    "name": {
                        "ko": "일본",
                        "en": "Japan",
                        "fr": "일본"
                    },
                    "is_active": false
                },
                {
                    "code": "CN",
                    "name": {
                        "ko": "중국",
                        "en": "China",
                        "fr": "중국"
                    },
                    "is_active": false
                },
                {
                    "code": "SG",
                    "name": {
                        "ko": "싱가포르",
                        "en": "Singapore",
                        "fr": "싱가포르"
                    },
                    "is_active": false
                },
                {
                    "code": "HK",
                    "name": {
                        "ko": "홍콩",
                        "en": "Hong Kong",
                        "fr": "홍콩"
                    },
                    "is_active": false
                },
                {
                    "code": "TW",
                    "name": {
                        "ko": "대만",
                        "en": "Taiwan",
                        "fr": "대만"
                    },
                    "is_active": false
                },
                {
                    "code": "VN",
                    "name": {
                        "ko": "베트남",
                        "en": "Vietnam",
                        "fr": "베트남"
                    },
                    "is_active": false
                },
                {
                    "code": "TH",
                    "name": {
                        "ko": "태국",
                        "en": "Thailand",
                        "fr": "태국"
                    },
                    "is_active": false
                },
                {
                    "code": "MY",
                    "name": {
                        "ko": "말레이시아",
                        "en": "Malaysia",
                        "fr": "말레이시아"
                    },
                    "is_active": false
                }
            ],
            "international_shipping_enabled": false,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao",
            "carriers": [
                {
                    "id": 13,
                    "code": "apidoc",
                    "name": {
                        "ko": "API 문서 샘플 배송사",
                        "en": "API Doc Sample Carrier"
                    },
                    "type": "domestic",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": 1,
                    "code": "cj",
                    "name": {
                        "ko": "CJ대한통운",
                        "en": "CJ Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://trace.cjlogistics.com/next/tracking.html?wblNo={tracking_number}",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": 2,
                    "code": "hanjin",
                    "name": {
                        "ko": "한진택배",
                        "en": "Hanjin Express"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.hanjin.com/kor/CMS/DeliveryMgr/WaybillResult.do?wblnb={tracking_number}",
                    "is_active": true,
                    "sort_order": 2
                },
                {
                    "id": 3,
                    "code": "lotte",
                    "name": {
                        "ko": "롯데택배",
                        "en": "Lotte Global Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.lotteglogis.com/home/reservation/tracking/link498?InvNo={tracking_number}",
                    "is_active": true,
                    "sort_order": 3
                },
                {
                    "id": 4,
                    "code": "logen",
                    "name": {
                        "ko": "로젠택배",
                        "en": "Logen Logistics"
                    },
                    "type": "domestic",
                    "tracking_url": "https://www.ilogen.com/web/personal/trace/{tracking_number}",
                    "is_active": true,
                    "sort_order": 4
                },
                {
                    "id": 5,
                    "code": "ups",
                    "name": {
                        "ko": "UPS",
                        "en": "UPS"
                    },
                    "type": "international",
                    "tracking_url": "https://www.ups.com/track?tracknum={tracking_number}",
                    "is_active": true,
                    "sort_order": 5
                },
                {
                    "id": 6,
                    "code": "ems",
                    "name": {
                        "ko": "EMS",
                        "en": "EMS"
                    },
                    "type": "international",
                    "tracking_url": "https://service.epost.go.kr/trace.RetrieveEmsRi498.postal?POST_CODE={tracking_number}",
                    "is_active": true,
                    "sort_order": 6
                },
                {
                    "id": 7,
                    "code": "dhl",
                    "name": {
                        "ko": "DHL",
                        "en": "DHL"
                    },
                    "type": "international",
                    "tracking_url": "https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id={tracking_number}",
                    "is_active": true,
                    "sort_order": 7
                },
                {
                    "id": 8,
                    "code": "fedex",
                    "name": {
                        "ko": "FedEx",
                        "en": "FedEx"
                    },
                    "type": "international",
                    "tracking_url": "https://www.fedex.com/fedextrack/?tracknumbers={tracking_number}",
                    "is_active": true,
                    "sort_order": 8
                },
                {
                    "id": 9,
                    "code": "sf",
                    "name": {
                        "ko": "SF Express",
                        "en": "SF Express"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 9
                },
                {
                    "id": 10,
                    "code": "yamato",
                    "name": {
                        "ko": "야마토운수",
                        "en": "Yamato Transport"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 10
                },
                {
                    "id": 11,
                    "code": "sagawa",
                    "name": {
                        "ko": "사가와익스프레스",
                        "en": "Sagawa Express"
                    },
                    "type": "international",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 11
                },
                {
                    "id": 12,
                    "code": "other",
                    "name": {
                        "ko": "기타",
                        "en": "Other"
                    },
                    "type": "domestic",
                    "tracking_url": null,
                    "is_active": true,
                    "sort_order": 99
                }
            ],
            "types": [],
            "api_request_fields": [
                {
                    "value": "policy_id",
                    "label": "배송정책 ID"
                },
                {
                    "value": "country_code",
                    "label": "국가 코드"
                },
                {
                    "value": "items",
                    "label": "주문 항목"
                },
                {
                    "value": "group_total",
                    "label": "그룹 합계 금액"
                },
                {
                    "value": "total_quantity",
                    "label": "총 수량"
                }
            ],
            "api_http_methods": [
                {
                    "value": "GET",
                    "label": "GET"
                },
                {
                    "value": "POST",
                    "label": "POST"
                }
            ],
            "api_auth_types": [
                {
                    "value": "none",
                    "label": "인증 없음"
                },
                {
                    "value": "bearer",
                    "label": "Bearer 토큰"
                },
                {
                    "value": "custom_header",
                    "label": "커스텀 헤더"
                }
            ],
            "api_response_types": [
                {
                    "value": "json",
                    "label": "JSON"
                },
                {
                    "value": "text",
                    "label": "텍스트"
                }
            ]
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "meta_product_description": "",
            "meta_shop_index_title": "{commerce_name}",
            "meta_shop_index_description": "",
            "seo_category": true,
            "seo_search_result": true,
            "seo_product_detail": true,
            "seo_shop_index": true
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
                    "max_use_value": 50000
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
| order_settings | object | `{"default_pg_provider":null,"payment_methods":[{"id":"car…` | 주문/결제 설정 (기본 PG·병합된 결제수단·은행/무통장 계좌·자동취소·장바구니 만료 등) |
| shipping | object | `{"default_country":"KR","available_countries":[{"code":"K…` | 배송 설정 (기본 국가·배송 가능 국가·무료배송·DB 관리 배송사(carriers)·배송유형(types)·계산 API 후보 필드 포함) |
| seo | object | `{"meta_category_title":"{commerce_name} - {category_name}…` | SEO 메타 설정 (카테고리·검색·상품·쇼핑몰 인덱스별 메타 타이틀/설명 및 SEO 활성 토글) |
| review_settings | object | `{"write_deadline_days":90,"max_images":5,"max_image_size_…` | 리뷰 정책 (작성 기한일·이미지 최대 개수·이미지 최대 용량 MB) |
| inquiry | object | `{"board_slug":null}` | 문의 연동 설정 (문의 게시판 slug) |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 채널 설정 (채널 ID·활성 여부·정렬 순서) |
| mileage | object | `{"enabled":false,"default_earn_rate":1,"earn_trigger":"co…` | 마일리지 설정 (사용 여부·기본 적립률·적립 트리거·통화별 규칙·소멸/소멸 알림·실제 활성 알림 채널 포함) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.save_success",
    "data": {
        "basic_info": {
            "shop_name": "",
            "route_path": "shop",
            "no_route": false,
            "company_name": "",
            "business_number": "",
            "ceo_name": "",
            "business_type": "",
            "business_category": "",
            "zipcode": "",
            "base_address": "",
            "detail_address": "",
            "phone": "",
            "fax": "",
            "email": "",
            "privacy_officer": "",
            "privacy_officer_email": "",
            "mail_order_number": "",
            "telecom_number": ""
        },
        "language_currency": {
            "default_currency": "KRW",
            "currencies": [
                {
                    "code": "KRW",
                    "name": {
                        "ko": "KRW (원)",
                        "en": "KRW (Won)",
                        "fr": "KRW (원)"
                    },
                    "symbol": "₩",
                    "exchange_rate": null,
                    "base_unit": 1000,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": true,
                    "locales": [
                        "ko"
                    ],
                    "flag": "🇰🇷"
                },
                {
                    "code": "USD",
                    "name": {
                        "ko": "USD (달러)",
                        "en": "USD (Dollar)",
                        "fr": "USD (달러)"
                    },
                    "symbol": "$",
                    "exchange_rate": 0.85,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇺🇸"
                },
                {
                    "code": "JPY",
                    "name": {
                        "ko": "JPY (엔)",
                        "en": "JPY (Yen)",
                        "fr": "JPY (엔)"
                    },
                    "symbol": "¥",
                    "exchange_rate": 115,
                    "base_unit": 100,
                    "rounding_unit": "1",
                    "rounding_method": "floor",
                    "decimal_places": 0,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇯🇵"
                },
                {
                    "code": "CNY",
                    "name": {
                        "ko": "CNY (위안)",
                        "en": "CNY (Yuan)",
                        "fr": "CNY (위안)"
                    },
                    "symbol": "元",
                    "exchange_rate": 5.8,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇨🇳"
                },
                {
                    "code": "EUR",
                    "name": {
                        "ko": "EUR (유로)",
                        "en": "EUR (Euro)",
                        "fr": "EUR (유로)"
                    },
                    "symbol": "€",
                    "exchange_rate": 0.78,
                    "base_unit": 1,
                    "rounding_unit": "0.01",
                    "rounding_method": "round",
                    "decimal_places": 2,
                    "is_default": false,
                    "locales": [
                        "en"
                    ],
                    "flag": "🇪🇺"
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
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "신용카드",
                        "en": "Credit Card",
                        "fr": "신용카드"
                    },
                    "_cached_description": {
                        "ko": "신용카드로 안전하게 결제",
                        "en": "Pay securely with credit card",
                        "fr": "신용카드로 안전하게 결제"
                    },
                    "_cached_icon": "credit-card",
                    "_cached_source": "builtin"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "가상계좌",
                        "en": "Virtual Account",
                        "fr": "가상계좌"
                    },
                    "_cached_description": {
                        "ko": "가상계좌로 입금",
                        "en": "Pay via virtual account",
                        "fr": "가상계좌로 입금"
                    },
                    "_cached_icon": "money-check",
                    "_cached_source": "builtin"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "무통장입금",
                        "en": "Bank Transfer",
                        "fr": "무통장입금"
                    },
                    "_cached_description": {
                        "ko": "지정 계좌로 직접 입금",
                        "en": "Direct bank transfer",
                        "fr": "지정 계좌로 직접 입금"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "bank",
                    "pg_provider": null,
                    "sort_order": 4,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "계좌이체",
                        "en": "Account Transfer",
                        "fr": "계좌이체"
                    },
                    "_cached_description": {
                        "ko": "실시간 계좌이체",
                        "en": "Real-time bank transfer",
                        "fr": "실시간 계좌이체"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "phone",
                    "pg_provider": null,
                    "sort_order": 5,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "휴대폰결제",
                        "en": "Mobile Payment",
                        "fr": "휴대폰결제"
                    },
                    "_cached_description": {
                        "ko": "휴대폰 소액결제",
                        "en": "Mobile phone payment",
                        "fr": "휴대폰 소액결제"
                    },
                    "_cached_icon": "mobile-screen-button",
                    "_cached_source": "builtin"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 6,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "포인트결제",
                        "en": "Points",
                        "fr": "포인트결제"
                    },
                    "_cached_description": {
                        "ko": "적립 포인트로 결제",
                        "en": "Pay with points",
                        "fr": "적립 포인트로 결제"
                    },
                    "_cached_icon": "coins",
                    "_cached_source": "builtin"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 7,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "예치금결제",
                        "en": "Store Credit",
                        "fr": "예치금결제"
                    },
                    "_cached_description": {
                        "ko": "예치금으로 결제",
                        "en": "Pay with store credit",
                        "fr": "예치금으로 결제"
                    },
                    "_cached_icon": "wallet",
                    "_cached_source": "builtin"
                },
                {
                    "id": "free",
                    "pg_provider": null,
                    "sort_order": 8,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "무료",
                        "en": "Free",
                        "fr": "무료"
                    },
                    "_cached_description": {
                        "ko": "결제 없이 주문 완료",
                        "en": "Order without payment",
                        "fr": "결제 없이 주문 완료"
                    },
                    "_cached_icon": "gift",
                    "_cached_source": "builtin"
                }
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
            "auto_cancel_days": 3,
            "cart_expiry_days": 30,
            "stock_restore_on_cancel": true,
            "cancellable_statuses": [
                "payment_complete"
            ],
            "confirmable_statuses": [
                "shipping",
                "delivered"
            ]
        },
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea",
                        "fr": "대한민국"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States",
                        "fr": "미국"
                    },
                    "is_active": false
                },
                {
                    "code": "JP",
                    "name": {
                        "ko": "일본",
                        "en": "Japan",
                        "fr": "일본"
                    },
                    "is_active": false
                },
                {
                    "code": "CN",
                    "name": {
                        "ko": "중국",
                        "en": "China",
                        "fr": "중국"
                    },
                    "is_active": false
                },
                {
                    "code": "SG",
                    "name": {
                        "ko": "싱가포르",
                        "en": "Singapore",
                        "fr": "싱가포르"
                    },
                    "is_active": false
                },
                {
                    "code": "HK",
                    "name": {
                        "ko": "홍콩",
                        "en": "Hong Kong",
                        "fr": "홍콩"
                    },
                    "is_active": false
                },
                {
                    "code": "TW",
                    "name": {
                        "ko": "대만",
                        "en": "Taiwan",
                        "fr": "대만"
                    },
                    "is_active": false
                },
                {
                    "code": "VN",
                    "name": {
                        "ko": "베트남",
                        "en": "Vietnam",
                        "fr": "베트남"
                    },
                    "is_active": false
                },
                {
                    "code": "TH",
                    "name": {
                        "ko": "태국",
                        "en": "Thailand",
                        "fr": "태국"
                    },
                    "is_active": false
                },
                {
                    "code": "MY",
                    "name": {
                        "ko": "말레이시아",
                        "en": "Malaysia",
                        "fr": "말레이시아"
                    },
                    "is_active": false
                }
            ],
            "international_shipping_enabled": false,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao"
        },
        "seo": {
            "meta_category_title": "{commerce_name} - {category_name}",
            "meta_category_description": "",
            "meta_search_title": "{commerce_name} - {keyword_name}",
            "meta_search_description": "",
            "meta_product_title": "{commerce_name} - {product_name}",
            "meta_product_description": "",
            "meta_shop_index_title": "{commerce_name}",
            "meta_shop_index_description": "",
            "seo_category": true,
            "seo_search_result": true,
            "seo_product_detail": true,
            "seo_shop_index": true
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
                    "max_use_value": 50000
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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

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
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
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
GET /api/modules/sirsoft-ecommerce/admin/settings/basic_info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| category | string | `basic_info` | 조회한 설정 분류(탭) 식별자 (`basic_info`·`language_currency`·`shipping` 등 요청 경로의 category 값 반영) |
| settings | object | `{"shop_name":"","route_path":"shop","no_route":false,"com…` | 해당 분류의 설정 값 객체 (분류에 따라 구조가 달라지며, 배송·클레임 등은 DB 관리 대상이 병합되어 반환) |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
    "data": {
        "category": "basic_info",
        "settings": {
            "shop_name": "",
            "route_path": "shop",
            "no_route": false,
            "company_name": "",
            "business_number": "",
            "ceo_name": "",
            "business_type": "",
            "business_category": "",
            "zipcode": "",
            "base_address": "",
            "detail_address": "",
            "phone": "",
            "fax": "",
            "email": "",
            "privacy_officer": "",
            "privacy_officer_email": "",
            "mail_order_number": "",
            "telecom_number": ""
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
| order_settings | object | `{"default_pg_provider":null,"payment_methods":[{"id":"car…` | 체크아웃용 주문/결제 설정 (기본 PG·활성 결제수단·무통장 계좌 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
    "data": {
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea",
                        "fr": "대한민국"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States",
                        "fr": "미국"
                    },
                    "is_active": false
                },
                {
                    "code": "JP",
                    "name": {
                        "ko": "일본",
                        "en": "Japan",
                        "fr": "일본"
                    },
                    "is_active": false
                },
                {
                    "code": "CN",
                    "name": {
                        "ko": "중국",
                        "en": "China",
                        "fr": "중국"
                    },
                    "is_active": false
                },
                {
                    "code": "SG",
                    "name": {
                        "ko": "싱가포르",
                        "en": "Singapore",
                        "fr": "싱가포르"
                    },
                    "is_active": false
                },
                {
                    "code": "HK",
                    "name": {
                        "ko": "홍콩",
                        "en": "Hong Kong",
                        "fr": "홍콩"
                    },
                    "is_active": false
                },
                {
                    "code": "TW",
                    "name": {
                        "ko": "대만",
                        "en": "Taiwan",
                        "fr": "대만"
                    },
                    "is_active": false
                },
                {
                    "code": "VN",
                    "name": {
                        "ko": "베트남",
                        "en": "Vietnam",
                        "fr": "베트남"
                    },
                    "is_active": false
                },
                {
                    "code": "TH",
                    "name": {
                        "ko": "태국",
                        "en": "Thailand",
                        "fr": "태국"
                    },
                    "is_active": false
                },
                {
                    "code": "MY",
                    "name": {
                        "ko": "말레이시아",
                        "en": "Malaysia",
                        "fr": "말레이시아"
                    },
                    "is_active": false
                }
            ],
            "international_shipping_enabled": false,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao"
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
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "신용카드",
                        "en": "Credit Card",
                        "fr": "신용카드"
                    },
                    "_cached_description": {
                        "ko": "신용카드로 안전하게 결제",
                        "en": "Pay securely with credit card",
                        "fr": "신용카드로 안전하게 결제"
                    },
                    "_cached_icon": "credit-card",
                    "_cached_source": "builtin"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "가상계좌",
                        "en": "Virtual Account",
                        "fr": "가상계좌"
                    },
                    "_cached_description": {
                        "ko": "가상계좌로 입금",
                        "en": "Pay via virtual account",
                        "fr": "가상계좌로 입금"
                    },
                    "_cached_icon": "money-check",
                    "_cached_source": "builtin"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "무통장입금",
                        "en": "Bank Transfer",
                        "fr": "무통장입금"
                    },
                    "_cached_description": {
                        "ko": "지정 계좌로 직접 입금",
                        "en": "Direct bank transfer",
                        "fr": "지정 계좌로 직접 입금"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "bank",
                    "pg_provider": null,
                    "sort_order": 4,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "계좌이체",
                        "en": "Account Transfer",
                        "fr": "계좌이체"
                    },
                    "_cached_description": {
                        "ko": "실시간 계좌이체",
                        "en": "Real-time bank transfer",
                        "fr": "실시간 계좌이체"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "phone",
                    "pg_provider": null,
                    "sort_order": 5,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "휴대폰결제",
                        "en": "Mobile Payment",
                        "fr": "휴대폰결제"
                    },
                    "_cached_description": {
                        "ko": "휴대폰 소액결제",
                        "en": "Mobile phone payment",
                        "fr": "휴대폰 소액결제"
                    },
                    "_cached_icon": "mobile-screen-button",
                    "_cached_source": "builtin"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 6,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "포인트결제",
                        "en": "Points",
                        "fr": "포인트결제"
                    },
                    "_cached_description": {
                        "ko": "적립 포인트로 결제",
                        "en": "Pay with points",
                        "fr": "적립 포인트로 결제"
                    },
                    "_cached_icon": "coins",
                    "_cached_source": "builtin"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 7,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "예치금결제",
                        "en": "Store Credit",
                        "fr": "예치금결제"
                    },
                    "_cached_description": {
                        "ko": "예치금으로 결제",
                        "en": "Pay with store credit",
                        "fr": "예치금으로 결제"
                    },
                    "_cached_icon": "wallet",
                    "_cached_source": "builtin"
                },
                {
                    "id": "free",
                    "pg_provider": null,
                    "sort_order": 8,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "무료",
                        "en": "Free",
                        "fr": "무료"
                    },
                    "_cached_description": {
                        "ko": "결제 없이 주문 완료",
                        "en": "Order without payment",
                        "fr": "결제 없이 주문 완료"
                    },
                    "_cached_icon": "gift",
                    "_cached_source": "builtin"
                }
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
            "auto_cancel_days": 3,
            "cart_expiry_days": 30,
            "stock_restore_on_cancel": true,
            "cancellable_statuses": [
                "payment_complete"
            ],
            "confirmable_statuses": [
                "shipping",
                "delivered"
            ]
        }
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

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
| order_settings | object | `{"default_pg_provider":null,"payment_methods":[{"id":"car…` | 공개 가능한 결제 설정 (활성 결제수단·무통장 은행명 매핑 포함, 민감 정보 제외) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
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
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "신용카드",
                        "en": "Credit Card",
                        "fr": "신용카드"
                    },
                    "_cached_description": {
                        "ko": "신용카드로 안전하게 결제",
                        "en": "Pay securely with credit card",
                        "fr": "신용카드로 안전하게 결제"
                    },
                    "_cached_icon": "credit-card",
                    "_cached_source": "builtin"
                },
                {
                    "id": "vbank",
                    "pg_provider": null,
                    "sort_order": 2,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "가상계좌",
                        "en": "Virtual Account",
                        "fr": "가상계좌"
                    },
                    "_cached_description": {
                        "ko": "가상계좌로 입금",
                        "en": "Pay via virtual account",
                        "fr": "가상계좌로 입금"
                    },
                    "_cached_icon": "money-check",
                    "_cached_source": "builtin"
                },
                {
                    "id": "dbank",
                    "pg_provider": null,
                    "sort_order": 3,
                    "is_active": true,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "order_placed",
                    "_cached_name": {
                        "ko": "무통장입금",
                        "en": "Bank Transfer",
                        "fr": "무통장입금"
                    },
                    "_cached_description": {
                        "ko": "지정 계좌로 직접 입금",
                        "en": "Direct bank transfer",
                        "fr": "지정 계좌로 직접 입금"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "bank",
                    "pg_provider": null,
                    "sort_order": 4,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "계좌이체",
                        "en": "Account Transfer",
                        "fr": "계좌이체"
                    },
                    "_cached_description": {
                        "ko": "실시간 계좌이체",
                        "en": "Real-time bank transfer",
                        "fr": "실시간 계좌이체"
                    },
                    "_cached_icon": "building-columns",
                    "_cached_source": "builtin"
                },
                {
                    "id": "phone",
                    "pg_provider": null,
                    "sort_order": 5,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "payment_complete",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "휴대폰결제",
                        "en": "Mobile Payment",
                        "fr": "휴대폰결제"
                    },
                    "_cached_description": {
                        "ko": "휴대폰 소액결제",
                        "en": "Mobile phone payment",
                        "fr": "휴대폰 소액결제"
                    },
                    "_cached_icon": "mobile-screen-button",
                    "_cached_source": "builtin"
                },
                {
                    "id": "point",
                    "pg_provider": null,
                    "sort_order": 6,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "포인트결제",
                        "en": "Points",
                        "fr": "포인트결제"
                    },
                    "_cached_description": {
                        "ko": "적립 포인트로 결제",
                        "en": "Pay with points",
                        "fr": "적립 포인트로 결제"
                    },
                    "_cached_icon": "coins",
                    "_cached_source": "builtin"
                },
                {
                    "id": "deposit",
                    "pg_provider": null,
                    "sort_order": 7,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "예치금결제",
                        "en": "Store Credit",
                        "fr": "예치금결제"
                    },
                    "_cached_description": {
                        "ko": "예치금으로 결제",
                        "en": "Pay with store credit",
                        "fr": "예치금으로 결제"
                    },
                    "_cached_icon": "wallet",
                    "_cached_source": "builtin"
                },
                {
                    "id": "free",
                    "pg_provider": null,
                    "sort_order": 8,
                    "is_active": false,
                    "min_order_amount": 0,
                    "stock_deduction_timing": "order_placed",
                    "mileage_deduction_timing": "payment_complete",
                    "_cached_name": {
                        "ko": "무료",
                        "en": "Free",
                        "fr": "무료"
                    },
                    "_cached_description": {
                        "ko": "결제 없이 주문 완료",
                        "en": "Order without payment",
                        "fr": "결제 없이 주문 완료"
                    },
                    "_cached_icon": "gift",
                    "_cached_source": "builtin"
                }
            ],
            "banks": [],
            "bank_accounts": [
                {
                    "bank_code": "004",
                    "account_number": "",
                    "account_holder": "",
                    "is_active": false,
                    "is_default": false,
                    "bank_name": "004"
                }
            ],
            "auto_cancel_expired": true,
            "auto_cancel_days": 3,
            "cart_expiry_days": 30,
            "stock_restore_on_cancel": true,
            "cancellable_statuses": [
                "payment_complete"
            ],
            "confirmable_statuses": [
                "shipping",
                "delivered"
            ]
        }
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

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
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
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

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

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
    "message": "sirsoft-ecommerce::messages.settings.fetch_success",
    "data": {
        "shipping": {
            "default_country": "KR",
            "available_countries": [
                {
                    "code": "KR",
                    "name": {
                        "ko": "대한민국",
                        "en": "South Korea",
                        "fr": "대한민국"
                    },
                    "is_active": true
                },
                {
                    "code": "US",
                    "name": {
                        "ko": "미국",
                        "en": "United States",
                        "fr": "미국"
                    },
                    "is_active": false
                },
                {
                    "code": "JP",
                    "name": {
                        "ko": "일본",
                        "en": "Japan",
                        "fr": "일본"
                    },
                    "is_active": false
                },
                {
                    "code": "CN",
                    "name": {
                        "ko": "중국",
                        "en": "China",
                        "fr": "중국"
                    },
                    "is_active": false
                },
                {
                    "code": "SG",
                    "name": {
                        "ko": "싱가포르",
                        "en": "Singapore",
                        "fr": "싱가포르"
                    },
                    "is_active": false
                },
                {
                    "code": "HK",
                    "name": {
                        "ko": "홍콩",
                        "en": "Hong Kong",
                        "fr": "홍콩"
                    },
                    "is_active": false
                },
                {
                    "code": "TW",
                    "name": {
                        "ko": "대만",
                        "en": "Taiwan",
                        "fr": "대만"
                    },
                    "is_active": false
                },
                {
                    "code": "VN",
                    "name": {
                        "ko": "베트남",
                        "en": "Vietnam",
                        "fr": "베트남"
                    },
                    "is_active": false
                },
                {
                    "code": "TH",
                    "name": {
                        "ko": "태국",
                        "en": "Thailand",
                        "fr": "태국"
                    },
                    "is_active": false
                },
                {
                    "code": "MY",
                    "name": {
                        "ko": "말레이시아",
                        "en": "Malaysia",
                        "fr": "말레이시아"
                    },
                    "is_active": false
                }
            ],
            "international_shipping_enabled": false,
            "free_shipping_threshold": 50000,
            "free_shipping_enabled": true,
            "address_validation_enabled": false,
            "address_api_provider": "kakao"
        }
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 인증 없이 접근 가능한 공개 엔드포인트로, 체크아웃에서 필요한 배송 설정을 반환합니다. `EcommerceSettingsService::getSettings('shipping')`로 기본 배송 국가·이용 가능한 국가 목록·국제 배송 활성화 여부·배송 타입·무료 배송 설정 등을 `shipping` 으로 내려줍니다. 프론트가 배송지 선택과 배송비 안내를 구성하는 데 사용하며, `logApiUsage('settings.shipping')`로 사용 로그를 남깁니다.


