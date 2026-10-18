# 비즈뿌리오 메시지 발송 — 프론트엔드

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
플러그인 설정 화면(`plugin_settings`) 하나뿐입니다. **이 플러그인의 운영 UI 대부분은 레이아웃이
아니라 확장 조각 7개**이며, 알림 설정·알림 템플릿 편집·발송 이력 화면 안에 들어갑니다.

설정 화면 자체가 두 역할을 합니다 — 환경설정(크리덴셜·검수 모드·webhook URL 복사)과 "알림
템플릿 관리"(전체 알림의 템플릿 상태 조회·검색·필터). 후자를 별도 메뉴로 빼지 않은 것은 알림별
편집이 이미 알림 설정 화면 안에 있어, 여기서는 **전체를 훑어보는 용도**만 필요하기 때문입니다.

`plugin_settings.g7.html` 은 **파일 이름이 계약**입니다. 코어가 플러그인 디렉토리의 이 고정 경로를
찾아 설정 화면을 그리므로, 이름을 바꾸면 설정 화면이 사라집니다.

제목이 식(`|name={{…}}`)인 모달 셋(`modal_bizppurio_template` · `modal_bizppurio_sms` ·
`modal_bizppurio_rejection`)은 `<meta editor_label="…"/>` 로 레이아웃 편집기 전용 이름을 둡니다 —
없으면 편집기 모달 목록에 제목 식 원문이 그대로 보입니다. 설정 화면의 복합 컴포넌트(`page-header` ·
`toggle` · `pagination` · 모달)에는 `data-type="composite"` 가 붙어 컴파일이 템플릿의 컴포넌트
목록과 무관하게 종류를 판정합니다. 리포트 주소 「복사」 버튼은 플러그인 핸들러를 네임스페이스째
(`sirsoft-message_bizppurio.copyToClipboard`) 부릅니다 — 접두사 없는 `copyToClipboard` 는
등록된 핸들러가 없어 눌러도 아무 일이 일어나지 않았습니다.

작성 창의 「저장」(임시 저장) 두 버튼(신규 `POST` · 기존 `PUT`)은 body 에 `:draft="true"` 를
싣습니다. 서버는 이 표시가 있을 때만 필수 조건을 걷어 내므로(→ [AGENTS.md](../AGENTS.md)
「임시 저장과 검수 신청의 검증 분담」), 이 속성을 빼면 쓰다 만 초안이 다시 422 로 막힙니다.
「저장 후 검수 신청」에는 싣지 않습니다. 코어 알림 템플릿 [편집] 창 하단에 주입되는 통합 「저장」
(`resources/extensions/notification_template_form_footer_actions`)도 같은 규약입니다 — 생성·갱신 두 갈래에
`:draft="true"` 를 싣고, 발송 설정만 보내는 `delivery` 갈래와 「저장 후 검수 신청」 두 갈래에는 싣지 않습니다.
「알림 템플릿 관리」 탭 안내가 「알림 설정 화면과 동일하게 동작합니다」라고 하므로 두 화면의 「저장」이 갈리면 안 됩니다.

레이아웃·조각 JSON 만 고쳤다면 빌드는 필요 없고
`php artisan plugin:update sirsoft-message_bizppurio --force` 로 반영합니다.
빈 값도 **모양**이 계약입니다. 이름표로 값을 꺼내는 자리(`results?.[row.id]` 처럼)는 비어도 map(`{}`)이고,
순서가 있는 목록은 빈 배열(`[]`)입니다. 마크업에서는 `:value="{}"` 와 `:value="[]"` 로 갈리며,
화면이 읽기만 할 때는 둘 다 똑같이 비어 보이지만 그 값이 서버로 실리거나 레이아웃을 `.json`
원문으로 설치하면 서버가 대상을 찾지 못해 오류도 로그도 없이 적용되지 않습니다. 이 플러그인에서는 알림 로그의 `fallback.data.results`(`row.id` 키)와 통합 탭 3종의 `fallback.data.templates`(`notification_type` 키 요약 맵)가 그 자리입니다.

검수 신청이 빠진 필수값으로 거절되면 서버는 `errors` 에 항목별 문구를 모두 싣고, 요약 문장(`message`)에는 첫 항목과
「외 N개」만 담습니다. 그래서 작성 창은 두 곳(코어 편집 창 섹션·「알림 템플릿 관리」 탭 창) 모두 실패 때
`bz_tpl_modal.errors` 를 채워 항목 목록으로 그리고, 목록 행의 [검수 신청]은 항목 문구를 이어 붙여 알립니다.
삭제 완료 안내는 응답의 `kakao_deleted`·`kakao_skip_reason` 으로 가릅니다 — `not_registered`(카카오에 등록 안 됨),
`state_not_deletable`(상태 제약), 그 밖(카카오 사유·서버 연결 실패 문구)은 그 사유를 안내 뒤에 붙입니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
핸들러 3개 (정의: `resources/js/handlers/index.ts`).

| 핸들러 | 레이아웃에서 부르는 이름 |
|---|---|
| `insertVariable` | `sirsoft-message_bizppurio.insertVariable` |
| `uploadTemplateImage` | `sirsoft-message_bizppurio.uploadTemplateImage` |
| `copyToClipboard` | `sirsoft-message_bizppurio.copyToClipboard` |
<!-- @generated:handlers END -->

<!-- @intent START -->
셋이며 둘은 **알림톡 템플릿 작성 폼**, 하나는 연결 설정 화면을 위한 것입니다.

| 핸들러 | 하는 일 |
|---|---|
| `insertVariable` | 본문 입력 커서 위치에 `#{변수}` 형식의 알림 변수를 삽입 |
| `uploadTemplateImage` | 강조 유형이 "이미지" 일 때 쓸 이미지를 업로드 |
| `copyToClipboard` | 리포트(발송 결과 통보) 수신 주소를 클립보드에 복사 — 비즈뿌리오 콘솔에 그대로 붙여 넣는 값 |

`copyToClipboard` 는 설정 화면이 어느 관리자 템플릿으로 그려지든 같게 동작해야 하므로 특정 템플릿의 핸들러에 기대지 않고 플러그인이 직접 둡니다(코어에는 클립보드 핸들러가 없습니다). `navigator.clipboard` 가 막힌 비보안(HTTP) 접속에서는 숨긴 입력칸 복사로 내려갑니다.

`uploadTemplateImage` 는 **카카오 규격 검증**이 붙는 자리입니다 — jpg/png · 500KB 이하 ·
가로 500px 이상 · 가로:세로 2:1. 규격 위반은 업로드 단계에서 사유와 함께 거부해야 합니다.
통과시키면 검수 신청 후 카카오가 반려하는데, 그때는 이미 며칠이 지나 있습니다.
업로드는 multipart 지만 코어 API 클라이언트(`G7Core.api.post(url, formData)`)로 보냅니다 — Bearer 부착,
401 처리(토큰 갱신·재로그인 유도), 운영자 로케일(`Accept-Language`)을 코어가 맡으므로 핸들러가 로그인
토큰을 읽어 헤더를 만들지 않습니다. 4xx·5xx 는 예외로 오므로 응답 본문의 사유를 꺼내 배너에 싣습니다.

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
| `dist/css/plugin.css` | 빌드 산출물 (커밋 대상) |
| `dist/js/plugin.iife.js` | 빌드 산출물 (커밋 대상) |
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

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
