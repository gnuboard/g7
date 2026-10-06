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

## 화면 표시는 레이아웃 조각, 상태는 공개 API

<!-- @intent START -->
이 플러그인의 스크립트는 남의 화면을 찾거나 고치지 않는다. 주문 상세·주문 완료·관리자 화면에 붙는 표시는 모두
레이아웃 조각(`resources/extensions/*.g7.html`)이 자기 데이터 소스로 그리고, 스크립트는 핸들러 등록과 결제창 열기·
결제창 닫힘 보고만 맡는다 (#639). 상태는 공개 API(`G7Core.state.get()`·`subscribe()`, `G7Core.api`)와 공개 상태 계약
(`_global.guestOrderToken`)으로만 읽는다.

| 화면 | 맡는 곳 |
|---|---|
| 관리자 주문 상세 — KCP 거래 정보·간편결제 원 수단·에스크로 배송 등록 | `admin_order_payment_query` (데이터 소스 `kcp_status`·`kcp_escrow_delivery`) |
| 관리자 주문 목록 — 테스트 결제 배지 | `admin_order_list_payment_method_badge` (확장점 `admin_order_list_payment_method_badges`) |
| 관리자 결제수단 설정 — 브랜드 배지 | 결제수단 카탈로그 `brand_mark` 선언(`RegisterEasyPayMethodsListener`, 병합 결과 `_cached_brand_mark`). 그리는 일은 이커머스 관리자 결제수단 목록 몫이다 |
| 관리자 결제수단 설정 — 애플페이 iOS 전용 안내 | 카탈로그 설명 `payment_methods.applepay.description` |
| 주문서 — 비 iOS 기기에서 애플페이 감춤 | 카탈로그 `requires_ios` (템플릿이 `_global.appConfig.isIos` 로 거른다) |
| 내 주문·신청서 상세 — 가상계좌 안내·매출전표·테스트 모드 모의입금 | `user_order_show` (확장점 `mypage_order_payment_provider_slot`, 데이터 소스 `nhnkcp_mock_deposit`) |
| 주문 완료 — 가상계좌 안내·매출전표 | `user_order_complete_receipt` (대상 id `order_complete_payment_summary`) |

**깨뜨리면 안 되는 것**: `window.fetch`·`history.pushState`·`history.replaceState` 를 덮어쓰지 않는다 · 로그인 토큰을
저장소에서 읽어 Bearer 를 붙이지 않는다(`G7Core.api` 가 싣고 401 도 처리한다) · 화면을 주소 정규식이나 제목 글자,
클래스 모양으로 찾지 않는다. 정적 검사 `extension-private-core-internals` 가 앞의 둘을 막는다.
<!-- @intent END -->

### 매출전표 요청의 자격 증명

주문 상세 카드의 「매출전표 보기」와 주문 완료의 「매출전표 불러오기」는 회원·비회원이 함께 쓴다. 두 조각의 요청은
선택 인증(`auth_mode=optional`)으로 회원 토큰을 싣고, 비회원은 공개 상태 `_global.guestOrderToken` 을
`X-Guest-Order-Token` 헤더로 싣는다(전역 헤더는 이 플러그인 주소에 조회 토큰을 붙이지 않는다). 영수증 조회 응답은
공통 형식(`{ success, message, data }`)이라 조각은 `$prev.data.*`(내 주문 상세)·`response.data.*`(주문 완료)를 읽는다.
최상위에서 읽으면 버튼은 그려져도 빈 주소로 팝업이 열린다.

### 테스트 모드 모의입금 도구

`user_order_show` 의 데이터 소스 `nhnkcp_mock_deposit` 은 로그인 전용 주소(`auth:sanctum`)라 `auth_mode=required` 로
두어 비회원이면 요청하지 않고, 주소에 주문번호가 있는 주문 상세에서만(`if: route.order_number`) 부른다 — 신청서 상세는
주소가 신청번호다. 서버는 조건(테스트 모드·본인 또는 관리자·입금 대기 가상계좌)이 맞지 않으면 `data.available=false`
로 답하고, 조각은 NHN KCP 가상계좌 주문이면서 `available=true` 일 때만 KCP 테스트 입금통보 폼을 그린다. 글자는
`user.mock_*` 키로 그린다(일본어는 언어팩).

### 스크립트가 그리는 글자는 번역 키로 낸다

결제 안내·오류 문구(`payment.error.krw_only`·`applepay_ios_only`·`mobile_approval_key_failed`·`pg_error`)는 `translateNhnkcp(key, 대체 문구, 값)` 로 낸다 —
`{{이름}}` 자리는 값으로 채우고, 번역 엔진이 없을 때만 한국어 대체 문구를 쓴다. 화면 언어(`documentElement.lang`)로 한국어/영어를 가르던 분기는 없앴다.
PG 가 돌려준 오류 원문(`resMsg`)은 그대로 보여 준다. 플러그인은 ko·en 을 갖고 일본어는 언어팩이 채운다.

## 결제창 진입 핸들러의 결제수단

<!-- @intent START -->
`requestPayment` 는 결제수단을 **액션 인자로만** 정한다 — 호출부가 넘긴 `paymentMethod`, 없으면 같은 인자의
`pgPaymentData.payment_method`(서버가 주문에 저장한 값, 확장 수단이면 확장 ID 그대로)다. 화면 상태(`_local`)나
옛 `pay_method` 키는 보지 않는다. 둘 다 없는 응답(구버전 서버)만 신용카드로 연다. 결제 통화는 서버가 주문 스냅샷으로
정해 실은 `pgPaymentData.currency` 를 쓴다(KCP 는 KRW 전용).
<!-- @intent END -->

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-pay_nhnkcp/payment/close-report`)·재결제 준비(`/payment/retry`)는 결제대기 주문을
실패·취소로 바꾸므로 서버가 **주문 소유자만** 받아 준다(KVE-2026-2434). 주문자 이메일·전화번호는
제3자도 알 수 있어 소유 증명이 되지 못하므로 보내지 않는다.

- 회원: `G7Core.api` 가 `Authorization: Bearer …` 를 스스로 싣는다 — 호출부는 아무것도 붙이지 않는다.
- 비회원: 공개 상태 `_global.guestOrderToken` 을 `X-Guest-Order-Token` 헤더로 싣는다(`resources/js/guestOrderToken.ts`).
  회원 주문은 서버가 로그인 사용자로만 판정하므로 이 헤더가 함께 실려도 결과가 같다.

요청은 `G7Core.api` 로만 보낸다 — `fetch` 로 따로 보내는 폴백은 없다. 서버(client-config)가 주는 보고 주소는 사이트 절대
URL(`https://사이트/plugins/…/payment/close-report`, `/api` 없음)이므로 `toApiPath()` 가 경로만 꺼내 같은 사이트 API 로
보낸다. 출처는 버리므로 조회 토큰이 다른 사이트로 새지 않는다. 재결제 준비가 실패하면 예외를 그대로 던져 결제창을 열지 않고,
닫힘 보고 실패는 경고만 남긴다(결제대기 주문은 이커머스 만료 정리 잡이 정리한다).

결제 실패로 돌아온 화면의 보고(`reportPaymentFailureOnReturn`)는 핸들러 등록이 성공한 뒤(코어 준비 뒤) 부팅 때 한 번만 돈다
— 재등록 진입점(`initPlugin()`)은 핸들러 등록만 다시 한다. 템플릿이 세션 저장소의 조회 토큰을 전역 상태에 되살리기 전일 수
있으므로 `waitForGuestOrderToken()` 이 `G7Core.state.subscribe()` 로 최대 3초 기다린 뒤 보낸다(회원이면 토큰이 생기지 않아
제한 시간 뒤 보낸다).
<!-- @intent END -->
