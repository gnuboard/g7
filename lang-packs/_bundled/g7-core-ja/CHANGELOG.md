# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.1] - 2026-07-03

### Added

- 설정 복원 관련 안내 메시지 일본어 번역 추가 (`settings.backup_path_required`·`restore_success`·`restore_failed`·`restore_error`) — 백업 경로 미입력 안내와 설정 복원 성공/실패 결과가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0] - 2026-07-01

### Added

- 비회원 알림 채널 차단 안내 메시지 일본어 번역 추가 (`notification.channel_guest_not_allowed`) — 비회원 발송을 허용하지 않는 채널(사이트내 알림 등)로 발송이 건너뛰어질 때의 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 "요소 ID" 컨트롤의 데이터 칩 연동 안내 문구 일본어 번역 추가 (`layout_editor.core_props.id.add_data` / `binding_hint`) — 반복 목록 항목에 고유 ID 를 부여하는 데이터 칩 안내가 일본어 로케일에서 표시됩니다.
- 레이아웃 편집기 "조건에 따라 분기" 동작 라벨 일본어 번역 추가 (`layout_editor.action_recipe.conditions.*`) — 동작 추가 목록의 분기 동작과 분기별 실행조건·동작·추가/삭제·순서이동 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.4] - 2026-06-14

### Added

- 관리자 대시보드 "최근 알림" 위젯 백엔드 응답 메시지 일본어 번역 추가 (`dashboard.recent_notifications_loaded`·`recent_notifications_failed`) — 최근 발송 알림 조회 성공/실패 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 검색엔진 설정에서 확장 제공 SEO 자동값 연결 칩 안내 문구 일본어 번역 추가 (`layout_editor.page_settings.seo.auto_chip_source`·`auto_chip_replace`) — 확장이 제공하는 공유 이미지·제목 등의 데이터 출처 표시와 "다른 데이터로 변경" 동작이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 [화면 동작] 탭 동작 입력칸 친화화 신규 라벨 일본어 번역 추가 (`layout_editor.action_param.*` 4건 + `layout_editor.init_actions.base_readonly_hint`) — 동작 입력칸의 값·이름 placeholder, "값 추가" 버튼, 공통 레이아웃 동작 읽기 전용 안내가 일본어 로케일에서 표시됩니다.
- "모달 열기" 동작 대상 선택칸 placeholder 일본어 번역 추가 (`layout_editor.recipe.modal_placeholder`).
- 이미 식으로 작성된 복잡한 값의 친화 편집 불가 안내 문구 일본어 번역 추가 (`layout_editor.value_tree.raw_expression_hint`).
- 레이아웃 편집기 [화면 동작] 탭의 본인인증 대상 입력칸·실패 처리 안내 신규 라벨 일본어 번역 추가 (`layout_editor.action_param.identity_target_*`·`param_on_error`) — 본인인증 동작의 이메일/전화 대상 지정과 실패 시 처리 입력이 일본어 로케일에서 표시됩니다.
- 본인인증 정책 우선순위 동률 차단 안내 문구 일본어 번역 추가 (`identity_policy.priority_duplicate`) — 같은 적용 위치에 동일 우선순위의 활성 정책을 저장하려 할 때의 안내가 일본어 로케일에서 표시됩니다.

### Removed

- 제거된 "공통 레이아웃에서 수정" 라벨(`layout_editor.init_actions.edit_in_base`)의 일본어 번역을 정리했습니다.

## [1.0.0-beta.3] - 2026-05-22

### Changed

- 레이아웃 편집기 동작 목록 순서 변경 라벨을 끌어서 순서 변경으로 통일 (`layout_editor.action_list.drag_reorder` 추가, `layout_editor.{action_list,init_actions}.{move_up,move_down}` 제거) — 동작 순서를 ▲▼ 버튼 대신 손잡이를 끌어 바꾸도록 통일하면서, 끌기 손잡이 안내 문구가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 요소 연결(영역 선택)·검색엔진 데이터 선택 안내 문구 일본어 번역 추가 (`layout_editor.target_picker.needs_id`·`layout_editor.page_settings.seo.data_sources_hint`) — 직접 ID를 부여한 요소만 연결 대상으로 고를 수 있다는 안내와, 검색엔진 변수·구조화 데이터가 참조할 데이터 선택 안내가 일본어 로케일에서 자연스럽게 표시됩니다.

### Added

- 레이아웃 편집기 폼 항목 편집 필드 캡션 일본어 번역 추가 (`layout_editor.list_editor.{item_label,item_placeholder}`) — 폼 속성 편집 목록에서 각 항목의 라벨/안내 문구 입력란 위 캡션이 일본어 로케일에서 자연스럽게 표시됩니다.

- 레이아웃 편집기 라우트 트리 버전 배지·슬롯 영역 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.badge.version_tooltip`·`layout_editor.preview.slot_area_label`) — 좌측 트리의 현재 저장 버전 배지 툴팁과 공통 레이아웃 편집 캔버스의 "콘텐츠 영역" 슬롯 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 별도 편집 모드 + 확장 주입 시각 편집 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.group.extension`·`layout_editor.chrome.route_tree.badge.{extension_point,overlay,priority,inactive}`·`layout_editor.property_modal.injected_props.*`) — 라우트 트리의 "확장 주입" 그룹·확장 타입/우선순위/비활성 배지와, 호스트 노드 속성 모달의 "확장이 주입한 속성" 섹션 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 패널 텍스트 다국어 위젯·데이터 소스 라벨 일본어 번역 추가 (`layout_editor.prop_i18n.*`·`layout_editor.data_sources.field.label_key*` 등) — 안내 문구·라벨 등 속성 텍스트를 언어별로 편집하는 위젯과 데이터 소스 표시 명칭 입력 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 연결 라벨 일본어 번역 추가 (`layout_editor.binding.*` — 데이터 연결 섹션·검색·연결 상태·shape(단일값/배열)·필수 표시 등) — 컴포넌트 속성을 데이터소스/상태에 연결할 때의 검색형 피커 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표(Table) 빌트인 편집기 라벨 일본어 번역 추가 (`layout_editor.table_editor.{title,cell_text,cell_class_placeholder,move_col_left,move_col_right,structural_cell,grid_invalid,select_hint}`) — 속성 탭에서 표의 행/열 추가·삭제·이동, 셀 병합/해제, 셀 테두리, 셀 텍스트 편집 라벨과 그리드 정합성 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 캔버스 인플레이스 편집 라벨 일본어 번역 추가 (`layout_editor.table_inplace.*` — 셀 선택·행/열 추가·삭제·이동·병합·해제·영역 선택·테두리 일괄 등) — 캔버스에서 표를 직접 편집할 때의 어포던스 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 셀 배경색·내부 여백 컨트롤 라벨 일본어 번역 추가 (`layout_editor.table_editor.{cell_fill,cell_fill_none,cell_fill_custom,cell_padding,cell_padding_custom}`) — 셀 배경색(없음/프리셋/직접 선택)과 내부 여백(단계/직접 px) 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 셀 색상 라이트/다크 컨트롤 라벨 일본어 번역 추가 (`layout_editor.table_editor.{cell_color,dark_free_color_hint}`) — 셀 테두리·배경 색상의 라이트/다크 단일 공용 탭 라벨과 다크 모드 자유색 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 동작 탭 이벤트 명칭 일본어 번역 추가 (`layout_editor.action.event.*` — 버튼 클릭·항목 추가/삭제·언어 변경·정렬 변경 등 15종) — 동작 탭 이벤트 슬롯 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 라우트 트리 검색·템플릿 전환 라벨 일본어 번역 추가 (`layout_editor.chrome.route_tree.{search_placeholder,search_clear,search_no_results}`·`layout_editor.chrome.toolbar.switch_template`) — 좌측 화면/라우트 패널의 검색 입력칸과 상단 툴바의 템플릿 전환 드롭다운 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 정의 빌트인 편집기 라벨 일본어 번역 추가 (`layout_editor.array_editor.number_list_placeholder`·`layout_editor.cell_tree_editor.*`) — 차트 데이터셋의 수치 목록 입력 안내와 카드 그리드 중첩 컬럼/셀 트리 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 텍스트 데이터 연결 라벨 일본어 번역 추가 (`layout_editor.inline_binding.*`·`layout_editor.translation.{token_mismatch,placeholder_mismatch,placeholder_locked}`) — 텍스트에 박힌 데이터를 조각 단위로 교체/해제/추가하는 [속성] 탭 영역과, 번역 시 데이터 자리표시 보호 안내 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 버전 기록·미리보기 라벨 일본어 번역 추가 (`layout_editor.version_history.*`·`layout_editor.preview_action.*`·`layout_editor.chrome.toolbar.{previewing,version_history}`) — 툴바 버전 기록 모달(버전 목록·복원·비교 diff)과 실데이터 미리보기 버튼 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 확장 편집·미리보기 검증 메시지 일본어 번역 추가 (`validation.layout_extension.*`)
- 모듈·플러그인 업데이트 커맨드의 레이아웃 전략 안내 메시지 일본어 번역 추가
- 코어 프론트엔드 다국어 자원 일본어 번역 추가 (`core.errors.*`) — 템플릿 엔진 에러 메시지가 일본어 로케일에서 일본어로 노출됩니다.
- 레이아웃 편집기 셸 일본어 번역 추가 (`layout_editor.chrome.*`) — 툴바·라우트 트리·진입 FAB·권한 거부·dirty guard·캔버스 placeholder 가 일본어 로케일에서 자연스럽게 표시됩니다.
- 템플릿 자산 서빙 메시지 일본어 번역 추가 (`templates.messages.editor_*`, `templates.messages.language_*`) — 편집기 자산 매니페스트·다국어 데이터 응답 메시지가 일본어로 노출됩니다.
- 레이아웃 편집기 낙관적 잠금 안내 일본어 번역 추가 (`exceptions.concurrent_modification`, `validation.layout(.layout_extension).expected_lock_version.*`) — 코드 편집기 ↔ 레이아웃 편집기 동시 저장 충돌 시 사용자 안내 메시지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 chrome 일본어 번역 추가 (`layout_editor.{palette,insertion,context_menu,overlay,save}.*`) — 요소 추가 팔레트·컨텍스트 메뉴·오버레이 어포던스·저장 안내·동시 저장 충돌 안내 모달이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 여백 방향·정렬 박스 해제·표 라벨 일본어 번역 추가 (`layout_editor.control.spacing.*`, `layout_editor.flex.unmake_flex`, `layout_editor.palette.table.*`) — 여백 측별 편집(일괄/개별·상하좌우)·정렬 박스 해제·표 팔레트 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 페이지 상태 토글 라벨 일본어 번역 추가 (`layout_editor.toolbar.state`) — 캔버스 툴바의 페이지 상태 드롭다운 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 반복 항목 편집 모드 라벨 일본어 번역 추가 (`layout_editor.chrome.toolbar.{exit_iteration_item_edit,mode_badge.iteration_item}`, `layout_editor.overlay.edit_iteration_item`) — 반복 영역의 "반복 항목 편집" 진입·종료·모드 배지가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 스타일 탭 색 모드·디바이스별 편집 라벨 일본어 번역 추가 (`layout_editor.property_modal.scope.*`, `layout_editor.property_modal.dark_code_only`, `layout_editor.toolbar.color_scheme_*`) — 라이트/다크·기본값/PC/태블릿/모바일·커스텀 크기 세부탭, 기본값 초기화, 다크 읽기전용 안내, 프리뷰 색상 테마 토글이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 색 컨트롤 다크 프리셋 안내 일본어 번역 추가 (`layout_editor.property_modal.dark_preset_only`, `layout_editor.property_modal.scope.reset_to_base`) — 다크 모드에서 자유 색 대신 프리셋 색을 고르라는 안내와 기본값 초기화 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 탭 선택지 목록 편집 라벨 일본어 번역 추가 (`layout_editor.list_editor.{option_value,option_label,bound_degraded}`) — 속성 탭에서 선택지(options) 배열을 값·라벨로 편집하는 입력 라벨과, 데이터에 연결된 목록의 "코드 편집" 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 목록 빌트인(자식 항목 편집) 라벨 일본어 번역 추가 (`layout_editor.list_editor.{items_title,item_text,new_item,add_child,no_child_component}`) — 목록/컨테이너 컴포넌트(Ul/Ol/Nav/Form/Li)의 자식 항목 추가·텍스트 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 겹친 부모 선택 라벨 일본어 번역 추가 (`layout_editor.overlay.select_parent`) — 부모/자식 크기가 같아 겹친 경우 부모 요소를 선택하는 타입 칩 툴팁이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 커스텀 다국어 키 검증 메시지 일본어 번역 추가 (`validation.custom_translation.*`) — 인라인 편집으로 등록하는 동적 다국어 키의 입력 검증 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 환경설정 로그 드라이버·드라이버 조건부 필수·드라이버 필드명 검증 메시지 일본어 번역 추가 (`validation.settings.{log_*,*_required}`, `validation.attributes.*`) — 로그/스토리지/캐시/세션/웹소켓 드라이버 설정 입력 검증 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 다국어 인라인 편집·콘텐츠 로케일 전환·서식 툴바·번역 탭 라벨 일본어 번역 추가 (`layout_editor.{locale,inline_edit,translation}.*`) — 콘텐츠 언어 전환 토글, 더블클릭 인라인 편집 힌트·서식 툴바(굵기/기울임/밑줄/정렬/크기/색상), 속성 모달 번역 탭의 로케일별 일괄 편집 안내가 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 커스텀 다국어 키 관리 모달 라벨 일본어 번역 추가 (`layout_editor.chrome.toolbar.translations`, `layout_editor.translation_manager.*`, `validation.custom_translation.ids.*`) — 상단 🌐 다국어 버튼, 키 목록·상태 필터(전체/사용중/미사용)·미사용 배지·번역 일괄 편집·삭제/일괄 삭제 안내와 일괄 삭제 입력 검증이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 디바이스 분기 편집 라벨 일본어 번역 추가 (`layout_editor.device.portable`·`layout_editor.context_menu.{separate_branch*,merge_branch*,device_portable}`·`layout_editor.overlay.jump_device*`·`layout_editor.property_modal.{scope.device_portable,branch_badge}`) — 디바이스 토글의 "모바일+태블릿" 구간, 디바이스 전용 구성 분리/해제·다른 디바이스 구성으로 이동 버튼과 안내, 특정 디바이스 전용 구성 안내 배지가 일본어 로케일에서 자연스럽게 표시됩니다.

## [1.0.0-beta.2] - 2026-05-12

### Added

- 본인인증 활동 로그 액션 라벨 일본어 번역 추가 (`verify`, `verify_failed`)

### Changed

- 본인인증 관리자 권한 시드를 코어의 조회/수정 분리 정책에 맞춰 갱신
  - `core.admin.identity.manage` → `core.admin.identity.providers.update`
  - `core.admin.identity.policies.manage` → `core.admin.identity.policies.update`
  - `core.admin.identity.providers.read`, `core.admin.identity.policies.read` 신설
- 코어 최소 요구 버전을 `>=7.0.0-beta.5` 로 상향 (신 권한 키가 코어 beta.5 시드에 의존)

## [1.0.0-beta.1] - 2026-05-11

### Added

- 코어의 일본어 번들 언어팩 초기 제공
