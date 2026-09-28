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
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_nicepayments.requestPayment` |
| `setPaymentMethod` | `sirsoft-pay_nicepayments.setPaymentMethod` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`setPaymentMethod`가 별도로 필요한 이유는 나이스페이먼츠 간편결제 버튼(네이버페이/카카오페이/
삼성페이/애플페이/PAYCO/11pay/SSG페이/L.pay 8종)이 레이아웃 컴포넌트가 아니라 PG가
제공하는 DOM을 그대로 쓰기 때문입니다 — React 상태로 선택 하이라이트를 그리는 대신 DOM을
직접 조작해 선택된 버튼에 테두리를 입힙니다(`updateEasyPayButtonStyles`). `requestPayment`
하나로 PC/모바일 2가지 프로토콜(§docs/architecture.md "인증→승인 2단계")을 모두 처리합니다.
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
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
나이스페이먼츠가 제공하는 결제창 SDK는 이 목록에 없습니다 — `requestPayment` 핸들러가
결제 시도 시점에 동적으로 로드하는 제3자 자산이라, 이 플러그인이 빌드 시 번들링하는
`dist/` 산출물과는 다른 층입니다. CSS 산출물이 없는 것은 결제창 자체는 PG 가 그리고, 이
플러그인은 간편결제 버튼 같은 최소한의 UI만 코어 컴포넌트로 구성하기 때문입니다.
<!-- @intent END -->

## 비회원 주문의 자격 증명

<!-- @intent START -->
영수증·결제수단 표시는 주문 정보를 서버에서 다시 조회해 만든다. 이 요청은 레이아웃의
`globalHeaders` 배선을 타지 않는 직접 호출(`fetch`)이므로, 자격 증명을 호출부가 직접 실어야 한다.

- 회원: `Authorization: Bearer …`
- 비회원: `X-Guest-Order-Token: …` (코어 storageHandlers 가 sessionStorage 에 저장, 전역 상태 폴백)

두 경로 모두 `plugins/_bundled/sirsoft-pay_nicepayments/resources/js/guestOrderToken.ts` 의 `buildOrderRequestHeaders()` 한 곳을 거친다.
비회원 분기를 비우면 서버는 그 주문을 찾을 수 없다고 응답하고, 화면에는 영수증 버튼이 아예
나타나지 않는다 — 예외도 콘솔 오류도 남지 않아 운영자가 원인을 알 수 없다. 서버(`UserReceiptController`)는
비회원을 이미 지원하므로, 이 배선이 빠지면 서버 기능이 도달 불가 상태로만 남는다.

주문 상세 화면의 경로 판정(`ORDER_SHOW_RE`)도 회원(`/mypage/orders/{N}`)과
비회원(`/shop/guest/orders/{N}`) 두 주소를 함께 매칭해야 한다. 한쪽을 빼면 그 화면에서는
조회가 시작조차 되지 않는다.
<!-- @intent END -->

마이페이지 주문 상세의 영수증 조각(`resources/extensions/user_mypage_order_receipt.g7.html`)도 결제사 주소를 글자
그대로 적지 않는다 — 적으면 레이아웃 편집기의 저장 게이트(외부 URL 금지)가 이 조각을 거절한다. 조각은 마운트 때
영수증 조회 API 를 불러 `payment_receipt_url`(첫 출시부터 링크가 열어 온 `type=2` 주소)을 로컬 상태
(`_npMpPaymentReceiptUrl`)에 담고 그 값으로 「결제 영수증」 링크를 그린다. 회원은 토큰(`auth_mode=optional`),
비회원은 `X-Guest-Order-Token` 헤더를 실어 같은 API 를 부른다. 주소를 받지 못하면 링크를 그리지 않는다.
주문번호는 주소가 아니라 불러온 주문(`order.data.order_number`)에서 읽는다 — 이 결제 정보 카드(결제 영수증 링크·현금영수증 조회 버튼)는 주소에 주문번호가 없는
교환·반품·환불 신청서 상세(회원·비회원)에서도 같은 조각으로 쓰인다.
