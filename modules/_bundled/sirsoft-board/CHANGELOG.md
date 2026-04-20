# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.2] - 2026-04-20

### ⚠️ 업데이트 주의사항

- 파티션 제거 마이그레이션 포함 — 데이터 규모에 따라 시간이 오래 걸릴 수 있습니다
  - 게시글 1만 건 이하: 약 1분 이내 / 50만 건 이상: 약 20~30분 이상
  - 댓글 90만 건 이상: 약 20~30분 이상
- 강제 종료 시 모듈 상태가 `updating`으로 남을 수 있음 — tinker로 `status`를 `active`로 직접 복구

### Added

- **사이트 내 알림 연동**
  - 이메일 외 사이트 내 알림(database 채널) 추가 — 알림센터에서 즉시 확인 가능
  - 환경설정 > 알림 탭에서 채널별 ON/OFF 토글 및 템플릿 통합 관리
  - 사용자 개인 알림 수신 설정(마이페이지) 반영
  - 댓글 직권 처리(블라인드/삭제/복원) 시에도 알림 발송
  - 게시글/댓글 처리 알림에 대상 유형 표기 추가
  - 신고 누적 자동 블라인드는 직권 처리 알림에서 제외
  - 알림 정의 일괄 초기화 기능 — 확인 모달 + 진행 스피너 UX 포함
  - 게시판 알림 정의를 코어 리셋 로직에 자동 기여하여 기본값 복원 가능
  - 알림 탭은 코어 알림 권한 기준으로 편집 가능 여부를 판단하도록 개선 (모듈 설정 권한과 분리)
- 게시판 유형(BoardType) 사용자 수정 보존 지원
- 게시판 환경설정 SEO 설정 탭 추가
  - 게시판 목록/글 목록/게시글 상세 페이지별 메타 제목·설명 직접 설정
  - 페이지별 SEO 제공 여부 온/오프 및 캐시 초기화 버튼
- SEO 변수 시스템 적용 — 게시판/게시글 페이지 타입별 변수 정의 및 레이아웃 연동

#### 성능 최적화

- 이전글/다음글 조회를 독립 API로 분리하여 게시글 상세 페이지 초기 로딩 쿼리 감소
- 게시글·댓글 수 등 집계 정보를 사전 계산 컬럼으로 관리하도록 개선 — 목록 조회 시 COUNT 쿼리 제거
- 게시글 본문 검색 및 통합검색에 FULLTEXT 인덱스 적용 — 대용량 데이터에서도 빠른 검색 가능

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향

#### 알림

- 알림 발송 방식을 코어 공통 필터 기반으로 전환하여 이중 발송 제거
- 알림 탭 채널 목록을 코어 공통 API로 교체
- 알림 정의 시더를 인라인 방식으로 전환 — 사용자 수정 자동 보존
- 레거시 메일 템플릿 테이블을 통합 알림 시스템으로 이관
- 이전 업그레이드 스텝에 클래스 부재 시 안전 스킵 처리 추가
- 알림 템플릿 편집 모달 다국어 탭을 동적 생성으로 전환

#### 성능 최적화

- 게시글 목록 조회 시 비활성 게시판 필터링 방식을 개선하여 쿼리 성능 향상
- 인기글 정렬 기준을 조회수 기반으로 단순화하여 인덱스를 활용할 수 있도록 변경
- 글수정 폼 진입 시 불필요한 데이터 조회를 줄여 로딩 쿼리 감소
- 캐시 유지 시간을 설정값 기반으로 관리할 수 있도록 변경

#### 기타

- 공통 캐시 시스템으로 이관 — 모듈 내 모든 캐시 호출을 코어 캐시 인터페이스로 전환하고 키 접두사 통일
- 게시판 목록 데이터 소스를 비인증 접근 허용으로 변경 — SEO 봇 대응
- 게시글 에디터를 확장 포인트(extension_point)로 변환

### Removed

- 직접 발송 리스너 제거 — 코어 알림 시스템으로 대체
- 기본 설정·신고 정책 탭의 알림 채널 선택 제거 — 알림 탭으로 통합
- 알림 채널 관련 컬럼 및 모듈 전용 API 제거 — 코어 공통 API 사용
- 레거시 메일 템플릿 시더 및 관련 다국어 키 제거

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.9.5] - 2026-03-31

### Added

- 이커머스 1:1 문의게시판 Extension Point에 `mode: "replace"` 적용 — 게시판 모듈 활성 시 default 폴백 메시지 교체

### Fixed

- 게시판 글 목록·신고 목록에서 다른 페이지로 이동 후 돌아올 때 이전 검색어가 Input에 유지되던 문제 수정 — `_global` 상태를 `_local`로 전환 (admin_board_posts_index.json, admin_board_reports_index.json)

## [0.9.4] - 2026-03-30

### Fixed

- 활동 로그 enum 미번역 수정 — Post, Comment, Board, Report 모델의 `$activityLogFields` status/설정 필드 `type: 'text'` → `type: 'enum'` 변경 + 6개 Enum 클래스에 `labelKey()` 메서드 추가
- 활동 로그 샘플 시더 enum 타입 동기화 — `ActivityLogSampleSeeder`의 status 관련 9건 `type: 'text'` → `type: 'enum'` 수정 및 `old_label_key`/`new_label_key` 추가

## [0.9.3] - 2026-03-30

### Fixed

- 관리자 다크모드 레이아웃 전수 수정 — 19개 파일에서 누락된 `dark:` variant 약 70건 추가 (text-gray, bg-white/gray, focus:ring, text-red/blue, border-gray 등)

## [0.9.2] - 2026-03-30

### Changed

- `window.G7Config` 설정 노출 최소화 — frontend_schema 4개 카테고리(basic_defaults, report_policy, spam_security, display) 전체 `expose: false` 처리 (프론트엔드 미참조)

## [0.9.1] - 2026-03-30

### Changed

- ReportRepository의 직접 `MATCH...AGAINST` 호출을 `DatabaseFulltextEngine::whereFulltext()` 정적 헬퍼로 전환 — 다중 DBMS 호환
- Post 모델에 `FulltextSearchable` 인터페이스 추가 — Scout 검색 파이프라인 지원
- `searchIndexShouldBeUpdated()` 훅 기반 전환 — 검색 플러그인이 Post 인덱스 업데이트 제어 가능
- 마이그레이션 FULLTEXT 인덱스 생성을 `DatabaseFulltextEngine::addFulltextIndex()` 정적 헬퍼로 전환

## [0.9.0] - 2026-03-30

### Added

- FULLTEXT 인덱스(ngram) 추가 — boards(name), boards_report_logs(snapshot) 총 2개

### Changed

- 신고 관리 검색 쿼리를 `LIKE '%keyword%'`에서 `MATCH...AGAINST IN BOOLEAN MODE`로 전환 — boards.name, boards_report_logs.snapshot 검색 성능 향상

## [0.8.1] - 2026-03-30

### Fixed

- 신규 게시판 생성 시 조회수 표시가 기본 OFF로 설정되던 문제 수정 — 기본값을 ON으로 변경 (#228)
- 신규 게시판 생성 시 최소 내용 길이 기본값이 10자로 설정되던 문제 수정 — 기본값을 2자로 완화 (#228)
- 파일 크기 설정 단위를 bytes에서 MB로 통일 — 관리자 설정 입력, DB 저장, 검증, 안내 문구 모두 MB 기준으로 변경 (#228)
- 게시글 삭제(소프트삭제) 시 댓글과 첨부파일이 함께 삭제되어 복원 시 미표시되던 문제 수정 — 게시글만 삭제하고 댓글/첨부파일은 유지 (#228)

## [0.8.0] - 2026-03-30

### Added

- 이커머스 1:1 문의 게시판 연동 지원
  - 이커머스 환경설정 > 문의게시판 설정 화면에 게시판 선택 드롭다운, 게시판 수정 이동, 문의내역 관리, 관리자 메뉴 추가 버튼 동적 주입
  - 문의/답변 생성·수정·삭제를 게시판 게시글로 처리하는 훅 리스너 추가
- `PostRepository`에 관계 포함 조회 메서드 3개 추가 (`findWithBoard`, `findByIdsWithRelations`, `findFirstReplyWithBoard`)
- 검색/필터/정렬 성능 향상을 위한 누락 인덱스 일괄 추가 — board_posts 2개([board_id+author_name] 비회원 작성자 검색, [board_id+status+created_at] 게시판별 상태+날짜 필터)

### Changed

- 문의 삭제/답변 삭제 시 게시판 알림이 발송되지 않도록 처리 방식 개선
  - 외부 모듈 위임 방식에서 `skip_notification` 옵션 직접 체크 방식으로 변경

### Fixed

- 게시글 수정 후 `after_update` 훅이 트랜잭션 내부에서 실행되어 롤백 시 부작용이 발생할 수 있던 문제 수정
- `Board`, `Post` 모델 직접 조회를 Repository 경유로 교체

## [0.7.1] - 2026-03-27

### Fixed

- 게시판 관리 부모 메뉴 URL을 null로 변경 — 대응 라우트 없는 `/admin/boards` URL이 404 발생 (module.php)

## [0.7.0] - 2026-03-25

### Added

- BoardActivityLogListener: 게시판/게시판유형/게시물/댓글/첨부파일/신고 전체 로깅
- 활동 로그 다국어 키 33개 정의 (resources/lang/ko/activity_log.php, en/activity_log.php)
- $activityLogFields 메타데이터: Board, Post, Comment 모델
- ActivityLog 샘플 시더 (database/seeders/ActivityLogSampleSeeder.php)
- ActivityLogDescriptionResolver: 활동 로그 표시 시점에 게시판/게시물/댓글 엔티티명을 ID에서 해석하는 필터 훅 리스너
- BoardActivityLogListener: 신고 일괄 상태변경 before 스냅샷 + after ChangeDetector 추가 (1훅 추가, 총 32훅)
- BoardActivityLogListener: 신고 일괄 상태변경 per-Report 로깅 전환 (건별 loggable_id 기록)

### Fixed

- 활동 로그 description에 번역 키가 원문으로 노출되는 문제 수정 — src/lang 번역 파일 동기화, description_params 불일치 수정
- UpdateBoardTypeRequest: `name.ko` 로케일 하드코딩 메시지 제거 — LocaleRequiredTranslatable이 자체 메시지 반환

## [0.6.10] - 2026-03-26

### Added

- 사용자 게시판 목록/상세에서 manager 권한 사용자가 삭제된 게시글/댓글을 선택적으로 볼 수 있는 토글 기능 추가

### Fixed

- 게시판 권한 탭 manager/step TagInput UUID 불일치 수정 — options 및 change 핸들러 표현식에서 `u.id` → `u.uuid`로 수정 (4곳)
- `BoardController::getFormData()` 생성 모드에서 `board_manager_ids`에 uuid 반환하도록 수정
- `BoardSampleSeeder` — `pluck('id')` → `pluck('uuid')` 수정
- `StoreCommentRequest` — 서버 주입 필드(`user_id`, `ip_address`) 검증 규칙 제거 (규정 준수)
- `CommentController` — `prepareForValidation()`으로 처리된 `post_id` 중복 대입 제거
- 신고 시스템 API 응답 UUID 적용 — `ReportResource`, `ReportLogResource`, `ReportDetailResource`, `ReportService` `id` → `uuid` 전환
- 마이페이지 내 게시글 목록에서 비활성 게시판(`is_active=false`)의 게시글이 노출되던 문제 수정
- 공지글 쿼리에서 삭제 상태 공지 제외 누락 수정
- 삭제된 게시글 상세 접근 시 명시적 404 처리 추가
- 삭제된 게시글의 댓글을 권한 없는 사용자에게 반환하던 문제 수정
- 게시판 폼 탭 순서 통일 (기본정보 → 설정 → 권한)
- 대댓글 UI 들여쓰기 버그 수정

## [0.6.9] - 2026-03-24

### Added

- 게시판 설정 화면의 각 탭 이름을 한국어/영어로 표시할 수 있도록 다국어 키 추가

### Changed

- 게시판 설정 화면을 기능별 탭(기본, 권한, 목록, 게시글, 답변글, 댓글, 첨부파일, 알림, 일괄적용)으로 분리하여 레이아웃 파일 전면 재구성
- 변경된 탭 구조에 맞게 레이아웃 렌더링 테스트 업데이트

## [0.6.8] - 2026-03-23

### Changed

- API Resource: author.id → author.uuid 전환 (PostResource, CommentResource, BoardResource)
- PostResource: user_id FK → user.uuid 전환
- CommentResource: author.id → author.uuid 전환
- 관리자 레이아웃: author.id → author.uuid 참조 전환 (5개 파일)
- 유저 게시글 라우트: 정수 제약 제거 (Route Model Binding 호환)
- FormRequest: 정수 검증 → UUID 검증 전환 (StoreBoardRequest, StoreCommentRequest, UpdateBoardRequest)
- BoardService: UUID→정수 FK 변환 처리 추가

## [0.6.7] - 2026-03-21

### Fixed

- 공개 API 라우트 throttle을 60/분(댓글은 30/분)에서 600/분으로 일괄 상향 — 페이지 간 네비게이션 시 동일 카운터(sha1(userId)) 공유로 인한 429 Too Many Requests 방지 (게시글, 댓글, 첨부파일, 사용자 프로필, 마이페이지 활동 라우트 포함)

## [0.6.6] - 2026-03-21

### Added
- 게시판 날짜 표기 방식 개선 — `standard`(표준형) / `relative`(유동형) 전역 설정 추가
- 게시판 환경설정 `general` 탭 신규 추가 — 날짜 표시 방식 선택 UI
- 블라인드/삭제 게시글 댓글 작성 폼 숨김 및 안내 메시지 표시

### Changed
- `PostResource` `created_at` — 원본 datetime 반환으로 변경, 포맷된 날짜는 `created_at_formatted` 필드로 분리
- `SearchPostsListener` — 게시판별 N번 쿼리 → 단일 쿼리로 리팩토링

### Fixed
- 게시판 환경설정 권한 일괄적용 시 manager/step 역할이 초기화되던 문제 수정
- 게시판 권한 자동주입 정책 변경 — 게시판 생성/수정 시에만 적용, 일괄적용 시 제외
- 일괄적용 모달 열기 전 이전 오류 상태가 남아있던 문제 수정
- 이미지 미리보기(preview) API throttle을 게시판 API 그룹(60/분)에서 분리하여 전용 그룹(300/분)으로 변경 — 갤러리 게시판에서 다수 썸네일 로드 시 429 Too Many Requests 방지

## [0.6.5] - 2026-03-18

### Added

- `SeoBoardSettingsCacheListener` — 게시판 모듈 설정 변경 시 관련 SEO 캐시(board/index, board/show, board/boards, sitemap) 선별 무효화

### Changed

- `SeoBoardCacheListener` — 게시판별 독립 무효화로 전환 (`invalidateByLayout` → `invalidateByUrl` URL 기반), `search/index`·`home` 무효화 추가, 생성/수정 시 해당 게시물 상세 캐시 즉시 재생성(`SeoCacheRegenerator`)

## [0.6.4] - 2026-03-16

### Changed

- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
- 시더 디렉토리 분리 — 샘플 시더를 `Sample/` 하위로 이동
- 라이선스 프로그램 명칭 정비
- 게시판 환경설정 메일 템플릿 탭 레이아웃 개선

## [0.6.3] - 2026-03-13

### Added
- 신고 접수 시 관리자에게 알림 이메일 발송 기능 추가
- 신고 처리(블라인드/삭제/반려) 결과를 게시글 작성자에게 알림 이메일로 안내하는 기능 추가
- 관리자 알림 발송 범위 설정 추가 — 게시글당 최초 신고 시에만 발송하거나, 신고가 들어올 때마다 발송하는 옵션 선택 가능
- 관리자 역할별 신고 알림 수신 여부 설정 기능 추가
- 이미 신고한 게시글/댓글의 신고 버튼 비활성화 기능 추가

### Changed
- 신고 정책 설정 화면에서 알림 발송 범위를 더 직관적인 라디오 버튼 형태로 변경
- 신고 반려 처리 알림 문구 개선 — "반려(복원)"으로 명확하게 안내하도록 변경
- 블라인드 처리된 게시글 안내 메시지 통일
- 신고 알림 수신 관리자 역할 지정 방식 개선 — 중복 지정 없이 정확하게 반영되도록 수정

### Fixed
- 존재하지 않는 게시글 접근 시 404 페이지로 이동하지 않던 문제 수정
- 신고 일괄 처리 드롭다운이 정상 작동하지 않던 문제 수정

## [0.6.2] - 2026-03-12

### Added
- 신고 케이스 구조 전환 — 게시글/댓글당 케이스 1행 구조로 변경 (boards_reports + boards_report_logs 분리)
- 신고자 목록 페이지네이션 API 추가
- 신고 처리 이력 타임라인 표시
- ReportLogResource 추가
- manifest에 license 필드 및 LICENSE 파일 추가

### Changed
- 신고 목록 정렬 기준을 마지막 신고 일시 기준으로 변경
- 신고 일괄 처리 단순화 — 케이스 단위 직접 처리로 변경
- 게시판 샘플 시더 게시판 6개 → 8개로 확장
- 신고 샘플 시더 재신고자 없을 경우 방어 처리 추가

### Fixed
- 재활성 사이클 카운트 버그 수정 — 이전 사이클 신고 로그가 현재 사이클에 포함되던 문제
- 반려 시 복구 대상 케이스 상태 조건 불일치 수정

## [0.6.1] - 2026-03-11

### Changed

- `BoardResource` 응답의 `user_permissions` 키를 `user_abilities`로 변경 — `can_*` 형식 통일
- `getUserBoardPermissions()` 메서드의 반환 키를 `posts_write`, `manager` 등 underscore 형식에서 `can_write`, `can_manage` 등 `can_*` 형식으로 변경
- Admin/User PostController의 `include_user_permissions` 파라미터를 `include_user_abilities`로 변경

### Added

- `BoardUserAbilitiesTest` — BoardResource user_abilities 키 구조, PostResource/CommentResource abilities 키 검증 테스트 13개

## [0.6.0] - 2026-03-11

### Added
- 게시판별 동적 권한에 스코프(scope) 메타데이터 지원 추가 (`resource_route_key`, `owner_key`)
- `config/board.php` 권한 정의에 `scope` 키 추가 (12개 권한: admin/user 각 6개)
- `BoardPermissionService::ensureBoardPermissions()` — 권한 생성 시 scope 메타데이터 자동 설정
- `PostService` — 목록/상세 조회 시 `$context` 파라미터 기반 스코프 필터링 적용
- `CommentService::getCommentsByPostId()` — `$context` 파라미터 기반 스코프 필터링 적용
- `AttachmentService::download()` — `$context` 파라미터 기반 스코프 접근 제어 적용
- User 컨트롤러 전체에 `AccessDeniedHttpException` catch 및 403 응답 처리
- Admin PostController/AttachmentController에 `AccessDeniedHttpException` catch 추가
- `Upgrade_0_6_0.php` — 기존 게시판 동적 권한의 scope 메타데이터 백필 업그레이드 스크립트
- `BoardPermissionScopeServiceTest` — 서비스 레이어 스코프 필터링 테스트 10개

### Fixed
- User 컨트롤러에서 `getBoardBySlug()` 호출 시 `checkScope: false` 적용 — 매니저 역할 사용자 500 에러 수정
- `PostRepository::countNormalPosts()` — 누락된 스코프 필터링 추가

## [0.5.5] - 2026-03-11

### Fixed
- Board 모델 `$guarded`에서 `created_by`, `updated_by` 제거 후 `$fillable`로 이동 — 게시판 생성 시 생성자/수정자 ID가 DB에 저장되지 않던 문제 해결
- `BoardService::createBoard()`에 `Auth::id()` 기반 `created_by`/`updated_by` 설정 추가
- `BoardService::updateBoard()`에 `Auth::id()` 기반 `updated_by` 설정 추가

### Changed
- `ModuleTestCase`에 `$migrated` 플래그 추가 — 마이그레이션 체크를 프로세스당 1회로 최적화

## [0.5.4] - 2026-03-11

### Added
- `BoardService`, `ReportService`에 `PermissionHelper::applyPermissionScope()` / `checkScopeAccess()` 적용
- `BoardController`, `ReportController` 상세/수정/삭제 엔드포인트에 `AccessDeniedHttpException` 처리 추가
- 게시판 목록 조회 시 스코프 필터링 적용 (본인만/역할/전체)
- 게시판 상세/수정/삭제/복사 시 스코프 접근 제어 적용
- 신고 상세/상태변경/삭제 시 스코프 접근 제어 적용

### Fixed
- `PostCollection::toArray()` 클로저에 `$request` 변수 미전달 버그 수정 (Undefined variable $request)
- `BoardPermissionTest` 테스트 assertions를 `permissions` → `abilities` 키로 수정
- `PostResourcePermissionTest` 테스트 assertions를 `permissions` → `abilities` 키로 수정

## [0.5.3] - 2026-03-11

### Added
- 게시판 설정 화면에 `isReadOnly` 패턴 적용 (읽기 전용 배너, 모든 폼 필드 비활성화, 저장 버튼 비활성화)
- 게시판 폼 화면에 `isReadOnly` 패턴 적용 (기본정보/권한설정/목록설정/게시글설정/알림설정 탭 전체)
- 게시글 폼 화면에 `isReadOnly` 패턴 적용 (제목/본문/카테고리/옵션/첨부파일 비활성화)
- `BoardSettingsController`에 abilities 응답 추가 (`can_update`)
- 게시판 목록 DS에 403 errorHandling 추가
- 게시판 폼 DS에 403 errorHandling 및 roles DS fallback 추가
- 게시판 설정 DS에 403 errorHandling 및 보조 DS fallback 추가

### Changed
- 게시판 목록 "게시판 추가" 버튼: `if`(숨김) → `disabled`(비활성화)로 변경
- 게시글 목록 "게시글 작성" 버튼: `if`(숨김) → `disabled`(비활성화)로 변경
- 게시글 상세 답글 버튼: `if`(숨김) → `disabled`(비활성화)로 변경
- 게시글 상세 ActionMenu: 권한 없는 항목을 숨김 대신 `disabled` 처리
- 댓글 답글 버튼 및 ActionMenu: 권한 없는 항목을 숨김 대신 `disabled` 처리
- 댓글 입력 영역: `if`에서 ability 조건 분리 → 내부 Textarea/Button `disabled` 처리
- 신고 목록 일괄 작업 버튼: `if`(숨김) → `disabled`(비활성화)로 변경
- 신고 상세 상태 변경 버튼: `if`에서 ability 조건 분리 → `disabled` 처리
- 블라인드/삭제 댓글 원본 보기 버튼: `if`(숨김) → `disabled`(비활성화)로 변경
- 게시판 유형 관리 모달에 `isReadOnly` 기반 disabled 추가

### Fixed
- `ReportCollection`의 `permissions` → `abilities` 키 불일치 수정 (신고 목록 일괄 작업 미표시 버그)
- `ReportDetailResource`의 `permissions` → `abilities` 키 불일치 수정 (신고 상세 상태 변경 버튼 미표시 버그)

## [0.5.2] - 2026-03-10

### Changed
- 권한 카테고리에 `resource_route_key`, `owner_key` 스코프 메타데이터 추가 (boards, reports)
- 라우트 미들웨어 `except:owner:*` 옵션 제거 (scope_type 데이터 기반 시스템으로 전환)
- Repository 목록 조회에 `PermissionHelper::applyPermissionScope()` 적용 (Board, Post, Comment, Report)
- `PermissionBypassable` 인터페이스 및 `getBypassUserId()` 제거 (Board, Post, Comment, Report, Attachment 모델)

## [0.5.1] - 2026-03-10

### Changed
- API 리소스 권한 플래그 키 `permissions` → `abilities`로 변경 (코어 표준화)
- `permissionMap()` → `abilityMap()`, `resolvePermissions()` → `resolveAbilities()` 메서드명 변경
- `BoardCollection.withPermissions()` 응답 키 `permissions` → `abilities`
- `PostCollection` 응답 키 `permissions` → `abilities`
- 기존 `board_permissions` 키를 원래 이름 `permissions`로 원복 (충돌 해소)
- 관리자 레이아웃 JSON의 `permissions.can_*` / `permissions?.can_*` 바인딩을 `abilities.can_*` / `abilities?.can_*`로 변경
- 사용자 레이아웃 JSON의 `permissions?.can_view` 바인딩을 `abilities?.can_view`로 변경

### Fixed
- 알림 채널(이메일/사이트 알림) 일괄 적용 시 채널 설정이 저장되지 않는 버그 수정
- 일괄 적용 모달에서 알림 채널 항목 선택 시 체크박스가 정상 동작하지 않는 버그 수정
- 일괄 적용 모달에서 알림 채널 항목명이 키 코드로 표시되는 다국어 누락 수정

## [0.5.0] - 2026-03-06

### Changed
- 메일 템플릿 목록 API에 검색/필터/페이지네이션 지원 추가
- 메일 템플릿 편집 모달 UX 개선 (blur_until_loaded, sticky footer, 함수형 body)
- `getDefaultTemplateData()`를 Controller에서 Service로 이동 (Service-Repository 패턴 준수)
- 메일 템플릿 탭 UI를 코어 환경설정과 동일한 구조로 변경

### Added
- 메일 템플릿 미리보기(preview) API 엔드포인트 추가
- 메일 템플릿 검색 기능 (제목/본문/전체)
- 페이지당 항목 수 선택 및 페이지네이션
- 메일 템플릿 관련 다국어 키 추가 (검색/필터/빈 상태/편집 모달)

## [0.4.0] - 2026-03-05

### Added
- DB 기반 메일 템플릿 시스템 (5종: 새 댓글, 대댓글, 답변글, 관리자 처리, 관리자 새 게시글)
- `board_mail_templates` 테이블, BoardMailTemplate 모델/서비스/리포지토리/컨트롤러
- 메일 템플릿 관리 API 및 환경설정 UI
- 업그레이드 스텝 (`Upgrade_0_4_0`) — 기존 설치에 메일 템플릿 초기 시딩

### Changed
- 5개 알림 클래스 DbTemplateMail 기반으로 전환 (Blade → DB 기반)

### Removed
- `BoardNotificationMail`, `board-notification.blade.php` 삭제

## [0.3.2] - 2026-03-06

### Added
- 게시판 유형(BoardType)을 독립 엔티티로 분리 — `board_types` 테이블, `BoardType` 모델
- 게시판 유형 API — `BoardTypeController`, `BoardTypeService`, `BoardTypeRepository` 추가
- 게시판 유형 요청/응답 클래스 — `StoreBoardTypeRequest`, `UpdateBoardTypeRequest`, `BoardTypeResource` 추가
- 환경설정 > 기본설정 탭에 게시판 유형 관리 모달 추가 (생성 / 수정 / 삭제)
- 게시판 유형 삭제 시 사용 중인 게시판 수 검증 (사용 중이면 삭제 불가)
- 기본값으로 설정된 게시판 유형 삭제 방지 로직 추가
- 미지원 게시판 유형에 대한 fallback 블록 추가 (basic 레이아웃으로 표시)

### Changed
- 게시판 생성/수정 폼에서 유형 관리 버튼 제거 — 환경설정 > 기본설정에서 일원 관리하도록 UX 변경
- 게시판 폼 유형 Select 하단에 관리 안내 힌트 텍스트 추가
- 게시판 폼 및 환경설정 기본 게시판 유형 Select에 slug 함께 표시 + 긴 목록 스크롤 처리
- 게시판 유형 slug 허용 문자 규칙을 하이픈(-) 통일 (기존 언더스코어(_) 혼용에서 변경)

### Fixed
- 게시판 유형 모달에서 저장 실패 시 필드별 에러 메시지 표시 및 빨간 테두리 강조
- 테스트 환경에서 `Accept-Language` 헤더 미설정 시 `name.ko` 필수 검증이 통과되던 문제 수정

## [0.3.1] - 2026-03-05

### Added
- 신고 남발 방지 기능 — 일일 신고 횟수 제한, 반려 누적 시 신고 차단, 연속 신고 쿨타임
- 관리자 환경설정 신고 정책 탭을 "자동 숨김 설정" / "신고 제한 설정" 2개 카드로 분리
- 연속 신고 쿨타임 설정 항목 추가 (기본 60초, 0이면 비활성화)
- 각 설정 항목에 동작 설명 안내 텍스트 보강
- 관리자 신고 목록에 마지막 신고일시 표시 (2건 이상 신고 시)
- 관리자 신고 목록/상세에 비회원 신고자 배지 표시
- 비회원 신고 샘플 시더 데이터 추가

### Changed
- 쿨타임 설정 변경 시 즉시 반영되도록 설정 조회 방식 변경
- 쿨타임 메시지를 신고 전용으로 분리

### Fixed
- 신고 모달에서 사유 미선택 시 콘솔 오류가 발생하던 문제 수정
- 댓글 신고 시 관리자 목록에서 게시글 제목이 표시되지 않던 문제 수정
- 관리자 신고 목록에서 페이지/정렬 변경 시 체크박스 선택이 유지되던 문제 수정
- 연속 신고 쿨타임 설정값이 저장되지 않던 문제 수정

### Removed
- "신고 차단 기간" 설정 항목 제거 — 실제 동작과 불일치하여 혼란 유발

## [0.3.0] - 2026-03-03

### Added
- 마이페이지 "내가 쓴 댓글" API (`GET /me/my-comments`) — Comment 기반 페이지네이션
- `CommentRepository::getUserComments()` — 삭제 게시글·비활성 게시판 댓글 자동 제외
- `PostRepository::findByBoardId()`, `CommentRepository::findByBoardId()` — 신고 처리용 Repository 메서드 추가

### Changed
- `board_posts`, `board_comments`, `board_attachments` 단일 테이블 전환 (기존 슬러그별 동적 테이블 폐지)
- `board_id` 파티션(`PARTITION BY LIST`)으로 게시판 분리 — 게시판 추가 시 파티션 동적 생성
- `DynamicPost/Comment/Attachment` 모델 → `Post/Comment/Attachment` 단일 모델로 통합
- Repository 전체 `setTableBySlug()` 패턴 → `where('board_id', ...)` 단일 쿼리 패턴으로 전환
- 마이페이지 게시판 활동 조회 PHP `forPage()` → DB `paginate()` 전환 (메모리 부족·타임아웃 해결)
- 통계 `total_comments` 집계 조건을 목록 API와 동일하게 INNER JOIN 적용 (삭제 게시글 댓글 제외)
- Admin 라우트에 `admin` 미들웨어 추가 (5개 그룹)
- `ReportService` — `logger()` 헬퍼 → `Log::` 파사드 교체
- `BoardService::clearBoardCaches()` — `Cache::forget()` → `CacheService::forget()` 통일
- `PostService`, `CommentService` — actionLog 배열 생성 중복 → `buildActionLog()` 메서드 공통화

### Fixed
- raw SQL 내 테이블명에 DB prefix 미적용 문제 수정
- `DB::table('x as y')` alias에 prefix 이중 적용되는 문제 수정
- 답글 작성 시 삭제된 게시글 체크 후 조기 종료 누락 버그 수정, 공지 답글 금지 및 깊이 제한 추가
- 댓글 작성 시 삭제된 댓글 체크 후 조기 종료 누락 버그 수정, 깊이 제한 추가
- 게시글 목록에서 depth 2 이상 답글이 표시되지 않는 버그 수정
- 관리자 게시글 목록 답글 depth 시각화 — depth에 비례한 들여쓰기 적용
- 관리자 답글 버튼 — 공지글이 아니고 최대 답글 깊이 미만인 게시글에만 표시되도록 조건 수정
- 인기글 목록이 HTTP 200이지만 빈 배열로 반환되는 버그 수정 (raw SQL DB prefix 누락)
- 게시글·댓글 API 응답에서 비밀번호 필드 노출 방지
- 게시글·댓글 트리거 타입을 Enum으로 올바르게 처리
- 댓글 단건 조회 시 소프트 삭제된 댓글이 조회되지 않는 문제 수정
- 신고 처리 시 DB 직접 접근 → Repository 패턴으로 교체
- 게시글 상세 조회 비즈니스 로직을 Controller에서 Service로 이동
- 신고 상태별 집계 로직을 Controller에서 Service로 이동
- 신고 가능 여부·본인 콘텐츠 검증 로직을 Controller에서 Service로 이동

### Removed
- `BoardTableService` — 동적 테이블 생성 서비스 삭제
- `TableCreationException` 삭제
- `DynamicPost`, `DynamicComment`, `DynamicAttachment` 모델 삭제
- `getUserCommentedActivities()` (PostRepository) — 미사용 메서드 (마이댓글 API 전환으로 대체)

## [0.2.0] - 2026-02-26

### Added
- 환경설정 페이지 (3탭: 기본 설정 / 신고 정책 / 스팸·보안)
- 환경설정 CRUD API (`GET/PUT /admin/settings`, `GET /admin/settings/{category}`)
- 일괄 적용 API (`POST /admin/settings/bulk-apply`) — 체크된 항목을 선택 게시판에 일괄 적용
- 캐시 초기화 API (`POST /admin/settings/clear-cache`)
- `BoardSettingsService` (ModuleSettingsInterface 구현)
- `boards` 테이블에 `max_reply_depth`(기본 5), `max_comment_depth`(기본 10) 컬럼 추가
- 게시판 폼에 답변글 최대 depth(1~5), 대댓글 최대 depth(1~10) 필드 추가
- 게시판 폼 알림 탭에 알림별 채널 선택 UI 추가 (필터 훅 `sirsoft-board.notification.channels`로 확장 가능)

### Changed
- `config/board.php`의 기본값(`defaults`, `default_board_permissions`, `blocked_keywords`, `view_count_*`)을 ModuleSettings 시스템으로 이관
- `config/board.php`에서 `report_types` 제거 (dead code, `ReportReasonType` Enum이 원본)
- 기존 코드의 `config()` 참조를 `g7_module_settings()` 헬퍼로 전환 (9개 파일)
- `boards` 테이블 기본값 통일: `show_view_count=false`, `notify_admin_on_post=true`, `notify_author=true`
- 조회수 중복 방지 방식을 단일 캐시 TTL 방식으로 통합 (세션 방식 제거, API 미들웨어에 `StartSession` 미적용)

### Fixed
- 권한 TagInput 수정 시 저장 버튼이 표시되지 않는 문제 수정
- 일괄 적용 모달에서 `fields` 값이 전달되지 않는 문제 수정
- `.` 포함 권한 키(예: `sirsoft-board.posts.read`)가 `BulkApplySettingsRequest` 검증에서 422 오류 발생하는 문제 수정

## [0.1.2] - 2026-02-25

### Changed
- 모듈 라우트 admin/user 분기 서빙 적용 (routes.json → routes/admin.json 이동)

## [0.1.1] - 2026-02-24

### Changed
- 버전 체계 조정 (정식 출시 전 0.x 체계로 변경)

## [0.1.0] - 2026-02-23

### Added
- 게시판 모듈 초기 구현
- 게시판 CRUD (생성, 읽기, 수정, 삭제) 기능
- 게시판별 스킨 설정, 카테고리 관리
- 게시물 CRUD 기능 (작성, 조회, 수정, 삭제)
- 비밀글 기능: 작성자/관리자만 열람 가능
- 댓글 시스템: CRUD, 대댓글 (계층형 댓글)
- 게시물 검색 기능 (제목, 내용, 작성자 검색)
- 첨부파일: 이미지/파일 업로드, 다운로드, 인라인 표시
- 카드/갤러리 레이아웃: 다양한 게시물 목록 표시 형태 지원
- 인기글/최신글 위젯
- SEO 메타 태그 자동 생성
- 관리자 레이아웃 (게시판 관리, 게시물 관리, 댓글 관리)
- 사용자 레이아웃 (게시판 목록, 상세, 작성/수정)
- 권한 시스템 연동 (게시판별 읽기/쓰기/댓글/관리 권한)
- 다국어 지원 (ko, en)
