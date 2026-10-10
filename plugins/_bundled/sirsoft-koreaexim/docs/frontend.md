# 수출입은행 환율 — 프론트엔드

> 레이아웃·액션 핸들러·전역 진입점·에셋 · 진입점: [AGENTS.md](../AGENTS.md)

## 레이아웃

<!-- @generated:layouts START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
레이아웃 12개 (루트: `resources/layouts` — 작성 지점은 `.g7.html`, 짝 `.json` 은 `php artisan layout:decompile <경로> --sync` 굽기 산출).

| 그룹 | 개수 |
|---|---|
| `admin` | 12개 |

| 레이아웃 | 그룹 | 종류 | extends |
|---|---|---|---|
| `_history_filter` | `admin` | partial | - |
| `_history_grid` | `admin` | partial | - |
| `_modal_notification_definition_reset` | `admin` | partial | - |
| `_modal_notification_template_edit` | `admin` | partial | - |
| `_modal_notification_template_preview` | `admin` | partial | - |
| `_notifications_card` | `admin` | partial | - |
| `_recent_runs` | `admin` | partial | - |
| `_settings_form` | `admin` | partial | - |
| `_status_card` | `admin` | partial | - |
| `_tab_history` | `admin` | partial | - |
| `_tab_settings` | `admin` | partial | - |
| `plugin_settings` | `admin` | 화면 | `_admin_base` |
<!-- @generated:layouts END -->

<!-- @intent START -->
plugin_settings 하나에 상태·설정·알림·최근 이력과 전체 이력 탭을 담습니다. tab/status/trigger/date/page를 URL에서 읽고 필터 변경은 페이지를 1로 되돌립니다. 행 펼침 시 단건 API로 통화 상세를 가져옵니다.

알림 템플릿 편집 창(`_modal_notification_template_edit`)은 코어·게시판·이커머스 모달의 사본입니다. 분기 규칙뿐 아니라
외형도 네 벌을 함께 맞춥니다 — 수신자 카드 목록은 `stack-tight` 로 카드 사이 간격을 두며, 한 벌만 빠지면 그 화면에서만
카드 테두리가 맞붙습니다.

이 사본은 코어 창과 **같은 저장 API** 를 쓰므로 코어 계약을 그대로 따라야 합니다 — 관련 사용자는 정의가 선언한
관계(`relations`)에서 고르고(선언이 없으면 그 선택지를 세우지 않는다, 선택지 이름은 정의 응답의 `relation_labels`), 역할은 여러 개를 고르며(서버가 배열로
내려 준다), 본문 편집기는 채널 선언(`template_editor`: rich·plain·none)을 따릅니다. none 채널이면 코어 제목·본문·
미리보기·저장을 숨기고 채널 소유 확장이 슬롯으로 대신합니다. 코어 창이 바뀌면 이 창도 함께 고칩니다.

수신자 카드의 삭제 단추는 아이콘만 있으므로 코어 창과 같은 번역 키(`admin.settings.notification_definitions.recipients.remove_rule`,
`index` 에 몇 번째 규칙인지)를 `aria-label` 로 실어 화면 읽기 프로그램이 「N번째 규칙 삭제」로 읽게 합니다. 아이콘은
꾸밈으로 숨겨지므로(공통 `Icon` 컴포넌트) 이 이름이 없으면 이름 없는 단추가 됩니다 — 사본 네 벌이 같은 키를 씁니다.

이력 기간 필터는 종료일이 시작일보다 앞서는 기간을 세 겹으로 막습니다. ① 두 날짜 칸이 서로의 선택 범위를 묶습니다(시작일 `max="{{query.date_to}}"`, 종료일 `min="{{query.date_from}}"`). ② 그래도 직접 입력이나 공유 주소로 뒤집힌 기간이 들어오면 데이터소스 `koreaeximRuns` 의 `if` 가 조회를 건너뜁니다 — 서버(`IndexRateRunRequest` 의 `after_or_equal:date_from`)가 422 로 거절할 요청을 보내지 않기 위해서입니다. ③ 조회를 건너뛰면 데이터소스에 직전 결과가 남으므로 그리드 `data` 도 같은 조건에서 `[]` 로 비우고, 필터 아래에 `date_range_invalid` 안내(`role="alert"`)를 띄워 빈 목록이 「기록 없음」으로 읽히지 않게 합니다. 세 곳의 조건식(`query.date_from && query.date_to && query.date_to < query.date_from`)은 같아야 하며, 하나만 고치면 조회는 막혔는데 옛 행이 보이거나 안내 없이 목록이 비는 식으로 어긋납니다.

서버 쪽 `IndexRateRunRequest::attributes()` 는 검증 오류 문구의 필드 이름을 `lang/{locale}/validation.php` 의 `attributes` 에서 읽어(시작일·종료일 등) 파라미터 원문(`date to`·`date from`)이 문구에 드러나지 않게 합니다. 이력 조회 파라미터를 추가하면 이 목록과 언어 파일에 함께 넣습니다.

알림 템플릿 편집 창(`resources/layouts/admin/partials/plugin_settings/_modal_notification_template_edit.g7.html`)의 회원 검색은 코어 `GET /api/admin/users/search` 를 부르는데, 코어가 빈(공백뿐 포함) `keyword` 를 422 로 거절하므로 검색칸을 비울 때는 `if="{{String($args[0] ?? '').trim() !== ''}}"` 로 요청하지 않습니다.
<!-- @intent END -->

## 액션 핸들러

<!-- @generated:handlers START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 액션 핸들러가 없습니다._
<!-- @generated:handlers END -->

<!-- @intent START -->
별도 JavaScript 핸들러 없이 코어 apiCall/navigate/setState/refetchDataSource를 사용합니다. Select는 $event.target.value, TabNavigation과 DataGrid는 각 컴포넌트의 인자 계약을 사용합니다.

상태 카드의 「연결 테스트」와 「지금 갱신」은 나란히 놓여 항상 둘 다 보이므로, 진행 중 표시를 `_local.busy` 한 개의 참·거짓으로 두지 않습니다. 누른 쪽이 `busy` 에 자기 이름(`test` · `update`)을 넣고 스피너는 `_local.busy === '<자기 이름>'` 으로 자기 것만 봅니다. 반면 `disabled` 는 `_local.busy` 단독 판정을 그대로 씁니다 — 서버가 두 경로에 같은 실행 락을 걸어 한쪽이 도는 동안 다른 쪽을 눌러도 거절되므로, 함께 잠그는 편이 화면과 서버가 일치합니다.

레이아웃 소스는 코어 `docs/frontend/layout-markup.md` §10.5 의 주석 규약(S21)을 따릅니다 — 파일 헤더·데이터소스·모달·액션 블록마다 「무엇을 언제 왜」 를 적고, 자식이 둘 이상인 `<on>` 은 `<sequence>` 로 감싸 그 앞에 씁니다. 실행 이력 그리드처럼 한 줄에 `data-each` 와 정적 `data-ref` 가 함께 놓이면 저작 트랙 종료 게이트(`LayoutMarkup` PHPUnit 스위트)가 반복 안 정적 id 로 잡으므로 태그 경계에서 줄을 나눠 둡니다(2026-09-17 develop-7.1.0 2차 병합 전수 보존 검사에서 정정).
<!-- @intent END -->

## 전역 진입점

<!-- @generated:frontend-entry START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_프론트 엔트리포인트가 없습니다._
<!-- @generated:frontend-entry END -->

<!-- @intent START -->
전역 함수는 추가하지 않습니다. 알림 모달 상태와 펼친 통화 상세 맵만 페이지의 고유 접두사로 구분하고 API 인증·알림 발송은 코어 경로를 사용합니다.

알림 채널은 화면에 적어 두지 않습니다. 코어가 `core.notification.filter_available_channels` 로 런타임에 정하는 목록을 `/api/admin/notification-channels` 로 받아 **발송 수단을 줄로 깔고 복수로** 켭니다 — 채널을 화면에 적어 두면 확장이 들인 수단(문자 플러그인의 SMS·알림톡 등)에 도달할 수 없고, 단일 선택이면 여러 수단으로 동시에 보낼 수 없습니다. 코어가 아닌 수단은 어느 확장이 들였는지 함께 표시합니다.

켠 값은 폼에만 두고 **저장 버튼을 눌러야** `PUT /api/admin/notification-definitions/{id}` 로 반영합니다(코어 알림 정의 탭과 같은 규약 — 같은 카드의 문구 토글이 즉시 저장인 것과 구분됩니다). 하나도 켜지 않으면 코어가 `min:1` 로 거절하므로 버튼에서 먼저 막습니다. 켰는데 그 수단의 문구가 없으면 코어는 발송 때 조용히 건너뛰고 로그에만 남기므로, 그 줄에 「발송되지 않습니다」를 표시합니다.

문구 목록은 채널을 나눠 그리지 않습니다 — 정의 목록을 `template_channel=all` 로 받아 켠 수단의 문구를 한 번에 보여주므로 수단을 바꾸려고 화면을 다시 부르지 않습니다.
<!-- @intent END -->

## 에셋

<!-- @generated:assets START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 구분 |
|---|---|
| `editor-spec.json` | 레이아웃 편집기 스펙 (manifest) |

로딩 설정: `{"strategy":"global","priority":100,"dependencies":[]}`
<!-- @generated:assets END -->

<!-- @intent START -->
기존 관리자 템플릿의 컴포넌트와 CSS 유틸리티를 사용하므로 런타임 에셋 번들이 없습니다. 레이아웃 변경 후 플러그인 업데이트로 반영하며, 관리 배지는 입력줄 아래에서 한 줄을 유지합니다.
<!-- @intent END -->
