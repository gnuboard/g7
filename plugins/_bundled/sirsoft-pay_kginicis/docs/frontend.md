# KG 이니시스 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 1개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면 하나뿐입니다 — 체크아웃·주문상세의
결제 UI는 이 플러그인 소유가 아니라 §레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는
조각)으로 존재합니다. "화면"과 "레이아웃 확장 조각"을 헷갈리면 체크아웃 결제 버튼을 찾으러
`resources/layouts/`를 뒤지게 되는데, 실제로는 `resources/extensions/`에 있습니다.
<!-- @intent END -->

### 영수증 링크는 API 주소로 그린다

주문 상세 결제 정보 카드의 영수증 링크 조각(`plugins/_bundled/sirsoft-pay_kginicis/resources/extensions/user_order_show.g7.html`)은 결제사 주소를
글자 그대로 적지 않는다. 적으면 레이아웃 편집기의 저장 게이트(외부 URL 금지)가 이 조각을 거절한다. 조각은 마운트 때
`GET /api/plugins/sirsoft-pay_kginicis/user/orders/{orderNumber}/receipt` 를 불러 공통 응답 형식의 `data.receipt_url` 을 로컬 상태
(`_kgReceiptUrl`)에 담고 그 값으로 링크를 그린다. 회원·비회원 주문 상세가 같은 조각을 쓰므로 회원은 토큰
(`auth_mode=optional`), 비회원은 `X-Guest-Order-Token` 헤더를 실어 같은 API 를 부른다. 주소를 받지 못하면 링크를 그리지 않는다.
주문번호는 주소가 아니라 불러온 주문(`order.data.order_number`)에서 읽는다 — 이 결제 정보 카드(영수증 링크·에스크로 구매확정 링크)는 주소에 주문번호가 없는
교환·반품·환불 신청서 상세(회원·비회원)에서도 같은 조각으로 쓰인다.

### 영수증 글자는 요청 언어로 오고, 스크립트는 항목을 키로 찾는다

영수증 조회 응답의 버튼 이름·확인서 제목·안내·항목 이름·결제수단 이름은 서버가 요청 언어로 번역해 보낸다.
그래서 확인서 항목(`receipt_fields`)을 이름(`label`)으로 찾으면 영어·일본어 화면에서 못 찾는다 — 일본 편의점
입금 안내 패널(`plugins/_bundled/sirsoft-pay_kginicis/resources/js/vbankInfoInjector.ts`)은 항목의 고정 키(`key`: `deposit_status`·`amount`·`cvs_code` 등)로
값을 찾고, 편의점 결제 여부도 결제수단 이름이 아니라 응답의 결제수단 코드(`cbt_pay_method`)로 가린다. 패널 제목·행 이름,
확인서 팝업의 인쇄 버튼, 서버가 이름을 주지 않았을 때의 버튼 이름은 `resources/lang` 의 `user.*` 키로 그린다
(`receiptPopup.ts` 의 `translateKginicis`).

입금 안내의 입금 기한은 쇼핑몰이 사용자 언어·시간대로 서식을 입혀 주는 `payment.vbank_due_at_formatted` 를 그대로 쓴다.
플러그인은 날짜에 서식을 입히지 않는다 — 원시 값(`vbank_due_at`)을 한국 서식·한국 시간으로 바꿔 쓰면 영어·일본어 화면에도
한국식 날짜가 나온다. 주문 완료 화면의 날짜도 쇼핑몰의 서식 값을 템플릿이 그대로 표시하므로 플러그인이 건드리지 않는다.

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_kginicis.requestPayment` |
| `copyToClipboard` | `sirsoft-pay_kginicis.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`requestPayment` 하나로 PC/모바일/CBT 3가지 프로토콜을 전부 처리합니다 — 체크아웃 버튼은
결제수단·통화가 무엇이든 이 핸들러 하나만 호출하고, PC 결제창을 열지 모바일 폼을 제출할지
CBT 인증 URL로 이동할지는 핸들러 내부에서 서버 응답(§API `/payment/*/signature`,
`/payment/cbt/hash-data`)에 따라 분기합니다. 레이아웃 JSON 작성자가 결제수단별로 다른
핸들러를 호출할 필요가 없다는 뜻입니다.

`copyToClipboard` 는 플러그인 설정의 가상계좌 입금통보 주소를 복사합니다(상점관리자에 그대로 붙여 넣는 값).
설정 화면이 어느 관리자 템플릿으로 그려지든 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않고 플러그인이 직접 둡니다(코어에는 클립보드 핸들러가 없습니다). `navigator.clipboard` 가 막힌 비보안(HTTP) 접속에서는 숨긴 입력칸 복사로 내려갑니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftKginicis` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftKginicis` 로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로 재등록
진입점을 찾기 때문입니다(§코어 AGENTS.md "재등록 진입점"). `initPlugin()`이 KG 이니시스 결제창
스크립트(`INIStdPay.js`) 자체를 미리 로드하지 않는 것도 의도입니다 — 그 스크립트는
`requestPayment` 핸들러가 실제 결제 시도 시점에만 동적으로 로드합니다(모든 방문자가 결제
페이지에 오는 것은 아니므로 전역 부팅에서 미리 불러올 필요가 없습니다).
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
KG 이니시스가 제공하는 `INIStdPay.js`/모바일 결제창 스크립트는 이 목록에 없습니다 — 그
스크립트들은 KG 이니시스 CDN 에서 결제 시도 시점에 동적으로 로드되는 제3자 자산이라, 이
플러그인이 빌드 시 번들링하는 `dist/` 산출물과는 다른 층입니다. CSS 산출물이 없는 것은
결제창 자체는 KG 이니시스가 그리고, 이 플러그인은 결제 버튼 같은 최소한의 UI만 코어
컴포넌트로 구성하기 때문입니다.
<!-- @intent END -->

### 엔화 결제수단 제한의 화면 보정은 id·표식으로만 찾는다

`checkoutJpyPaymentMethodRestrictor` 는 두 겹이다 — 결제 설정 응답에서 허용 밖 결제수단을 거르는 앞단(결제수단 id 기준)과,
이미 그려진 버튼을 숨기는 화면 보정. 화면 보정은 템플릿이 단 칸 `id="checkout_payment_method_section"` 과 버튼의
`data-payment-method`(결제수단 id)로만 대상을 찾는다. 종전에는 칸 제목 글자와 버튼 이름 글자(한국어·영어·일본어 이름표)로
찾아 번역이 다르면 빠졌다. 표식이 없는 버튼은 건드리지 않는다.

### 결제 정보 카드는 id 로만 찾는다

주문 상세·신청서 상세에 영수증 버튼·입금 안내를 붙이는 주입 스크립트는 결제 정보 카드를 템플릿이 약속한 `id="order_payment_info_panel"` 로만
찾는다. 화면 제목 글자(「결제 정보」 등)로 찾지 않는다 — 제목은 언어마다 다르다. id 가 없는 템플릿에서는 붙이지 않는다
(약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 결제 정보 카드에 붙일 때의 약속」).

간편결제 이름을 결제 방법 행에 덧붙일 때도 행을 id 로만 찾는다 — 사용자 주문 상세·주문 완료는 `order_payment_method_row`, 관리자 주문 상세는
`payment_method_block_{결제 순번}`. 행 이름 글자(「결제 방법」「결제수단」)로 찾지 않는다.

### 관리자 결제수단 설정의 브랜드 표시는 결제수단 id 표식으로만 찾는다

관리자 쇼핑몰 설정의 결제수단 목록에서 브랜드 배지를 붙일 항목은 이커머스 관리자 화면이 항목마다 단
`data-payment-method`(결제수단 id)로만 찾는다. 이름 글자(한국어·영어 이름표)와 클래스 모양으로 찾지 않는다 — 관리자 언어·화면 폭마다
다르다. 약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 관리자 결제수단 설정 항목을 볼 때의 약속」.

### 스크립트가 그리는 글자는 번역 키로 낸다

영수증 버튼을 누른 뒤의 조회 중 글자는 `user.loading` 번역 키(`translateKginicis`)로 낸다. 플러그인은 ko·en 을 갖고 일본어는 언어팩이 채운다.

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-pay_kginicis/payment/close-report`)는 결제대기 주문을
실패·취소로 바꾸므로 서버가 **주문 소유자만** 받아 준다(KVE-2026-2434). 주문자 이메일·전화번호는
제3자도 알 수 있어 소유 증명이 되지 못하므로 보내지 않는다.

- 회원: `G7Core.api` 가 `Authorization: Bearer …` 를 스스로 싣는다 — 호출부는 아무것도 붙이지 않는다.
- 비회원: 체크아웃이 주문 생성 응답에서 받아 저장한 조회 토큰을 `X-Guest-Order-Token` 헤더로 싣는다.

헤더는 `resources/js/guestOrderToken.ts` 의 `buildGuestOwnershipHeaders()` 한 곳에서 만든다(로그인
상태면 비회원 토큰을 싣지 않는다). `G7Core.api` 가 없어 `fetch` 로 직접 보내는 폴백은 같은 출처일 때만
토큰을 싣는다(`isSameOriginUrl`). 토큰이 없으면 서버가 403 으로 거부하고 화면 흐름은 그대로 진행한다 —
결제대기 주문은 이커머스 만료 정리 잡이 정리한다.
<!-- @intent END -->

## 주문 화면 주입의 폴링·조회 규약

<!-- @intent START -->
주문 상세 화면에 결제 정보를 덧붙이는 인젝터 셋 — 관리자 주문 상세 결제수단 표시
(`adminOrderPaymentDisplayInjector.ts`), 마이페이지·비회원 주문 상세 영수증 행
(`mypageOrderShowInjector.ts`), 마이페이지 가상계좌 안내(`vbankInfoInjector.ts`) — 는 대상 DOM 이
그려질 때까지 재시도하며, 폴링과 서버 조회를 전부 `resources/js/orderScreenPolling.ts` 에 맡깁니다.
예전 `setInterval` 폴러는 틱이 앞선 비동기 조회를 기다리지 않아 첫 응답이 올 때까지 400ms 마다 같은
조회를 새로 보냈고(조회마다 서버가 KG 이니시스 API 까지 갑니다), 라우트가 바뀔 때마다 폴러가 겹겹이
쌓였습니다.

- **직렬 폴러** — `createOrderScreenPoller()`(기본 400ms · 최대 30틱)는 한 틱의 비동기 작업이 끝난 뒤에야
  다음 틱을 예약합니다. 인젝터마다 폴러를 모듈 수준에 **하나만** 두고, `start(key, tick)` 은 같은 key 가
  돌고 있으면 무시하고 다른 key 면 이전 폴러를 멈춥니다. 대상 경로를 벗어나면 `stop()` 합니다. 틱은
  `isActive()` 를 받아, 응답을 기다리는 사이 폴러가 멈췄으면(다른 주문으로 이동) DOM 을 건드리지 않고 끝냅니다.
- **화면 진입당 1회 조회** — 서버 조회는 `requestOncePerOrderScreen(key, request)` 로만 보냅니다. 한 화면
  진입(같은 경로) 동안 key 별로 한 번 보내고, 진행 중이거나 끝난 결과를 **실패(`null`·404)까지 포함해**
  재사용합니다. 캐시 키에는 회원 인증 토큰·비회원 조회 토큰이 들어가므로, 비회원 토큰이 늦게 준비되면 그때
  한 번 더 보냅니다. 인젝터의 라우트 변경 처리는 첫머리에서 `enterOrderScreen()` 을 불러, 경로가 바뀌면 이전
  화면의 결과를 버립니다. 영수증 행과 가상계좌 안내는 같은 주소를 부르므로
  `fetchKginicisReceiptInfoForOrderScreen()`(key `kginicis-receipt:{주문번호}`)으로 한 결과를 나눠 씁니다.
  영수증 버튼 클릭 같은 사용자 동작은 이 경로를 쓰지 않고 매번 새로 조회합니다.
- **관리자 주문 상세는 레이아웃 확장의 데이터 소스를 재사용** — 같은 화면의 `admin_order_payment_query`
  확장이 이미 `transaction-status` 를 데이터 소스 `kginicis_status` 로 받으므로, 인젝터는
  `G7Core.dataSource.get('kginicis_status')` 값을 읽어(`readSharedDataSource`) 씁니다. 값이 아직 없으면 최대
  20틱(약 8초) 기다리고, 그래도 없으면(확장 미적용·조회 실패) 화면 진입당 한 번만 직접 조회합니다. 엔진이
  없으면 바로 직접 조회합니다. 라우트 전환 직후에는 엔진이 새 값을 받기 전이라 이전 주문의 값이 남아 있으므로,
  `history.pushState`·`popstate` 직후 `rememberLeavingDataSources(['kginicis_status'])` 로 떠나는 화면의 값을
  기억해 두고 그 값과 같은 객체는 새 화면 값으로 쓰지 않습니다. 이 재사용이 없던 때는 주문 상세를 열 때마다
  거래 조회가 두 번 나갔습니다.
- **주문 데이터 로딩 대기** — 마이페이지·비회원 영수증 행 인젝터는 `isOrderDataLoading()`(데이터 컨텍스트에
  `order` 키가 있고 값이 아직 `undefined`)인 동안 조회하지 않고 기다립니다. 불러오는 도중 「주문 정보 없음」으로
  보고 비회원 경로의 주문번호로 영수증을 조회하면, 다른 결제대행사 주문에도 404 가 났습니다.

**깨뜨리면 안 되는 것**: 인젝터 안에서 `setInterval` 이나 직접 `fetch` 루프로 되돌리지 않는다 · 조회 결과
캐시에서 실패를 빼지 않는다(실패 주문에서 매 틱 재조회가 된다) · 레이아웃 확장의 데이터 소스 ID
`kginicis_status` 를 바꾸면 인젝터 상수 `STATUS_DATA_SOURCE` 도 함께 바꾼다(어긋나면 매번 20틱을 기다린 뒤
직접 조회로 떨어져 중복 조회가 되살아난다). 회귀는 Vitest `resources/js/__tests__/orderPaymentDisplayInjectors.test.ts` 가
지킵니다 — 느린 외부 PG 응답·라우트 전환 타이밍에 의존해 브라우저 E2E 로는 재현하지 않습니다.
<!-- @intent END -->
