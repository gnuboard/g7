# 나이스페이먼츠 — 프론트엔드

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
다른 PG 플러그인들과 마찬가지로 이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면
하나뿐입니다 — 체크아웃·주문상세·마이페이지의 결제 UI는 이 플러그인 소유가 아니라
§레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는 조각)으로 존재합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 3개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_nicepayments.requestPayment` |
| `setPaymentMethod` | `sirsoft-pay_nicepayments.setPaymentMethod` |
| `copyToClipboard` | `sirsoft-pay_nicepayments.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`setPaymentMethod`가 별도로 필요한 이유는 나이스페이먼츠 간편결제 버튼(네이버페이/카카오페이/
삼성페이/애플페이/PAYCO/11pay/SSG페이/L.pay 8종)이 레이아웃 컴포넌트가 아니라 PG가
제공하는 DOM을 그대로 쓰기 때문입니다 — React 상태로 선택 하이라이트를 그리는 대신 DOM을
직접 조작해 선택된 버튼에 테두리를 입힙니다(`updateEasyPayButtonStyles`). `requestPayment`
하나로 PC/모바일 2가지 프로토콜(§docs/architecture.md "인증→승인 2단계")을 모두 처리합니다.

`copyToClipboard` 는 플러그인 설정의 가상계좌 입금통보 주소를 복사합니다(상점관리자에 그대로 붙여 넣는 값).
설정 화면이 어느 관리자 템플릿으로 그려지든 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않고 플러그인이 직접 둡니다(코어에는 클립보드 핸들러가 없습니다). `navigator.clipboard` 가 막힌 비보안(HTTP) 접속에서는 숨긴 입력칸 복사로 내려갑니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftNicepayments` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftNicepayments`로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로 재등록
진입점을 찾기 때문입니다(§코어 AGENTS.md "재등록 진입점"). 나이스페이먼츠 결제창 스크립트
자체는 이 진입점이 미리 로드하지 않습니다 — `requestPayment` 핸들러가 결제 시도 시점에
동적으로 스크립트를 삽입한 뒤 `goPay()`를 호출합니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
나이스페이먼츠가 제공하는 결제창 SDK는 이 목록에 없습니다 — `requestPayment` 핸들러가
결제 시도 시점에 동적으로 로드하는 제3자 자산이라, 이 플러그인이 빌드 시 번들링하는
`dist/` 산출물과는 다른 층입니다. 결제창 자체는 PG가 그리므로
이 플러그인의 CSS 산출물(`dist/css/plugin.css`, 원본 `resources/css/main.css`)은 작습니다 — 환경설정 화면의
안내 글자색(경고·성공·위험·안내, 다크 모드 짝 포함)을 접두 `pay-nicepayments-tone-*` 클래스로 직접 제공하는 것이
전부입니다. 관리자 템플릿의 의미 글자색 클래스(`text-warning-soft` 등)에 기대면 템플릿이 그 클래스를 바꿀 때
이 화면의 색이 함께 바뀌기 때문입니다(7.1.0 때 경고 본문이 초록으로 바뀐 사례). 다른 결제 플러그인도 같은
이름 체계를 쓰지만 접두가 달라 서로를 참조하지 않습니다.
<!-- @intent END -->

## 화면 표시는 레이아웃 조각만 그린다

<!-- @intent START -->
주문 완료·마이페이지 주문 상세의 영수증과 관리자 주문 상세의 간편결제 서비스명은 모두 레이아웃 조각(`resources/extensions`)이 그린다.
브라우저 스크립트(`resources/js`)는 결제 핸들러(`requestPayment`·`setPaymentMethod`·`copyToClipboard`)만 등록한다.

#639 이전에는 주입 스크립트 넷이 화면을 직접 고쳤다 — 주소 정규식(`/shop/...`·`/mypage/...`·`/admin/...`)으로 화면을 알아보고
`history.pushState`·`replaceState` 를 덮어써 이동을 엿본 뒤, 로그인 토큰을 저장소에서 직접 읽어 원시 `fetch` 로 조회하고
결제수단 칸 글자를 바꾸거나 버튼을 덧그렸다. 쇼핑몰 경로 설정을 바꾸면 주소 정규식이 빗나가고, 덮어쓰기는 다른 확장의 덮어쓰기와
감싸는 순서에 따라 동작이 바뀌었다. 지금은 아래처럼 나뉜다.

| 화면 | 조각 | 붙는 자리 | 데이터 |
|---|---|---|---|
| 주문 완료 | `user_order_complete_receipt` | `shop/order_complete` 의 `order_complete_payment_summary` | 영수증 조회 API (마운트 조회) |
| 마이페이지·비회원 주문 상세, 신청서 상세 | `user_mypage_order_receipt` | 슬롯 `mypage_order_payment_provider_slot` | 주문 응답 + 영수증 조회 API (마운트 조회) |
| 관리자 주문 상세 | `admin_order_payment_query` | `admin_ecommerce_order_detail` 의 `tab_content_area` | 데이터 소스 `nicepay_status`·`nicepay_escrow` |
| 관리자 주문 목록 | `admin_order_list_payment_method_badge` | 확장점 `admin_order_list_payment_method_badges` | 데이터 소스 `nicepay_test_map` |

- **간편결제 서비스명** — 이커머스 주문 응답은 간편결제 주문도 기본 결제수단(신용카드)만 안다. 조각은 플러그인 API 가 주는 원 수단
  코드(영수증 조회의 `embedded_pg_provider`, 거래 조회의 `_embedded_pg_provider` — `naverpay` 등)를 번역 키
  `user.easy_pay_names.{대문자 코드}` 로 풀어 자기 행에 보인다. 이커머스의 결제수단 칸 글자는 고치지 않는다.
- **영수증 주소는 API 가 준다** — 결제사 주소(`npg.nicepay.co.kr`)를 조각에 적으면 레이아웃 편집기 저장 게이트(외부 URL 금지)가
  거절한다. 영수증 조회 API 의 `payment_receipt_url`(결제 영수증, `type=2`)·`receipt_url`(매출전표, `type=0`)·`cash_receipt_url`
  을 화면 상태(`_npMp*`·`_npOc*`)에 담아 링크로 그린다. 주소를 받지 못하면 링크를 그리지 않는다. 링크는 새 창(`target=_blank`)을
  `rel="noopener noreferrer"` 로 연다.
- **마운트 조회는 조건 블록 안쪽 행에 건다** — 엔진은 `data-if` 가 거짓인 노드에서도 마운트 동작을 돌린다. 나이스페이먼츠 결제
  조건이 걸린 바깥 블록에 걸면 다른 결제사 주문·입금 대기 주문에도, 주문을 받기 전 첫 렌더에도(주문번호 빈 주소) 조회가 나간다.
- **주문번호는 불러온 주문에서 읽는다** — 마이페이지 조각은 `order.data.order_number`, 주문 완료 조각은
  `orderData.data.order_number`. 결제 정보 카드는 주소에 주문번호가 없는 교환·반품·환불 신청서 상세에서도 같은 조각으로 쓰인다.
- 가상계좌 입금 안내도 마이페이지 조각만 그리고, 입금 기한은 쇼핑몰이 서식을 입힌 `vbank_due_at_formatted` 를 그대로 쓴다. 취소된 주문(`payment_status`·`order_status` 가 cancelled)엔
  그리지 않고, 자동 취소 경고는 입금 전에만 보인다. 입금 금액은 결제예정금액(`total_due_amount_formatted`, 마일리지 차감 뒤)을 먼저
  읽고, 그 값이 없는 비회원 응답에서만 주문금액(`total_amount_formatted`)으로 대신한다.
- 관리자 결제 조회 조각의 가상계좌 입금 통지 금액은 이커머스 원화 접두·접미 번역 키(`sirsoft-ecommerce.currency.krw_prefix`·`krw_suffix`)로
  적는다 — 고정 「원」이면 영어 관리자에게도 「5,000원」이 보인다.
- 관리자 쇼핑몰 설정의 결제수단 브랜드 마크는 이 플러그인이 결제수단 카탈로그에 싣는다(`RegisterEasyPayMethodsListener` 의
  `brand_mark` → 이커머스 `_cached_brand_mark`). 그리는 것은 화면 소유자(이커머스·템플릿) 몫이다 — 종전의 브랜드 마크 주입
  스크립트는 걷어냈다.
<!-- @intent END -->

## 비회원 주문의 자격 증명

<!-- @intent START -->
비회원 주문은 서버가 `X-Guest-Order-Token` 으로 소유자를 확인한다. 토큰은 이커머스가 약속한 공개 상태 계약
`_global.guestOrderToken`(sirsoft-ecommerce `module.json` 의 `state_contracts`)에서만 읽는다 — 코어 저장소 키(sessionStorage)나
로그인 토큰(localStorage)을 직접 읽지 않는다.

- 레이아웃 조각: 조회 `api-call` 에 `auth_mode="optional"` 과 헤더 `X-Guest-Order-Token="{{_global.guestOrderToken ?? ''}}"` 를 함께 단다.
  회원은 엔진이 Bearer 를 싣는다.
- 스크립트: `resources/js/guestOrderToken.ts` 의 `buildGuestOrderTokenHeaders()` 한 곳에서 헤더를 만들어 `G7Core.api` 요청에 싣는다.
  회원 Bearer 와 401 처리는 `G7Core.api` 몫이다. 회원 주문은 서버가 로그인 본인으로만 판정하므로 이 헤더가 함께 실려도 결과가 바뀌지 않는다.

비회원 헤더를 빼면 서버는 그 주문을 찾을 수 없다고 응답하고, 화면에는 영수증 링크가 아예 나타나지 않는다 — 예외도 콘솔 오류도
남지 않는다. 서버(`UserReceiptController`)는 비회원을 이미 지원하므로, 이 배선이 빠지면 서버 기능이 도달 불가 상태로만 남는다.
<!-- @intent END -->

### 화면 자리는 약속된 id·확장점으로만 찾는다

조각이 붙는 자리는 화면 소유자가 약속한 id·확장점뿐이다(약속 원문: 이커머스 `modules/_bundled/sirsoft-ecommerce/docs/extension-points.md`
「PG 플러그인이 …의 약속」). 화면 제목·행 이름 글자나 클래스 모양으로 자리를 찾지 않는다 — 언어·화면 폭마다 다르다. 자리가 없는
화면에서는 조각이 오류 없이 빠진다.

### 스크립트·조각이 그리는 글자는 번역 키로 낸다

결제 실패 알림(`payment.error.sdk_load_failed`·`request_failed`)은 `translateNicepay(key, 대체 문구, 값)`
(`plugins/_bundled/sirsoft-pay_nicepayments/resources/js/translate.ts`)로 낸다. 조각의 간편결제 이름은
`$t('sirsoft-pay_nicepayments.user.easy_pay_names.' + 대문자 코드)` 로 푼다 — 조각 안에 한국어 이름표를 두지 않는다.
플러그인은 ko·en 을 갖고 일본어는 언어팩이 채운다.

## 결제수단은 액션 인자로만 받는다

<!-- @intent START -->
`requestPayment` 는 결제수단을 액션 인자로만 받는다 — `paymentMethod` 를 명시하면 그 값, 아니면 서버가 저장해 내려준
`pgPaymentData.payment_method`(확장 수단이면 확장 ID 그대로). 둘 다 없으면 결제를 시작하지 않고 오류를 알린다. 종전에는 엔진
내부(`window.__templateApp`)의 화면 상태와 `'card'` 기본값까지 뒤져, 잘못된 수단으로 결제창이 열릴 수 있었다.

`setPaymentMethod` 의 간편결제 선택 표시 감시(`initEasyPayWatcher`)는 공개 API `G7Core.state.subscribe()` 로 걸고, 선택값은
`G7Core.state.getLocal()` 로 읽는다. 코어가 재초기화 때 `initPlugin` 을 다시 부르면 앞 구독의 해제 함수를 먼저 불러 리스너가
쌓이지 않게 한다.
<!-- @intent END -->

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-pay_nicepayments/payment/close-report`)는 결제대기 주문을
실패·취소로 바꾸므로 서버가 **주문 소유자만** 받아 준다(KVE-2026-2434). 주문자 이메일·전화번호는
제3자도 알 수 있어 소유 증명이 되지 못하므로 보내지 않는다.

- 회원: `G7Core.api` 가 `Authorization: Bearer …` 를 스스로 싣는다 — 호출부는 아무것도 붙이지 않는다.
- 비회원: 공개 상태 계약 `_global.guestOrderToken` 의 조회 토큰을 `X-Guest-Order-Token` 헤더로 싣는다.

보고는 `G7Core.api` 로만 보낸다. 주소는 이 플러그인의 API 상대 경로(`CLOSE_REPORT_PATH`)다 — client-config 의 `close_report_url`
은 읽지 않는다(사이트 절대 주소로 부르면 별칭 Host 화면에서 교차 출처가 되어 Bearer 가 실리지 않는다). `G7Core.api` 가 없으면
원시 `fetch` 로 우회하지 않고 보고를 건너뛴다. 보고가 거부되면(토큰 없음 403 등) 화면 흐름은 그대로 진행한다 — 결제대기 주문은
이커머스 만료 정리 잡이 정리한다.

나이스페이의 결제창 서명 발급(`sign-data`)도 `G7Core.api` 로 같은 헤더를 실어 보낸다. 실패한 같은 주문을 다시 결제할 때 서버가
주문을 결제대기로 되살리는데, 그 복구는 주문 소유자만 할 수 있기 때문이다.
<!-- @intent END -->

## 클레임 추가결제 창을 닫을 때

<!-- @intent START -->
교환·반품·환불 신청서의 추가결제는 주문 결제가 아니다. 이커머스가 결제 데이터에 `pg_payment_data.claim_cancel_return_url`
(회원은 신청서 상세 `?claim_payment=cancelled`, 비회원은 주문 조회 화면 — `ClaimPaymentRedirect::cancelled`)을 실어 보내면,
결제창을 닫을 때 닫힘 보고를 보내지 않고 그 주소로 이동한다. 닫힘 보고는 결제대기 **주문**을 실패로 바꾸는 요청이라
클레임 결제에는 대상이 없고, 이동하지 않으면 신청서 상세가 「결제 진행 중…」 버튼에 멈춘 채 안내도 뜨지 않는다.
신청은 그대로 남고, 신청서 상세가 「결제를 취소하셨습니다」 안내와 함께 [결제하기]를 다시 연다.

처리 위치: `resources/js/handlers/requestPayment.ts` 의 결제창 닫힘 처리(정리 후 이동).
<!-- @intent END -->
