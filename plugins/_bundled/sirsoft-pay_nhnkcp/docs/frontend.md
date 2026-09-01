# NHN KCP — 프론트엔드

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
`sirsoft-pay_kginicis`와 마찬가지로 이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면
하나뿐입니다 — 체크아웃·주문상세·마이페이지의 결제 UI는 이 플러그인 소유가 아니라
§레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는 조각)으로 존재합니다.
<!-- @intent END -->

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
진입점을 찾기 때문입니다(§CLAUDE.md "재등록 진입점"). KCP 결제창 스크립트 자체는 이
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
