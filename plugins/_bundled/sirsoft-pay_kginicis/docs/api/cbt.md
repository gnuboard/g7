# Cbt API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Cbt 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_kginicis/admin/cbt-connectivity-check
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.cbt.connectivity.check -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.cbt.connectivity.check`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtConnectivityCheckController@check`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read|core.plugins.update,false` (OR)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/cbt-connectivity-check HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| egress_ip | string | `203.0.113.10` | 서버가 외부 통신 시 사용하는 egress IP. KG 이니시스 측에 DEVCBT 접근용 IP 화이트리스트 등록을 요청할 때 알려줄 IP이며, 외부 echo 서비스(ipify 등)를 순차 조회해 얻는다(모두 실패 시 null). 사이트 환경설정에 아웃바운드 프록시가 적용되어 있으면 그 프록시를 거쳐 조회하므로, 실제 결제 요청과 같은 경로의 IP 가 반환된다. |
| server_ip | string | `127.0.0.1` | `$_SERVER['SERVER_ADDR']` 로 읽은 서버 내부 IP. egress IP와 대조해 NAT/프록시 여부를 가늠하는 참고값이다. |
| hosts | array | `[{"name":"devcbt.inicis.com","env":"test","dns_resolved_i…` | 진단 대상 호스트별 결과 배열. 각 항목은 호스트명(`devcbt.inicis.com`), 환경(`test`), DNS 해석 IP(`dns_resolved_ip`), TCP 443 도달 여부(`tcp_443_reachable`)와 에러·응답지연(`tcp_443_error`, `tcp_443_latency_ms`)을 담는다. 운영계(`cbt.inicis.com`)는 화이트리스트 제약이 없어 제외된다. 도달 여부는 연결만 수행하고 데이터는 주고받지 않으며, 사이트 환경설정에 아웃바운드 프록시가 적용되어 있으면 그 프록시를 거쳐 확인한다 — 실제 결제 요청이 지나는 경로와 같은 경로를 재기 위함이다. |
| callback | object | `{"app_url":"https:\/\/example.com","callback_url":"https:\/\/g…` | 결제 콜백 URL 진단 정보. 앱 URL·콜백 URL과 각각의 HTTPS 여부(`app_url_https`, `callback_url_https`)·공인 호스트 여부(`app_url_public`, `callback_url_public`), 그리고 콜백 호스트가 앱 URL 호스트와 일치하는지(`host_matches_app_url`)를 담아 CBT 콜백 수신 가능 여부를 점검한다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-pay_kginicis::messages.cbt_connectivity.checked",
    "data": {
        "egress_ip": "203.0.113.10",
        "server_ip": "127.0.0.1",
        "hosts": [
            {
                "name": "devcbt.inicis.com",
                "env": "test",
                "dns_resolved_ip": "183.109.71.154",
                "tcp_443_reachable": false,
                "tcp_443_error": "A connection attempt failed because the connected party did not properly respond after a period of time, or established connection failed because connected host has failed to respond",
                "tcp_443_latency_ms": 3008
            }
        ],
        "callback": {
            "app_url": "https://test.example.com",
            "callback_url": "https://api.example.com/plugins/sirsoft-pay_kginicis/payment/cbt/callback",
            "app_url_https": true,
            "callback_url_https": true,
            "app_url_public": true,
            "callback_url_public": true,
            "host_matches_app_url": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read\|core.plugins.update`)이 없는 경우 |

<!-- @generated:end -->

**설명** 7.1.0 부터 플러그인 설정 화면의 버튼이라 플러그인 설정 수정 권한으로도 쓸 수 있습니다.

일본 CBT(테스트 모드, DEVCBT) 결제를 위한 호스트 연결 상태를 관리자 설정 페이지에서 셀프 진단하는 엔드포인트입니다. 서버의 egress IP(외부 통신에 쓰이는 IP, KG 이니시스 IP 화이트리스트 등록 대상)와 서버 내부 IP 를 조회하고, `devcbt.inicis.com` 에 대한 DNS 해석 및 TCP 443 도달성(3초 timeout)을 점검하며, 결제 콜백 URL 이 HTTPS·공인 호스트인지 등 콜백 진단 정보도 함께 반환합니다. 콜백 진단의 `app_url` 은 사이트 주소(`APP_URL`, 하위 폴더 설치면 설치 경로 포함)이고, `callback_url` 은 일부러 이 요청의 스킴과 Host 로 만듭니다 — 둘을 비교해야 프록시나 도메인 설정이 어긋난 상태(`host_matches_app_url=false`)를 보여 줄 수 있기 때문입니다. 운영계(`cbt.inicis.com`)는 IP 화이트리스트 제약이 없어 진단 대상에서 제외됩니다. 관리자 인증(`auth:sanctum`)과 `sirsoft-ecommerce.settings.read` 권한이 필요하며, 토큰 누락·만료는 401, 권한 부족은 403, 진단 중 예외 발생 시 500 으로 응답합니다.


### POST /api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.cbt.test-product.create -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.cbt.test-product.create`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtTestProductController@create`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.products.create`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-pay_kginicis/admin/cbt-test-product HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| product_id | integer | `114` | product 식별자 (연관 리소스 참조) |
| product_code | string | `CBT-TEST-20260712085816` | 생성된 테스트 상품의 상품코드. `CBT-TEST-` 접두사에 생성 시각(YmdHis)을 붙여 자동 부여되며, SKU는 여기에 `KGINICIS-` 접두사를 더해 만들어진다. |
| admin_url | string | `/admin/ecommerce/products/114/edit` | admin URL |
| shop_url | string | `/shop/products/0221GJD53ZA6GA7X?locale=ja` | 생성된 테스트 상품의 상점 화면 주소. 상품 화면과 같은 상품 코드(`product_code`) 주소다(7.2.0 전에는 상품 ID). 앞부분은 상점 주소 설정을 따른다 — 기본은 `/shop`, 이커머스 모듈 설정 `basic_info.route_path` 를 바꾸면 그 값(`/store/...`), `basic_info.no_route` 를 켜면 세그먼트 없이 루트에 붙는다(`/products/0221GJD53ZA6GA7X?locale=ja`). 화면 주소 키 `ecommerce.product` 필터(`core.screen_url.filter_path`)로 바꿀 수 있다. 예시는 기본 설정 기준. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "product_id": 4,
        "product_code": "CBT-TEST-20260708063238",
        "admin_url": "/admin/ecommerce/products/4/edit",
        "shop_url": "/shop/products/0221GJD53ZA6GA7X?locale=ja"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.products.create`)이 없는 경우 |

<!-- @generated:end -->



**설명**

일본 CBT(JPPG) 결제 검증을 손쉽게 하기 위해 JPY 가격(100엔)이 설정된 테스트용 상품을 한 번에 자동 생성하는 관리자 엔드포인트입니다. 이커머스 공개 계약 `ProductProvisioner::createSimpleProduct()` 로 다국어(ko/en/ja) 이름·설명과 판매 상태, 기본 옵션 1행을 갖춘 상품을 생성하며(가격은 명세 통화 JPY 100 — 상점 기본 통화가 KRW 여도 지금 환율로 기본 통화 가격을 역산해 저장하므로 결제 때 정확히 100엔이고, 환율이 바뀌면 엔화 금액도 바뀝니다. JPY 환율이 없는 상점이면 500 으로 실패합니다), 성공 시 `product_id`·`product_code` 와 함께 어드민 편집 URL·일본어 쇼핑몰 URL 을 반환해 운영자가 곧바로 CBT 결제 흐름을 테스트할 수 있게 합니다. 관리자 인증(`auth:sanctum`)과 `sirsoft-ecommerce.products.create` 권한이 필요하며, 토큰 누락·만료는 401, 권한 부족은 403, 상품 생성 중 예외 발생 시 500 으로 응답합니다. `errors.detail` 은 코어 `SafeExceptionMessage::for()` 가 고른 사유입니다 — DB 예외·PHP 오류의 원문(SQL·DB 접속 정보·서버 경로)은 운영 모드에서 일반 문구(`extensions.errors.internal_error`)로 바뀌고 원문은 서버 로그에 남습니다.


