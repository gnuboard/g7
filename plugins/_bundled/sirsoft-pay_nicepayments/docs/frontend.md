# 나이스페이먼츠 — 프론트엔드

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
