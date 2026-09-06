# KG 이니시스 — 프론트엔드

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
이 플러그인이 소유한 화면 레이아웃은 관리자 설정 화면 하나뿐입니다 — 체크아웃·주문상세의
결제 UI는 이 플러그인 소유가 아니라 §레이아웃 확장(다른 확장/템플릿 레이아웃에 주입되는
조각)으로 존재합니다. "화면"과 "레이아웃 확장 조각"을 헷갈리면 체크아웃 결제 버튼을 찾으러
`resources/layouts/`를 뒤지게 되는데, 실제로는 `resources/extensions/`에 있습니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 1개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `requestPayment` | `sirsoft-pay_kginicis.requestPayment` |
<!-- @generated:handlers END -->

<!-- @intent START -->
`requestPayment` 하나로 PC/모바일/CBT 3가지 프로토콜을 전부 처리합니다 — 체크아웃 버튼은
결제수단·통화가 무엇이든 이 핸들러 하나만 호출하고, PC 결제창을 열지 모바일 폼을 제출할지
CBT 인증 URL로 이동할지는 핸들러 내부에서 서버 응답(§API `/payment/*/signature`,
`/payment/cbt/hash-data`)에 따라 분기합니다. 레이아웃 JSON 작성자가 결제수단별로 다른
핸들러를 호출할 필요가 없다는 뜻입니다.
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
