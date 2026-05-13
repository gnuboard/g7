# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0-beta.5] - 2026-05-12

### Fixed

- 알림 센터의 미확인 알림 카운트 뱃지·알림 행의 unread 점·"읽지 않은 알림만" 체크박스가 다크 모드에서 색상이 부정합하게 노출되던 문제 수정

## [1.0.0-beta.4] - 2026-05-11

### Added

- 본인인증 정책이 활성화된 모든 강제 지점(회원가입·비밀번호 재설정·민감 작업·게시판/이커머스 정책 등)에서 동일한 모달 UX 가 자동으로 표시되도록 공통 본인인증 모달 추가
- 본인인증 모달의 "취소" 클릭 시 서버 본인인증 challenge 가 즉시 정리되도록 개선 — 미인증 challenge 가 만료까지 남아있던 동작이 audit 기록과 함께 즉시 cancelled 상태로 정합
- 외부 본인인증 provider 플러그인이 자기 SDK UI 를 주입할 수 있는 슬롯 도입 — 다음 우편번호 / WYSIWYG 에디터 와 동일한 G7 표준 패턴으로 KCP·PortOne·토스인증·Stripe Identity 등 추가 가능
- 본인인증 챌린지 화면 (`/identity/challenge`) — render_hint 에 따라 OTP 코드 입력 / 이메일 링크 안내 / 외부 본인인증 리다이렉트 3종 분기. 만료 카운트다운(분:초), 남은 시도 횟수, 30초 재전송 쿨다운, live region 접근성 지원. 모달과 동일한 결과 통보 흐름을 사용하여 코어 인터셉터의 launcher 폴백과 일관
- 회원가입 폼이 쿼리 파라미터로 전달된 `verification_token` 을 서버로 자동 전송하도록 개선 — 코어 본인인증 인프라(Mode B) 연동
- 로그인 화면에 세션 만료 안내 토스트 추가 — 토큰 만료로 자동 리다이렉트된 사용자에게 "세션이 만료되었습니다. 다시 로그인해 주세요." 메시지 표시 (`?reason=session_expired` 쿼리 파라미터 감지)

### Fixed

- 사용자 프로필/게시글 목록/마이페이지에서 댓글 수가 0으로 표시되던 문제 수정
- 게시글 상세의 댓글/답글/첨부파일 헤더 카운트가 실제 수와 어긋나던 문제 수정
- 비로그인 상태에서 사용자 공개 프로필 페이지 접근 시 페이지가 표시되지 않던 문제 수정
- 주문 완료 페이지의 "이 배송지를 주소록에 저장" 버튼이 작동 불가 상태였던 문제 수정 — 호출 URL 누락으로 요청이 발송되지 않고 성공/실패 토스트도 표시되지 않던 회귀 해소

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.4 로 상향 — 엔진의 `startInterval` / `stopInterval` 액션 핸들러(engine-v1.45.0) 의존
- 번들 모듈 의존성 최소 버전을 `>=1.0.0-beta.3` 로 상향 (sirsoft-board, sirsoft-ecommerce, sirsoft-page)

## [1.0.0-beta.3] - 2026-04-21

### Fixed

- 알림 레이어를 닫을 때 목록이 읽음 상태로 갱신되지 않아 재토글 시 읽음 처리가 누락되던 문제 수정
- 알림 레이어 무한 스크롤 시 "안 읽은 알림만" 필터가 유지되지 않아 읽은 알림이 섞여 노출되던 문제 수정
- 알림 레이어 무한 스크롤 시 동일 페이지 API 가 중복 호출되던 문제 수정
- 안 읽은 알림이 없는데도 알림 레이어를 닫을 때 읽음 처리 API 가 불필요하게 호출되던 문제 수정
- 알림 드롭다운이 화면 경계를 벗어나지 않도록 자동으로 좌/우 정렬 전환 및 최대 너비 제한

## [1.0.0-beta.2] - 2026-04-20

### Added

- NotificationCenter 컴포넌트 — 알림 드롭다운, 무한 스크롤, 안 읽은 필터, 개별/전체 삭제 (관리자 템플릿과 동일 UX)
- 알림 전체 삭제 확인 모달 추가
- 마이페이지 알림 목록에 "안 읽은 알림만" 필터 토글 추가
- 실시간 알림 수신 시 카운트 갱신 + 토스트 표시 (WebSocket 연동)
- 게시판/쇼핑몰 레이아웃에 SEO 설정 적용
- 게시글 상세 페이지에 이전글/다음글 네비게이션 추가 — 독립 API로 비동기 로딩하여 초기 렌더링 속도 개선

### Changed

- 코어 최소 요구 버전을 7.0.0-beta.2 로 상향
- 모듈/플러그인 의존성 버전 제약을 실제 릴리스 버전에 맞춰 정비
- TabNavigation / Header / MobileNav / PageSkeleton 컴포넌트를 반응형으로 개선 — `useResponsive()` hook 기반 단일 분기 렌더링
- 헤더 알림 버튼을 NotificationCenter 드롭다운으로 교체
- 언어 선택 UI를 하드코딩에서 `localeNames` 기반 동적 생성으로 전환
- HtmlContent DOMPurify를 FORBID 방식으로 전환 (보안 기본값 항상 유지)
- HtmlEditor/HtmlContent를 extension_point로 변환
- 인기글 기간 필터 "전체" 옵션을 "연간"으로 변경

### Fixed

- 마이페이지 배송지 관리에서 수정 버튼에 권한 체크가 누락되어 있던 문제 수정
- 인기상품/신상품/최근본상품 섹션 반응형 개선 — grid 기반 카드 크기 제어 및 스크롤 버튼 breakpoint별 비활성화 처리
- 다크 모드 variant 누락 보완
- FileUploader 갤러리 이미지 깨짐 수정 — stale closure 문제
- 사용자 화면 버전 배지 표현식 수정
- 모바일 메뉴 통합검색 Enter 키 미작동 수정 — keydown 액션 누락
- 상품 상세 페이지 모바일에서 가격이 중복 표시되던 문제 수정 — 모바일/데스크톱 가격 섹션을 상호 배타적 조건부 렌더링으로 분리
- 상품 상세 모바일 가격 표시에 할인율 누락 수정 — PC와 동일하게 할인율 표시 추가
- 주문 상세 아이템의 상태 뱃지·배송정보·구매확정/리뷰 버튼이 DOM에 이중 렌더링되던 문제 수정 — CSS hidden 토글에서 조건부 렌더링으로 전환
- 장바구니 요약 패널이 모바일에서 sticky로 고정되어 스크롤 시 비정상 동작하던 문제 수정
- 모달 내부 Select 드롭다운이 잘려 보이는 문제 수정

## [1.0.0-beta.1] - 2026-04-01

### Changed

- 오픈 베타 릴리즈

## [0.5.1] - 2026-03-30

### Changed

- 마이페이지 프로필 국가 입력항목 및 표시 숨김 처리 (_edit.json, _view.json)

### Fixed

- 게시글 작성 폼의 첨부파일 안내 문구(최대 개수/용량)가 게시판 설정과 무관하게 고정 표시되던 문제 수정 — 게시판 설정값에 따라 동적으로 표시 (#228)
- 비로그인 사용자가 로그인이 필요한 게시판 접근 시 오류 화면이 순간 노출된 후 리다이렉트되던 문제 수정 — "로그인이 필요합니다" 안내 후 로그인 페이지로 이동 (#228)
- 본인이 작성한 댓글/답글의 수정·삭제 버튼이 표시되지 않던 문제 수정 (#228)
- 주문 상세·결제 페이지 배송지 다국어 키 누락 수정 — `mypage.order_detail.paid_at`, `shop.checkout.intl_*` 키 9개 추가 (ko/en mypage.json, shop.json)

## [0.5.0] - 2026-03-30

### Added

- 상품 상세 페이지에 1:1 문의 QnA 탭 화면 추가
  - 비로그인/로그인/관리자 권한에 따라 버튼 및 기능 분기
  - 문의 클릭 시 내용과 답변 인라인 펼침
  - 비밀글 토글 필터 (전체 / 비밀글 제외)
  - 문의 작성/수정/삭제 모달, 관리자 답변 등록/수정/삭제
  - 작성자명 마스킹, 비밀글 잠금, 페이지네이션
- 마이페이지에 상품 1:1 문의내역 탭 추가
  - 내 문의 목록, 답변 여부/내용 확인
  - 필터 탭 (전체 / 답변 대기 / 답변 완료)
  - 문의 수정, 삭제 (답변 유무에 따라 삭제 안내 모달 분기)
  - 문의 게시판 미설정 시 안내 화면 표시
- TabNavigation 컴포넌트: 모바일 배지 가로 배치 개선

## [0.4.34] - 2026-03-30

### Fixed

- 다크모드 전수 조사 및 수정 — TSX 컴포넌트 약 11건의 누락된 `dark:` variant 추가
  - FileUploader(FileList, SortableThumbnailItem, FileDropZone), Header, Modal 등
  - main.css: `.btn-primary`, `.btn-danger`, `.btn-secondary`, `.btn-ghost` 다크 variant 추가
- Select 컴포넌트 다크모드 텍스트 색상 누락 수정 — 커스텀 `bg-*` className 사용 시 텍스트 색상 자동 보충 로직 추가
- safelist에 `dark:hover:text-red-*`, `dark:hover:text-green-*`, `dark:focus:ring-blue-400` 패밀리 추가

## [0.4.33] - 2026-03-30

### Changed

- 페이지 전환 시 스켈레톤 UI → 스피너 로딩으로 변경 (transition_overlay style: skeleton → spinner)
  - PageLoading.tsx 컴포넌트 신규 추가 (스피너 아이콘 + 다국어 텍스트, 포지셔닝/배경/다크모드 자체 제어)
  - _user_base.json 및 mypage/*.json 7개 레이아웃 설정 변경
  - 다국어 번역 키 추가: nav.loading (ko: "로딩 중...", en: "Loading...")

## [0.4.32] - 2026-03-30

### Fixed

- FileUploader 상품 수정 시 이미지 순서 유실 버그 3건 수정 — customOrder 상태 도입으로 기존/신규 파일 간 드래그 순서 보존, handleDragEnd에서 customOrder 기반 정렬, 업로드 완료 즉시 pending ID→hash 교체 (useFileUploader.ts)
- FileUploader onReorder 콜백 추가 — 드래그 순서 변경 및 업로드 완료 시 부모에게 정렬된 파일 목록 전달 (useFileUploader.ts, FileUploader.tsx, types.ts)
- FileUploader 업로드 응답 ResponseHelper 이중 래핑 대응 — response.data.data ?? response.data 패턴 (useFileUploader.ts)

## [0.4.31] - 2026-03-29

### Fixed

- FileUploader mime_type null 방어 코드 추가 — optional chaining으로 startsWith 에러 방지 (useFileUploader.ts, SortableThumbnailItem.tsx, FileList.tsx, utils.ts)
- FileUploader 대표이미지 식별 hash 기반 전환 — primaryFileId 비교/이벤트에 hash 우선 사용, 복사 모드(id 없는 이미지) 삭제 시 서버 API 호출 스킵 (useFileUploader.ts, FileList.tsx, types.ts)
- FileUploader 복사 모드 이미지 삭제 후 재출현 버그 수정 — deletedIdsRef에 hash 추가 추적, initialFiles 동기화 시 hash 기반 필터링 (useFileUploader.ts)

## [0.4.30] - 2026-03-28

### Fixed

- FileUploader 업로드 후 'uploading' 항목 영구 잔류 버그 수정 — `response.data?.data` 이중 접근을 `response?.data`로 수정 (ApiClient가 Axios response.data를 이미 언래핑하므로), attachment null 시 에러 상태 전환 방어 로직 추가 (useFileUploader.ts) (#225)

### Added

- FileUploader 업로드 응답 파싱 테스트 추가 — renderHook 기반 훅 직접 테스트로 정상 파싱 및 null 응답 방어 검증 (FileUploaderUpload.test.ts) (#225)

## [0.4.29] - 2026-03-27

### Changed

- Toast 기본 위치를 top-right → bottom-center로 변경 — 우측 상단 버튼과의 겹침 해소 (#148)
- Toast 숨김 애니메이션 방향을 position에 따라 분기 처리 (bottom → 아래로, top → 위로)

## [0.4.28] - 2026-03-26

### Fixed

- FileUploader 컴포넌트 모달 내 사용 시 무한 렌더 루프로 인한 모달 닫기/등록 버튼 미작동 수정: `initialFiles = []` 기본값이 매 렌더마다 새 참조 생성 → useEffect 무한 실행 → startTransition 모달 닫기 렌더 영구 차단. 모듈 레벨 EMPTY\_FILES 상수 + useMemo 기반 참조 안정화로 해결 (FileUploader.tsx, useFileUploader.ts)

## [0.4.27] - 2026-03-26

### Fixed

- 리뷰 작성 모달 닫기/등록 버튼 클릭 불가 수정: `flex flex-col max-h-[75vh]` 이중 overflow 래퍼 제거 — `max-h-[75vh]`가 Tailwind CSS 빌드에 미포함되어 높이 제한 미적용 → 콘텐츠가 Modal 컴포넌트의 `max-h-[90vh] overflow-hidden` 경계를 초과하여 버튼 영역이 클리핑됨. 구매확정 모달과 동일한 플랫 구조로 변경 (\_modal\_write\_review.json)

## [0.4.26] - 2026-03-26

### Fixed

- FileUploader accept 검증에서 MIME 타입 와일드카드(image/*, video/* 등) 미지원 버그 수정 (useFileUploader.ts)
- 리뷰 작성 모달 닫기 불가 버그 수정: 모달 최상위 actions의 onClose 이벤트가 모달 닫기 기능 충돌 (\_modal\_write\_review.json)
- 리뷰 작성 모달 FileUploader 미지원 props 제거: showPreview, multiple, buttonLabel, maxFileSize → maxSize

### Added

- 리뷰 작성 모달 제출 버튼 스피너 표시 (구매확정 모달 패턴 동일: Icon spinner + animate-spin)

## [0.4.25] - 2026-03-25

### Fixed

- 주문 상세 쿠폰 할인코드 참조 키 수정: `discountCodes` → `discount_codes` (snake_case 정규화) (_items.json)

## [0.4.24] - 2026-03-25

### Added

- 주문 취소 모달: 주문금액 비교 테이블 (취소 전/후 13개 항목 비교 + 적색 하이라이트 + 다통화 인라인 표시)
- 주문 취소 모달: 쿠폰 상세 표시 (상품/주문/배송비쿠폰별 적용 쿠폰명 + 할인금액)
- 주문 취소 모달: 상품 소계 표시 (단가 × 수량 = 소계)
- 다국어 키 18개 추가 (ko/en — comparison_title, col_before, col_after 등)

### Changed

- 주문 취소 모달 크기 조정 (size: lg → width: 750px, max-w-full)
- 취소 모달 "환불 예정금액" 섹션을 "주문금액 비교" 테이블로 전면 교체

## [0.4.23] - 2026-03-25

### Added

- 주문 상세 상품 영역(_items.json): 체크박스 기반 상품 선택 + 상태 뱃지 + 배송사/운송장 표시 + 취소 버튼 이동
- 주문 상세(show.json): selectedItemIds, selectAllItems initLocal 상태 추가

### Fixed

- 주문 취소 모달(_modal_cancel.json): isOpen prop 기반 제어에서 openModal/closeModal 패턴으로 전환 — modals 섹션에서 모달이 열리지 않던 버그 수정
- 주문 취소 모달: 커스텀 헤더 제거(중복 닫기 버튼/빈 헤더 해결) — Modal title prop 사용
- 주문 취소 모달: cancelReason setState가 모달 격리 스코프에 기록되어 커스텀 핸들러에서 읽지 못하던 버그 수정 — $parent._local 패턴 적용
- 주문 취소 버튼: 상품 미선택 시 숨김 → 비활성화(disabled) 상태로 변경

## [0.4.22] - 2026-03-26

### Added

- 게시판 목록/상세에서 manager 권한 사용자 대상 "삭제된 게시글 포함" / "삭제된 댓글 포함" 토글 UI 추가
- 게시판 목록 카드/갤러리 타입에 삭제 글 포함 토글 UI 추가

### Fixed

- 대댓글 UI 들여쓰기 버그 수정
- PageSkeleton: Flex/Grid 컴포넌트의 레이아웃 props(justify, align, direction, gap 등)가 스켈레톤에서 누락되는 문제 수정 (resolveLayoutProps)
- UserInfo.tsx: AuthorInfo 인터페이스에 uuid 필드 누락 수정 (TS2339 빌드 오류)

## [0.4.21] - 2026-03-24

### Added

- 마이페이지 주문 취소 모달 확장: 부분취소 지원 (옵션별 수량 선택, 환불 예상 금액 표시)
- 주문 취소 관련 다국어 키 추가 (ko/en common, mypage)

### Changed

- 주문상세(show.json): 취소 버튼 조건 개선 (취소 가능 상태에서만 표시)

### Fixed

- 마이페이지 프로필에서 국가 선택 목록이 올바른 다국어 이름으로 표시되지 않던 문제 수정
- 마이페이지 프로필에서 언어 선택 목록이 앱 지원 언어 기준으로 표시되지 않던 문제 수정

## [0.4.20] - 2026-03-23

### Changed

- `_global.currentUser?.id` → `_global.currentUser?.uuid` 전환 (12개 파일, 24개 참조)
- Header.tsx: currentUser.id → currentUser.uuid 전환
- UserInfo.tsx: author.id → author.uuid 프로필 URL 전환
- _post_form.json: author.id → author.uuid 전환

### Fixed

- 글쓰기 버튼 abilities 비활성화 누락 수정 — `can_write !== true` 시 버튼 disabled 처리

## [0.4.19] - 2026-03-23

### Fixed

- 비회원 로그인 체크 시 `_global.currentUser` 빈 배열 truthy 오판 수정 — `?.uuid` 속성 체크로 변경 (7건: _info_summary, _welcome_card, _checkout_discount, _checkout_items, _checkout_mileage)
- 쿠폰 다운로드 conditions 핸들러 `"else"` → `"then"` 수정 — else 키 미지원으로 login_required_modal 미동작 (2건: _info_summary)

## [0.4.18] - 2026-03-23

### Fixed

- UserInfo 드롭다운 메뉴가 overflow-hidden 부모(카드/갤러리)에 가려지는 문제 — React Portal(createPortal)로 document.body에 fixed 렌더링, 스크롤/리사이즈 시 자동 닫기 추가
- 사용자 작성글(users/posts) 페이지네이션 무반응 수정 — setState key/value 리터럴 저장 + refetch 잘못된 핸들러명 → navigate+mergeQuery 패턴으로 변경, URL 동기화 지원

## [0.4.17] - 2026-03-23

### Added

- Hr (수평선) basic 컴포넌트 추가 — HTML `<hr>` 래퍼, className 지원

## [0.4.16] - 2026-03-21

### Added

- 마이페이지 6개 레이아웃에 3단계 스켈레톤 타겟팅 적용
  - `transition_overlay` override: `target: "mypage_tab_content"`, `fallback_target: "main_content_area"`
  - 마이페이지→마이페이지 탭 전환: 탭 컨텐츠 영역만 스켈레톤 (탭 네비 유지)
  - 헤더→마이페이지 전환: 컨텐츠 영역만 스켈레톤 (헤더 유지)
  - 초기 로드(직접 URL): 전체 페이지 스켈레톤
  - 대상: orders, profile, board, addresses, wishlist, notifications
- PageSkeleton: Header/Footer 컴포지트 스켈레톤 추가 — fullpage 스코프에서 헤더/푸터 구조 표현

### Fixed

- PageSkeleton: `sanitizeClassName`에서 standalone `border` 클래스 미제거 — 스켈레톤 요소에 검정 테두리 누출 방지
- PageSkeleton: `filterChildren` 상호 배타적 값 분기(`=== 'value'`) 감지 — 게시물 상세 등에서 type별 조건부 블록 중복 렌더링 방지
- PageSkeleton: 텍스트/버튼 스켈레톤 크기 실제 비율에 맞게 조정 (H1 w-2/5→w-3/5, Span w-1/6→w-1/4, Button w-20→w-24)
- PageSkeleton: children 있는 Button 컨테이너에 스켈레톤 경계(border+bg) 추가 — 버튼 형태 시각적 표현

## [0.4.15] - 2026-03-21

### Fixed

- PageSkeleton: children이 있는 Button을 컨테이너로 처리 — 게시판 행 래퍼(Button > desktop/mobile Div) 정상 렌더링
- PageSkeleton: empty state 조건(`=== 0`, `.length === 0`, `.total === 0`) 부정 조건 감지 추가 — `_empty_states.json` 스킵
- PageSkeleton: 비-컨테이너 컴포넌트 className sanitize 적용 — 스켈레톤 바에 원본 색상(bg-red-500 등) 누출 방지

## [0.4.14] - 2026-03-21

### Fixed

- PageSkeleton: Tailwind 반응형 display 클래스(`hidden lg:grid`, `lg:hidden`) JS 런타임 해석 추가
  - `resolveResponsiveDisplay()` — `window.innerWidth` 기준 Tailwind cascade 적용
  - 데스크톱에서 `hidden lg:grid` → `grid`, 모바일에서 `lg:hidden` → 숨김 처리
- PageSkeleton: arbitrary `grid-cols-[...]` 값을 inline `gridTemplateColumns` style로 변환
  - Tailwind 빌드 CSS에 포함되지 않는 동적 grid 값 지원
- PageSkeleton: 로딩/에러 wrapper 컨테이너 스킵 — 모든 자식이 부정 조건(`!data`, `hasError`)인 경우 컨테이너 자체 제거
- PageSkeleton: 표현식/반응형 해석 파이프라인 정리 — `renderSkeletonNode`에서 일괄 처리 (표현식 → 반응형 → sanitize → grid inline style)

## [0.4.13] - 2026-03-21

### Added

- PageSkeleton 컴포넌트 — 레이아웃 JSON 컴포넌트 트리 기반 동적 스켈레톤 UI 렌더러 (engine-v1.24.0)
  - 컴포넌트 트리를 재귀 순회하여 텍스트→바, 인풋→사각형, 미디어→큰 사각형 등 자동 생성
  - DataGrid, Tabs 등 복합 컴포넌트 특화 스켈레톤 지원
  - iteration 블록 기본 반복 횟수 설정 가능
  - pulse/wave/none 애니메이션 지원, 다크 모드 호환
  - 접근성: role="status", aria-busy="true"

### Changed

- `_user_base.json` transition_overlay를 skeleton 스타일로 변경 (opaque → skeleton)

## [0.4.12] - 2026-03-21

### Changed

- `_user_base.json` 페이지 전환 오버레이를 엔진 레벨 `transition_overlay`로 교체 (engine-v1.23.0)
  - React 컴포넌트(PageTransitionBlur) → 순수 DOM 조작으로 변경 (stale flash 근본 해결)
  - `target: "main_content_area"` 지정으로 헤더/네비게이션은 유지, 콘텐츠 영역만 오버레이

### Removed

- `_user_base.json`에서 PageTransitionBlur 컴포넌트 제거 (transition_overlay로 대체)

## [0.4.11] - 2026-03-21

### Added

- PageTransitionBlur 컴포넌트 — 페이지 전환 시 전체 콘텐츠 블러 오버레이 (TransitionManager 구독)
  - `_user_base.json`에 배치, 레이아웃 전환 시 stale DOM flash 방지
  - backdrop-blur-sm + bg-white/30 dark:bg-gray-900/30 + pointer-events-none
  - PageTransitionIndicator(z-50)보다 아래 레이어(z-40)에 위치
- `UserInfo` 컴포넌트 `subTextTitle` prop 추가 — 날짜 tooltip 지원

### Changed
- 게시판 레이아웃 날짜 표시 — `created_at` → `created_at_formatted` + `title` tooltip 적용 (14개 파일)

### Fixed
- `UserInfo` 드롭다운이 카드형/갤러리형에서 잘리거나 겹치는 문제 수정 (`absolute` → `fixed` 포지션)
- 블라인드/삭제 게시글의 `UserInfo` 드롭다운 메뉴가 투명하게 보이던 문제 수정
- Font Awesome Pro 아이콘 4종 → Free 버전으로 교체

## [0.4.10] - 2026-03-19

### Added

- seo-config.json `header_nav` 렌더 모드 — Header 컴포넌트 SEO 렌더링: 사이트명 링크 + 정적 네비게이션($t: 다국어 키) + boards iterate 네비게이션
- seo-config.json `footer_nav` 렌더 모드 — Footer 컴포넌트 SEO 렌더링: 사이트명 + 소셜 링크 + 커뮤니티/정보/정책 링크 그룹($t: 다국어 키) + 저작권 + Powered by
- Header/Footer component_map `render` 속성 변경 — `text_format` → `header_nav`/`footer_nav` 전환

## [0.4.9] - 2026-03-19

### Added

- _user_base.json `meta.seo.data_sources` 에 `boards` 추가 — SEO 렌더링 시 게시판 목록 데이터 사전 로드
- _user_base.json `meta.seo.vars.site_name` 추가 — Header/Footer seoVars 치환용

## [0.4.8] - 2026-03-19

### Added

- seo-config.json `seo_overrides` 섹션 추가 — `_local.collapsedReplies` 와일드카드 오버라이드로 대댓글 SEO 강제 펼침
- seo-config.json `pagination_links` 렌더 모드 + Pagination 컴포넌트 렌더 설정 추가

## [0.4.7] - 2026-03-19

### Added

- seo-config.json Avatar/UserInfo text_format 렌더 모드 추가 — 댓글 작성자 닉네임, 작성일 SEO HTML 출력 (`{author.nickname}` dot notation)

## [0.4.6] - 2026-03-19

### Added

- _user_base.json에 기본 SEO 설정 추가 (`enabled: false`, `og.type: "website"`) — 자식 레이아웃 SEO 상속 기반 마련

## [0.4.5] - 2026-03-18

### Changed

- 위시리스트 레이아웃 페이지네이션 경로 수정 — `WishlistCollection` 응답 구조 대응 (`wishlist.data.total` → `wishlist.data.pagination.total` 등)

## [0.4.4] - 2026-03-18

### Added

- seo-config.json에 `product_card_view` fields 렌더 모드 추가 — ProductCard 컴포넌트의 상품 정보(이미지, 가격, 할인, 라벨 등) SEO HTML 생성

### Fixed

- 상품 목록 레이아웃 데이터 경로 수정 (`products?.data` → `products?.data?.data`) — ProductCollection 응답 구조 대응
- seo-config.json Header/Footer format 변수명 수정 (`siteName` → `site_name`) — seoVars 주입 키와 일치

### Changed

- shop/index.json SEO data_sources에 `categories` 추가 — 카테고리 데이터 SEO 활용

## [0.4.3] - 2026-03-18

### Added

- seo-config.json에 `text_props`, `attr_map`, `allowed_attrs` 선언 추가 — 텍스트 추출, 속성 매핑, 허용 속성을 템플릿 수준에서 선언
- 레이아웃 JSON `meta.seo`에 `page_type`, `toggle_setting`, `vars` 선언 추가 — SEO 변수 치환, 페이지 유형, 모듈 SEO 토글을 레이아웃 책임으로 이전

### Changed

- SEO 엔진 하드코딩 제거 — text_props/attr_map/allowed_attrs, vars/page_type, toggle_setting 모두 선언적 설정으로 이전

## [0.4.2] - 2026-03-18

### Added

- seo-config.json Icon 컴포넌트 `name_to_class` 속성 추가 — name prop을 Font Awesome CSS class로 변환
- seo-config.json Header/Footer 컴포넌트 `text_format` 렌더 모드 적용 — 빈 태그 방지

### Fixed

- seo-config.json image_gallery src 필드 패턴에 `download_url` fallback 추가 — DB url 컬럼 비어있을 때 API 서빙 URL 사용

## [0.4.1] - 2026-03-18

### Fixed

- seo-config.json image_gallery 렌더 모드 alt 필드 패턴 수정 (`{alt}` → `{alt_text_current|alt_text|alt}`, `{url|src}` → `{url|src|image_url}`)

## [0.4.0] - 2026-03-18

### Added

- seo-config.json에 기본 컴포넌트 30개 매핑 추가 (Div, Span, P, H1-H6, A, Button, Img 등)
- seo-config.json에 render_modes 섹션 추가 (image_gallery, tab_list, text_format, html_content)
- seo-config.json에 self_closing 태그 목록 추가 (img, input, hr, br)

### Changed

- SEO 컴포넌트→HTML 매핑을 엔진 하드코딩에서 seo-config.json 선언적 설정으로 이전

## [0.3.2] - 2026-03-18

### Added

- 상품 상세 리뷰 탭 구현
  - 별점 통계: 평균 별점 및 1~5점 분포 바 표시
  - 옵션 필터: 구매 옵션별 리뷰 필터링
  - 포토리뷰 필터: 이미지 있는 리뷰만 보기
  - 정렬: 최신순 / 별점 높은순 / 별점 낮은순
- 리뷰 이미지 미리보기 모달 (슬라이더, 이전/다음 탐색)
- 리뷰 이미지 최대 4개 그리드 표시, 초과 시 +N 오버레이
- 상품 카드에 평균 별점 및 리뷰수 표시
- 리뷰 관련 다국어 키 추가 (한국어/영어)

### Fixed

- 별점 분포 바 너비 계산 수정
- 개별 리뷰 별점 아이콘 표시 수정

## [0.3.1] - 2026-03-17

### Fixed

- 로그인 성공 시 "이미 로그인되어 있습니다" 중복 토스트 수정
- 프로필 메뉴 주문내역/찜목록 navigate 경로 수정
- 주문 배송지 변경 openModal 핸들러 포맷 수정 (`params.modalId` → `target`)
- 마이페이지 배송지 삭제 버그 수정 — `handler: "confirm"` → setState + openModal 모달 패턴 전환
- 마이페이지 배송지 기본배송지 체크 버그 수정 — `editingAddress: null` → `{ is_default: false }` 초기화
- 마이페이지 배송지명 `[object Object]` 표시 수정 — Form auto-binding name prop 제거
- 마이페이지 배송지 카드 간격 미적용 수정 — iteration/space-y 요소 분리
- 배송지 덮어쓰기 모달 truthy 체크 수정 (`editingAddress?.id`)

### Added

- 마이페이지 배송지 삭제 확인 모달 (스피너 처리 상태 포함)
- 배송지 삭제 관련 다국어 키 추가 (`delete_title`)

### Changed

- 마이페이지 배송지 수정/삭제 아이콘 버튼 → 텍스트 버튼 변경
- 마이페이지 배송지 기본배송지 라벨-체크박스 연동 (htmlFor)

## [0.3.0] - 2026-03-07

### Added
- 마이페이지 배송지 관리 화면 (목록, 추가/수정/삭제 모달)
- 체크아웃 배송지 저장 체크박스 및 배송지명 입력
- 체크아웃 배송지 관리 모달 (신규 배송지 추가/선택)
- 주문상세 배송지 변경 모달
- 배송지명 중복 덮어쓰기 확인 모달
- 마이페이지/체크아웃 배송지 관련 다국어 키 추가 (ko/en)

### Changed
- 체크아웃 배송비 표시 영역 배송지 변경 연동 개선
- 체크아웃 주문요약 결제수단 바인딩 수정 (`_computed.selectedPaymentMethod` 사용)

### Fixed
- 체크아웃 무통장입금 결제수단이 'card'로 잘못 전송되던 문제 수정
- 배송지 변경 시 배송비 미재계산 수정
- 배송지 모달 폼 전송 실패 수정

## [0.2.7] - 2026-03-16

### Fixed

- 상품 그리드 페이지네이션 수정 — ServerSidePagination 컴포넌트 전환
- 검색 필터 바 카테고리 경로 수정

### Changed

- 라이선스 프로그램 명칭 정비

## [0.2.6] - 2026-03-13

### Added
- 이미 신고한 게시글/댓글의 신고 버튼 비활성화 처리 추가

### Changed
- 카드형/갤러리형 게시글 목록 레이아웃 재설계 — UI 구조 및 표시 방식 개선
- 카드형 게시판 좌우 여백 및 썸네일 이미지 모서리 디자인 개선
- 블라인드 처리된 게시글 안내 메시지 통일

### Fixed
- 존재하지 않는 게시글 접근 시 404 페이지로 이동하지 않던 문제 수정

## [0.2.5] - 2026-03-12

### Added
- 신고 모달 자동 블라인드 사유 다국어 키 추가 (ko/en)
- manifest에 license 필드 및 LICENSE 파일 추가

### Changed
- 신고 모달 상세 사유 입력란 플레이스홀더 문구 수정

## [0.2.4] - 2026-03-11

### Changed
- 게시판 레이아웃 10파일의 `permissions` → `abilities`, `user_permissions` → `user_abilities` 키 수정 — 백엔드 abilities 표준에 맞춤
- 상품 Q&A 탭의 `permissions?.posts_create` → `abilities?.can_write` 키 수정
- 마이페이지 배송지 삭제 버튼 조건을 `!address.is_default` → `address.abilities?.can_delete === true` 변경

## [0.2.3] - 2026-03-10

### Fixed
- 댓글에서 답글 버튼이 게시판 설정과 무관하게 표시되지 않는 버그 수정
- 댓글 더보기 메뉴(답글/수정/삭제)가 조건에 따라 올바르게 표시되지 않는 버그 수정

## [0.2.2] - 2026-03-09

### Added
- 게시판 index/show/form 유형별 레이아웃 구조 개편 — 유형별 UI를 `partials/board/types/유형/` 독립 partial로 분리하여, 새 유형 추가 시 기존 파일 수정 없이 새 파일 생성만으로 완결되도록 개선
- 게시글 이전/다음글 네비게이션을 별도 partial로 분리

### Changed
- 갤러리형 게시글 목록 레이아웃의 `permissions?.can_view` 바인딩을 `abilities?.can_view`로 변경 (코어 표준화)

### Fixed
- 비밀글 수정폼에서 비밀번호 확인 후 게시글 내용이 표시되지 않던 문제 수정
- 게시글 상세 답글 목록이 반복 렌더링되지 않던 표현식 오타 수정

## [0.2.1] - 2026-03-06

### Fixed
- 상품 상세 쿠폰 배지 데이터 경로 수정 (`productDownloadableCoupons.data` → `.data?.data`)
- 쿠폰 배지/확인 모달 API 필드 매핑 수정 (`discount_type`/`discount_value` → `benefit_formatted`, `name` → `localized_name`, `id` → `coupon_id`)
- 다운로드 완료 쿠폰 비활성화 상태 추가 (`is_downloaded` 기반 disabled + 아이콘 전환)
- 쿠폰 다운로드 후 상품 상세 쿠폰 목록 즉시 갱신 (`refetchDataSource` 추가)
- 핸들러명 수정: `refreshDataSource`(미존재) → `refetchDataSource`

## [0.2.0] - 2026-03-06

### Added
- 쿠폰 다운로드 모달 (`_modal_coupon_download.json`) — 3-상태 분기(로딩/빈상태/데이터), 페이지네이션, 다운로드 버튼
- 쿠폰 다운로드 확인 모달 (`_modal_coupon_download_confirm.json`) — 상품 상세 개별 쿠폰 다운로드 확인
- 로그인 필요 안내 모달 (`_modal_login_required.json`) — 비로그인 사용자 쿠폰 다운로드 시도 시
- 상품 상세 쿠폰 배지 섹션 (`_info_summary.json`) — 다운로드 가능 쿠폰 뱃지 표시, 로그인/비로그인 분기
- 체크아웃 할인 섹션에 쿠폰 다운로드 버튼 추가 (`_checkout_discount.json`)
- `checkout.json` — 쿠폰 다운로드 모달 연동 (initLocal 상태 + modals 등록)
- `show.json` — 쿠폰 데이터소스 + 모달 3종 등록 + init_actions 상태 초기화
- 쿠폰 다운로드 다국어 키 21건 추가 (ko/en `shop.json`)

## [0.1.5] - 2026-03-05

### Changed
- 신고 모달 내부 상태 구조 개선 — 모달에서 정상적으로 데이터 접근 가능하도록 수정
- 신고 모달 취소 시 입력 내용 초기화 추가

### Fixed
- 신고 모달에서 사유 미선택 시 콘솔 오류가 발생하던 문제 수정
- 게시글 상세 페이지 신고 폼 변수명 오타 수정

## [0.1.4] - 2026-03-03

### Changed
- 게시글 목록 답글 depth 시각화 — `style.marginLeft` 동적 계산 (`depth * 1rem`, 최대 10rem, 데스크톱·모바일 동일 적용)
- 사용자 답글 버튼 — 공지글이 아니고 최대 답글 깊이 미만인 게시글에만 표시되도록 조건 수정
- 마이페이지 "내가 쓴 댓글" 탭 — Post 집계 기반 → `/me/my-comments` Comment 기반 페이지네이션 전환
- 마이페이지 게시판 `myComments` data source `auto_fetch: false` 설정 (기본 탭 진입 시 불필요한 API 호출 방지)

## [0.1.3] - 2026-03-02

### Changed
- Footer JSDoc 주석 저작권 연도 2025 → 2026 변경

## [0.1.2] - 2026-02-27

### Changed
- 코어 페이지 기능을 sirsoft-page 모듈로 전환
- 페이지 조회 API를 모듈 엔드포인트(`/api/modules/sirsoft-page/pages/{slug}`)로 변경
- 회원가입 레이아웃에서 페이지 버전 관련 바인딩 제거
- 통합검색 페이지 탭을 모듈 API 기반으로 전환 (contents/policies → pages 통합)
- sirsoft-page 모듈 의존성 버전 조건 수정 (>=0.1.2 → >=0.1.1)

### Removed
- 코어 페이지 API 참조 제거 (회원가입 약관/개인정보 data_source)
- user_consents 관련 version 바인딩 제거

## [0.1.1] - 2026-02-24

### Changed
- 버전 체계 조정 (정식 출시 전 0.x 체계로 변경)
- 모듈 의존성 버전 조건 수정 (>=1.0.0 → >=0.1.1)

## [0.1.0] - 2026-02-23

### Added
- 사용자 기본 템플릿 초기 구현
- 템플릿 구조 (template.json, routes.json)
- 기본 컴포넌트 세트 (Basic 30개, Composite 18개, Layout 4개)
- 인증 화면 (로그인, 회원가입, 비밀번호 찾기/재설정)
- 게시판 화면 (목록, 상세, 작성/수정, 인기글)
- 쇼핑몰 화면 (상품 목록/상세, 장바구니, 주문/결제, 주문 완료)
- 마이페이지 (프로필, 주소 관리, 주문 내역, 위시리스트, 알림)
- 검색 화면 (통합 검색, 카테고리별 탭)
- 정책 페이지 (이용약관, 개인정보처리방침, 환불정책, FAQ, 소개, 문의)
- 사용자 프로필 공개 페이지
- 에러 페이지 (403, 404, 500, 503)
- 커스텀 핸들러 (장바구니, 통화 포맷, 상품 옵션, 테마 전환, 스토리지)
- 다크 모드 지원
- 반응형 레이아웃
- 다국어 지원 (ko, en)
- 다중 통화 지원
