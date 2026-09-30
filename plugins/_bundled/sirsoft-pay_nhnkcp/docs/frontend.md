# NHN KCP — 프론트엔드

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
`sirsoft-pay_kginicis`와 마찬가지로 이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면
하나뿐입니다 — 체크아웃·주문상세·마이페이지의 결제 UI는 이 플러그인 소유가 아니라
§레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는 조각)으로 존재합니다.
<!-- @intent END -->

### 매출전표 보기 요청의 주문번호와 자격 증명

주문 상세 결제 정보 카드의 [매출전표 보기](`plugins/_bundled/sirsoft-pay_nhnkcp/resources/extensions/user_order_show.g7.html`)는 주문번호를 주소가 아니라
불러온 주문(`order.data.order_number`)에서 읽는다 — 이 카드는 주소에 주문번호가 없는 교환·반품·환불 신청서 상세(회원·비회원)에서도
같은 조각으로 쓰인다. 회원·비회원 주문 상세가 같은 조각을 쓰므로 회원은 토큰(`auth_mode=optional`), 비회원은
`X-Guest-Order-Token` 헤더를 실어 같은 API 를 부른다(전역 헤더는 이 플러그인 주소에 조회 토큰을 붙이지 않는다).

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 3개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_nhnkcp.requestPayment` |
| `setPaymentMethod` | `sirsoft-pay_nhnkcp.setPaymentMethod` |
| `copyToClipboard` | `sirsoft-pay_nhnkcp.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`sirsoft-pay_kginicis`가 핸들러 1개(`requestPayment`)로 끝나는 것과 달리 이 플러그인은
3개입니다. `setPaymentMethod`가 별도로 필요한 이유는 KCP 간편결제 버튼(PAYCO/네이버페이/
카카오페이/Apple Pay)이 레이아웃 컴포넌트가 아니라 KCP 가 제공하는 DOM 을 그대로 쓰기
때문입니다 — React 상태로 선택 하이라이트를 그리는 대신 DOM 을 직접 조작해 선택된 버튼에
테두리를 입힙니다(`updateEasyPayButtonStyles`). Apple Pay 는 iOS 모바일이 아니면 여기서
바로 오류 모달을 띄우고 요청 자체를 막습니다 — KCP 서버까지 보냈다가 거부당하면 사용자가
결제 실패 이유를 알 수 없기 때문입니다. `copyToClipboard`는 가상계좌 계좌번호 복사
버튼처럼 결제와 무관한 범용 유틸리티라 KCP 고유 로직이 없습니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftNhnkcp` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftNhnkcp`로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로 재등록
진입점을 찾기 때문입니다(§코어 AGENTS.md "재등록 진입점"). KCP 결제창 스크립트 자체는 이
진입점이 미리 로드하지 않습니다 — 모든 방문자가 결제 페이지에 오는 것은 아니므로 전역
부팅에서 미리 불러올 필요가 없습니다(`requestPayment` 핸들러가 실제 결제 시도 시점에만
동적으로 로드).
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
KCP 가 제공하는 `payplus_web.jsp` SDK 는 이 목록에 없습니다 — `requestPayment` 핸들러가
결제 시도 시점에 iframe 안으로 동기 로드하는 제3자 자산이라, 이 플러그인이 빌드 시
번들링하는 `dist/` 산출물과는 다른 층입니다. CSS 산출물이 없는 것은 결제창 자체는 KCP 가
그리고, 이 플러그인은 간편결제 버튼·복사 버튼 같은 최소한의 UI만 코어 컴포넌트로
구성하기 때문입니다.
<!-- @intent END -->

## 비회원 주문의 자격 증명

<!-- @intent START -->
영수증·결제수단 표시는 주문 정보를 서버에서 다시 조회해 만든다. 이 요청은 레이아웃의
`globalHeaders` 배선을 타지 않는 직접 호출(`fetch`)이므로, 자격 증명을 호출부가 직접 실어야 한다.

- 회원: `Authorization: Bearer …`
- 비회원: `X-Guest-Order-Token: …` (코어 storageHandlers 가 sessionStorage 에 저장, 전역 상태 폴백)

두 경로 모두 `plugins/_bundled/sirsoft-pay_nhnkcp/resources/js/guestOrderToken.ts` 의 `buildOrderRequestHeaders()` 한 곳을 거친다.
비회원 분기를 비우면 서버는 그 주문을 찾을 수 없다고 응답하고, 화면에는 영수증 버튼이 아예
나타나지 않는다 — 예외도 콘솔 오류도 남지 않아 운영자가 원인을 알 수 없다. 서버(`UserReceiptController`)는
비회원을 이미 지원하므로, 이 배선이 빠지면 서버 기능이 도달 불가 상태로만 남는다.

주문 상세 화면의 경로 판정(`ORDER_SHOW_RE`)도 회원(`/mypage/orders/{N}`)과
비회원(`/shop/guest/orders/{N}`) 두 주소를 함께 매칭해야 한다. 한쪽을 빼면 그 화면에서는
조회가 시작조차 되지 않는다.

영수증 조회 응답은 공통 형식(`{ success, message, data }`)이고 매출전표·현금영수증 주소는 `data` 안에 있다.
주입 스크립트는 응답 본문의 `data` 를, 레이아웃 조각은 `response.data.*`(주문 완료) · 직전 apiCall 결과
`$prev.data.*`(마이페이지 「매출전표 보기」)를 읽는다. 최상위에서 읽으면 버튼은 그려져도 빈 주소로 팝업이 열린다.
주입 스크립트가 그리는 버튼·가상계좌 안내 글자는 `resources/lang` 의 `user.*` 키로 그린다(`translate.ts`).
테스트 모드 모의입금 도구도 같다 — 정보 API 응답의 `data` 를 읽고, 제목·입력 이름·버튼·관리자 안내는 `user.mock_*` 키로 그린다.
일본어 등 다른 언어의 번역은 언어팩이 맡는다(플러그인은 ko·en 원본만 갖는다).
<!-- @intent END -->

### 결제 정보 카드는 id 로만 찾는다

주문 상세·신청서 상세에 영수증 버튼·입금 안내를 붙이는 주입 스크립트는 결제 정보 카드를 템플릿이 약속한 `id="order_payment_info_panel"` 로만
찾는다. 화면 제목 글자(「결제 정보」 등)로 찾지 않는다 — 제목은 언어마다 다르다. id 가 없는 템플릿에서는 붙이지 않는다
(약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 결제 정보 카드에 붙일 때의 약속」).

간편결제 이름을 결제 방법 행에 덧붙일 때도 행을 id 로만 찾는다 — 사용자 주문 상세·주문 완료는 `order_payment_method_row`, 관리자 주문 상세는
`payment_method_block_{결제 순번}`. 행 이름 글자(「결제 방법」「결제수단」)로 찾지 않는다.

### 관리자 결제수단 설정의 브랜드 표시는 결제수단 id 표식으로만 찾는다

관리자 쇼핑몰 설정의 결제수단 목록에서 브랜드 배지·애플페이 iOS 전용 안내를 붙일 항목은 이커머스 관리자 화면이 항목마다 단
`data-payment-method`(결제수단 id)로만 찾는다. 이름 글자(한국어·영어 이름표)와 클래스 모양으로 찾지 않는다 — 관리자 언어·화면 폭마다
다르다. 약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md` 「PG 플러그인이 관리자 결제수단 설정 항목을 볼 때의 약속」.

### 스크립트가 그리는 글자는 번역 키로 낸다

결제 안내·오류 문구(`payment.error.krw_only`·`applepay_ios_only`·`mobile_approval_key_failed`·`pg_error`)는 `translateNhnkcp(key, 대체 문구, 값)` 로 낸다 —
`{{이름}}` 자리는 값으로 채우고, 번역 엔진이 없을 때만 한국어 대체 문구를 쓴다. 화면 언어(`documentElement.lang`)로 한국어/영어를 가르던 분기는 없앴다.
PG 가 돌려준 오류 원문(`resMsg`)은 그대로 보여 준다. 플러그인은 ko·en 을 갖고 일본어는 언어팩이 채운다.

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report`)·재결제 준비(`/payment/retry`)는 결제대기 주문을
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
주문 상세 화면에 결제 정보를 덧붙이는 인젝터 둘 — 관리자 주문 상세 결제수단 표시
(`adminOrderPaymentDisplayInjector.ts`), 마이페이지·비회원 주문 상세의 결제수단 표시·영수증 행·모의입금 안내
(`mypageOrderShowInjector.ts`) — 는 대상 DOM 이 그려질 때까지 재시도하며, 폴링과 서버 조회를
`resources/js/orderScreenPolling.ts` 에 맡긴다. 예전 `setInterval` 폴러는 틱이 앞선 비동기 조회를 기다리지 않아
첫 응답이 올 때까지 400ms 마다 같은 조회를 새로 보냈고(조회마다 서버가 KCP API 까지 간다), 라우트가 바뀔 때마다
폴러가 쌓였다. 마이페이지는 안내 블록을 하나 붙일 때마다 틱이 다시 돌아 영수증을 또 조회했다.

- **직렬 폴러** — `createOrderScreenPoller()`(기본 400ms · 최대 30틱)는 한 틱의 비동기 작업이 끝난 뒤에야 다음
  틱을 예약한다. 인젝터마다 폴러를 모듈 수준에 **하나만** 두고, `start(key, tick)` 은 같은 key 면 무시하고 다른
  key 면 이전 폴러를 멈춘다. 대상 경로를 벗어나면 `stop()` 한다. 틱은 `isActive()` 를 받아, 응답을 기다리는 사이
  폴러가 멈췄으면 DOM 을 건드리지 않고 끝낸다.
- **화면 진입당 1회 조회** — 영수증(`nhnkcp-receipt:{주문번호}`)·모의입금(`nhnkcp-mock-deposit:{주문번호}`)·관리자
  거래 조회는 `requestOncePerOrderScreen()` 으로만 보낸다. 한 화면 진입(같은 경로) 동안 key 별로 한 번 보내고
  결과를 **실패까지 포함해** 재사용한다. 캐시 키에 회원·비회원 토큰이 들어가 토큰이 늦게 준비되면 그때 한 번 더
  보낸다. 라우트 변경 처리 첫머리의 `enterOrderScreen()` 이 경로가 바뀌면 이전 결과를 버린다. 영수증 버튼 클릭은
  이 경로를 쓰지 않고 매번 새로 조회한다.
- **관리자 주문 상세는 레이아웃 확장의 데이터 소스를 재사용** — 같은 화면의 `admin_order_payment_query` 확장이
  `transaction-status` 를 데이터 소스 `kcp_status` 로 이미 받으므로 인젝터는 `G7Core.dataSource.get('kcp_status')`
  값을 쓴다(`readSharedDataSource`). 값이 아직 없으면 최대 20틱(약 8초) 기다리고, 그래도 없으면(확장 미적용·조회
  실패) 화면 진입당 한 번만 직접 조회한다. 라우트 전환 직후 남아 있는 이전 주문 값을 새 값으로 오인하지 않도록
  `history.pushState`·`popstate` 직후 `rememberLeavingDataSources(['kcp_status'])` 로 떠나는 값을 기억해 두고, 그
  값과 같은 객체는 쓰지 않는다. 이 재사용 전에는 주문 상세를 열 때마다 거래 조회가 두 번 나갔다.
- **주문 데이터 로딩 대기** — 마이페이지 인젝터는 `isOrderDataLoading()`(데이터 컨텍스트에 `order` 키가 있고 값이
  아직 `undefined`)인 동안 기다린다. 「주문 정보 없음」 분기(다른 결제대행사 주문·비회원 화면에서 모의입금 안내를
  찾는 분기)는 불러오기가 끝난 뒤에만 탄다.
- **모의입금 조회는 로그인 전용** — `vbank-mock-deposit-info` 는 `auth:sanctum` 라우트라 비회원 조회 토큰으로는 늘
  401 이다. `fetchMockDepositInfo()` 는 회원 인증 토큰(`getAuthToken()`)이 없으면 요청하지 않고 `null` 을 돌려준다.
  이 두 가드가 없던 때는 비회원 주문 상세가 불러오는 도중 다른 결제대행사 주문에도 모의입금 조회를 보내 401 이 났다.

**깨뜨리면 안 되는 것**: 인젝터 안에서 `setInterval` 이나 직접 `fetch` 루프로 되돌리지 않는다 · 결과 캐시에서
실패를 빼지 않는다 · 레이아웃 확장의 데이터 소스 ID `kcp_status` 를 바꾸면 인젝터 상수 `STATUS_DATA_SOURCE` 도 함께
바꾼다(어긋나면 매번 20틱을 기다린 뒤 직접 조회로 떨어져 중복 조회가 되살아난다) · 모의입금 라우트의 미들웨어를
바꾸면 토큰 가드도 다시 맞춘다. 회귀는 Vitest `resources/js/__tests__/orderPaymentDisplayInjectors.test.ts` 가 지킨다.
<!-- @intent END -->
