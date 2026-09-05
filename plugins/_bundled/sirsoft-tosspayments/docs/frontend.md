# 토스페이먼츠 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 1개 (루트: `resources/layouts`).

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
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-tosspayments.requestPayment` |
<!-- @generated:handlers END -->

<!-- @intent START -->
핸들러가 이것 하나뿐인 이유는 이 플러그인이 통합결제창 SDK 호출 이후를 전부 브라우저
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
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
토스 SDK 자체는 이 목록에 없습니다 — `requestPayment` 핸들러가 결제 시도 시점에 동적으로
로드하는 제3자 자산이라, 이 플러그인이 빌드 시 번들링하는 `dist/` 산출물과는 다른 층입니다.
다른 PG 플러그인과 달리 `editor-spec.json`이 없는 것은 이 플러그인이 레이아웃 편집기에서
커스터마이즈 가능한 전용 컴포넌트를 노출하지 않기 때문입니다 — 결제 UI는 코어 기본
컴포넌트와 §레이아웃 확장 조각만으로 구성됩니다.
<!-- @intent END -->
