# 비즈뿌리오 메시지 발송 — 프론트엔드

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
플러그인 설정 화면(`plugin_settings`) 하나뿐입니다. **이 플러그인의 운영 UI 대부분은 레이아웃이
아니라 확장 조각 7개**이며, 알림 설정·알림 템플릿 편집·발송 이력 화면 안에 들어갑니다.

설정 화면 자체가 두 역할을 합니다 — 환경설정(크리덴셜·검수 모드·webhook URL 복사)과 "알림
템플릿 관리"(전체 알림의 템플릿 상태 조회·검색·필터). 후자를 별도 메뉴로 빼지 않은 것은 알림별
편집이 이미 알림 설정 화면 안에 있어, 여기서는 **전체를 훑어보는 용도**만 필요하기 때문입니다.

`plugin_settings.json` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면이 사라집니다.

레이아웃·조각 JSON 만 고쳤다면 빌드는 필요 없고
`php artisan plugin:update sirsoft-message_bizppurio --force` 로 반영합니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 2개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `insertVariable` | `sirsoft-message_bizppurio.insertVariable` |
| `uploadTemplateImage` | `sirsoft-message_bizppurio.uploadTemplateImage` |
<!-- @generated:handlers END -->

<!-- @intent START -->
둘뿐이며 **알림톡 템플릿 작성 폼**을 위한 것입니다.

| 핸들러 | 하는 일 |
|---|---|
| `insertVariable` | 본문 입력 커서 위치에 `#{변수}` 형식의 알림 변수를 삽입 |
| `uploadTemplateImage` | 강조 유형이 "이미지" 일 때 쓸 이미지를 업로드 |

`uploadTemplateImage` 는 **카카오 규격 검증**이 붙는 자리입니다 — jpg/png · 500KB 이하 ·
가로 500px 이상 · 가로:세로 2:1. 규격 위반은 업로드 단계에서 사유와 함께 거부해야 합니다.
통과시키면 검수 신청 후 카카오가 반려하는데, 그때는 이미 며칠이 지나 있습니다.

핸들러 TS 를 고치면 빌드가 필요합니다 — `php artisan plugin:build` 후
`plugin:update --force`. 프론트엔드 변경은 Playwright spec 을 함께 갱신·실행합니다.
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| 엔트리 파일 | `resources/js/index.ts` |
| 전역 객체 | `window.__SirsoftMessageBizppurio` |
| 재등록 진입점 | `initPlugin()` |

로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
`window.__SirsoftMessageBizppurio.initPlugin()` 이 재등록 진입점입니다. 로케일을 전환하면
코어가 이 함수를 다시 불러 핸들러를 재등록하는데, 없거나 이름이 다르면 **로케일 전환 직후
템플릿 작성 폼의 변수 삽입·이미지 업로드가 무반응**이 됩니다 — 오류도 토스트도 남지 않습니다.

진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
커밋되는 산출물은 `dist/js/plugin.iife.js` 하나이며 동봉 제3자 자산은 없습니다 — 이 플러그인의
프론트엔드는 폼 보조 기능 둘뿐이라 외부 라이브러리가 필요하지 않습니다.

`dist/` 는 **배포 산출물**입니다. 소스(`resources/js/**`)를 고치면 `--production` 으로 다시
굽고 그 결과를 함께 커밋합니다. 새 소스 리터럴이 `dist/` 에 없으면 stale 빌드이며, 브라우저가
받는 것은 커밋된 `dist/` 이므로 소스만 고친 변경은 사이트에 반영되지 않습니다.
`sourceMappingURL` 참조를 남기지 않습니다(`.map` 은 커밋 대상이 아니라 404 가 됩니다).

나중에 제3자 자산이 필요해지면 외부 CDN 이 아니라 `dist/vendor/{lib}/{version}/` 에 동봉하고
same-origin 으로 서빙합니다 — CDN 도달 실패는 예외도 서버 로그도 남기지 않고 화면 기능만
조용히 사라집니다. 자산 URL 은 문자열로 조립하지 않고 `G7Core.asset.plugin` 을 씁니다.
<!-- @intent END -->
