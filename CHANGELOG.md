# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [7.0.0-beta.3] - 2026-04-23

### Fixed

- CKEditor5 플러그인 활성 상태에서 게시판 글쓰기 저장 시 "제목은 필수입니다" 422 오류가 발생하던 호환성 문제 수정 — 제목·내용 입력 순서와 무관하게 정상 저장되도록 개선
- 코어 업데이트가 sudo 로 실행된 환경에서 캐시·세션·확장 디렉토리의 그룹 쓰기 권한이 일부 손실되어 업데이트 직후 "Permission denied" 또는 플러그인 제거 검증 실패가 발생하던 문제 수정 — 업데이트 종료 시점에 그룹 쓰기 권한을 자동 정상화하며 기존 손실 분은 1회성 복구 스텝으로 회수
- 업데이트 완료 후 Laravel 런타임이 새로 만드는 캐시·세션 하위 디렉토리가 기본 umask(022) 때문에 다시 그룹 쓰기 권한을 잃어 재차 "Permission denied" 가 발생하던 문제 수정 — 업그레이드 스텝이 업데이트 진행 프로세스의 umask 를 그룹 쓰기 친화적으로 전환하고, 이후 부팅 시점에도 `storage/` 의 현재 그룹 쓰기 설정을 감지해 프로세스 umask 를 자동 동조 (운영자가 그룹 공유를 비활성화한 환경은 그대로 보존)
- 코어 업데이트 마지막 단계의 진행 표시줄이 끝난 뒤 줄바꿈 없이 다음 셸 프롬프트가 같은 줄에 붙어 표시되던 출력 문제 수정

### Notes

- 7.0.0-beta.1 에서 7.0.0-beta.3 로 직접 업그레이드하는 경우, 환경(opcache CLI 활성 등)에 따라 권한 복구 스텝이 자동 실행되지 않고 건너뛰어질 수 있습니다. 업데이트 후 `storage/framework/cache` 등에서 Permission denied 가 발생하면 아래 명령을 수동 실행해 주세요 — 7.0.0-beta.2 에서 올라오는 경로에서는 해당 없음
  - `php artisan core:execute-upgrade-steps --from=7.0.0-beta.2 --to=7.0.0-beta.3 --force`
- 시스템 레벨에서도 일관된 그룹 공유 권한을 원하면 php-fpm pool 설정에 `umask = 002` (또는 systemd unit 의 `UMask=0002`) 추가를 권장합니다. 코드 레벨 동조와 병행하면 외부 프로세스(cron, composer 등) 도 동일 권한으로 파일을 생성합니다

## [7.0.0-beta.2] - 2026-04-20

### Added

#### 알림 시스템 재설계

- 알림 시스템을 Definition(정의) × Template(채널별 템플릿) × Recipients(수신자) 3계층으로 재설계
- 채널별 독립 수신자 설정 — trigger_user, related_user, role, specific_users 4종 타입 지원
- 채널 Readiness 검증 — 미설정 채널(SMTP 미구성 등)의 발송을 사전 차단
- 알림 발송 공통 디스패처 — 채널별 독립 발송, 발송 전후 훅 자동 실행, 모든 채널 자동 로깅
- 알림 클릭 URL 지원 — database 채널 알림에 클릭 시 이동할 URL 패턴 설정 가능
- 사용자/관리자 알림 전체 삭제 API 추가
- 비밀번호 변경 시 `password_changed` 알림 자동 발송
- 코어 권한에 사용자 알림 전용 카테고리 분리 (관리자/사용자 권한 의미 분리)
- 알림 정의 일괄 초기화 기능 — 커스터마이징된 알림의 모든 채널 템플릿을 기본값으로 복원 (확인 모달 + 스피너 UX 포함)
- 모듈/플러그인이 자신의 기본 알림 정의를 코어 리셋 로직에 제공할 수 있는 필터 훅 제공
- 템플릿 편집 시 편집 상태가 자동 추적되어 리셋 버튼이 자동 노출
- 알림 정의/템플릿 응답에 편집/삭제 권한 정보(abilities) 포함으로 UI가 권한에 따라 자동 조정

#### Vendor 번들 시스템

- Composer 실행 불가 환경(공유 호스팅 등)을 위한 vendor 번들 시스템 추가
- vendor-bundle Artisan 커맨드 — 빌드, 검증, 일괄 처리
- 모듈/플러그인 설치·업데이트에 `--vendor-mode=auto|composer|bundled` 옵션 추가
- 인스톨러 Step 3에 Vendor 설치 방식 선택 UI 추가 (환경 자동 감지)

#### GeoIP 및 타임존

- MaxMind GeoLite2 자동 다운로드 스케줄러 + 관리자 환경설정 UI 추가
- IANA 타임존 전체(약 425개) 지원 — 기존 7개 하드코딩 화이트리스트 폐기
- Select 컴포넌트에 `searchable` prop 추가 — 타임존 등 대량 옵션에서 검색 가능

#### 인스톨러 개선

- SSE/폴링 듀얼 모드 지원 — Nginx 프록시 + Apache 환경에서 SSE 문제 시 폴링 모드로 전환 가능
- 확장 의존성 자동 해결 — 템플릿 선택 시 필요한 모듈/플러그인을 즉시 자동 선택, 전이적 의존성까지 해결
- 자동 선택된 항목을 시각적으로 구분하고 요구한 확장 이름을 함께 표시
- 다른 확장이 의존하는 항목은 선택 해제 차단 (의존 관계 안내 메시지 포함)
- 의존성 버전 제약 사전 검증 — 버전 불일치 시 설치 진행 전 경고 (semver 비교: `>=`, `^`, `~` 등 지원)
- 기존 DB 테이블 감지 및 안전한 재설치 지원 — 백업 안내 + 명시적 동의 후 진행
- 권한 안내 단순화 — `chmod -R 755` 단일 명령어로 통합 (업계 표준 정렬)
- 소유자 불일치(`ownership_mismatch`) 감지 — 전통적 Apache 환경에서 3가지 해결 옵션 제시
- Step 5에 "설치 시작" 버튼 도입 — 모드 선택 후 사용자 클릭으로 설치 시작

#### 공통 캐시 시스템

- 코어/모듈/플러그인 격리 캐시 시스템 도입 (접두사 자동 부여)
- 모델 변경 시 태그 기반 자동 캐시 무효화 트레이트 추가
- 환경설정에서 캐시 TTL 중앙 관리 (7개 설정 키)

#### 어드민 UI

- 페이지 진입/탭 전환 시 로딩 spinner 표시 — 데이터 fetch 완료까지 유지
- 목록 페이지네이션 시 DataGrid body 영역 한정 spinner (pagination 버튼 가림 방지)
- DataGrid 컴포넌트에 `id` prop 추가

#### 기타

- HookManager 공통 큐 디스패치 — 환경설정 하나로 모든 훅 리스너 자동 비동기 실행, 큐 워커에서도 Auth/Request/Locale 컨텍스트 자동 복원
- WebSocket 클라이언트/서버 endpoint 분리 설정 지원 — 리버스 프록시 환경 대응, SSL 검증 옵션 추가
- SEO 변수 시스템 — 모듈/플러그인이 페이지별 SEO 변수를 제공할 수 있도록 개선
- 코어 드라이버 확장 시스템 — 플러그인이 필터 훅으로 스토리지/캐시/세션/큐 등 드라이버를 등록 가능
- `HasUserOverrides` 트레이트 — 시더 데이터와 사용자 수정을 자동으로 분리 추적
- 템플릿 엔진 engine-v1.42.0 — `render: false` 선택적 리렌더 제어
- 템플릿 엔진 engine-v1.41.0 — setLocal/dispatch debounce API + stale 값 오염 방지
- 템플릿 엔진 engine-v1.40.0 — navigate fallback 옵션 (교차 템플릿 경로 대응)
- 템플릿 엔진 engine-v1.30~38 — sortable wrapper 요소 지정, React.memo 자동 래핑, resolvedProps 참조 안정화, transition_overlay spinner 시스템, reloadExtensions 통합 핸들러

#### 확장 업데이트

- 코어 업데이트 완료 후 `_bundled` 에 새 버전이 있는 확장을 감지하고 일괄 업데이트 여부를 묻는 인터랙티브 프롬프트 제공
- 일괄 업데이트 시 전역 레이아웃 전략(overwrite / keep) 1회 질의 후 확장별 예외 지정 가능
- 모듈/플러그인 업데이트에도 `--layout-strategy=overwrite|keep` CLI 옵션 및 관리자 UI 모달 선택 지원 (기존 템플릿 전용 → 확장 전체 일관)
- 관리자 모듈/플러그인 업데이트 모달에 사용자가 수정한 레이아웃 감지 및 목록 표시 기능 추가
- 모듈/플러그인 업데이트 시 각 upgrade step 버전을 콘솔에 실시간 출력 (기존에는 파일 로그에만 기록)
- 확장 업데이트 메서드 파라미터 순서를 3종(템플릿·모듈·플러그인) 공통 prefix(id, force, onProgress) 로 정렬해 일관성 확보
- 모듈/플러그인이 런타임에 동적으로 생성한 권한·역할·메뉴(예: 게시판별 권한 세트)도 업데이트 시 보존되도록 개선
- 모듈/플러그인/템플릿 업데이트 커맨드에 업데이트 소스 선택 옵션(`--source=auto|bundled|github`) 추가 — GitHub 원격 장애·태그 롤백 등 상황에서 번들 소스로 강제 설치 가능
- GitHub 원격에 존재하지 않는 태그로 업데이트 시 "아카이브 추출 실패" 로 불명확한 오류가 나던 문제 개선 — 태그 존재 여부를 먼저 검증해 "업데이트 소스 없음" 안내로 명확화
- 관리자 모듈/플러그인 업데이트 모달의 _global 상태키를 `hasModifiedTemplateLayouts`, `hasModifiedModuleLayouts`, `hasModifiedPluginLayouts` 로 통일해 네이밍 충돌 방지
- `module:install` / `plugin:install` / `template:install` 에 `--force` 옵션 추가 — 이미 설치된 확장도 `_bundled`/`_pending` 원본으로 활성 디렉토리를 덮어써서 재설치 (불완전 설치 복구용)
- 확장 활성 디렉토리 무결성 검사 추가 — `module.php`/`module.json` (모듈), `plugin.php`/`plugin.json` (플러그인), `template.json` (템플릿) 중 하나라도 누락된 활성 디렉토리를 로드 시 경고 로그 + `install --force` 복구 힌트 제공
- 코어 업데이트 커맨드에 외부 ZIP 파일 직접 지정 옵션(`--zip=/path/to/g7.zip`) 추가 — GitHub 다운로드 대신 지정 ZIP 을 사용해 오프라인/수동 배포 가능
- 모듈/플러그인/템플릿 업데이트 커맨드에 외부 ZIP 파일 직접 지정 옵션(`--zip=/path/to/ext.zip`) 추가 — manifest 식별자와 대상 확장이 일치하는지 검증 후 manifest 의 버전으로 업데이트
- 외부 ZIP 은 GitHub 릴리스 zipball 의 래퍼 디렉토리와 평탄 루트 구조를 모두 자동 감지해 지원

### Changed

#### 알림 시스템

- MailTemplate 시스템을 NotificationDefinition + NotificationTemplate 체계로 전면 전환
- 코어 알림 3종의 직접 발송을 훅 기반 발송으로 전환
- 알림 수신자를 definition 레벨에서 template 레벨로 이동 (채널별 독립 수신자)
- 알림 broadcast 채널을 정수 User ID에서 UUID 기반으로 변경 (보안 강화)
- 알림 시각 표시를 ISO 8601에서 사용자 타임존 기준으로 변경

#### 캐시 시스템

- 24개 서비스의 캐시 호출을 `Cache::` 파사드에서 `CacheInterface` DI로 전환
- 13개 서비스의 TTL을 환경설정 중앙 관리로 통일 (하드코딩 제거)
- 캐시 키 접두사를 `g7:core:`, `g7:module.{id}:`, `g7:plugin.{id}:` 체계로 통일
- `CacheService` 클래스 삭제 — `CacheInterface`로 완전 대체

#### 인스톨러

- 권한 검증을 비트 값 비교에서 실제 읽기/쓰기 가능 여부 기반으로 단순화
- `.env` 생성 안내를 소유자 일치 여부에 따라 1-step으로 통합

#### 기타

- 확장 정보 모달에 의존성 정보 노출 — 코어 요구 버전(`g7_version`), 의존 모듈/플러그인 목록과 각각의 요구 버전·설치 버전·충족 상태 뱃지를 모듈/플러그인/템플릿 정보 모달에서 확인 가능
- `locale_names` 설정 추가 — 언어 표시명을 중앙에서 관리
- 매 요청 `Schema::hasTable()` 호출 제거 — 설치 완료 환경에서 10~14회의 불필요한 스키마 쿼리 제거로 응답 시간 단축
- SeoRenderer에 모듈/플러그인 SEO 변수 자동 해석 기능 추가
- ResponseHelper — 디버그 모드에서 예외 상세 자동 포함, 프로덕션에서 내부 메시지 노출 차단
- 환경설정 드라이버 변경 시 큐 워커 자동 재시작
- `allow_url_fopen=Off` 환경 지원 — GitHub 연동 HTTP 호출을 Laravel Http 파사드로 전면 교체
- 코어 업데이트 완료 후 안내 메시지를 "수동 업데이트 명령 나열"에서 "일괄 업데이트 인터랙티브 프롬프트"로 변경
- 확장 삭제 시 권한·메뉴·역할은 "데이터도 함께 삭제" 옵션을 체크했을 때만 삭제되도록 변경 — 기본 삭제에서는 보존되어 재설치 시 사용자 역할 할당이 복원

### Fixed

- WebSocket 데이터소스가 progressive 목록에 포함되어 영구 블러되던 문제 수정
- WebSocket 채널/이벤트 표현식 미평가로 인증 실패하던 문제 수정
- WebSocket broadcast HTTP API가 클라이언트 host로 POST되어 실패하던 문제 수정
- WebSocket 활성화 시 필수 필드 미검증 문제 수정
- WebSocket 비활성화 시에도 Reverb 연결을 시도하던 문제 수정
- `--force` 업데이트 시 번들 없는 외부 확장이 업데이트 소스를 찾지 못하던 문제 수정
- 확장 의존성 데이터 구조 불일치 수정 — manifest JSON 기반으로 통일
- 모듈/플러그인/템플릿 install/activate/deactivate/uninstall 직후 라우트 미반영 수정
- 다국어 번역 캐시 경합으로 raw key 노출되던 문제 수정 (engine-v1.38.1)
- 조건부 `$t:` 표현식에서 번역 실패하던 문제 수정 (engine-v1.38.2)
- 테스트 환경과 개발 환경이 동일한 Redis 캐시를 공유하여 상호 오염되던 문제 수정
- 대시보드 브로드캐스트 스케줄이 무한 락에 걸리던 문제 수정
- 환경설정 저장 후 `config:cache` 상태에서 변경값 미반영 수정
- Windows 환경에서 파일 잠금 감지 시 30초+ hang 수정 — 0.5초로 단축
- 개발 의존성 없이 설치된 프로덕션 환경에서 샘플 시더와 팩토리가 실행되지 않던 문제 수정 — 한국어 샘플 데이터 생성기가 자동으로 대체 동작하도록 개선
- `sudo php artisan core:update` 실행 시 vendor 디렉토리 전체가 root 소유로 오염되던 문제 수정
- 코어 업데이트 후 신규 권한·메뉴가 DB에 반영되지 않아 관리자 환경설정 페이지 접근이 거부되던 문제 수정
- beta.1에서 업그레이드 시 제거된 메일 템플릿 모델 참조로 Fatal 오류가 발생하던 문제 수정
- 업그레이드 후 `bootstrap/cache` 등 일부 디렉토리가 root 소유로 남아 확장 설치·업데이트가 거부되던 문제 수정 — 소유권 복원 범위를 설치 안내 경로 전체로 확장
- 코어 업데이트 진행률 표시와 upgrade step 실행 로그가 같은 줄에 뒤섞이던 문제 수정 — 각 step 안내가 별도 줄로 깔끔하게 출력됨
- 템플릿/레이아웃 공개 API가 확장 활성화 전·설치 중에 받은 "찾을 수 없음" 에러 응답을 영구 캐시하여 복구 후에도 같은 오류를 반환하던 문제 수정 — 에러 응답은 캐시에서 제외
- `_pending`/`_bundled` 에 업데이트 중 남은 임시 디렉토리(`{id}_YYYYMMDD_HHMMSS`, `{id}_updating_*` 등) 의 원본 manifest 때문에 `install` 이 존재하지 않는 표준 경로로 접근해 실패하던 문제 수정 — 디렉토리명과 identifier 불일치 시 스캔에서 제외
- 업그레이드 후 일부 코어 권한이 DB에 반영되지 않아 사용자 역할의 알림 기능 권한이 비어있던 문제 수정 — 별도 프로세스 실행으로 최신 로직 기반 재동기화 보장
- 코어·확장 설정에서 제거된 메뉴·권한·역할·알림 정의·알림 템플릿·게시판 유형·클레임 사유가 업데이트 후에도 DB에 잔존하던 문제 수정 — 고아 레코드 자동 정리
- 관리자 UI에서 메뉴·역할·알림·게시판 유형·클레임 사유·배송 유형 등을 수정해도 다음 업데이트 시 기본값으로 덮어써지던 문제 수정 — 사용자가 수정한 필드는 모든 저장 경로에서 자동 추적되어 업데이트 후에도 보존
- 알림 정의·템플릿도 업데이트 기준으로 동기화 — 새 버전에서 제거된 알림은 DB에서도 삭제

### Removed

- MailTemplate 시스템 일괄 제거 — 모델, 컨트롤러, 서비스, 리스너, 시더, 팩토리, 다국어 파일 등. NotificationDefinition + NotificationTemplate 체계로 완전 대체

> 참고: beta.1에서 업그레이드하는 운영 환경의 데이터 이관은 `Upgrade_7_0_0_beta_2`가 자동 처리합니다.

## [7.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [7.0.0-alpha.21] - 2026-03-30

### Added

- 인스톨러 PHP CLI/Composer 필수 검증 기능 — 기본 `php` 미감지 시 CLI 설정 필수 전환, Composer 실행 확인 및 미설치 시 설치 안내, 둘 다 검증 완료 전 다음 단계 진행 차단
- 템플릿 레이아웃 수정 감지 시스템 — `original_content_hash`/`original_content_size` 컬럼 추가, SHA-256 해시 기반 수정 감지로 `updated_by` 방식 대체 (TemplateManager, LayoutRepository, 마이그레이션)
- 관리자 로그인 화면 개선 — 비밀번호 찾기/재설정 플로우 추가, 테마 Segmented Control, 언어 셀렉트 확대 (PasswordResetController, PasswordResetNotification, ForgotPasswordRequest, ResetPasswordRequest)
- `{{raw:expression}}` 바인딩 번역 면제 마커 시스템 — `$t:` 자동 번역 대상에서 특정 바인딩을 제외하는 마커 도입 (rawMarkers.ts)
- 레이아웃 프리뷰 모드 — 관리자 레이아웃 편집기에서 실시간 미리보기 지원 (LayoutPreviewController, LayoutPreviewService, 마이그레이션)
- 활동 로그 이력 조회 메뉴 신설 — ActivityLogController, ActivityLogResource, ActivityLogService, ActivityLogRepository, config/core.php 메뉴/권한 등록, 관리자 레이아웃 추가

### Changed

- `window.G7Config` 설정 노출 최소화 Phase 1 — 프론트엔드 미참조 설정의 브라우저 소스 노출 차단
  - SettingsService: 필드 레벨 `expose: false` 지원 추가 (formatCategorySettings)
  - FiltersFrontendSchema: `fields: {}` (빈 객체) 시 전체 차단 안전 기본값 적용
  - View Composers: TemplateComposer/UserTemplateComposer에 ModuleSettingsService 주입 — frontend_schema 기반 필터링 적용 (기존 config() 직접 참조 우회 수정)
  - 코어 defaults.json: general 4개 필드, security 전체, seo 전체, advanced 9개 필드(debug_mode 제외), upload 2개 필드, drivers 전체를 `expose: false` 처리
- 캐시 무효화 로직 정비 — 016862cb 이전의 단순한 방식으로 복귀 (버전 증가 + TTL 자연 만료), `extension_cache_previous_versions` 추적 및 `getCacheVersionsToInvalidate()`/`getCacheVersionsForLayoutInvalidation()` 제거, Cache Tags 드라이버 분기 제거로 캐시 드라이버 비의존성 확보

### Fixed

- MariaDB를 `DB_CONNECTION=mysql` 드라이버로 연결 시 `WITH PARSER ngram` 에러로 인스톨러 설치 실패 수정 — `isMariaDb()` 메서드 추가하여 서버 버전 문자열 기반 실제 DBMS 감지 (DatabaseFulltextEngine)
- 캐시 무효화 버전 키 누락 수정 — `warmTemplateCache()`가 버전 포함 키(`.v{version}`)로 캐시를 생성하나 `clearTemplateCache()`가 레거시 키만 삭제하던 문제, routes/language 캐시에 버전 포함 키 삭제 추가
- 캐시 버전 0 무효화 누락 수정 — `array_filter()`가 버전 `0`을 falsy로 제거하여 `.v0` 캐시 키가 삭제 대상에서 누락되던 버그 (ClearsTemplateCaches, InvalidatesLayoutCache)
- 테스트 활성 디렉토리 보호 강화 — `ProtectsExtensionDirectories`에 `moveDirectory` spy 추가, `copyToActive()` 원자적 교체가 활성 디렉토리를 rename으로 파괴하는 것 방지
- 활동 로그 하위 호환 — `ActivityLogResource`에서 기존 DB 레코드(`type: 'text'`)의 enum 필드를 모델의 현재 `$activityLogFields` 기준으로 동적 번역 + 일괄 변경 `:count` 미치환 보정
- 활동 로그 enum 미번역 전수 수정 — 14개 모델 필드의 `$activityLogFields` `type: 'enum'` 전환 + 14개 Enum 클래스에 `labelKey()` 메서드 추가 (User, Order, OrderOption, Product, Coupon, Post, Comment, Board)
- 활동 로그 삭제/일괄삭제 `description_key` 및 샘플 시더 누락 추가 — 코어 시더 `activity_log_descriptions` 테이블에 delete/bulk_delete 키 보충 + 다국어 파일 동기화
- 관리자 다크모드 품질 전수 조사 및 수정 — 레이아웃 JSON ~546건, TSX ~37건, CSS 5건의 누락된 `dark:` variant 추가 (이커머스 101파일, 게시판 19파일, 페이지 2파일, 마케팅 1파일, 템플릿 컴포넌트 ~20파일)
- 레이아웃 편집 캐시 무효화 및 버전 히스토리 수정 — LayoutService의 저장/복원 시 PublicLayoutController 서빙 캐시 무효화 + `extension_cache_version` 증가 누락 수정
- 사용자 관리에서 관리자/슈퍼관리자 삭제 시 토스트 메시지 및 어빌리티 처리 — CannotDeleteAdminException 추가, UserResource에 `can_delete` 어빌리티 반영
- ActivityLog 핸들러 `$result` 타입 불일치 수정 — CoreActivityLogListener에서 `bool` → `array` 반환 타입 정합성 보정
- 환경설정 사이트 로고 저장 시 Attachment 객체 정수 검증 오류 수정 — SaveSettingsRequest에서 Attachment JSON 객체를 정수로 검증하던 문제 수정
- 관리자 SPA 네비게이션 로고 깜빡임 수정 — extends base 컴포넌트 불필요 remount 방지 (`_fromBase` 마킹 기반 stable key 패턴)
- 인스톨러 다크모드 적색 배경 가독성 개선 — `alert-title`, `alert-message`, `permission-badge`, `test-result` 다크모드 색상 override 추가
- 인스톨러 `BASE_PATH` 심볼릭 링크 미해석 — `realpath()` 적용 + 절대경로/상대경로 병기로 호스팅 환경 대응
- 인스톨러 403 에러 페이지 다크모드 미지원 — `prefers-color-scheme: dark` 미디어쿼리 추가
- 템플릿 업데이트 수정 감지 실패 — `LayoutRepository::update()`가 `updated_by`를 설정하지 않아 `hasModifiedLayouts()`가 항상 "수정 없음" 반환하던 문제 수정 (hash 비교 방식으로 전환)
- 템플릿 업데이트 "수정 유지" 전략 미작동 — `layoutStrategy === 'keep'` 시 `refreshTemplateLayouts()` 미호출 → 양쪽 전략 모두 호출하되 `preserveModified` 플래그로 분기
- 템플릿 업데이트 API 응답 키 불일치 — 백엔드 `has_modified` → 프론트엔드 기대 `has_modified_layouts`/`modified_count` 키 정렬
- 템플릿/모듈/플러그인 설치 시 `incrementExtensionCacheVersion()` 누락 수정 — TemplateManager(install/activate/deactivate/uninstall) + ModuleManager(install) + PluginManager(install) 총 7곳 추가
- 캐시 버전 변경 시 프론트엔드 다국어 미갱신 수정 — TemplateApp.ts에서 캐시 버전 변경 감지 시 TranslationEngine 재로드 추가
- `warmTemplateCache()` 다국어 캐시 워밍 시 `$partial` 디렉티브 미해석 수정 — `json_decode`만 수행하던 코드를 `TemplateService::getLanguageDataWithModules()` 호출로 교체하여 fragment 해석 및 모듈/플러그인 다국어 병합 정상화
- `ActivateTemplateCommand` 의존성 미충족 시 성공으로 보고하는 버그 수정 — `if ($result)` → `if ($result['success'])` 변경 및 의존성 경고 메시지 출력, `--force` 옵션 추가
- `ActivateModuleCommand`/`ActivatePluginCommand` 의존성 미충족 시 경고 메시지 미출력 수정 — 의존성 경고 표시 및 `--force` 옵션 전달 추가

## [7.0.0-alpha.20] - 2026-03-30

### Added

- Laravel Scout 통합 및 MySQL FULLTEXT(ngram) 검색엔진 드라이버 확장 시스템 도입 — 커스텀 `DatabaseFulltextEngine`으로 `MATCH...AGAINST IN BOOLEAN MODE` 지원, `core.search.engine_drivers` 필터 훅으로 Meilisearch/Elasticsearch 등 외부 엔진 플러그인 등록 가능
- `DatabaseFulltextEngine` 다중 DBMS 호환 — FULLTEXT 미지원 DBMS(PostgreSQL, SQLite)에서 LIKE fallback 자동 전환, `whereFulltext()` 정적 헬퍼(관계 검색용), `addFulltextIndex()` 마이그레이션 헬퍼(DBMS별 조건부 DDL)
- `config/scout.php` 설정 파일 추가 — 기본 드라이버 `mysql-fulltext`, `SCOUT_DRIVER` 환경변수로 전환 가능
- `FulltextSearchable` 인터페이스 — FULLTEXT 검색 대상 컬럼 및 가중치 정의 계약
- `AsUnicodeJson` 커스텀 캐스트 — JSON 컬럼에 한글을 `\uXXXX` 이스케이프 없이 실제 UTF-8로 저장하여 FULLTEXT ngram 토크나이저 정상 동작 보장
- 환경설정 > 드라이버 탭에 검색엔진 설정 카드 추가 — 기본 MySQL FULLTEXT(ngram) 드라이버 표시, 플러그인 설치 시 추가 드라이버 자동 표시
- `SaveSettingsRequest`에 검색엔진 드라이버 동적 validation 추가 — `core.search.engine_drivers` 필터 훅 기반 허용 목록

## [7.0.0-alpha.19] - 2026-03-29

### Added

- 검색/필터/정렬 성능 향상을 위한 누락 인덱스 일괄 추가 — activity_logs(description_key), users(created_at), mail_send_logs(status), template_layouts(template_id), schedules(created_at)

## [7.0.0-alpha.18] - 2026-03-26

### Added

- SEO ExpressionEvaluator 산술 연산자 확장 — `*`, `/`, `%` 추가 (기존 `+`, `-`만 지원)
- SEO PipeRegistry 파이프 함수 엔진 구현 — 프론트엔드 PipeRegistry.ts 빌트인 파이프 15종 PHP 미러링 (date, datetime, relativeTime, number, truncate, uppercase, lowercase, stripHtml, default, fallback, first, last, join, length, filterBy, keys, values, json, localized)
- ActivityLog `description_params` ID→이름 변환 필터 훅 (`core.activity_log.filter_description_params`) — `ActivityLog::getLocalizedDescriptionAttribute()`에서 실행
- 코어 모델 `$activityLogFields` 정의 — `User`, `Role`, `Menu`, `Schedule`, `MailTemplate` (5개 모델)
- ActivityLog ChangeDetector 필드 라벨 다국어 키 추가 (`lang/ko/activity_log.php`, `lang/en/activity_log.php` — `fields` 섹션)
- `module_helpers.php` — `getModuleSetting()` 헬퍼 함수 추가
- CoreActivityLogListener: bulk_update per-User/per-Schedule 전환 + bulk_delete per-Schedule 전환 (건별 loggable_id 기록)
- ActivityLogHandler: 삭제된 엔티티용 loggable_type/loggable_id 직접 지정 fallback 지원
- 메뉴 관리 크로스 depth 이동 지원 — `UpdateMenuOrderRequest`에 `moved_items` 검증 추가, `MenuRepository`에 크로스 depth reorder 로직 구현
- `NotCircularParent` 검증 규칙 추가 — 메뉴 순환 참조 방지

### Changed

- ActivityLog 규정 문서 대폭 보강 (`docs/backend/activity-log.md`) — `description_params` 저장 정책, `ActivityLogDescriptionResolver` 패턴, Bulk Update ChangeDetector 패턴, 개발자 체크리스트 추가

## [7.0.0-alpha.17] - 2026-03-26

### Added

- ActivityLog `description_params` ID→이름 변환 필터 훅 (`core.activity_log.filter_description_params`) — `ActivityLog::getLocalizedDescriptionAttribute()`에서 실행
- 코어 모델 `$activityLogFields` 정의 — `User`, `Role`, `Menu`, `Schedule`, `MailTemplate` (5개 모델)
- ActivityLog ChangeDetector 필드 라벨 다국어 키 추가 (`lang/ko/activity_log.php`, `lang/en/activity_log.php` — `fields` 섹션)
- `module_helpers.php` — `getModuleSetting()` 헬퍼 함수 추가
- CoreActivityLogListener: bulk_update per-User/per-Schedule 전환 + bulk_delete per-Schedule 전환 (건별 loggable_id 기록)
- ActivityLogHandler: 삭제된 엔티티용 loggable_type/loggable_id 직접 지정 fallback 지원

### Changed

- ActivityLog 규정 문서 대폭 보강 (`docs/backend/activity-log.md`) — `description_params` 저장 정책, `ActivityLogDescriptionResolver` 패턴, Bulk Update ChangeDetector 패턴, 개발자 체크리스트 추가

## [7.0.0-alpha.17] - 2026-03-26

### Added

- Monolog 기반 ActivityLog 아키텍처: `Log::channel('activity')` → `ActivityLogHandler` → DB (3단계)
- `ActivityLogChannel` (커스텀 Monolog 채널), `ActivityLogHandler`, `ActivityLogProcessor` 신규
- ActivityLog i18n 지원: `description_key` + `description_params` 기반 실시간 다국어 번역
- ActivityLog 구조화된 변경 이력: `changes` JSON 컬럼 (필드별 `label_key`, `old`/`new`, `type` 포함)
- `ChangeDetector` 유틸리티 (모델 스냅샷 비교 → 구조화된 변경 이력 생성)
- `CoreActivityLogListener` 전면 확장: 모든 코어 Service 훅 구독 (User/Role/Menu/Settings/Schedule/Auth/Module/Plugin/Template/Layout/MailTemplate/Attachment — 66개 훅)
- 활동 로그 다국어 키 105개 정의 (`lang/ko/activity_log.php`, `lang/en/activity_log.php`)
- `config/logging.php`에 `activity` 채널 추가
- `config/activity_log.php` 전용 설정 파일 신규
- `activity_logs` 테이블 복합 인덱스 추가 (`loggable_type`+`loggable_id`+`created_at`, `log_type`+`action`+`created_at`)

### Fixed

- `resolveLogType()` 사용자 역할 기반 → 요청 경로 기반으로 변경: 관리자가 사용자 화면에서 수행한 액션이 `admin`으로 기록되던 문제 수정 (ResolvesActivityLogType)

### Changed

- `ActivityLog` 모델: `description` 컬럼 삭제 → `description_key`/`description_params` 기반 다국어 전환
- `ActivityLogService`: 기록 메서드 전면 제거 → 조회 전용으로 축소
- `ActivityLogResource`: `description` → `localized_description` (실시간 번역)
- 모든 Controller에서 `logAdminActivity()` 호출 전면 제거 → Listener 경로로 전환

### Removed

- `activity_logs.description` 컬럼 (DB 삭제)
- `ActivityLogManager`, `ActivityLogDriverInterface`, `DatabaseActivityLogDriver`, `NullActivityLogDriver`
- `ActivityLogListener` (이중 훅 계층 — Monolog Handler로 대체)
- `ActivityLogService.log`/`logAdmin`/`logUser`/`logSystem` (Monolog 채널로 대체)
- `AdminBaseController.logAdminActivity()`, `generateActivityDescription()`, `flattenDataForTranslation()`

### Fixed

- 마이페이지 프로필 저장 시 국가·언어 미선택 상태에서 오류가 발생하던 문제 수정 — 해당 필드를 선택 사항으로 변경
- 인스톨러 Step 2 .env 복사 명령어 안내 수정 — `.env.example.production` → `.env.example` (functions.php 2곳, installer.js 4곳)

## [7.0.0-alpha.16] - 2026-03-23

### Fixed

- 코어 업데이트 롤백 시 vendor 디렉토리 복원 불가 수정 — 백업 targets에 vendor 포함, excludes에서 vendor 제거
- 코어 백업 복원 시 개별 target 실패가 전체 복원을 중단하는 문제 수정 — 개별 try-catch로 나머지 target 복원 계속 진행
- 코어 업데이트 롤백 실패 시 수동 복구 안내 미출력 수정 — composer install 등 복구 단계 안내 추가
- 코어 업데이트 완전 복원 성공 시 유지보수 모드 자동 해제 추가
- 코어 업데이트 시 vendor 디렉토리 이중 처리로 인한 마이그레이션 실패 수정 — `backup_only` 설정 분리 (applyUpdate 제외, 백업/복원 전용)

## [7.0.0-alpha.15] - 2026-03-23

### Added

- Users UUID 전환 — 외부 노출 ID를 UUID v7으로 전환, 정수 `id`는 API 응답에서 숨김
- UniqueIdService 코어 서비스 추가 (UUID v7 + NanoID 생성)
- 코어 업그레이드 스텝 추가 (Upgrade_7_0_0_beta_15)

### Changed

- User 모델: `getRouteKeyName()` → 'uuid', `$hidden`에 'id' 추가
- 공개 프로필 API: Route Model Binding 전환 (`{userId}` → `{user}`)
- 사용자 벌크 상태변경: 정수 ID → UUID 기반
- API Resource: user.id → user.uuid 전환 (UserResource, UserCollection 외 7개)
- Activity Log 메타데이터: user_id → uuid 전환
- FormRequest: 정수 검증 → UUID 검증 전환

### Fixed

- `_global.currentUser?.id` → `?.uuid` 전환 (sirsoft-basic 템플릿 12개 파일)
- 글쓰기 버튼 abilities 비활성화 누락 수정
- 코어 업그레이드 스텝 raw SQL 테이블 프리픽스 미적용 수정 — `UpgradeContext::table()` 헬퍼 추가 (Upgrade_7_0_0_beta_15)

## [7.0.0-alpha.14] - 2026-03-20

### Fixed

- 확장 업데이트 시 임시 디렉토리(`_updating_*`, `_old_*`) 오토로드 오염 방지 — 임시 디렉토리를 `_pending/` 하위에 생성하여 IDE 잠금 등으로 잔존 시에도 Fatal Error 방지 (ExtensionPendingHelper)

### Added

- Windows 파일잠금 감지/해제 기능 — 확장 업데이트 시 IDE 등이 파일 핸들을 보유한 경우 자동 감지 및 해제 시도 (FileHandleHelper, ExtensionPendingHelper)

- SEO 렌더러 훅 시스템: `core.seo.filter_context`, `core.seo.filter_meta`, `core.seo.filter_view_data` — 확장이 SEO 렌더링 파이프라인에 런타임 데이터 변환으로 개입 가능
- `seo.blade.php` 확장 슬롯: `extraHeadTags` (`</head>` 직전), `extraBodyEnd` (`</body>` 직전) — `filter_view_data` 훅을 통해 커스텀 스크립트/스타일 주입
- `ComponentHtmlMapper` pagination 렌더 모드 — Pagination 컴포넌트에서 SEO용 페이지 링크 자동 생성 (currentPage/totalPages props 기반)
- `ComponentHtmlMapper` text_format dot notation 지원 — `{author.nickname}` 형태로 객체 prop의 중첩 필드 접근
- `ExpressionEvaluator::evaluateRaw()` — 표현식 결과를 원본 타입(배열 등)으로 반환하는 메서드
- `SeoRenderer` SEO 컨텍스트에 `_global`/`_local` 빈 객체 추가 — 프론트엔드 전용 상태 참조 시 null 대신 빈 객체 제공
- `ComponentHtmlMapper` fields 렌더 모드 — 컴포지트 컴포넌트(ProductCard 등)의 객체 prop에서 SEO용 HTML 필드 자동 생성 (조건부/반복/속성 기반)
- `SeoRenderer` seoVars 주입 — `meta.seo.vars` 선언을 해석하여 ComponentHtmlMapper format 모드에서 `{key}` 플레이스홀더 치환
- `ExpressionEvaluator` 리터럴 값 감지 — 숫자, 문자열, boolean, null/undefined 리터럴을 경로 해석 없이 직접 반환
- `ExpressionEvaluator` $t: 파라미터 `{{}}` 표현식 해석 — 번역 키 파라미터 값에 포함된 바인딩 표현식을 컨텍스트에서 평가
- `TemplateManager` seo-config 검증에 `fields` 타입 추가
- `ExpressionEvaluator` seo_overrides — seo-config.json에서 `_local`/`_global` 상태 오버라이드 선언 (와일드카드 매칭으로 접혀있는 콘텐츠 SEO 강제 펼침)
- `TemplateManager` seo-config 검증에 `pagination` 타입 및 `seo_overrides` 검증 추가
- `SeoConfigMerger` — 모듈/플러그인/템플릿의 seo-config.json을 수집·병합하는 동적 확장 시스템 (우선순위: 모듈 → 플러그인 → 템플릿, 24시간 TTL 캐싱)
- `SeoRenderer` `_global` 컨텍스트 주입 — SettingsService/PluginSettingsService 프론트엔드 설정을 `_global`에 주입 + `initGlobal` 매핑으로 데이터소스 응답을 `_global` 경로에 바인딩
- `AbstractModule`/`AbstractPlugin` SEO 기여 메서드 — `getSeoConfig()`, `getSeoDataSources()` 인터페이스 추가
- `ModuleManager`/`PluginManager`/`TemplateManager` install/activate/update 시 훅 발행 추가 — Artisan 커맨드에서도 SEO 캐시 자동 무효화 보장
- SEO Artisan 커맨드 다국어 파일 추가 (`lang/ko/seo.php`, `lang/en/seo.php`)
- `ComponentHtmlMapper` fields 모드 `$all_props` source — 모든 props 표현식을 해석하여 데이터 객체로 사용 (Header/Footer 등 다수 props 컴포넌트용)
- `ComponentHtmlMapper` fields 모드 `$t:` 번역 키 지원 — content 패턴에서 `$t:key` → 다국어 텍스트 렌더링
- `ComponentHtmlMapper` fields iterate `item_attrs` — 아이템별 동적 HTML 속성 (예: `{ "href": "/board/{slug}" }`)
- `ExpressionEvaluator` `evaluateRaw()` `??` null coalescing 지원 — 원본 타입(배열/객체) 유지하면서 null coalescing 수행
- 인스톨러 Windows 환경 명령어 대응 — `chmod`/`chown` 스킵, 미존재 디렉토리 안내 메시지 추가
- `ExpressionEvaluator` 삼항 연산자 (`a ? b : c`) — JS 우선순위 준수, `?.`/`??` 자동 구분, 중첩 우측 결합
- `ExpressionEvaluator` `$t()` 함수 호출 구문 — 삼항 내부에서 `$t('key')` 형태로 번역 키 사용 가능 (기존 `$t:key` 방식 확장)
- `ExpressionEvaluator` `$localized()` 전역 함수 — 다국어 객체에서 현재 로케일 값 추출 (`{ko: "상품", en: "Product"}` → `"상품"`)
- `ExpressionEvaluator` 객체 리터럴 파서 — `{key: value, ...obj, [dynamicKey]: value}` 구문 지원
- `ExpressionEvaluator` 스프레드 연산자 — 배열 `[...arr, item]` 및 객체 `{...obj, key: value}` 스프레드 지원
- `SeoRenderer` computed 속성 해석 — 레이아웃 `computed` 섹션을 `_computed`/`$computed`에 저장 (문자열 표현식 + `$switch` 형식)
- `ComponentHtmlMapper` classMap 지원 — `base`/`variants`/`key`/`default`로 조건부 CSS 클래스 선언적 적용

### Fixed

- Redis 캐시 DB가 환경설정 값(`REDIS_CACHE_DB`)을 따르도록 수정 — `config/database.php` cache 연결 DB 반영

- `ExpressionEvaluator` 배열 리터럴 파싱 지원 — `['gallery','card'].includes(...)` 표현식에서 배열 리터럴을 PHP 배열로 변환하여 `includes` 등 배열 메서드 정상 동작 (SEO 게시판 타입 분기 조건 중복 렌더링 수정)
- `ExpressionEvaluator` null 비교 JavaScript 시맨틱 적용 — `null !== value` → `true`, `null === null` → `true` (SEO 컨텍스트에서 `_global` 미존재 경로 비교 시 빈 문자열 대신 올바른 boolean 반환)
- `ExpressionEvaluator` $t: 번역에서 `{{param}}` 형식 파라미터 치환 지원 — 템플릿 번역 파일의 `{{param}}` + Laravel 표준 `:param` 형식 모두 처리
- `ExpressionEvaluator` 비교 연산 좌측 optional chaining 경로 타입 보존 — `?.` 포함 경로가 `evaluateExpression`으로 불필요 라우팅되어 boolean 타입이 문자열로 변환되던 문제 수정

## [7.0.0-alpha.13] - 2026-03-18

### Added

- `SeoCacheRegenerator` — 단건 URL 캐시 즉시 재생성 서비스 (다국어 로케일별 렌더링 + 캐시 저장)
- `SeoSettingsCacheListener` — 코어 SEO 설정 변경 시 전체 SEO 캐시 + 사이트맵 삭제

### Fixed

- `SeoMiddleware`에서 `put()` 대신 `putWithLayout()` 사용 — `invalidateByLayout()`이 레이아웃명 미저장으로 항상 0건 매칭되던 근본 버그 수정
- `SeoRenderer`에서 레이아웃명을 request attribute로 저장 — `putWithLayout()` 연동

## [7.0.0-alpha.12] - 2026-03-17

### Changed

- API 인증을 토큰 전용으로 전환 — 세션 기반 인증 의존 제거, Bearer 토큰 단일 방식으로 통일
- README 업데이트 — 표기 통일, 섹션 정비, 문서 링크 연결

## [7.0.0-alpha.11] - 2026-03-16

### Added

- 역할(Role) 상태 토글 API 추가 — `PATCH /api/admin/roles/{role}/toggle-status` 엔드포인트, 훅 지원 (`core.role.before_toggle_status`, `core.role.after_toggle_status`)
- RoleResource에 `can_toggle_status` ability 추가 — 역할별 토글 권한 제어
- RoleResource에 `extension_name` 필드 추가 — 확장 출처별 로케일 이름 표시
- 역할 생성 시 `identifier` 직접 입력 기능 추가 — 미입력 시 name 기반 자동 생성 유지
- 역할 관련 다국어 키 추가 (ko/en) — identifier 검증 메시지
- `replaceUrl` 핸들러 추가 — refetch 없이 URL만 변경 (페이지네이션 등 브라우저 히스토리 관리용)
- 사용자 검색 API에 `id` 파라미터 지원 추가 — 특정 사용자 ID로 직접 조회 가능
- `TimezoneHelper` 유틸리티 클래스 추가 — 사용자/서버 타임존 간 변환 헬퍼
- `HasSampleSeeders` 트레이트 추가 — 모듈/플러그인 시더에서 `--sample` 옵션으로 샘플 데이터만 분리 실행
- `ComponentRegistry.getComponentNames()` 메서드 추가 — 등록된 컴포넌트 이름 목록 조회

### Fixed

- 템플릿 에러 페이지에서 `:identifier`가 리터럴로 표시되는 버그 수정 — Blade `@` 이스케이프 처리
- ActionDispatcher onChange raw value fallback 제거 — 마운트/리렌더 시 의도치 않은 setState 실행으로 상품 폼 회귀 유발
- Form 자동 바인딩 setState 경합 수정 — 자동 바인딩과 수동 setState가 동시 실행 시 stale 값으로 덮어쓰이는 문제 해결
- Form 자동 바인딩 bindingType 메타데이터 기반 boolean 바인딩 수정 — Toggle/Checkbox 등 boolean 컴포넌트에서 문자열 변환 대신 boolean 값 유지
- SPA 네비게이션 시 `_global._local`에 이전 페이지 상태가 잔존하는 버그 수정 — 페이지 전환 시 `_local` 초기화 처리
- DynamicRenderer `_computed` 참조 prop에서 캐시된 stale 값이 사용되는 버그 수정 — `_computed`/`$computed` 참조 시 `skipCache: true` 적용

### Changed

- 마이그레이션 통합 — 증분 마이그레이션을 테이블당 1개 create 마이그레이션으로 정리
- 시더 디렉토리 분리 — 설치 시더와 샘플 시더를 `Sample/` 하위로 분리, `--sample` 옵션 추가
- 라이선스 프로그램 명칭 정비
- 일정 관리 메뉴 기본 비활성화 (`config/core.php`)
- Composer 의존성 업데이트 — Laravel Framework v12.54.1, Reverb v1.8.0, Symfony v7.4.6~7 등

## [7.0.0-alpha.9] - 2026-03-13

### Added

- 그누보드7 커스텀 에러 페이지 도입 — Laravel 기본 에러 페이지(401, 403, 404, 500, 503)를 그누보드7 스타일로 교체, 다크 모드 지원, 접근 경로 기반 홈 링크 분기 (admin → `/admin`, 기타 → `/`)
- 환경설정 > 고급 디버그 모드 활성화 시 개발 대시보드(`/dev`) 바로가기 버튼 추가
- 루트 LICENSE 파일 생성 (MIT 라이선스, 한국어 번역 + 영문 원문)
- 코어 라이선스/Changelog API 엔드포인트 추가 (`GET /api/admin/license`, `GET /api/admin/changelog`)
- 확장 라이선스 API 엔드포인트 추가 (`GET /api/admin/modules/{id}/license`, `GET /api/admin/plugins/{id}/license`, `GET /api/admin/templates/{id}/license`)
- Admin 푸터 copyright 클릭 → 코어 라이선스 모달, 버전 클릭 → Changelog 모달 표시 기능
- 확장 상세 모달에서 라이선스 클릭 시 전문 모달 표시 기능 (모듈/플러그인/템플릿)
- 각 번들 확장에 LICENSE 파일 및 manifest `license` 필드 추가

### Changed

- 설치 화면 라이선스를 루트 LICENSE 파일로 통합 (`public/install/lang/license-ko.txt`, `license-en.txt` 삭제)
- `/dev` 라우트 뷰 이름을 `dev-dashboard`로 변경

## [7.0.0-alpha.8] - 2026-03-13

### Changed

- `.env.example.develop`과 `.env.example.production`을 `.env.example`로 통합 — 설치형 솔루션에 환경별 분리 불필요, Laravel/Vite 표준 준수
- 인스톨러에서 `.env.production` 백업 파일 생성 로직 제거 — Vite mode 기반 로딩 충돌 방지

### Improved

- 코어/확장 업데이트 시 composer install 스킵 최적화 — composer.json/composer.lock 미변경 시 composer install 및 vendor 디렉토리 교체를 건너뛰어 업데이트 시간 단축

### Fixed

- 확장 수동 설치 모달에서 설치 실패 시 상세 에러 사유(`errors.error`) 미표시 문제 수정 — 3개 모달에 상세 에러 P 요소 추가
- `checkDependencies()` 복수 의존성 에러 수집 — 첫 번째 미충족 의존성에서 즉시 throw 대신 전체 수집 후 줄바꿈 연결 (ModuleManager, PluginManager, TemplateManager)
- ModuleController/PluginController 에러 반환 형식을 TemplateController와 통일 — `['error' => $e->getMessage()]` 형태
- 확장 설치 실패 시 `_pending/{identifier}` 디렉토리 자동 정리 — ModuleService, PluginService, TemplateService에 try-catch 추가

## [7.0.0-alpha.7] - 2026-03-13

### Added

- 템플릿 엔진 `multipart/form-data` 지원 — apiCall 핸들러 및 DataSourceManager에서 `contentType: "multipart/form-data"` 설정 시 params를 FormData로 자동 변환
  - ActionDispatcher: `fetchWithOptions()`에 FormData 변환 로직 추가, Content-Type 헤더 자동 생략 (브라우저 boundary 설정)
  - DataSourceManager: `toFormData()` 메서드 추가, 인증/비인증 경로 모두 multipart 지원
  - File/Blob 원본 유지, null/undefined 제외, 객체/배열 JSON.stringify 변환
- `deepMergeWithState()` non-plain 객체(File/Blob/Date) 보호 — spread 복사로 인한 내부 데이터 소실 방지
- `resolveParams()` non-plain 객체 재귀 해석 스킵 — File 객체가 빈 객체로 변환되는 문제 방지
- 컴포넌트 onChange raw value fallback — FileInput, Toggle 등 Event가 아닌 값을 전달하는 컴포넌트 지원

### Fixed

- DataSourceManager `isMultipart` 변수 TDZ(Temporal Dead Zone) 버그 수정 — 선언 전 참조로 인한 ReferenceError 해결

## [7.0.0-alpha.6] - 2026-03-13

### Fixed

- 플러그인 환경설정 페이지 진입 시 404 오류 수정 — `registerPluginLayouts()` admin/user 분기 도입 후 루트 `settings.json`이 스킵되던 문제
  - 플러그인 설정 레이아웃을 `resources/layouts/settings.json` → `resources/layouts/admin/plugin_settings.json`으로 이동하여 모듈과 동일한 구조로 통일
  - `AbstractPlugin::getSettingsLayout()` 경로 변경
  - `PluginSettingsService` 오버라이드 경로 및 주석 수정
  - 영향받는 플러그인: sirsoft-daum_postcode, sirsoft-marketing, sirsoft-tosspayments

### Changed

- 플러그인 설정 레이아웃 규정 문서(`plugin-development.md`) 경로/설명 업데이트

## [7.0.0-alpha.5] - 2026-03-12

### Added

- 확장(모듈/플러그인/템플릿) changelog GitHub 원격 소스 지원 — `source=github` 시 GitHub에서 CHANGELOG.md 조회, 실패 시 bundled 폴백
- `ChangelogParser::parseFromString()`, `getVersionRangeFromString()` 문자열 기반 파싱 메서드 추가
- `ChangelogRequest` validation 에러 다국어 메시지 추가 (source, version format, required_with)
- 플러그인 GitHub/ZIP 설치 기능 추가 — 모듈/템플릿에는 있지만 플러그인에 누락되어 있던 기능 신규 구현
  - `PluginService::installFromGithub()`, `installFromZipFile()`, `findPluginJson()` 메서드 추가
  - `PluginController::installFromFile()`, `installFromGithub()` 엔드포인트 추가
  - `InstallPluginFromGithubRequest`, `InstallPluginFromFileRequest` FormRequest 추가
  - `install-from-file`, `install-from-github` API 라우트 추가
- `ExtensionManager::hasComposerDependenciesAt(string $path)` 메서드 추가 — 임의 경로의 Composer 의존성 확인
- 모듈/플러그인 설치 시 `_pending` Composer 선행 설치 로직 추가 — 활성 디렉토리 이관 전 의존성 설치

### Changed

- 확장 수동 설치 모달 3개(모듈/플러그인/템플릿) UI 통일 — TabNavigation underline, 에러 배너, 필드별 적색 테두리
- 확장 GitHub/ZIP 설치 공통 로직을 `GithubHelper`, `ZipInstallHelper`로 추출하여 3개 Service 중복 제거
- `CoreUpdateService` GitHub 관련 protected 메서드를 `GithubHelper` 위임으로 리팩토링
- 확장 목록 PageHeader에서 새로고침 버튼 제거, 업데이트 확인 버튼으로 통일
- 코어 업데이트 `core_pending` 고정 경로 → `core_{Ymd_His}` 타임스탬프 기반 격리 디렉토리로 변경
- 확장(모듈/플러그인/템플릿) 업데이트에 `_pending/{identifier}_{timestamp}/` 스테이징 패턴 도입
- 확장 업데이트 시 스테이징 내에서 composer install 실행 (활성 디렉토리 무영향)
- 확장 GitHub 다운로드 코드를 코어와 동일한 패턴으로 통합 (인증 헤더, 폴백 체인, 타임아웃)
- GitHub 다운로드 공용 로직을 `ExtensionManager`로 추출하여 3개 Manager 중복 제거
- `config/app.php`에서 `preserves` 설정 키 제거 (타임스탬프 격리로 불필요)
- 모듈/플러그인 GitHub/ZIP 설치 흐름을 `temp → _pending → composer install → 활성 디렉토리` 패턴으로 통일

### Fixed

- 코어 업데이트 결과 모달에서 from/to 버전 파라미터가 전달되지 않는 버그 수정 — `params.params` → `params.query`
- 플러그인에 GitHub/ZIP 설치 기능이 누락되어 있던 결함 수정
- 파일 복사 시 퍼미션/소유자/소유그룹 미보존 문제 수정 — `File::copy()` → `FilePermissionHelper::copyFile()` 교체 (6개 위치)
- Windows 환경에서 확장/코어 업데이트 후 `_pending` 하위에 빈 디렉토리가 잔존하는 문제 수정 — `cleanupStaging()`에 3단계 retry 로직 추가
- 코어 업데이트 후 vendor 교체 시 stale `packages.php`/`services.php`로 인한 500 오류 수정 — `clearAllCaches()`에 컴파일 캐시 삭제 + `package:discover` + `extension:update-autoload` 추가
- 코어 업데이트 시 `bootstrap/cache` 디렉토리가 소스에서 덮어씌워지는 문제 수정 — excludes에 `bootstrap/cache` 추가

## [7.0.0-alpha.4] - 2026-03-12

### Added

- 코어 업그레이드 스텝 검증용 샘플 마이그레이션 및 업그레이드 스텝 추가
- 코어 업그레이드 스텝 경로를 `database/upgrades/` → `upgrades/`로 변경
- 업그레이드 스텝 실행을 프로그레스바 별도 단계로 분리 및 터미널 피드백 추가

### Changed

- `--source` 모드에서 원본 소스 디렉토리를 `_pending`으로 복제 후 작업 (원본 보호)
- Step 8: 운영 디렉토리 `composer install` 재실행 → `_pending/vendor/` 복사로 변경 (효율화)

### Fixed

- `--source` 모드에서 소스 버전 감지 시 현재 `env()` 대신 `config/app.php` default 값 파싱
- 코어 업데이트 targets에 `upgrades` 디렉토리 누락 수정

## [7.0.0-alpha.3] - 2026-03-12

### Fixed

- `.gitattributes`의 `CHANGELOG.md export-ignore`가 모든 CHANGELOG 파일을 릴리스 아카이브에서 제외하던 문제 수정
- PharData(tar ustar) 100바이트 경로 제한으로 324개 파일이 누락되어 orphan 삭제가 발생하던 버그 수정

### Removed

- PharData 아카이브 추출 전략 제거 — tar ustar 형식의 100바이트 경로 제한은 근본적 해결 불가

### Added

- `core:update --source=` 옵션 추가 — ZipArchive/unzip 불가 환경에서 수동 업데이트 지원
  - 상대경로, 절대경로, Windows 경로 모두 지원
  - 소스 디렉토리의 그누보드7 프로젝트 유효성 검증 (`config/app.php` + `version` 키)
- 업데이트 안내 모달에 수동 업데이트 가이드 섹션 추가
- 시스템 요구사항 미충족 시 `--source` 옵션 안내 메시지 추가

## [7.0.0-alpha.2] - 2026-03-12

### Fixed
- 코어 업데이트 확인 시 업데이트할 버전이 없으면 피드백 없던 문제 수정
  - `openModal` 호출 형식 수정 (`params.id` → `target`)
  - 모달 데이터 바인딩 경로 수정 (`_local` → `$parent._local`)
- 코어 업데이트 명령어 에러 미출력 수정
- `.env` 예제 파일에서 `G7_UPDATE_TARGETS` 하드코딩 제거

### Added
- 코어 업데이트 targets 확장 및 orphan 삭제 로직 추가
- 코어 업데이트 `--local` 옵션 및 설정 기반 제외/보존 추가
- 환경설정 고급 탭 코어 업데이트 설정 섹션 추가

## [7.0.0-alpha.1] - 2026-03-07

### Added

#### 코어 아키텍처

- Laravel 12 기반 CMS 플랫폼 초기 구조 설계 및 구현
- Service-Repository 패턴 기반 계층 분리 아키텍처 구축
- CoreServiceProvider를 통한 인터페이스-구현체 바인딩 시스템
- ResponseHelper를 통한 통일된 API 응답 형식 (success/error/paginated)
- AdminBaseController / AuthBaseController / PublicBaseController 컨트롤러 계층 구조
- FormRequest + Custom Rule 기반 검증 시스템
- BaseApiResource 상속 기반 API 리소스 패턴
- PHP 8.2+ Backed Enum 기반 상태/타입/분류 관리

#### 확장 시스템 (Extension System)

- 모듈(Module) 시스템: 디렉토리 스캔 기반 자동 발견, 설치/활성화/비활성화/삭제 관리
- 플러그인(Plugin) 시스템: 모듈 의존 기반 기능 확장, 설정 UI(settings.json) 지원
- 템플릿(Template) 시스템: Admin/User 타입 분리, JSON 기반 레이아웃, 컴포넌트 레지스트리
- ExtensionManager: 모듈/플러그인/템플릿 통합 관리 (설치, 업데이트, 삭제)
- HookManager: Action/Filter 훅 시스템 (doAction, applyFilters, HookListenerInterface)
- 확장 업데이트 시스템: _bundled/_pending 디렉토리 구조, GitHub/로컬 업데이트 감지 및 적용
- 확장 백업/복원 시스템 (ExtensionBackupHelper)
- 확장 상태 가드 (ExtensionStatusGuard): Installing/Updating/Uninstalled 상태 관리
- 확장 권한 동기화 (ExtensionRoleSyncHelper): 설치/삭제 시 역할-권한 자동 동기화
- 확장 메뉴 동기화 (ExtensionMenuSyncHelper): 모듈 메뉴 자동 등록/해제
- 확장 오토로드 시스템: 런타임 Composer 오토로드 (composer.json 수정 불필요)
- 확장 Composer 의존성 관리 (extension:composer-install 커맨드)
- 확장 업그레이드 스텝 시스템 (UpgradeStepInterface, UpgradeContext)
- 확장 설정 시스템 (SettingsMigrator): 모듈/플러그인별 독립 설정 관리
- 확장 소유권 시스템: extension_type/extension_identifier 기반 리소스 귀속
- 확장 에셋 시스템: module.json 매니페스트 기반 JS/CSS 자동 로딩
- 확장 Changelog 시스템: ChangelogParser 헬퍼 + API 엔드포인트 + 관리 화면 인라인 표시
- 확장 빌드 시스템: Artisan 커맨드 기반 (module:build, template:build, plugin:build)
- 확장 캐시 관리: 확장별 독립 캐시 + 일괄 클리어 커맨드

#### 템플릿 엔진 (Template Engine)

- DynamicRenderer: JSON 레이아웃 기반 React 컴포넌트 동적 렌더링
- DataBindingEngine: `{{expression}}` 문법, Optional Chaining(`?.`), Nullish Coalescing(`??`) 지원
- ActionDispatcher: 20+ 내장 핸들러 (navigate, apiCall, setState, openModal, closeModal, sequence, condition, replaceUrl, scrollTo, copyToClipboard, downloadFile, debounce, emit, showToast, confirm, validate, submit, reset, filter, sort 등)
- ComponentRegistry: 컴포넌트 등록/검색/해석 시스템 (기본/집합/레이아웃 타입)
- TranslationEngine: `$t:key` 즉시 평가 / `$t:defer:key` 지연 평가 다국어 바인딩
- LayoutLoader: JSON 레이아웃 로딩, 캐싱, ETag 기반 조건부 요청
- Router: SPA 라우팅, 동적 경로 파라미터(`{{route.id}}`), 쿼리스트링 관리
- 레이아웃 상속 시스템: `extends` 기반 베이스 상속 + `type: "slot"` 위치에 컨텐츠 삽입
- Partial 시스템: 레이아웃 모듈화, 컴포넌트 치환 (data_sources/computed/modals/state 미지원)
- 조건부 렌더링: `if` 속성 기반 표현식 평가 (type: "conditional" 미지원)
- 반복 렌더링: `iteration` 설정 (source, item_var, index_var, key)
- 반응형 레이아웃: `responsive` 속성 기반 breakpoint 오버라이드 (portable/compact/wide)
- 다크 모드: Tailwind `dark:` variant 기반 자동 전환
- classMap: 조건부 CSS 클래스 매핑 (key → variants)
- computed: 계산된 속성 시스템 (의존성 추적, 자동 재계산)
- 모달 시스템: `modals` 섹션 + openModal/closeModal 핸들러 (`_global.modalStack` 기반)
- init_actions: 레이아웃 초기화 시 자동 실행 액션 (루트 레이아웃 레벨)
- Named Actions: 액션 재사용 시스템 (DRY 패턴)
- errorHandling: 전역/데이터소스별 에러 핸들링 설정
- scripts: 레이아웃 레벨 커스텀 스크립트 로딩
- globalHeaders: API 호출 공통 헤더 설정 (pattern 기반 매칭)
- blur_until_loaded: 데이터 로딩 전 블러 처리
- lifecycle: 컴포넌트 생명주기 훅 (onMount, onUnmount)
- slots: 컴포넌트 슬롯 시스템
- layout_extensions: 모듈/플러그인의 동적 UI 주입 포인트
- isolatedState: 컴포넌트 상태 격리
- 데이터소스 조건부 로딩: `if` 표현식 기반 활성화/비활성화

#### 컴포넌트 시스템

- Basic 컴포넌트 (27+): Div, Button, Input, Select, Form, A, H1~H6, Span, P, Img, Label, Textarea, Table, Thead, Tbody, Tr, Th, Td, Ul, Ol, Li, Hr, Strong, Em, Small, Pre, Code, Blockquote, Nav, Header, Footer, Main, Section, Article, Aside, Figure, FigCaption
- Composite 컴포넌트: DataGrid, CardGrid, Pagination, Modal, SearchBar, Tabs, TabPanel, Accordion, Badge, Breadcrumb, Card, Checkbox, CheckboxGroup, DatePicker, Dropdown, FileUpload, Icon, Notification, Radio, RadioGroup, RangeSlider, Rating, Select (enhanced), Sidebar, Stepper, Switch, Tag, Timeline, Toast, Tooltip, PasswordInput, DynamicFieldList, SortableList, ColorPicker, NumberInput, TreeView, DateRangePicker
- Layout 컴포넌트: FlexLayout, GridLayout, ScrollLayout, StickyLayout, Spacer, Container, AspectRatio
- HtmlEditor: TinyMCE 기반 HTML 에디터 컴포넌트
- Icon 컴포넌트: Font Awesome 6.4.x Free 아이콘 지원 (Solid 1,390개 / Regular 163개 / Brands 472개)
- Alert, EmptyState, LoadingSpinner, Skeleton, StatusBadge, CopyButton 유틸리티 컴포넌트

#### 상태 관리

- 전역 상태 (`_global`): 앱 전체 공유, 페이지 이동 시 유지
- 로컬 상태 (`_local`): 레이아웃 단위 격리
- 계산된 상태 (`_computed`): 의존성 기반 자동 재계산
- 폼 자동 바인딩: Form 컴포넌트 `stateKey` 기반 자동 상태 연동
- setState 핸들러: target(global/local), 함수형 업데이트, 배열 조작 (push/filter/map)
- 상태 구독: `G7Core.state.subscribe` 기반 반응형 업데이트
- initGlobal: 전역 상태 초기값 선언
- 모달 스코프 상태: `$parent._local` 스냅샷 기반 데이터 전달

#### 인증 시스템

- Laravel Sanctum 하이브리드 인증 (세션 + Bearer 토큰)
- AuthManager: 싱글톤 인증 상태 관리
- 자동 토큰 갱신: 401 응답 시 자동 리프레시 후 재시도
- OptionalSanctumMiddleware: 선택적 인증 지원 (비인증 사용자 허용)
- 로그인/로그아웃 3단계 프로토콜 (토큰 삭제 → 세션 무효화 → Auth::logout)
- 비밀번호 재설정: 이메일 인증 기반 토큰 발급 및 검증
- 회원가입: 약관 동의, 이메일 인증 지원

#### 권한 시스템

- Role 기반 권한 관리 (User → Role → Permission 3계층)
- permission 미들웨어 체인 기반 접근 제어 (FormRequest authorize() 사용 금지)
- 확장별 권한 자동 등록/해제
- 역할별 메뉴 접근 제어 (role_menus 피벗 테이블)
- 슈퍼 관리자 (superadmin) 전체 권한 자동 부여

#### 메뉴 시스템

- 계층형 메뉴 구조 (parent_id 기반)
- 역할별 메뉴 가시성 제어
- 모듈 메뉴 자동 등록 (getAdminMenus 인터페이스)
- 메뉴 순서 관리 (드래그 앤 드롭 SortableList)
- 다국어 메뉴명 지원 (JSON 배열 형식)

#### 데이터 소스 (Data Sources)

- API 엔드포인트 선언적 정의 (id, endpoint, method, params)
- loading_strategy: immediate/lazy/manual 3가지 로딩 전략
- 데이터소스 의존성: depends_on 기반 연쇄 로딩
- 폴링: poll_interval 기반 주기적 갱신
- 조건부 로딩: `if` 표현식 기반 활성화
- transform: 응답 데이터 변환 함수
- cache_duration: 응답 캐싱

#### 관리자 기능 (Admin)

- 대시보드: 시스템 정보, 통계 위젯, 최근 활동
- 사용자 관리: CRUD, 역할 할당, 상태 관리 (활성/비활성/차단)
- 역할 관리: CRUD, 권한 할당, 다국어 역할명
- 권한 관리: 카테고리별 권한 목록, 역할별 권한 할당
- 메뉴 관리: 계층형 메뉴 편집, 순서 변경, 역할별 가시성 설정
- 모듈 관리: 설치/활성화/비활성화/삭제, 업데이트 확인, 상세 정보 모달, changelog 표시
- 플러그인 관리: 설치/활성화/비활성화/삭제, 설정 UI, 업데이트 확인, changelog 표시
- 템플릿 관리: 설치/활성화/비활성화/삭제, 업데이트 확인, changelog 표시
- 환경설정: 사이트 기본 정보, SEO 설정, 메일 설정, 보안 설정, 탭 레이아웃
- 일정 관리: 스케줄 CRUD, 캘린더 뷰, 카테고리 분류
- 메일 템플릿 관리: DB 기반 메일 템플릿 CRUD, 변수 치환, 미리보기 기능
- 메일 발송 로그: 발송 이력 조회, 상태 추적, 상세 정보 모달
- 시스템 정보: PHP/Laravel/DB 버전, 디스크 사용량, 확장 현황 표시
- 코어 업데이트: 버전 확인, 업데이트 가이드, changelog 인라인 표시, 백업 생성

#### 사용자 기능 (User/Public)

- 로그인/회원가입/비밀번호 재설정 페이지
- 마이페이지: 프로필 수정, 비밀번호 변경
- 통합 검색 기능
- 게시판 뷰: 목록/상세/작성/수정 (board 모듈 연동)
- 에러 페이지: 403, 404, 500, 503 커스텀 에러 페이지
- 점검 모드(maintenance) 페이지

#### 설치 프로그램 (Installer)

- 다단계 웹 설치 마법사 (환경 체크 → DB 설정 → 관리자 생성 → 완료)
- SSE(Server-Sent Events) 기반 실시간 설치 진행 상태 표시
- 설치 롤백 기능: 실패 시 자동 복원 (마이그레이션/시더 롤백)
- 언어 선택 지원 (한국어/영어)
- 다크 모드 지원
- 환경 요구사항 자동 검증 (PHP 버전, 확장, 디렉토리 권한)

#### 모듈: sirsoft-board (게시판)

- 게시판 관리: CRUD, 카테고리, 스킨 설정, 권한 설정
- 게시글 관리: CRUD, 검색, 정렬, 페이지네이션
- 댓글 시스템: CRUD, 대댓글 (계층형), 답글 알림
- 신고 시스템: 게시글/댓글 신고, 관리자 처리 (승인/거절)
- 첨부파일: 이미지/파일 업로드, 다운로드, 인라인 표시
- 비밀글: 작성자/관리자만 열람 가능
- 블라인드/복원: 관리자 블라인드 처리 및 복원 기능
- 카드/갤러리 레이아웃: 다양한 목록 표시 형태 지원
- 게시판 권한: 읽기/쓰기/댓글/관리 권한 분리
- 인기글/최신글 위젯
- SEO 메타 태그 자동 생성

#### 모듈: sirsoft-ecommerce (이커머스)

- 상품 관리: CRUD, 옵션(사이즈/색상), 라벨, SEO 메타, 이미지 갤러리
- 상품 카테고리: 계층형 카테고리, 순서 관리, TreeView 편집
- 브랜드 관리: CRUD, 로고, 설명
- 주문 관리: 주문 목록, 상태 변경, 상세 정보, 주문 타임라인
- 쿠폰 시스템: 정액/정률 할인, 사용 조건 (최소 금액, 특정 상품), 유효기간, 사용 횟수 제한
- 배송 정책: 무료/유료/조건부 배송, 지역별 요금 설정
- 공통 정보 관리: 배송/교환/환불 안내, 판매자 정보
- 장바구니: 추가/수량 변경/삭제, 옵션별 관리, 품절 상품 알림
- 체크아웃: 주소 입력 (다음 우편번호 연동), 배송지 저장 체크박스, 결제수단 선택, 쿠폰 적용
- 주문 완료: 주문 번호, 결제 정보 요약, 장바구니 자동 비우기
- 상품 상세 페이지: 이미지 갤러리, 옵션 선택, 수량 입력, 장바구니 담기
- 상품 목록: 필터링 (카테고리/브랜드/가격), 정렬, 페이지네이션, 카드 그리드
- 위시리스트: 찜하기/해제 기능
- 상품 검색: 키워드 검색, 카테고리 필터 연동

#### 모듈: sirsoft-page (페이지 관리)

- CMS 페이지: CRUD, 슬러그 기반 URL 매핑
- 페이지 버전 관리: 버전 이력 조회, 이전 버전 복원
- 첨부파일: 이미지/파일 업로드 지원
- 검색 통합: 페이지 내용 통합 검색 지원
- SEO: 메타 태그, Open Graph 설정

#### 플러그인: sirsoft-daum_postcode (다음 우편번호)

- 다음 우편번호 검색 API 연동
- 주소 선택 후 폼 자동 입력
- 체크아웃 배송지 입력 연동

#### 플러그인: sirsoft-tosspayments (토스페이먼츠)

- 토스페이먼츠 결제 API 연동
- 카드/계좌이체/가상계좌/무통장입금 결제 지원
- 결제 확인 (confirm) 프로세스
- 결제 성공/실패 콜백 처리
- 관리자 설정 UI (API 키 관리)

#### 플러그인: sirsoft-marketing (마케팅 동의)

- 마케팅 동의 관리: 이메일 구독, 마케팅 동의, 제3자 제공 동의
- MarketingConsent / MarketingConsentHistory 모델 및 서비스
- MarketingConsentListener 훅 리스너 (회원가입/프로필 연동)
- 사용자 관리 화면 레이아웃 확장 (마케팅 동의 상세/폼/프로필)
- 회원가입 폼 마케팅 동의 항목 확장
- 플러그인 설정 UI
- 역할 기반 접근 제어 (마케팅 관리자)
- 다국어 지원 (ko, en)

#### 플러그인: sirsoft-verification (본인인증)

- 휴대폰 인증, 아이핀 인증 등 본인인증 기능 제공
- 사용자 관리 화면 레이아웃 확장 (본인인증 상세/폼)
- 역할 기반 접근 제어 (본인인증 관리자)
- 다국어 지원 (ko, en)

#### 템플릿: sirsoft-admin_basic (관리자)

- 관리자 대시보드 레이아웃
- 사이드바 네비게이션: 접기/펼치기, 계층형 메뉴, 활성 상태 표시
- 상단바: 사용자 정보, 알림 벨, 다크 모드 전환 토글
- 반응형 레이아웃 (데스크톱/태블릿/모바일)
- 관리자 전용 컴포넌트: DataGrid, CardGrid, SearchBar, 필터 패널 등
- CRUD 화면 표준 레이아웃 (목록/생성/수정/상세)
- 모달 기반 상세 보기/수정 기능
- 토스트 알림 시스템
- 확인 다이얼로그 (삭제 확인 등)
- 환경설정 탭 레이아웃
- 모듈/플러그인/템플릿 관리 화면 (설치/업데이트/상세 모달)
- 사용자/역할/권한 관리 화면
- 메뉴 관리 화면 (드래그 앤 드롭 순서 변경)
- 일정 관리 캘린더 화면
- 메일 템플릿 관리 화면
- 메일 발송 로그 화면
- 시스템 정보 화면
- 코어 업데이트 가이드/결과 모달

#### 템플릿: sirsoft-basic (사용자)

- 사용자 메인 페이지 레이아웃
- 헤더/푸터 공통 레이아웃 (반응형)
- 로그인/회원가입/비밀번호 재설정 화면
- 마이페이지 레이아웃
- 게시판 목록/상세/작성/수정 화면
- 상품 목록/상세 화면
- 장바구니/체크아웃/주문완료 화면
- 검색 결과 화면
- CMS 페이지 표시 화면
- 에러 페이지 (403, 404, 500, 503)
- 점검 모드(maintenance) 전용 페이지
- 반응형 레이아웃 (데스크톱/태블릿/모바일)

#### 다국어 시스템 (i18n)

- 백엔드 다국어: `__()` 함수 기반, `lang/{locale}/*.php` 파일 구조
- 프론트엔드 다국어: `$t:key` 즉시 평가 바인딩, `$t:defer:key` 지연 평가 바인딩
- 컴포넌트 다국어: `G7Core.t()` API 제공
- 모듈 다국어: 모듈별 독립 언어 파일, 네임스페이스 분리 (`__('vendor-module::key')`)
- 템플릿 다국어: partial 언어 파일 분할 (admin.json, errors.json 등)
- DB 다국어 필드: JSON 배열 형식 (`{"ko": "...", "en": "..."}`)
- 지원 로케일: 한국어(ko), 영어(en)
- TranslatableField 트레이트: 모델 다국어 필드 자동 해석

#### 보안 (Security)

- ValidLayoutStructure: JSON 레이아웃 구조 검증 Custom Rule
- WhitelistedEndpoint: API 엔드포인트 화이트리스트 검증 Custom Rule
- NoExternalUrls: 외부 URL 차단 검증 Custom Rule
- ComponentExists: 컴포넌트 존재 여부 검증 Custom Rule
- CSRF 보호: Laravel 기본 CSRF 토큰 검증
- XSS 방지: 출력 이스케이프, HTML sanitize 처리
- SQL Injection 방지: Eloquent ORM, 파라미터 바인딩 사용
- Rate Limiting: API 요청 제한 미들웨어

#### 메일 시스템

- DB 기반 메일 템플릿: 제목/본문 DB 관리, 변수 치환 (Blade 문법)
- Notification + Mailable 통합 패턴: BaseNotification 상속으로 보일러플레이트 제거
- 메일 발송 로그: 발송 이력 DB 기록, 수신자/상태/발송 시간 추적
- 메일 템플릿 사용자 오버라이드 추적 (user_overrides 필드)

#### 알림 시스템 (Notification)

- BaseNotification: 모든 알림의 베이스 클래스 (via() 보일러플레이트 제거)
- 알림 채널: 메일, 데이터베이스, 브로드캐스트 지원
- 실시간 알림: Laravel Reverb (WebSocket) 기반 Broadcasting

#### 스토리지 시스템

- StorageInterface: 모든 파일 저장의 추상화 인터페이스 (Storage::disk() 직접 호출 금지)
- CoreStorageDriver: 코어 스토리지 구현체
- 확장별 독립 스토리지 공간 할당

#### 코어 업데이트 시스템

- CoreUpdateService: GitHub API 기반 코어 버전 확인 및 업데이트 감지
- CoreUpdateController: 업데이트 상태 확인, 가이드 표시 API 엔드포인트
- CoreBackupHelper: 업데이트 전 코어 파일 백업 생성
- FilePermissionHelper: 파일/디렉토리 쓰기 권한 사전 검증
- MaintenanceModePage 미들웨어: 점검 모드 시 전용 페이지 표시
- 업데이트 가이드 모달: changelog 인라인 표시, 단계별 안내

#### 그누보드7 DevTools

- MCP 서버: 20+ 디버깅 도구 제공
  - 기본 도구: g7-state, g7-actions, g7-cache, g7-diagnose, g7-lifecycle, g7-network, g7-form, g7-expressions, g7-logs
  - 고급 분석: g7-datasources, g7-handlers, g7-events, g7-performance, g7-conditionals, g7-websocket
  - 상태 계층/스타일: g7-renders, g7-state-hierarchy, g7-context-flow, g7-styles, g7-auth, g7-tailwind, g7-layout
  - Phase 8 심화: g7-computed, g7-nested-context, g7-modal-state, g7-sequence, g7-stale-closure, g7-change-detection
- 브라우저 상태 덤프: 런타임 상태 캡처 및 MCP 서버 전송
- UI 패널: 브라우저 내 DevTools 패널 (실시간 상태/액션/로그 확인)
- 페이지네이션: offset/limit 기반 대용량 데이터 조회 지원

#### WYSIWYG 에디터

- 비주얼 레이아웃 에디터: 드래그 앤 드롭 기반 레이아웃 편집
- PropertyPanel: 컴포넌트 속성 편집 UI
- 컴포넌트 팔레트: 사용 가능한 컴포넌트 드래그 목록
- 실시간 미리보기: 편집 즉시 렌더링 결과 확인

#### 성능 최적화

- 레이아웃 캐싱: 파싱된 레이아웃 JSON + 상속 병합 결과 캐시
- 확장 캐싱: 모듈/플러그인/템플릿 목록 및 메타데이터 캐시
- 번역 캐싱: 언어 파일 파싱 결과 캐시
- ETag 지원: API 응답 조건부 캐시 검증 (304 Not Modified)
- Gzip 압축: API 응답 자동 압축
- Debounce 액션: 연속 이벤트 디바운스 처리 핸들러
- Lazy 로딩: 데이터소스 지연 로딩 (스크롤/이벤트 트리거)
- 순환 참조 방지 메커니즘: 레이아웃 상속 무한 루프 감지

#### 데이터베이스

- 코어 테이블: users, roles, permissions, role_has_permissions, role_menus, menus, settings, mail_templates, mail_send_logs, schedules, modules, plugins, templates, template_layouts, template_layout_versions
- 모든 컬럼 한국어 comment 필수 규칙 적용
- down() 메서드 완전 롤백 구현 필수 규칙 적용
- 다국어 필드 JSON 배열 형식 표준
- MariaDB 호환성 지원
- boolean/enum 컬럼 값 설명 comment 포함 규칙

#### 테스트 인프라

- PHPUnit 11.x: 백엔드 단위 테스트 (Unit) 및 기능 테스트 (Feature)
- Vitest: 프론트엔드 컴포넌트/템플릿 엔진 테스트
- createLayoutTest(): 레이아웃 JSON 렌더링 테스트 유틸리티 (Vitest + jsdom)
- mockApi(): API 응답 모킹 유틸리티 (fetch 자동 모킹)
- 트러블슈팅 회귀 테스트: 해결된 사례별 자동 검증
- 테스트 커버리지: 모델, 서비스, 컨트롤러, 컴포넌트, 레이아웃 렌더링

#### 빌드 시스템

- Vite 기반 프론트엔드 빌드 (코드 분할, 트리 쉐이킹)
- Artisan 커맨드 기반 빌드 관리:
  - `core:build`: 코어 템플릿 엔진 빌드 (--full, --watch 옵션)
  - `module:build`: 모듈 프론트엔드 빌드 (--all, --watch, --active 옵션)
  - `template:build`: 템플릿 빌드 (--all, --watch, --active 옵션)
  - `plugin:build`: 플러그인 빌드 (--all, --watch, --active 옵션)
- _bundled 디렉토리 기본 빌드, --active 옵션으로 활성 디렉토리 빌드
- --watch 모드: 파일 감시 기반 실시간 빌드 (활성 디렉토리 자동 사용)

#### Artisan 커맨드

- 모듈 관리: module:list, module:install, module:activate, module:deactivate, module:uninstall, module:composer-install, module:cache-clear, module:seed, module:check-updates, module:update, module:build
- 플러그인 관리: plugin:list, plugin:install, plugin:activate, plugin:deactivate, plugin:uninstall, plugin:composer-install, plugin:cache-clear, plugin:seed, plugin:check-updates, plugin:update, plugin:build
- 템플릿 관리: template:list, template:install, template:activate, template:deactivate, template:uninstall, template:cache-clear, template:check-updates, template:update, template:build
- 확장 공통: extension:composer-install, extension:update-autoload
- 코어: core:build

#### 개발 도구 및 문서

- AI 에이전트 개발 가이드 (AGENTS.md): 핵심 원칙, 코딩 규칙, 디버깅 프로토콜
- 규정 문서 체계 (docs/): 백엔드 14개, 프론트엔드 59개, 확장 23개, 공통 4개 (총 100개)
- AI 에이전트 자동화 도구: 검증/분석/구현 스킬 30+개, 인덱스 자동 생성 스크립트
- 트러블슈팅 가이드: 상태 관리, 캐시, 컴포넌트, 백엔드 문제 해결 사례집
- 코드 스타일: Laravel Pint (PSR-12) 자동 적용
