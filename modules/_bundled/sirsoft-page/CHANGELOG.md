# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0] - 2026-07-01

### Changed

- 페이지 등록 / 수정 화면의 입력 행(슬러그·발행여부 그리드, 제목, 내용, SEO 3개 항목)을 sirsoft-admin_basic 표준 시맨틱과 정합 — 라벨↔입력칸 간격이 한 폼 안에서 균일해지고, SEO 보조 라벨의 톤이 일관되며, 슬러그 안내문구의 위쪽 간격이 자연스럽게 정돈됩니다.
- 페이지 관리 화면의 발행 상태 필터(전체 / 발행 / 미발행)를 좌측 별도 카드에서 목록 상단 도구막대의 선택 메뉴로 통합 — 정렬·페이지 크기 선택과 같은 결의 드롭다운으로 정돈되어 한 줄에서 필터·정렬·페이지 크기를 함께 다룰 수 있습니다.
- 페이지 등록 / 수정 화면의 발행 상태·SEO 섹션에서 라벨과 입력칸 사이에 옅은 회색 분리선과 세로 간격이 끼어 보이던 회귀를 수정했습니다 — 라벨과 입력칸이 한 필드로 시각적으로 응집됩니다.

### Added

- 레이아웃 편집기에서 페이지 화면을 샘플 데이터로 미리보고 편집할 수 있도록 편집 스펙을 추가했습니다. 페이지 상세와 약관·개인정보 안내가 샘플 데이터로 표시되며, 게시됨/초안 미리보기 상태도 전환해 볼 수 있습니다.
- 레이아웃 편집기의 화면 상태 미리보기에 관리자 페이지 폼(신규/수정/검증 실패)을 추가해 편집기에서 전환해 미리볼 수 있도록 했습니다.
- 레이아웃 편집기 데이터 소스 목록에서 이 확장이 제공하는 데이터 소스가 친화 명칭으로 표시되고, 어느 확장이 제공했는지 출처가 함께 표시됩니다.

### Changed

- 페이지 등록 / 수정 화면의 입력 행 컨테이너를 sirsoft-admin_basic 표준 시맨틱 (.row-stack) 과 정합 — 행 사이 옅은 회색 구분선(1px) 이 자동 적용되어 페이지 폼의 행 묶음 가독성 개선.

## [1.0.0-beta.3] - 2026-05-11

### Changed

- 관리자 화면 좌우 정렬 컨테이너(섹션 헤더 · 액션바 · 카드 상단부 등) 외형을 표준 시맨틱(.flex-between)으로 정리 — 시각 변경 없음, 향후 톤 조정이 한 곳에서 가능.
- 페이지 등록 / 수정 화면의 입력 박스 외형을 관리자 표준 외형으로 정렬 — 다른 관리자 화면과 동일한 모양으로 보이도록 일관화.
- 페이지 목록의 발행 / 미발행 상태 배지 외형을 표준 배지 시맨틱으로 정리.
- 페이지 등록 / 수정 화면의 취소·저장 버튼을 페이지 상단에서 화면 하단 고정(스티키) 으로 이전 — 페이지 타이틀은 일반 흐름으로 정리되고, 첨부 / SEO 섹션을 스크롤하는 동안 저장 버튼이 항상 화면에 보이도록 일관화.
- 페이지 등록 / 수정 화면의 입력 행 컨테이너를 sirsoft-admin_basic 표준 시맨틱 (.row-stack) 과 정합 — 자식 행의 잔존 수직 패딩 / 마진이 표준 간격으로 정돈되고, 입력 박스 인라인 외형 토큰도 표준 입력 자산 (.input) 정의가 우선 표시되도록 정리.
- 페이지 관리 화면 (목록 · 상세 · 등록 / 수정) 의 콘텐츠 카드 / 패널 외형(흰 배경 · 테두리 · 라운드) 을 표준 시맨틱 (.admin-card) 과 정합 — 다른 관리자 화면과 같은 결로 통일하고, 패딩 · 외곽 마진 등 호출처별 변형 토큰만 보존.
- 페이지 관리 화면의 콘텐츠 카드 padding 을 표준 카드 외형 정의로 흡수 — 호출처가 들고 있던 padding 토큰(컴팩트 / 와이드 변형 등) 이 제거되고 모든 카드가 동일한 외형으로 통일.
- 페이지 관리 화면의 콘텐츠 카드 호출처를 표준 카드 자산 (.admin-card) 단독으로 일원화 — 외곽 마진 등 카드별 추가 토큰을 제거하고 모든 카드가 동일한 외형으로 표시.
- 페이지 관리 화면 곳곳의 본문 텍스트 (.text-body) 자산 정의를 실제 호출처 raw 패턴까지 흡수 — 텍스트 톤이 한 곳에서 일괄 조정 가능.
- 페이지 상세 화면의 카드 섹션 헤더 (펼침 / 접힘 가능한 영역 포함) 의 하단 분리선을 sirsoft-admin_basic 표준 헤더 외형 (.panel-header) 자산과 정합 — 직전 시맨틱 정리 과정에서 분리선이 흐려져 헤더 / 본문 경계가 모호하게 보이던 회귀를 해소.

### Added

- 페이지 수정 시 슬러그를 변경할 수 있도록 개선 (중복 확인 후 저장) (#16 @movielee2020 님께서 건의해주셨습니다.)
- 관리자 목록에서 슬러그 및 URL 텍스트 클릭 시 유저 화면으로 이동하는 링크 추가 (새 탭으로 열림) (#16 @movielee2020 님께서 건의해주셨습니다.)
- 활동 로그의 페이지 액션 라벨을 페이지 모듈 다국어 파일에서 관리하도록 정리 — 모듈 라벨이 자기 영역에서 자기설명되며, 일본어 등 추가 언어팩이 키 누락 없이 동기화

### Fixed

- 키워드 검색 결과의 전체 건수가 발행된 모든 페이지 수로 부풀려지던 문제 수정
- 페이지 발행/발행 취소 활동 로그의 "액션" 열에 `page.publish` / `page.unpublish` 가 번역되지 않은 키 그대로 노출되던 문제 수정
- 레이아웃 편집기 라우트 트리에서 페이지 관련 라우트 4건(목록·등록·수정·상세)의 라벨이 경로 마지막 세그먼트(`pages` / `create` / `edit` / `:id`)로만 표시되던 문제 수정. 한국어/영어 라벨이 정상 표시됩니다.

## [1.0.0-beta.2] - 2026-04-20

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- 페이지 편집 다국어 탭을 동적 생성으로 전환
- 페이지 에디터를 확장 포인트(extension_point)로 변환

### Fixed

- 사용자 화면 버전 배지가 잘못된 형식으로 표시되던 문제 수정 (#4 @laelbe 님께서 제보해주셨습니다.)
- 존재하지 않는 컬럼을 참조하던 draft/archived 배지 코드 제거

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.3.4] - 2026-03-30

### Fixed

- 관리자 다크모드 레이아웃 수정 — 2개 파일에서 누락된 `dark:` variant 약 7건 추가 (focus:ring, hover:bg 등)
- 페이지 목록에서 다른 페이지로 이동 후 돌아올 때 이전 검색어가 Input에 유지되던 문제 수정 — `_global` 상태를 `_local`로 전환 (admin_page_list.json)

## [0.3.3] - 2026-03-30

### Fixed

- Scout `query()` 콜백 내 `orWhere('slug', ...)` 조건이 `queryScoutModelsByIds()` 재실행 시 total=0을 반환하던 버그 수정 (PageRepository)
- BulkChangePageStatusRequest 다국어 키 매핑 오류 수정 — `ids.min`, `ids.integer` 키 경로 불일치 (BulkChangePageStatusRequest, validation.php)
- PageListRequest `search.max` 다국어 키가 `slug.max`를 재사용하던 문제 수정 (PageListRequest, validation.php)

### Changed

- 일괄발행/일괄발행해제 모달에 스피너 처리 추가 — sequence 핸들러, 버튼 비활성화, Icon spinner, onSuccess 순서 수정(closeModal 마지막), onError 리셋 (admin_page_list.json)
- 목록 DataGrid 선택 카운트 텍스트 비활성화 — `selectedCountText: ""` (admin_page_list.json)

## [0.3.2] - 2026-03-30

### Fixed

- 슬러그 중복 확인 API 응답의 `exists` 값을 무시하고 항상 사용 가능으로 표시되던 버그 수정 (admin_page_form.json)
- 슬러그 중복 확인 요청 시 `exclude_page_id` → `exclude_id` 파라미터명 불일치 수정 (admin_page_form.json)
- FormRequest `messages()` 다국어 키 경로에 잘못된 `page.` 접두사가 포함되어 번역이 되지 않던 버그 수정 (CheckSlugRequest, StorePageRequest, UpdatePageRequest, PageListRequest, ChangePageStatusRequest, BulkChangePageStatusRequest)
- 목록 검색 시 `filters` 배열 형식이 백엔드에서 처리되지 않던 버그 수정 — PageListRequest에 filters 검증 추가, PageRepository에 normalizeSearchFilters 변환 로직 추가
- 목록 페이지네이션 응답 경로 불일치 수정 — `pagination` → `meta` (admin_page_list.json)
- 목록 fallback 데이터 구조가 API 응답 구조와 불일치하던 버그 수정 (admin_page_list.json)

## [0.3.1] - 2026-03-30

### Changed

- PageRepository의 검색을 Laravel Scout `Page::search()` 파이프라인으로 전환 — DBMS별 검색 분기를 엔진 내부에서 처리
- `applyTitleKeywordSearch()`를 `DatabaseFulltextEngine::whereFulltext()` 정적 헬퍼로 전환 — 다중 DBMS 호환
- `searchIndexShouldBeUpdated()` 훅 기반 전환 — 검색 플러그인이 Page 인덱스 업데이트 제어 가능
- 마이그레이션 FULLTEXT 인덱스 생성을 `DatabaseFulltextEngine::addFulltextIndex()` 정적 헬퍼로 전환

## [0.3.0] - 2026-03-30

### Added

- FULLTEXT 인덱스(ngram) 추가 — pages(title, content) 총 2개
- Page 모델에 Laravel Scout `Searchable` 트레이트 + `FulltextSearchable` 인터페이스 적용

### Changed

- 페이지 검색 쿼리를 `LIKE '%keyword%'`에서 `MATCH...AGAINST IN BOOLEAN MODE`로 전환 — title, content 검색 성능 향상

## [0.2.0] - 2026-03-25

### Added

- PageActivityLogListener: 페이지/페이지첨부파일 전체 로깅
- 활동 로그 다국어 키 10개 정의 (resources/lang/ko/activity_log.php, en/activity_log.php)
- $activityLogFields 메타데이터: Page 모델
- ActivityLog 샘플 시더 (database/seeders/ActivityLogSampleSeeder.php)
- ActivityLogDescriptionResolver: 활동 로그 표시 시점에 페이지 제목을 ID에서 해석하는 필터 훅 리스너

### Fixed

- 활동 로그 description에 번역 키가 원문으로 노출되는 문제 수정 — src/lang 번역 파일 동기화, description_params 불일치 수정

## [0.1.9] - 2026-03-23

### Changed

- API Resource: creator.id / updater.id → creator.uuid / updater.uuid 전환 (PageResource, PageVersionResource)

## [0.1.8] - 2026-03-21

### Fixed

- 공개 페이지 API 라우트 throttle을 60/분에서 600/분으로 상향 — 게시판 모듈과 동일 카운터 공유로 인한 429 Too Many Requests 방지

## [0.1.7] - 2026-03-18

### Changed

- `SeoPageCacheListener` — 페이지 변경 시 `search/index` 무효화 추가

## [0.1.6] - 2026-03-16

### Changed

- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
- 라이선스 프로그램 명칭 정비

## [0.1.5] - 2026-03-11

### Added

- manifest에 license 필드 및 LICENSE 파일 추가

### Fixed
- Page 모델 `$fillable`에 `created_by`, `updated_by` 누락 수정 — 페이지 생성 시 생성자/수정자 ID가 DB에 저장되지 않던 문제 해결
- `PageRepositorySearchTest` 헬퍼에서 하드코딩된 user ID를 팩토리 생성 유저로 변경

### Changed
- `ModuleTestCase`에 `$migrated` 플래그 추가 — 마이그레이션 체크를 프로세스당 1회로 최적화

## [0.1.4] - 2026-03-11

### Added
- `PageCollection`에 `HasAbilityCheck` trait 추가 — Collection-level abilities (can_create, can_update, can_delete) 반환
- `PageService`에 scope 검증 추가 — `getPage`, `updatePage`, `deletePage`, `changePublishStatus`, `restoreVersion` 메서드에서 `PermissionHelper::checkScopeAccess()` 호출
- `PageController`에 `AccessDeniedHttpException` catch 추가 — scope 위반 시 403 응답
- 목록 화면: "페이지 추가" 버튼, 일괄 발행/미발행 버튼에 abilities 기반 disabled 제어
- 폼 화면: computed `isReadOnly` 패턴 적용 — 수정 권한 없을 시 읽기전용 배너 표시 및 모든 폼 필드 비활성화
- 상세 화면: DS `page` errorHandling (403/404) 추가, 수정/삭제/발행/복원 버튼 abilities 기반 disabled 제어
- 다국어: 읽기전용 배너 메시지 추가 (ko/en)
- 백엔드 테스트: Collection abilities 응답 구조, per-item abilities, Service-level scope 검증 (AccessDeniedHttpException) 테스트 추가
- 프론트엔드 테스트: 권한 기반 UI 제어 검증 테스트 추가 (목록/폼/상세 각 레이아웃)

## [0.1.3] - 2026-03-10

### Changed
- 권한 카테고리에 `resource_route_key`, `owner_key` 스코프 메타데이터 추가 (pages)
- 라우트 미들웨어 `except:owner:page` 옵션 제거 (scope_type 데이터 기반 시스템으로 전환)
- Repository 목록 조회에 `PermissionHelper::applyPermissionScope()` 적용 (Page)
- `PermissionBypassable` 인터페이스 및 `getBypassUserId()` 제거 (Page, PageVersion, PageAttachment 모델)

## [0.1.2] - 2026-03-10

### Changed
- API 리소스 권한 플래그 키 `permissions` → `abilities`로 변경 (코어 표준화)
- `permissionMap()` → `abilityMap()` 메서드명 변경

### Fixed
- 페이지 생성/수정 요청에서 제목(title) 다국어 필드에 `array` 검증 규칙 누락 수정

## [0.1.1] - 2026-02-27

### Added
- 코어 페이지 관리 기능을 독립 모듈로 분리
- 페이지 CRUD (생성, 조회, 수정, 삭제)
- 발행/미발행 토글 및 일괄 발행 기능
- 슬러그 기반 페이지 URL 및 중복 확인
- 버전 관리 시스템 (이력 조회, 상세, 복원)
- 첨부파일 시스템 (업로드, 삭제, 순서 변경, 다운로드, 미리보기)
- 공개 페이지 API (슬러그 기반 조회, 첨부파일 다운로드/미리보기)
- 통합검색 연동 (PageSearchListener)
- 관리자 레이아웃 3종 (목록, 등록/수정, 상세)
- 다국어 지원 (ko, en)
- 권한 4종 (read, create, update, delete)
- 관리자 메뉴 등록

### Changed
- `status` (draft/published/archived) Enum → `published` boolean 단순화
- `type` (content/policy) 구분 제거
- 단일 테이블 패턴 적용 (동적 테이블 → 정적 테이블)