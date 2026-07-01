# Changelog

이 언어팩의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0] - 2026-07-01

### Added

- 관리자 대시보드 개편 신규 텍스트 일본어 번역 추가 — 상단 배지의 활성 템플릿/활성 언어팩 수 라벨(`stats.active_templates`·`active_language_packs`), "최근 알림" 위젯(`recent_notifications.*`), 데이터 소스 선택 라벨(`data_source.dashboard_recent_notifications`)이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 트리의 모달 화면명 일본어 번역 추가 (`modal_label.*` — 회원 삭제 확인·언어팩 설치·플러그인 상세·매니페스트 미리보기 등) — 위지윅 편집기 트리의 모달 노드가 영문 식별자 대신 일본어 화면명으로 표시됩니다.
- 레이아웃 편집기 데이터 소스·편집기 라벨 일본어 번역 동기화 (`editor.data_source.*` 등 신규/누락 키) — 속성 패널의 데이터 소스 표시 명칭 등 편집기 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 배열 항목 편집기·인플레이스 라벨 일본어 번역 추가 (`editor.array_item.*`·`editor.array_field.*`·탭/메뉴/컬럼/액션/카드/차트 항목 라벨 등) — 탭 네비게이션·메뉴·표 컬럼 등 배열 prop 의 항목 단위 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 관리자 컴포넌트 속성 컨트롤·컴포넌트 라벨 일본어 번역 추가 (`editor.control.*`·`editor.component.*` — 관리자 템플릿 컴포넌트 전수 속성 편집 충실화에 따른 신규 라벨) — 속성/동작 탭 컨트롤과 팔레트 컴포넌트명이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 표 셀 배경색·내부 여백 카탈로그 및 표 셀(Td/Th) 컴포넌트 라벨 일본어 번역 추가 (`editor.cell_fill.*`·`editor.cell_padding.*`·`editor.component.{td,th}`) — 셀 배경색 프리셋 색·내부 여백 단계 라벨과 표 셀 컴포넌트명이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 데이터 정의 빌트인(차트 데이터/다중 배열/중첩 컬럼) 편집 라벨 일본어 번역 추가 (`editor.array_group.*`·`editor.col_type.*`·`editor.array_field.{chart_name,chart_value,chart_color,chart_series,chart_data,col_type,placeholder}`·`editor.array_item.{chart_slice,chart_dataset,card_column}`) — 차트 데이터셋·도넛 데이터·동적 필드 컬럼 등 구조적 데이터 편집 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 관리자 대시보드 및 템플릿 정보 모달의 신규 텍스트 일본어 번역 추가 (대시보드 배지·차트·외부 리소스 섹션 등).
- 공통 "목록" 버튼 라벨 일본어 번역 추가 (`一覧`).
- 레이아웃 편집 화면 트리 구조 및 확장 버전 히스토리·미리보기 모달의 신규 텍스트 일본어 번역 추가.
- 레이아웃 확장 항목의 오버라이드 표시 뱃지 텍스트 일본어 번역 추가.
- 템플릿 관리 화면의 `[코드 편집]` 과 `[레이아웃 편집]` 2버튼 분리에 따른 신규 라벨 일본어 번역 추가.
- 레이아웃 편집기 표(Table) 컴포넌트 라벨 일본어 번역 추가 (`editor.component.table`)
- 레이아웃 편집기 정렬 박스 on/off 라벨 일본어 번역 추가 (`editor.control.flex_enable.{on,off}`) — 디바이스별 정렬 박스 적용/해제 2상태 선택 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 색 컨트롤 프리셋 색 라벨 일본어 번역 추가 (`editor.control.color_preset.*`) — 글자색·배경색 컨트롤의 프리셋 색(회색 단계·흰색·파랑·빨강·초록) 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 신규 스타일 컨트롤 라벨 일본어 번역 추가 (`editor.control.{box_shadow,border_style,border_color,border_radius,opacity,overflow,font_bold,font_italic,text_underline,whitespace,toggle}.*` + `text_align.justify` + `color_preset.gray_300`) — 그림자·테두리·모서리 둥글기·투명도·스크롤·굵게/기울임/밑줄·줄바꿈·양쪽 정렬 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 페이지 상태 토글 라벨 일본어 번역 추가 (`editor.state.*`) — 캔버스 툴바의 페이지 상태(로그인/검증 실패·서브탭·신규 작성 모드·페이지 상세 기본/첨부 펼침 등) 미리보기 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 속성 탭 컨트롤 라벨 일본어 번역 추가 (`editor.control.{icon_name,img_src,img_alt,link_href,link_target,input_type,input_placeholder,input_name,input_required,input_disabled,field_label,field_error,field_helper,html_for,textarea_rows,elem_id,select_placeholder,select_options,component_size,button_variant,component_disabled}.*`) — 아이콘·이미지·링크·입력 필드·선택지 등 비-스타일 속성 편집 컨트롤 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 파일 업로더 이미지 개수 상한 초과 안내(`attachment.upload_limit_exceeded`) 일본어 번역 추가 — 이미지 첨부가 상한을 초과하면 일본어 로케일에서 안내 문구가 자연스럽게 표시됩니다.
- 회원 목록 일괄 작업 영역의 "상태 변경" 라벨(`users.bulk_status_change`) 일본어 번역 추가 — 회원 목록 일괄 작업 바의 상태 변경 라벨이 일본어 로케일에서 자연스럽게 표시됩니다.
- 레이아웃 편집기 신규 컴포넌트 속성 컨트롤·컴포넌트 라벨 일본어 번역 추가 (`editor.control.{as_*,af_*,tt_*,nc_*,cs_*,sh_*}` + `editor.component.section_header` + `layout_editor.*.section_header`) — 관리자 사이드바·푸터·테마 토글·알림 센터·컬럼 선택기·섹션 헤더 컴포넌트의 속성 편집 컨트롤과 팔레트 컴포넌트명·섹션 헤더 기본 문구가 일본어 로케일에서 자연스럽게 표시됩니다.

### Changed

- 본인인증 정책 편집 안내 문구(`declared_policy_notice`)의 일본어 번역을 호스트 템플릿 변경에 맞춰 정정 — 편집 가능 범위가 "키·강제 시점·강제 위치만 변경 불가, 그 외 자유 편집"으로 갱신됨에 따라 일본어 안내도 동일하게 반영
- 관리자 대시보드 게시판 영역이 게시판 모듈로 이전됨에 따라 옛 게시판 라벨(상단 빠른 진입 버튼·통계 카드)을 정리하고, 다른 영역의 일본어 번역도 호스트 템플릿 기준으로 재동기화

### Removed

- 템플릿 관리 화면의 위지윅 편집 버튼 라벨 번역 제거 (코어에서 MVP 위지윅 편집기 제거에 동기화).
- 대시보드에서 제거된 "시스템 리소스" 데이터 소스 라벨(`data_source.dashboard_resources`·`dashboard_resources_ws`)의 일본어 번역을 정리했습니다.

## [1.0.0-beta.2] - 2026-05-15

### Added

- 코어 업데이트 안내 모달의 신규 텍스트 5건에 대한 일본어 번역 추가 (`sudo` 권장 안내 / 외부 업그레이드 가이드 링크 라벨 등).

## [1.0.0-beta.1] - 2026-05-11

### Added

- 기본 관리자 템플릿(sirsoft-admin_basic)의 일본어 번들 언어팩 초기 제공
