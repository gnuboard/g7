# 토스페이먼츠 — 프론트엔드

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
하나뿐입니다 — 체크아웃·주문상세의 결제 UI는 이 플러그인 소유가 아니라 §레이아웃 확장
(다른 확장/템플릿 레이아웃에 주입되는 조각)으로 존재합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-tosspayments.requestPayment` |
| `copyToClipboard` | `sirsoft-tosspayments.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
결제 핸들러가 `requestPayment` 하나뿐인 이유는 이 플러그인이 통합결제창 SDK 호출 이후를 전부 브라우저
리다이렉트에 위임하기 때문입니다(§AGENTS.md "1. 이 확장은 무엇인가") — 다른 PG처럼 결제
수단 선택 UI를 DOM으로 직접 조작할 필요가 없습니다. `order_sheet_mode`에 따라 통합결제창
하나(카드 한 장)를 열지, 사용자가 고른 개별 토스 결제수단(`params.paymentMethodId`)을
지정해 열지가 갈리지만(`params.paymentMethod`, 미지정 시 `_local.paymentMethod` 참조) 그
분기도 이 핸들러 하나 안에서 처리합니다.

구매자가 결제창을 닫으면(`USER_CANCEL`) 이 핸들러가 이커머스 모듈의 결제 취소 기록
엔드포인트(`orders/{orderNumber}/cancel-payment`)를 부릅니다. 이 엔드포인트는 회원과
비회원이 공유하고 서버가 소유권을 대조하므로, 비회원 주문이면 `_global.guestOrderToken`
을 `X-Guest-Order-Token` 헤더로 함께 보내야 합니다 — 그 토큰은 주문 생성 직후 체크아웃이
발급합니다. 헤더가 빠지면 서버가 404 로 거부하는데, 여기서는 `console.warn` 만 남기고
취소 안내 모달이 평소대로 뜨기 때문에 이력이 유실된 사실이 화면에 드러나지 않습니다.
`G7Core.api` 는 레이아웃의 `globalHeaders` 를 타지 않으므로 헤더는 호출부가 직접 붙입니다.

`copyToClipboard` 는 플러그인 설정의 입금통보 웹훅 주소를 복사합니다(토스 상점관리자에 그대로 붙여 넣는 값). 주소는 `webhook_info` 데이터소스(`/api/plugins/sirsoft-tosspayments/admin/webhook-url`)가 내려주는 사이트 주소 포함 전체 URL 이며, 화면 표시와 복사가 같은 값(`_local.webhookInfo.url`)을 씁니다. 응답 전에는 경로로 폴백합니다.
설정 화면이 어느 관리자 템플릿으로 그려지든 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않고 플러그인이 직접 둡니다(코어에는 클립보드 핸들러가 없습니다). `navigator.clipboard` 가 막힌 비보안(HTTP) 접속에서는 숨긴 입력칸 복사로 내려갑니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftTosspayments` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftTosspayments`로 노출되는 이유는 코어가 로케일 전환 시 이 이름으로 재등록
진입점을 찾기 때문입니다(§코어 AGENTS.md "재등록 진입점"). 토스 SDK(`js.tosspayments.com/v2/standard`)
자체는 이 진입점이 미리 로드하지 않습니다 — `requestPayment` 핸들러가 결제 시도 시점에
동적으로 로드합니다.
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
토스 SDK 자체는 이 목록에 없습니다 — `requestPayment` 핸들러가 결제 시도 시점에 동적으로
로드하는 제3자 자산이라, 이 플러그인이 빌드 시 번들링하는 `dist/` 산출물과는 다른 층입니다.
다른 PG 플러그인과 달리 `editor-spec.json`이 없는 것은 이 플러그인이 레이아웃 편집기에서
커스터마이즈 가능한 전용 컴포넌트를 노출하지 않기 때문입니다 — 결제 UI는 코어 기본
컴포넌트와 §레이아웃 확장 조각만으로 구성됩니다.
<!-- @intent END -->

### 관리자 결제수단 설정의 브랜드 표시는 카탈로그 선언이 단일 출처다

간편결제 수단(토스페이·카카오페이·네이버페이·페이코·삼성페이)의 브랜드 배지 색·글자는
`RegisterTossPaymentMethodsListener` 가 결제수단 카탈로그에 `brand_mark` 로 선언하고, 이커머스가 그 값을
`_cached_brand_mark` 로 실어 화면에 내려준다. 그 값을 그리는 일은 화면을 소유한 쪽(이커머스 관리자 화면·
사용자 템플릿 주문서)이 한다. 이 플러그인은 남의 화면 DOM 을 고치지 않고 `history.pushState`·`replaceState`
를 덮어쓰지 않는다 — 덮어쓰기는 확장끼리 서로를 감싸 순서에 따라 동작이 바뀌고, 엔진이 화면 이동 방식을
바꾸면 조용히 깨진다.

## 결제창 닫힘 보고의 소유자 증명

<!-- @intent START -->
결제창 닫힘 보고(`POST /api/plugins/sirsoft-tosspayments/payment/close-report`)는 결제대기 주문을
실패·취소로 바꾸므로 서버가 **주문 소유자만** 받아 준다(KVE-2026-2434). 주문자 이메일·전화번호는
제3자도 알 수 있어 소유 증명이 되지 못하므로 보내지 않는다.

- 회원: `G7Core.api` 가 `Authorization: Bearer …` 를 스스로 싣고 401 을 처리한다 — 호출부는 아무것도 붙이지 않는다.
- 비회원: 쇼핑몰 공개 상태 계약 `_global.guestOrderToken`(사용자 템플릿이 주문 생성·조회 응답의 토큰을 올린다)을
  `X-Guest-Order-Token` 헤더로 싣는다. 회원 주문은 서버가 이 헤더를 보지 않으므로 토큰이 있으면 그대로 싣는다.

헤더는 `resources/js/guestOrderToken.ts` 의 `buildGuestOwnershipHeaders()` 한 곳에서 만들고, 결제 취소 기록
(`requestPayment`)도 같은 함수를 쓴다. 브라우저 저장소의 로그인 토큰이나 템플릿의 저장소 키를 직접 읽지 않는다.
요청은 언제나 `G7Core.api` 로 보낸다 — 코어 클라이언트를 우회하는 `fetch` 폴백은 없다.

플러그인 스크립트는 화면 초기화(템플릿의 init_actions)보다 먼저 실행되므로, 보고는 템플릿이
`_global.guestOrderToken` 키를 세울 때까지 기다린다(`waitForGuestOrderTokenContract()`, 100ms 간격 최대 5초).
키를 세우지 않는 템플릿이면 상한 뒤 헤더 없이 보낸다. 토큰이 없으면 서버가 403 으로 거부하고 화면 흐름은
그대로 진행한다 — 결제대기 주문은 이커머스 만료 정리 잡이 정리한다.
<!-- @intent END -->
