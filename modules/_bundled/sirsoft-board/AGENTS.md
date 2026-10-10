# 그누보드7 게시판 모듈 — 에이전트 가이드

> 이 문서는 이 모듈을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 모듈 (sirsoft-board) — 게시판·게시글·댓글·신고·게시판별 알림설정 도메인. 관리자 CRUD + 공개 API 만 소유하고, 방문자 화면(목록/상세/글쓰기)은 템플릿이 그린다
2. 확장 방식: 발행 훅 90개(전량 action/filter) — 새 콘텐츠 타입 연동은 `EcommerceInquiryHookListener` 식 필터 훅 위임 패턴을 참고
3. 건드리면 안 되는 것: 비밀글 게이팅(`SecretContentGate`)을 우회하는 신규 조회 경로, `chunk()`(OFFSET) 로 삭제·갱신 순회, count 컬럼(posts_count 등) 직접 갱신
4. 작업 위치: `modules/_bundled/sirsoft-board` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan module:update sirsoft-board --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
게시판·게시글·댓글·신고·게시판별 알림설정을 소유하는 콘텐츠 도메인 모듈입니다. 운영자가
`/admin/boards`에서 게시판을 자유롭게 생성(게시판 유형 `basic`/`gallery`/`card` 선택, 비밀글·
답변형·트리거 기반 관리자 알림 등 게시판별 세부 설정)하면, 그 게시판마다 동적 권한
(`sirsoft-board.{slug}.*`)·역할(`{slug}.manager`/`{slug}.step`)·메뉴가 자동 생성됩니다 —
게시판 하나하나가 사실상 독립된 작은 확장처럼 자기 권한 체계를 갖습니다.

**소유 범위는 관리자 CRUD + 공개 API 까지입니다.** 방문자가 실제로 보는 목록/상세/글쓰기
화면은 이 모듈이 그리지 않습니다 — `resources/layouts/` 46개가 전부 `admin` 그룹인 것이
그 증거입니다. 공개 조회·작성은 `routes/api.php` 의 `boards.*` 라우트(비회원도 접근하는
`optional.sanctum`)로만 나가고, 그 API 를 소비해 실제 화면을 그리는 것은 템플릿(`sirsoft-basic`)
쪽 책임입니다. 그래서 이 모듈에 의존하는 확장은 지금 템플릿 하나뿐입니다 — 새 방문자 화면이
필요하면 이 모듈이 아니라 그 화면을 쓰는 템플릿/모듈 쪽에 레이아웃을 추가합니다.

**설계 원칙**: 게시판은 이커머스 문의·후기처럼 "게시판을 흉내 낸" 다른 도메인의 콘텐츠 저장소로도
쓰입니다. 그래서 이 모듈을 다른 도메인이 재사용하는 지점은 코드 결합이 아니라 **필터 훅
위임**(`EcommerceInquiryHookListener`)입니다 — 이커머스 모듈이 자기 문의 게시판을 만들 때
`sirsoft-board` 를 직접 `use` 하지 않고, board 쪽 흐름 중간에서 자기 로직으로 갈아끼웁니다.

**의도적으로 하지 않는 것**: 게시판 콘텐츠에 대한 실시간 브로드캐스트(WebSocket)는 제공하지
않습니다 — 알림은 전부 코어 `GenericNotification`(mail/database) 경유이며, 새로고침 없이
갱신되는 목록 같은 기능은 이 모듈의 범위 밖입니다. 또한 비밀글 판정은 이 모듈이 API 계층에서
전량 서버측으로 강제합니다(`SecretContentGate`, KVE-2026-1914) — 클라이언트가 비밀글 여부를
판단해 화면만 가리는 방식은 쓰지 않습니다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `module.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `module.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan module:update sirsoft-board --force` (빌드 불필요) |
| `resources/routes/` | 라우트 → 레이아웃 매핑 (분할) | `php artisan module:update sirsoft-board --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-board --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan module:update sirsoft-board --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-board --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan module:update sirsoft-board --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**게시판 생성**: `Admin\BoardController` → `StoreBoardRequest`(다국어 이름·slug 유일성·게시판
유형 검증) → `BoardService::createBoard()` → `BoardRepository` 로 `boards` 행 생성 후, 같은
트랜잭션 안에서 `BoardPermissionService`(동적 권한 3계층 생성) → 역할(manager/step) 생성 →
관리자 메뉴 등록까지 연쇄 실행됩니다. 이 연쇄 때문에 `getDynamicPermissionIdentifiers()` /
`getDynamicRoleIdentifiers()` / `getDynamicMenuSlugs()` 가 `boards` 테이블을 다시 조회해
전수를 재구성합니다 — 게시판 삭제·정리(clean-up) 시 "stale 판정"의 기준이 이 세 메서드입니다.

**게시판 권한·담당자 저장 → 권한 부여 상한**: 게시판 권한에 역할을 붙이거나 떼는 것
(`BoardPermissionService::assignRolesToPermission()` — 게시판 생성·수정·환경설정 일괄 적용이 모두
이 한 곳을 지난다)과 게시판 관리자·스텝 명단을 바꾸는 것(`BoardService::syncBoardRoleUsers()`),
신고 관리 권한의 역할 재할당(`syncModulePermissionRoles()`), 환경설정의 게시판 기본 권한 저장
(`assertDefaultBoardPermissionsChangeAllowed()` — 바뀌는 키를 기존 모든 게시판에서 가진 액터만)은 모두 **권한을 주는 조작**이라 코어
`PermissionEscalationGuard` 의 상한을 받습니다 — 액터가 그 권한을 전체 범위로 가졌을 때만 역할을
바꿀 수 있고, 명단은 그 역할이 담은 권한을 모두 가진 액터(슈퍼관리자·그 게시판의 기존 관리자)만
바꿉니다(KVE-2026-2367). 게시판을 새로 만들 때 설정 기본값이 정한 역할(기본 ACL)은 검사하지 않고
그 위에 얹는 역할만 판정하며, 바뀌지 않은 키·명단(관리자 폼의 평소 저장)은 검사하지 않습니다.
새로 만든 게시판의 관리자 명단이 생성자 본인 한 명뿐이면 명단 상한을 보지 않습니다(새 게시판의 관리자가
되는 것은 기존 게시판에 대한 상승이 아니다). 이때 스텝을 관리자보다 먼저 판정해, 방금 얻은 관리자 역할로
같은 요청의 스텝 지정이 통과하지 않게 합니다. 환경설정 「관리자·스텝 일괄 지정」의 「모든 게시판」은 역할을
줄 수 없는 게시판만 `not_grantable` 로 건너뛰고(고른 게시판은 403), 건너뛴 게시판의 명단은 그대로입니다.
바꿀 것이 없던 게시판(「제거」 인데 그 회원이 없거나 「추가」 인데 이미 있는 게시판)은 `unchanged_count` 로 따로 세어
`updated_count + skipped_count + unchanged_count` 가 대상 게시판 수와 맞습니다. 카드 결과 줄과 완료 메시지(`bulk_members_success`
의 `:unchanged`)가 이 수를 「변화 없음 N개」로 보입니다 — 응답 필드를 바꾸면 카드 결과 줄의 `unchanged=` 인자도 함께 맞춥니다.
관리자·스텝 역할 행이 없는(데이터 손상) 게시판은 `role_missing` 사유로 `skipped` 에 실어 카드 결과 줄에 보입니다(서버 로그 경고만으로는 운영자가 알 수 없다). 카드의 게시판 목록 조회가 403 이면 「권한 없음」, 그 밖의 실패면 「불러오지 못함」 이유를 보입니다(`boards_list` 의 error-handling 이 남기는 `_local.bulkMembersBoardsError`).
위반은 `PermissionEscalationException` → 403 이고, 게시판 생성·수정은 트랜잭션이라 같은 요청의
다른 변경(이름·앞선 키의 매핑·새 게시판 행)도 함께 되돌아갑니다.
환경설정의 일괄 적용 확인 창은 저장·적용이 실패(상한 403 포함)해도 창을 닫지 않고 방금 시도한
항목·게시판을 그대로 보여 주며, 거부 사유는 서버 메시지 토스트로 알립니다 — 실패 분기에서
`_global.bulkApplySnapshot` 을 비우면 창이 「0개 · 전체 게시판」을 안내하게 됩니다.

**게시글 작성 → 비밀글 게이팅**: `User\PostController` → `StorePostRequest`
(`sirsoft-board.post.store_validation_rules` 필터로 게시판별 커스텀 규칙 추가) →
`PostService::createPost()` (`before_create`→`filter_create_data`→`after_create` 훅 순서,
`sirsoft-board.post.user_create` IDV 정책 게이트 통과 후) → `PostRepository`. 조회 시에는
`PostResource` 가 `SecretContentGate` 로 비밀글 여부·열람 권한을 판정해 `content`/`title`/
`reply`/`attachments` 를 마스킹합니다 — 이 판정은 댓글 목록·첨부 다운로드에도 **개별
재적용**됩니다(부모 글에서 한 번 판정하고 끝나지 않음, KVE-2026-1914).

**게시글 열람 → 조회수**: `User\PostController::show()` 가 권한 판정을 통과한 열람만
`PostService::incrementViewCountOnce()` 로 셉니다. 1회 판정 키는 로그인 회원이면 회원 번호,
비로그인이면 IP 이고(API 는 세션이 없는 무상태 요청), 유효시간은 `spam_security.view_count_cache_ttl`
입니다. SEO 렌더러가 서버 자신에게 보내는 내부 호출(글 작성·수정 직후 캐시 재생성, 검색 봇 방문)은
코어 `App\Seo\SeoRenderRequest::matches()` 로 걸러 세지 않습니다 — 세면 글을 쓰자마자 조회수가
1 이 되고 서버 IP 키가 같은 IP 의 비로그인 방문을 「이미 봄」으로 막습니다.

**신고 접수 → 처리**: `User\ReportController` → `StoreReportRequest` → `ReportService::create()`
(`before_create`→`filter_create_data`→`after_create`) → 신고 접수 관리자 알림 발송. 관리자가
`Admin\ReportController` 에서 처리(블라인드/삭제/복원)하면 `ReportService` 가 대상 게시글/댓글
서비스(`PostService`/`CommentService`)를 호출해 실제 콘텐츠 상태를 바꾸고, 그 결과가
`report_action`/`post_action` 알림으로 원 작성자에게 통지됩니다. 신고 삭제·일괄 처리는
관리자 민감 작업 IDV 정책(`report.delete`/`report.bulk_action`)이 걸려 있습니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 105개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 95개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 19개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 7개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 2개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 7개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 90개는 전부 `action`/`filter` 이며 코어 브로드캐스트 채널은 쓰지 않습니다(구독 훅
목록의 이커머스 8개가 예로 보여주듯, **콘텐츠를 다른 도메인이 재사용하는 자리는 훅이지 상속이
아닙니다**). 새 게시판 세부 검증 규칙을 추가하려면 `board.store_validation_rules`/
`update_validation_rules` filter 를, 게시판 생성 후 부가 리소스를 함께 만들려면
`board.after_create` action 을 잡습니다. 게시글/댓글의 `before_*`→`filter_*_data`→`after_*`
3단 패턴은 전 도메인(board/comment/report/attachment)에 동일하게 반복되므로, 하나를 배우면
나머지에 그대로 적용됩니다. 훅 리스너 16개 중 `EcommerceInquiryHookListener` 는 이 모듈의
CRUD 흐름 자체를 **자기 도메인으로 대체**하는 가장 무거운 형태의 확장 사례입니다 — 새로운
"게시판을 흉내 낸 도메인"을 만들 때 참고할 선례입니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan module:update sirsoft-board --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=module:sirsoft-board` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 관리자 템플릿의 빌드된 CSS 에 존재하는지 확인하고 없으면 `resources/css/main.css` 에 `board-` 접두 클래스로 정의한 뒤 `php artisan module:build sirsoft-board --production`
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 한국어 문구에 「이(가)」「(으)로」처럼 조사를 괄호로 남기거나 자리표시 바로 뒤에 조사를 붙이지 않는다(값의 받침에 따라 틀린 문장이 된다) — 「값이」「상태로」「에 대해」처럼 받침과 무관한 말로 잇는다. 새 FormRequest 에 `in`·`exists`·`required_with` 같은 규칙을 두면 칸별 문구를 주거나 `Concerns\ProvidesFallbackRuleMessages` 를 쓴다(코어 기본 문구는 조사 자리표시를 남긴다). 잠금: `tests/Unit/Lang/KoreanParticlePlaceholderTest.php`
- [ ] 검증 문구의 항목 이름 자리에 필드 키(`sort`, `board id`)가 보이지 않게 한다 — 새 요청 칸은 `attributes()` 로 이름을 주거나, 게시판 공용 이름 `validation.fallback_attributes`(ko·en·ja 팩 모두)에 더하고 요청에 `Concerns\ProvidesFallbackRuleMessages` 를 쓴다. 배열 항목(`키.*`)은 부모 칸 이름을 물려받는다. 잠금: `tests/Feature/ValidationAttributeNameTest.php`(게시판 요청 전부를 ko·en 으로 조립해 본다)
- [ ] 비밀글이 관여하는 새 조회 경로(댓글·첨부·검색 결과 등)를 추가할 때 `SecretContentGate` 재적용 (KVE-2026-1914 — 부모에서 한 번 판정하고 끝나지 않는다)
- [ ] `boards`/`board_posts`/`board_comments` 의 count 컬럼(`posts_count`/`comments_count`/`replies_count`/`attachments_count`)은 훅 리스너(`*CountSyncListener`)가 갱신 — Service 에서 직접 증감 금지
- [ ] 게시판 삭제 시 `getDynamicPermissionIdentifiers()`/`getDynamicRoleIdentifiers()`/`getDynamicMenuSlugs()` 가 최신 상태를 반영하도록 동일 트랜잭션에서 정리 (module.php `uninstall()` 의 `chunkById` 패턴 참고 — OFFSET 순회로 삭제 금지)
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan module:update sirsoft-board --force`
- [ ] 개인정보 보유기간 핸들러(`src/Privacy/`)를 고칠 때는 건수(`preview()`)·대상 명단(`targets()`)·실행(`process()`)이 **같은 조건식**을 쓰는지, 명단 권한(`targetPermissions()` = 설정 읽기 + 게시판 관리 읽기)과 식별 최소 열(본문·첨부 제외)을 지키는지 확인 — 계약 전체는 [코어 개인정보 보유기간 처리 가이드](../../../docs/backend/privacy-retention.md)
- [ ] 새 `.g7.html` 을 만들었다면 저작 트랙 종료 게이트(`LayoutMarkup` PHPUnit 스위트)의 코퍼스 목록에 등재하고, 파일 헤더·데이터소스·모달·액션 블록 주석(S21, `docs/frontend/layout-markup.md` §10.5)을 채운다 — 굽기·왕복이 초록이어도 이 스위트만 미등재·미주석을 잡는다(2026-09-26 develop-7.1.0 1차 병합 판독: 게시판 개인정보 조치 창이 미등재·미주석으로 들어왔다).
- [ ] 수정 라우트와 등록 라우트가 한 레이아웃을 공유하는 폼(`admin_board_form`·`admin_board_post_form`)은 생성 조건 `init_actions` 에서 `form: null` 로 직전 레코드를 비우고, 생성 모드에서도 폼을 채우는 데이터소스(`boards` form-data·`form_data`)의 `initLocal` 은 `{"_merge":"replace","form":"{{data}}"}` 맵으로 둔다 — 문자열형 `initLocal` 은 엔진이 기존 form 과 깊은 병합해 직전 글의 id·게시판 다국어 이름이 등록 폼에 남는다(트러블슈팅 state-global 사례 7)

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 비밀글 상세만 `SecretContentGate` 로 막고 댓글 목록·첨부 다운로드는 그대로 노출 | 댓글 목록은 부모 글 비밀 여부 확인 후 빈 배열, 첨부 서빙은 403 — 두 경로 모두 게이트 재적용 | 상세 API 하나만 막으면 같은 정보가 형제 엔드포인트로 새어나간다 (KVE-2026-1914) |
| 첨부 접근 URL 을 저장소 직접 URL(CDN)로 발급 | 게이트를 통과하는 다운로드 서빙 URL(`download_url`)을 돌려준다 | 직접 URL 은 비밀글·삭제글 확인이 걸린 서빙 경로를 통째로 우회한다 — 응답에서 감춰도 그 주소를 아는 쪽은 계속 열 수 있어 회수되지 않는다 |
| 게시글/댓글 삭제·복원 시 `posts_count`/`comments_count` 를 Service 에서 `increment()`/`decrement()` | 훅(`after_create`/`after_delete`/`after_restore`) 리스너의 count 동기화에 맡긴다 | 직접 증감은 훅 기반 동기화와 이중 집계되어 카운트가 어긋난다 |
| 게시판별 동적 권한/역할을 board 삭제와 별도 시점에 정리 | `BoardService` 삭제 흐름 안에서 즉시 정리(또는 stale cleanup 이 `getDynamicPermissionIdentifiers()` 로 정확히 판정하게 유지) | 정리가 늦으면 존재하지 않는 게시판의 권한이 역할에 남아 관리 화면에 유령 항목이 뜬다 |
| 새 콘텐츠 타입을 board 코드에 `if ($type === 'inquiry')` 로 직접 분기 | `EcommerceInquiryHookListener` 처럼 필터 훅으로 CRUD 를 위임 | board 코드가 알지 못하는 도메인이 늘어날수록 분기가 무한 증식한다 |
| 레이아웃 표현식에서 누산기에 멤버 대입 (`grouped[k] = []` · `.push()`) 하거나 rest 구조분해 (`const { a, ...rest } = obj`) 사용 | `map` / `filter` / `reduce(Object.assign)` / `Object.fromEntries` 로 누산 없이 구성 | 표현식 평가기가 그 구문을 거부해 **식 전체가 평가되지 않는다.** `iteration.source` 면 배열이 아니게 되어 그 목록이 통째로 렌더되지 않고, `apiCall` body 면 원문 문자열이 그대로 전송된다 — 둘 다 오류도 경고도 남지 않는다 (일괄 적용 확인 창에서 실제 발생) |
| 일괄 적용 대상 설정 키를 `sectionMap` 에만 추가 | 같은 키의 `bulk_apply.field_labels` 라벨을 ko/en(+번들 ja)에 함께 추가 | 라벨이 없으면 확인 창에 원시 다국어 키가 그대로 노출된다 |
| 게시판 권한(role_permissions)이나 게시판 관리자·스텝 명단을 바꾸는 새 경로를 `BoardPermissionService::assignRolesToPermission()`·`syncBoardRoleUsers()` 를 거치지 않고 직접 `attach`/`sync` | 기존 두 지점을 재사용하거나, 새 지점에서 `PermissionEscalationGuard`(권한은 `assertGrantWithinActorCeiling`, 역할 배정은 `assertRoleAssignmentWithinActorCeiling`)를 쓰기 전에 호출 | 한 경로만 상한을 걸면 나머지가 자기부여 우회로가 된다 — 권한 저장만 막았을 때 담당자 지정·일괄 적용이 같은 결과를 냈다 (KVE-2026-2367) |
| 요청의 `temp_key` 를 형식 검증 없이 임시 경로(`{slug}/temp/{temp_key}`)에 넣거나, 조회된 임시 첨부가 없는데도 그 디렉토리를 `deleteDirectory` | `ValidatesTempKey`(UUID 만) + 현재 사용자가 올린 임시 첨부가 있을 때만 이동·정리 | `../../..` 같은 키가 정리 경로를 모듈 저장소 루트로 끌어올려 전 모듈 파일이 지워진다 (KVE-2026-2349 — 응답은 201 이라 드러나지 않았다) |
| 임시 첨부를 본경로로 옮긴 뒤 임시 디렉토리 정리(`deleteDirectory`)의 예외를 그대로 던지게 두기 | `AttachmentService::linkTempAttachmentsWithMove()` 처럼 정리만 `try/catch` 로 감싸 경고 로그(`게시판 임시 첨부 디렉토리 정리 실패`)를 남기고 연결 결과는 그대로 반환 | 정리는 부수 작업이다. 막 옮긴 대용량 파일을 운영체제가 잠시 붙잡고 있으면 디렉토리 순회가 lstat 오류를 던지고, 그 예외가 글 저장 전체를 「게시글 등록에 실패했습니다」(500)로 되돌려 파일은 본경로·DB 는 임시 경로를 가리키는 어긋남이 남았다(#703). 정리에 실패해 남는 것은 빈 임시 디렉토리뿐이다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 232개 | `modules/_bundled/sirsoft-board/tests` |
| Vitest | 83개 | `vitest.config.ts` |
| Playwright | 36개 | `tests/Playwright` |
| 시나리오 매니페스트 | 135개 | `tests/scenarios` |

기저 TestCase: `tests/ModuleTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit modules/_bundled/sirsoft-board/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd modules/_bundled/sirsoft-board && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd modules/_bundled/sirsoft-board && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->

## 확장 조각의 CSS 클래스 규약

resources/extensions/ 아래 의 조각은 **다른 패키지의 화면 안에서** 그려진다. 그래서 여기 쓰는
클래스는 그 화면을 소유한 **템플릿의 CSS 에 실재해야** 한다.

두 가지를 지킨다.

1. **템플릿의 의미 클래스를 쓰지 않는다.** `flex-start` · `text-heading` · `section-heading-md`
   같은 이름의 규칙은 `templates/_bundled/sirsoft-admin_basic/src/styles/main.css` 의 `@layer components` 에만
   있다. 사용자 템플릿(`sirsoft-basic`)에는 그 규칙이 없어 `display:flex` 같은 기본 배치까지
   통째로 사라진다. 평범한 Tailwind 유틸로 쓴다(`flex items-start`).
   슬롯 확장(`extension-point=`)은 한 조각이 관리자·사용자 양쪽에 들어가므로, 어느 한쪽의
   어휘를 쓰면 **반드시** 다른 쪽이 깨진다.

2. **새 유틸은 safelist 에 실린다.** 두 템플릿 모두 Tailwind 가 레이아웃을 스캔하지 않는다 —
   `sirsoft-admin_basic` 은 `templates/_bundled/sirsoft-admin_basic/scripts/extract-safelist.cjs` 가(확장 조각 포함) 자동 생성하고,
   `sirsoft-basic` 은 `templates/_bundled/sirsoft-basic/src/styles/safelist.txt` 를 손으로 단다. 새 클래스를 쓴 뒤에는
   `php artisan template:build <템플릿>` 로 CSS 를 다시 만든다.

이 규약을 어겨도 **빌드는 성공하고 경고도 없으며 테스트도 전부 초록이다** — 사람이 브라우저를
열어야만 드러난다(2026-09-21, 마이페이지 「내 정보 수정」의 확장 카드에서 체크박스가 설명 위로
떨어져 있었다). 그래서 확장 조각이 쓰는 클래스가 대상 템플릿의 CSS 에 실제로 만들어졌는지를
정적 검사가 대조한다.
