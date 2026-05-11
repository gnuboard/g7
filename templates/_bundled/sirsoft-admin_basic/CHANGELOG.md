# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.4] - 2026-05-11

### Added

- 관리자 화면의 본인인증 정책 위반 시 동일한 모달 UX 가 자동으로 표시되도록 공통 본인인증 모달 추가 (관리자 작업·계정 탈퇴·확장 제거 등 민감 작업 모두 일관된 흐름)
- 관리자 본인인증 모달의 "취소" 클릭 시 서버 본인인증 challenge 가 즉시 정리되도록 개선 — 미인증 challenge 가 만료까지 남아있던 동작이 audit 기록과 함께 즉시 cancelled 상태로 정합
- 본인인증 챌린지 풀페이지(`/admin/identity/challenge`) 추가 — 외부 redirect 흐름 또는 모달 컨텍스트 손실 케이스에서 폴백
- 외부 본인인증 provider 플러그인이 자기 SDK UI 를 관리자 모달에 주입할 수 있는 슬롯 도입
- 모듈/플러그인/템플릿 관리자 목록 화면에 슈퍼관리자 전용 "숨김 포함" 토글 추가 (기본: 숨김 확장 제외)
- 환경설정 → 본인인증 탭에 "메시지 템플릿" 서브탭 추가 — 알림 템플릿 관리와 동일한 UX (채널 서브탭·페이지당 항목 수 셀렉터·카드 펼침 본문 미리보기·활성/기본 배지·페이지네이션) 로 정의 목록·활성 토글·다국어 편집(변수 가이드 + 기본값 복원) 제공
- 운영자가 추가한 본인인증 정책에 묶인 메일 메시지 정의를 화면에서 직접 추가/삭제 가능 — 시드 기본값 정의는 보호되어 삭제 불가, 신규 등록은 운영자 정책 키와 매칭될 때만 허용
- 모듈/플러그인 제거 모달의 "삭제될 데이터" 에 코어 공유 테이블 영역(권한·관리자 메뉴·알림 정의·본인인증 정책·본인인증 메시지 정의) 도 표시되어 운영자가 사라지는 데이터를 정확히 파악할 수 있도록 함
- 관리자 로그인 화면에 세션 만료 안내 토스트 추가 — 토큰 만료로 자동 리다이렉트된 관리자에게 "세션이 만료되었습니다. 다시 로그인해 주세요." 메시지 표시 (`?reason=session_expired` 쿼리 파라미터 감지)
- 언어팩 관리 화면에서 검색 버튼 + Enter 키로 즉시 검색 가능, 검색/스코프/상태 필터가 URL 쿼리에 동기화되어 새로고침·공유 시 동일한 필터 상태 유지
- 환경설정 > SEO 탭의 Sitemap 카드에 마지막 생성 시각 표시와 "지금 생성" 버튼 추가 — IP 기반 타임존 감지 카드와 동일한 방식으로 즉시 재생성

#### 언어팩 관리 화면 보강

- 환경설정 > 언어팩 관리 본문을 모듈 관리와 동일한 수준의 운영 도구로 구성 (업데이트 확인 / 캐시 갱신 / 다중 선택 일괄 제거 추가)
- 활성/비활성 상태를 토글 스위치로 일원화하고 보호된 팩은 비활성화된 토글로 표시
- 업데이트 가능 항목에 배지와 행 단위 업데이트 실행 버튼 노출
- 정보 모달을 4섹션 구조(기본정보 / 호환성 / 소스 정보 / 변경로그)로 제공하며 CHANGELOG.md 를 자동 파싱하여 버전별 카테고리로 표시
- 설치 모달에 ZIP 사전 검증을 위한 "manifest 미리보기" 진입 추가
- 모듈/플러그인/템플릿별 언어팩 페이지 진입 시 대상 확장 안내 배너와 환경설정 탭 회귀 링크 노출
- 목록에 컬럼 헤더(로케일 / 스코프 / 대상 / 벤더 / 버전 / 상태 / 작업) 표시

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.4 로 상향
- 레이아웃 버전 목록의 선택 전환 시 반응성 개선
- 본인인증 이력 페이지 UI 를 알림 발송 이력과 동일한 수준으로 개선 — Provider 탭, 분리된 검색 타입(자동/사용자 ID/대상 식별자), 상태·인증 목적 멀티 체크박스, 정렬·페이지 사이즈 선택, DataGrid 행 펼침으로 인라인 상세 표시, 부분 로딩, 모바일 반응형 대응
- 본인인증 이력 화면 용어를 환경설정 본인인증 정책과 일관되게 정비 (인증 목적/인증 수단/대상 식별자/발생 위치/추가 속성/부가 정보), 인증 목적 값은 사람이 읽을 수 있는 라벨로 표시
- 오래된 로그 일괄 파기 동작이 모달 확인 + 진행 중 스피너 표시로 변경되어 다른 관리자 작업과 일관된 UX 제공
- 코어 환경설정 본인인증 정책 화면에 "코어가 제공하는 본인인증 목적" 칩 섹션 추가 (목적 코드/라벨 + 설명·허용 채널 툴팁)
- 본인인증 정책 목록의 "인증 목적" 컬럼이 미정의 i18n 키를 그대로 노출하던 문제를 dynamic lookup 으로 변경 — 모듈/플러그인이 추가한 목적도 사람이 읽을 수 있는 라벨로 표시
- 환경설정 탭과 모듈/플러그인/템플릿별 언어팩 페이지가 동일 본문을 공유하도록 통합 — 한 곳만 수정하면 양쪽이 동시 반영
- 페이지네이션을 다른 관리 화면과 동일한 표준 컴포넌트로 교체하여 1페이지일 때도 일관된 형태로 노출
- 언어팩 활성 상태 뱃지를 토글로 대체 — 오류 상태일 때만 빨간 뱃지 표시

### Fixed

- 검색 드롭다운에서 빠른 모달 토글 시 포커스 처리가 충돌하던 문제 수정
- 알림 템플릿 편집 모달의 입력 필드에서 글자를 입력할 때마다 화면이 심하게 버벅이던 문제 수정
- 환경설정 알림 탭, 알림 발송 이력 채널 탭, 알림 템플릿 편집 모달의 채널 라벨이 식별자(mail / database)로 노출되던 문제 수정 — 활성 언어 기준의 사람이 읽을 수 있는 라벨로 표시

## [1.0.0-beta.3] - 2026-04-21

### Fixed

- 알림 레이어를 닫을 때 목록이 읽음 상태로 갱신되지 않아 재토글 시 읽음 처리가 누락되던 문제 수정
- 알림 레이어 무한 스크롤 시 "안 읽은 알림만" 필터가 유지되지 않아 읽은 알림이 섞여 노출되던 문제 수정
- 알림 레이어 무한 스크롤 시 동일 페이지 API 가 중복 호출되던 문제 수정
- 안 읽은 알림이 없는데도 알림 레이어를 닫을 때 읽음 처리 API 가 불필요하게 호출되던 문제 수정
- 알림 드롭다운이 화면 경계를 벗어나지 않도록 자동으로 좌/우 정렬 전환 및 최대 너비 제한

## [1.0.0-beta.2] - 2026-04-20

### Added

- 모듈/플러그인/템플릿 정보 모달에 의존성 섹션 추가 — 코어 요구 버전, 의존 확장의 요구/설치 버전과 충족 상태 뱃지 표시
- 알림센터 구현 — 읽은/안 읽은 알림 표시, 개별/전체 삭제, 모두 읽기, 안 읽은 필터, 무한 스크롤
- 알림 전체 삭제 확인 모달 추가
- 실시간 알림 수신 시 뱃지 갱신 + 토스트 표시 (WebSocket 연동)
- 알림 시각 표시를 사용자 타임존 기준으로 변경
- 환경설정 알림 탭에 일괄 초기화 확인 모달 추가 — 진행 스피너, 대상 이름 표시, 경고 메시지 포함
- 편집된 알림 제목이 식별자 대신 다국어 이름으로 표시되도록 개선

### Removed

- 코어 환경설정 > SEO > 애널리틱스/사이트 소유권 확인 카드 제거 (미작동 기능)

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- TabNavigation 컴포넌트를 반응형으로 개선 — 768px 미만에서 Select 드롭다운으로 전환
- 성능 테스트 fixture 파일(~19MB) 저장소에서 제거 — 테스트 실행 시 자동 생성/삭제로 전환
- 알림 템플릿 편집 모달 다국어 탭을 동적 생성으로 전환
- 언어 선택 UI를 하드코딩에서 `localeNames` 기반 동적 생성으로 전환
- 알림 발송 이력 레이아웃 재구성 — DataGrid 구조, 필터 분리, 일괄 삭제, expandable 상세보기
- HtmlContent DOMPurify를 FORBID 방식으로 전환 (보안 기본값 항상 유지)

### Fixed

- 확장 install/activate/deactivate/uninstall 직후 라우트·메뉴·다국어 미반영 수정 — `reloadExtensions` 핸들러 도입
- 여러 관리자 폼의 에러 핸들러에서 저장/삭제 버튼 로딩 상태 고정 수정
- FileUploader 갤러리 이미지 깨짐 수정 — stale closure로 인한 blob URL 문제
- FileInput onChange가 ActionDispatcher에서 무시되던 문제 수정
- 모달 내부 Select 드롭다운이 잘려 보이는 문제 수정

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.2.34] - 2026-03-31

### Added

- 템플릿 업데이트 모달 — "수정 유지" 선택 시 수정된 레이아웃 목록 패널 표시 (이름, 변화량, 수정 일시) (_modal_update.json, _tab_admin.json, _tab_user.json, lang)

### Changed

- 사용자 관리 국가 입력항목 및 표시 숨김 처리 (admin_user_list.json, admin_user_form.json, admin_user_detail.json)

### Fixed

- 관리자 검색 페이지에서 다른 페이지로 이동 후 돌아올 때 이전 검색어가 Input에 유지되던 문제 수정 — `_global` 상태를 `_local`로 전환, `init_actions`로 URL 쿼리 기준 초기화 (admin_user_list.json, admin_module_list.json, admin_plugin_list.json, admin_role_list.json, admin_schedule_list.json, admin_template_list.json, _tab_user.json, _tab_admin.json)

## [0.2.33] - 2026-03-30

### Added

- 관리자 로그인 화면 개선 — 비밀번호 찾기/재설정 플로우 레이아웃 추가, 테마 Segmented Control, 언어 셀렉트 확대 (admin_login.json, admin_forgot_password.json, admin_reset_password.json, lang, passwordResetHandlers)
- 활동 로그 이력 조회 관리자 레이아웃 및 다국어 추가 (admin_activity_logs.json, _partial_activity_log_detail.json)
- 환경설정 드라이버 탭 검색엔진 설정 UI 및 다국어 추가 (_tab_drivers.json, lang)

### Fixed

- DynamicFieldList/MultilingualInput stale closure 수정 — 디바운스 병합 시 이전 키 변경 유실 방지 (DynamicFieldList.tsx, MultilingualInput.tsx)
- FileUploader onRemove 시 form 데이터 동기화 누락 수정 — 환경설정 로고 삭제 시 폼 데이터 미반영 (_tab_general.json)
- 사용자 관리에서 관리자/슈퍼관리자 삭제 시 토스트 메시지 및 어빌리티 처리 — 삭제 불가 사용자 UI 반영 (admin_user_list.json)

### Removed

- 게시판 사용자 알림 설정 확장 파일 제거 (extensions/)

## [0.2.32] - 2026-03-30

### Fixed

- 레이아웃 편집 버전 히스토리 복원 후 에디터 content 미갱신 수정 — onSuccess에서 `refetchDataSource` 후 `_global.editorContent`를 명시적으로 갱신하여 stale 에디터 내용이 DB를 덮어쓰는 문제 방지 (_modal_version_history.json)
- 버전 히스토리 복원 버튼 `sequence` 핸들러 문법 수정 — top-level `actions` → `params.actions` (_modal_version_history.json)

### Removed

- 작동 불가한 "템플릿 업데이트 변경 내역 확인" 버튼 및 모달 삭제 (admin_template_layout_edit.json, _modal_template_changelog.json)

## [0.2.31] - 2026-03-30

### Changed

- 환경설정 드라이버 탭 저장 버튼 비활성화 조건을 `_global.settings.drivers` 참조에서 `_local.originalDrivers` (API 원본값) 참조로 변경 — `window.G7Config`에서 drivers 설정 미노출 대응 (admin_settings.json initLocal 맵 확장)

## [0.2.30] - 2026-03-30

### Fixed

- 다크모드 전수 조사 및 수정 — TSX 컴포넌트 약 26건, CSS 5건의 누락된 `dark:` variant 추가
  - PermissionTree, FileUploader, DynamicFieldList, SectionLayout, TagInput, SortableMenuItem, Accordion, ColumnSelector, IconSelect, LanguageSelector, MultilingualInput, Toggle 등
  - ui-elements.css: Select SVG 아이콘 다크모드 밝은 색상 추가, 폼 Input 6개 클래스 `dark:bg-gray-800` → `dark:bg-gray-700` (카드 배경과 구분)
- Select 컴포넌트 다크모드 텍스트 색상 누락 수정 — 커스텀 `bg-*` className 사용 시 텍스트 색상 자동 보충 로직 추가 (`hasTextColor` 검사 → `text-gray-700 dark:text-gray-200` 보충)
- safelist에 다크모드 클래스 패밀리 46개 추가 (amber, emerald, teal, cyan 등)

## [0.2.29] - 2026-03-30

### Fixed

- FileUploader 상품 수정 시 이미지 순서 유실 버그 3건 수정 — customOrder 상태 도입으로 기존/신규 파일 간 드래그 순서 보존, handleDragEnd에서 customOrder 기반 정렬, 업로드 완료 즉시 pending ID→hash 교체 (useFileUploader.ts)
- FileUploader 상품 복사 모드 지원 — hash 기반 이미지 식별, id 없는 이미지 서버 삭제 스킵, 복사 모드 삭제 후 재출현 방지 (useFileUploader.ts, FileList.tsx, types.ts)
- FileUploader onReorder 콜백 추가 — 드래그 순서 변경 및 업로드 완료 시 부모에게 정렬된 파일 목록 전달 (useFileUploader.ts, FileUploader.tsx, types.ts)
- FileUploader 대표이미지 식별 hash 기반 전환 — primaryFileId에 hash 우선 사용 (FileList.tsx, types.ts)
- FileUploader 업로드 응답 ResponseHelper 이중 래핑 대응 — response.data.data ?? response.data 패턴 (useFileUploader.ts)
- FileUploader mime_type null 방어 코드 추가 — optional chaining으로 startsWith 에러 방지 (useFileUploader.ts, SortableThumbnailItem.tsx, FileList.tsx, utils.ts)

## [0.2.28] - 2026-03-30

### Fixed

- DynamicFieldList 입력 시 포커스 아웃 수정 — itemIdKey가 없는 아이템에 자동 ID 부여로 setState 라운드트립 후에도 React key 안정성 유지 (DynamicFieldList.tsx)

## [0.2.27] - 2026-03-29

### Fixed

- FileUploader mime_type null 방어 코드 추가 — optional chaining으로 startsWith 에러 방지 (useFileUploader.ts, SortableThumbnailItem.tsx, FileList.tsx, utils.ts)
- FileUploader 대표이미지 식별 hash 기반 전환 — primaryFileId 비교/이벤트에 hash 우선 사용, 복사 모드(id 없는 이미지) 삭제 시 서버 API 호출 스킵 (useFileUploader.ts, FileList.tsx, types.ts)
- FileUploader 복사 모드 이미지 삭제 후 재출현 버그 수정 — deletedIdsRef에 hash 추가 추적, initialFiles 동기화 시 hash 기반 필터링 (useFileUploader.ts)

## [0.2.26] - 2026-03-28

### Changed

- 레이아웃 편집기 CodeEditor, 레이아웃 설명, 파일 설명 바인딩에 `raw:` 접두사 적용 — 사용자 입력 데이터 내 `$t:` 패턴 번역 방지 (admin_template_layout_edit.json) (#225)

## [0.2.25] - 2026-03-28

### Added

- 활동 로그 기간 검색 프리셋 버튼 추가 — 오늘, 1주일, 1개월, 3개월, 6개월, 1년 빠른 선택 (주문관리와 동일 UX) (_partial_filter.json) (#225)
- `setDateRange` 핸들러 추가 — 날짜 프리셋별 시작일/종료일 자동 계산, datetime-local 형식 반환 (setDateRangeHandler.ts) (#225)
- 삭제 모달 스피너 추가 — 개별/일괄 삭제 진행 중 삭제 버튼에 스피너 아이콘 표시 (admin_activity_log_list.json) (#225)

### Changed

- 활동 로그 기간 필터를 date에서 datetime-local로 변경 — 시/분/초 지정 가능 (_partial_filter.json) (#225)

## [0.2.24] - 2026-03-28

### Changed

- 활동 로그 행위자 필터를 텍스트 Input에서 SearchableDropdown으로 변경 — UUID 기반 사용자 검색, API `/api/admin/users/search` 연동 (_partial_filter.json, admin_activity_log_list.json) (#225)
- 활동 로그 DataGrid 행위자 컬럼을 ActionMenu로 변경 — 회원 정보 보기, 해당 행위자로 검색 기능 추가 (_partial_datagrid.json) (#225)
- 활동 로그 양방향(한글/영어) 검색 지원 — 액션/설명 검색 시 번역 라벨 역추적으로 원본 키도 함께 검색 (ActivityLogRepository.php) (#225)

## [0.2.23] - 2026-03-28

### Added

- 활동 로그 이력 조회 메뉴 및 목록 페이지 신설 — 필터(검색어, 로그 타입, 날짜 범위), DataGrid(확장 행으로 변경 내역 표시), 서버 사이드 페이지네이션, 단건/일괄 삭제 기능 (admin_activity_log_list.json, _partial_filter.json, _partial_datagrid.json) (#225)
- 활동 로그 다국어 키 추가 — 페이지 제목, 필터 레이블, 테이블 컬럼, 상세 정보, 삭제 확인 메시지 등 (ko/admin.json, en/admin.json) (#225)
- 활동 로그 목록 레이아웃 렌더링 테스트 추가 — 필터 UI, DataGrid 렌더링, 빈 상태, abilities 기반 삭제 버튼 제어 (admin-activity-log-list.test.tsx) (#225)
- 대시보드 활동 로그 레이아웃 렌더링 테스트 추가 (admin-dashboard-activity-log.test.tsx) (#225)

### Changed

- 활동 로그 필터 전면 재구현 — 검색 키워드 대상 드롭다운(전체/액션/설명/IP주소), 로그 타입 체크박스(전체/관리자/사용자/시스템), 행위자 필터, 기간 필터, filter-row/filter-label CSS 패턴 (_partial_filter.json, admin_activity_log_list.json) (#225)
- DataGrid `expandConditionField` prop 추가 — 행별 조건부 확장 아이콘 표시 지원, `has_changes` 필드 기반 변경 이력 있는 행만 확장 가능 (DataGrid.tsx) (#225)
- 활동 로그 DataGrid에 IP 주소 컬럼 추가, selectable 항상 활성화, expandConditionField 적용 (_partial_datagrid.json) (#225)
- 활동 로그 일괄 삭제 버튼 `if` 조건 → `disabled` 속성으로 변경 (admin_activity_log_list.json) (#225)
- 대시보드 "시스템 알림" 카드 숨김 처리 — `if: "false"` 추가, dashboard_alerts 데이터소스 `auto_fetch: false` 변경 (admin_dashboard.json) (#225)
- 대시보드 "최근 활동"이 사용자 가입 이력 대신 ActivityLog 전체를 표시하도록 변경 (admin_dashboard.json) (#225)

## [0.2.22] - 2026-03-28

### Fixed

- 대시보드 모듈 상태 배지가 항상 "비활성화"로 표시되는 버그 수정 — `module.is_active` (미존재 필드) → `module.status === 'active'` (admin_dashboard.json) (#225)
- 대시보드 확장 카드 아이콘을 사이드바 메뉴 아이콘과 일치 — 모듈: cube, 플러그인: puzzle-piece, 템플릿: palette (admin_dashboard.json) (#225)
- 대시보드 "더보기" 버튼 actions 형식을 배열 형식(`type: "click"`)으로 수정 — onClick 객체 형식에서 navigate 무반응 해결 (admin_dashboard.json) (#225)

### Added

- 대시보드 플러그인/템플릿 상태 카드 추가 — 모듈 카드와 동일 구조, 각 확장 타입별 아이콘 표시 (cube/puzzle-piece/palette) (admin_dashboard.json) (#225)
- 대시보드 확장 카드 "더보기" 버튼 추가 — 각 카드에서 해당 관리 페이지로 이동 (admin_dashboard.json) (#225)
- IconTypes.ts에 Palette 아이콘 추가 — 템플릿 관리 메뉴 아이콘(`fa-palette`) 지원 (IconTypes.ts) (#225)
- 대시보드 확장 상태 카드 레이아웃 렌더링 테스트 추가 (admin-dashboard-extension-status.test.tsx) (#225)

### Changed

- 대시보드 하단 그리드를 2열에서 3열로 변경 — 확장 카드 3개 (모듈/플러그인/템플릿) + 알림 카드 별도 행 (admin_dashboard.json) (#225)

## [0.2.21] - 2026-03-28

### Fixed

- FileUploader 업로드 후 'uploading' 항목 영구 잔류 버그 수정 — `response.data?.data` 이중 접근을 `response?.data`로 수정 (ApiClient가 Axios response.data를 이미 언래핑하므로), attachment null 시 에러 상태 전환 방어 로직 추가 (useFileUploader.ts) (#225)

### Added

- FileUploader 업로드 응답 파싱 테스트 추가 — renderHook 기반 훅 직접 테스트로 정상 파싱 및 null 응답 방어 검증 (FileUploader.test.tsx) (#225)

## [0.2.20] - 2026-03-27

### Added

- SortableMenuList 크로스 depth DnD 지원 — 중첩 SortableContext → flattened tree 방식 리팩토링, 단일 SortableContext로 depth 간 이동 가능
- AdminSidebar 부모 메뉴 클릭/토글 분리 — URL 있는 부모 메뉴 텍스트 클릭 시 네비게이션, chevron 클릭 시 하위 메뉴 토글

### Fixed

- SortableMenuList 드롭 위치 방향 판단 버그 수정 — flat 배열 인덱스 비교로 드래그 방향 판단, 위로 드래그 시 over 앞에 삽입

## [0.2.19] - 2026-03-27

### Changed

- Toast 기본 위치를 top-right → bottom-center로 변경 — 우측 상단 버튼과의 겹침 해소 (#148)
- Toast 숨김 애니메이션 방향을 position에 따라 분기 처리 (bottom → 아래로, top → 위로)

## [0.2.18] - 2026-03-24

### Added

- TabNavigationScroll에 스크롤 컨테이너 지정 기능 추가 — 특정 엘리먼트 내에서 스크롤이 발생하는 레이아웃(예: 게시판 설정 탭)에서 올바른 섹션 감지와 스크롤 이동을 지원
- TabNavigationScroll 스크롤 컨테이너 관련 테스트 추가

### Fixed

- UserProfile.tsx: User 인터페이스에 uuid 필드 누락 수정 (TS2339 빌드 오류)

## [0.2.17] - 2026-03-23

### Changed

- 사용자 목록: DataGrid row.id → row.uuid 참조 전환
- 사용자 상세/수정: UUID 읽기전용 필드 추가, user.id → user.uuid 전환
- UserProfile.tsx: 네비게이션 경로 user.id → user.uuid 전환
- ColumnSelector.tsx: localStorage 키 user.id → user.uuid 전환
- FilterVisibilitySelector.tsx: localStorage 키 user.id → user.uuid 전환

## [0.2.16] - 2026-03-23

### Added

- Hr (수평선) basic 컴포넌트 추가 — HTML `<hr>` 래퍼, className 지원

## [0.2.15] - 2026-03-22

### Added

- Textarea 크기 변형 CSS 클래스 추가 — `textarea-sm` (80px), `textarea-md` (128px), `textarea-lg` (192px)
- safelist에 `min-h` spacing 클래스 추가 — `min-h-10` ~ `min-h-64`

## [0.2.14] - 2026-03-21

### Added

- common.json 다국어 키 추가 — download, downloading, send, sending (ko/en)

## [0.2.13] - 2026-03-20

### Added

- Tfoot 기본 컴포넌트 추가 — HTML `<tfoot>` 래퍼, components.json 등록
- DataGrid footerRow 기능 — `footerCells`, `footerClassName`, `footerCardChildren` props 추가, PC(테이블)/모바일(카드) 합계 행 지원

### Changed

- DataGrid 조건부 렌더링을 엔진에 위임 — 자체 구현 `evaluateCondition` 제거, `condition` 속성은 엔진의 `evaluateRenderCondition`이 네이티브 처리 (engine-v1.21.1)
- DataGrid `mapConditionToIf` 워크어라운드 제거 — 엔진 `condition` 지원으로 불필요

## [0.2.12] - 2026-03-19

### Added

- 상품 리뷰 관리 화면 지원 (이커머스 모듈 연동)
  - 리뷰 목록: 상태 배지, 답변 여부 배지, 포토리뷰 이미지 썸네일 표시
  - 판매자 답변 등록·수정·삭제 모달
  - 리뷰 일괄 상태 변경 및 일괄 삭제
  - 이미지 미리보기 모달

### Fixed

- 역할 목록 페이지네이션 경로 수정 — `roles.pagination.total` 등 올바른 응답 경로 사용
- 역할 폼 신규 생성 시 `is_active` Toggle 값이 `false`로 저장되지 않는 버그 수정 — `init_actions`에서 Toggle 초기값 `true` 설정

## [0.2.11] - 2026-03-16

### Added

- CategoryTree 컴포넌트 추가 — 계층형 카테고리 트리 선택/해제 UI

### Fixed

- DataGrid `cellChildren`/`subRowChildren` 내부 액션에서 `setState(target: "local")`이 globalStateUpdater 경로로 실행되어 Form 자동 바인딩의 stale 값에 덮어쓰이는 버그 수정 — `componentContext`를 `renderCellChildren`/`renderSubRowChildren`에 전달하여 컴포넌트 setState 경로 사용

### Changed

- 라이선스 프로그램 명칭 정비

## [0.2.9] - 2026-03-14

### Fixed

- FormField 컴포넌트에 `error` prop 전달 시 자식 Input/Select/Textarea에 적색 테두리가 자동 적용되도록 수정
- `form-field-error` CSS 자손 선택자 추가 — FormField 에러 상태에서 `.input`, `input[type]`, `select`, `textarea` 요소에 `border-red-500` 적용

## [0.2.8] - 2026-03-14

### Fixed

- CodeEditor onChange를 `{ target: { value } }` 패턴으로 수정 — 엔진 isCustomComponentEvent 경로로 정상 처리
- 레이아웃 편집기 CodeEditor onChange에서 `$args[0]` → `$event.target.value` 수정 — 객체가 상태에 저장되는 버그
- 레이아웃 편집기 CodeEditor onChange에 debounce 300ms 추가 — 매 키 입력마다 전역 상태 갱신 방지

## [0.2.7] - 2026-03-14

### Added

- 역할 리스트에서 활성/비활성 상태 직접 토글 기능 (Toggle 컴포넌트, abilities 기반 비활성화)
- 역할 생성 폼에 `identifier` 입력 필드 추가 — 수정 모드에서는 비활성화
- 역할 토글/identifier 관련 다국어 키 추가 (ko/en)
- 역할 리스트 확장 출처 뱃지 개선 — extension_type별 구분 (코어/모듈/플러그인) + 로케일 확장명 표시

### Fixed

- PermissionTree 확장 타입 뱃지가 코어 권한에도 "모듈"로 표시되는 버그 수정 — API의 `extension_type` 필드 직접 활용

## [0.2.6] - 2026-03-13

### Added

- 환경설정 > 고급 디버그 모드 활성화 시 개발 대시보드(`/dev`) 바로가기 버튼 추가
- `dev_dashboard` 다국어 키 추가 (ko/en)
- Admin 푸터 copyright 클릭 → 코어 라이선스 모달, 버전 클릭 → Changelog 모달 기능
- 코어 라이선스/Changelog 모달 partial 추가 (`_modal_license.json`, `_modal_changelog.json`)
- 확장 상세 모달에서 라이선스 클릭 시 전문 모달 표시 (모듈/플러그인/템플릿)
- Pre 기본 컴포넌트 추가
- 라이선스/Changelog 관련 다국어 키 추가 (ko/en)
- TemplateResource에 `license` 필드 추가
- `_admin_base.json`에 버전 동적 바인딩 (`appConfig.version`)

## [0.2.5] - 2026-03-13

### Fixed

- 확장 수동 설치 모달에서 설치 실패 시 상세 에러 사유(`errors.error`) 미표시 → `whitespace-pre-line` P 요소 추가

## [0.2.4] - 2026-03-13

### Added

- 확장 수동 설치 모달 3개(모듈/플러그인/템플릿)에 `contentType: "multipart/form-data"` 적용 — ZIP 파일 업로드 시 FormData 전송

## [0.2.3] - 2026-03-13

### Changed

- 확장 업데이트 모달 changelog source를 동적으로 전환 — `source=bundled` 하드코딩 → `source={{row.update_source ?? 'bundled'}}` (모듈/플러그인/템플릿 4개 레이아웃)

### Fixed

- 코어 업데이트 결과 모달에서 from/to 버전 파라미터가 전달되지 않는 버그 수정 — `params.params` → `params.query`

## [0.2.2] - 2026-03-13

### Changed

- 확장 수동 설치 모달 3개(모듈/플러그인/템플릿) UI 통일 — TabNavigation underline, 에러 배너, 필드별 적색 테두리, download/spinner 아이콘
- 확장 목록 PageHeader에서 새로고침 버튼 제거, 업데이트 확인 버튼으로 통일

### Fixed

- 수동 설치 모달에서 `condition` 속성 사용으로 탭 콘텐츠가 모두 노출되던 문제 수정 — `if` 속성으로 전환

## [0.2.1] - 2026-03-08

### Changed

- 레이아웃 JSON의 `permissions.can_*` / `permissions?.can_*` 바인딩을 `abilities.can_*` / `abilities?.can_*`로 변경 (코어 표준화)
- 모듈/플러그인 상세 모달의 `defined_permissions` 키를 원래 이름 `permissions`로 원복

## [0.2.0] - 2026-03-05

### Changed
- 업데이트 모달 changelog 버전 헤더를 Badge 스타일로 개선 (모듈/플러그인/템플릿 공통)
- 버전 번호와 날짜를 분리 표시하여 중간 버전 시각적 구분 강화

## [0.1.9] - 2026-03-05

### Added
- 코어 메일 템플릿 관리 탭 및 발송 이력 탭 레이아웃
- 게시판/이커머스 메일 템플릿 관리 레이아웃

## [0.1.8] - 2026-03-02

### Changed
- 저작권 연도를 config/app.php SSoT 기반 동적 참조로 전환
- AdminFooter copyright prop 기본값 2025 → 2026 변경
- _admin_base.json copyright prop 동적 바인딩 전환 (`_global.appConfig.releaseYear`)
- components.json copyright default 값 2026으로 갱신

## [0.1.7] - 2026-02-26

### Added
- 확장 Changelog 시스템 구현 (CHANGELOG.md 파싱 및 관리화면 표시)
- 상세 모달에 변경 내역(Changelog) 섹션 추가 (모듈/플러그인/템플릿)
- 업데이트 모달에 인라인 변경사항 표시 추가
- Changelog API 엔드포인트 3개 추가 (modules/plugins/templates)
- 다국어 키 추가 (changelog, update_changelog_title 등)

### Fixed
- 미설치 확장의 변경 내역 미표시 버그 수정 (active → _bundled 폴백)

### Changed
- 업데이트/상세 모달 changelog 카드 간격 개선 (구분선 추가)

## [0.1.6] - 2026-02-26

### Fixed
- 업데이트 모달 '개발자' 필드에 vendor 대신 업데이트 소스가 표시되던 버그 수정
- vendor 행과 update_source 행을 분리하여 2개 행으로 표시

## [0.1.5] - 2026-02-25

### Changed
- 모듈/플러그인 업데이트 뱃지 색상 amber → red로 변경
- 모듈/플러그인 목록에 업데이트 버튼 추가

## [0.1.4] - 2026-02-25

### Fixed
- 확장 업데이트 버전 표시 버그 수정 (latest_version null fallback)
- iteration 컨텍스트 $t: → $t:defer: 수정 (7개 파일)

### Added
- 모듈/플러그인 레이아웃에 version_available 표시 추가
- 다국어 modules.version_available 키 추가

## [0.1.3] - 2026-02-25

### Fixed
- 모듈/플러그인 업데이트 체크 apiCall에 auth_required 누락 수정 (401 오류 해결)

## [0.1.2] - 2026-02-25

### Added
- 모듈 레이아웃 admin/user 분기 등록 기능
- 레이아웃 이름 포맷 통일 (DOT 포맷 지원)

### Changed
- LayoutResolverService 오버라이드 version_constraint 검사 추가
- 고아 오버라이드 레코드 자동 정리

## [0.1.1] - 2026-02-24

### Changed
- 버전 체계 조정 (정식 출시 전 0.x 체계로 변경)

## [0.1.0] - 2026-02-23

### Added
- 관리자 기본 템플릿 초기 구현
- 템플릿 구조 (template.json, routes.json, _base.json)
- 기본 컴포넌트 세트 (Basic 27개, Composite 7개)
- 사이드바 네비게이션: 접기/펼치기, 계층형 메뉴, 활성 상태 표시
- 상단바: 사용자 정보, 알림 벨, 다크 모드 전환 토글
- 대시보드 레이아웃: 시스템 정보, 통계 위젯
- 사용자/역할/권한 관리 화면
- 메뉴 관리 화면 (드래그 앤 드롭 순서 변경)
- 모듈/플러그인/템플릿 관리 화면 (설치/업데이트/상세 모달)
- 환경설정 탭 레이아웃 (사이트 정보, SEO, 메일, 보안)
- 일정 관리 캘린더 화면
- 시스템 정보 화면
- CRUD 화면 표준 레이아웃 (목록/생성/수정/상세)
- 모달 기반 상세 보기/수정 기능
- 토스트 알림 시스템
- 확인 다이얼로그 (삭제 확인 등)
- 에러 페이지 (403, 404, 500, 503)
- 다국어 지원 (ko, en)
- 다크 모드 지원
- 반응형 레이아웃 (데스크톱/태블릿/모바일)
