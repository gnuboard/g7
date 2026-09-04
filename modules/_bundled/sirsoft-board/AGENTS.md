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
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update sirsoft-board --force` (빌드 불필요) |
| `resources/routes/` | 라우트 → 레이아웃 매핑 (분할) | `php artisan module:update sirsoft-board --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-board --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan module:update sirsoft-board --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-board --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
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

**게시글 작성 → 비밀글 게이팅**: `User\PostController` → `StorePostRequest`
(`sirsoft-board.post.store_validation_rules` 필터로 게시판별 커스텀 규칙 추가) →
`PostService::createPost()` (`before_create`→`filter_create_data`→`after_create` 훅 순서,
`sirsoft-board.post.user_create` IDV 정책 게이트 통과 후) → `PostRepository`. 조회 시에는
`PostResource` 가 `SecretContentGate` 로 비밀글 여부·열람 권한을 판정해 `content`/`title`/
`reply`/`attachments` 를 마스킹합니다 — 이 판정은 댓글 목록·첨부 다운로드에도 **개별
재적용**됩니다(부모 글에서 한 번 판정하고 끝나지 않음, KVE-2026-1914).

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
| 발행 훅 | 90개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 77개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 16개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 5개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
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
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 비밀글이 관여하는 새 조회 경로(댓글·첨부·검색 결과 등)를 추가할 때 `SecretContentGate` 재적용 (KVE-2026-1914 — 부모에서 한 번 판정하고 끝나지 않는다)
- [ ] `boards`/`board_posts`/`board_comments` 의 count 컬럼(`posts_count`/`comments_count`/`replies_count`/`attachments_count`)은 훅 리스너(`*CountSyncListener`)가 갱신 — Service 에서 직접 증감 금지
- [ ] 게시판 삭제 시 `getDynamicPermissionIdentifiers()`/`getDynamicRoleIdentifiers()`/`getDynamicMenuSlugs()` 가 최신 상태를 반영하도록 동일 트랜잭션에서 정리 (module.php `uninstall()` 의 `chunkById` 패턴 참고 — OFFSET 순회로 삭제 금지)
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan module:update sirsoft-board --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 비밀글 상세만 `SecretContentGate` 로 막고 댓글 목록·첨부 다운로드는 그대로 노출 | 댓글 목록은 부모 글 비밀 여부 확인 후 빈 배열, 첨부 서빙은 403 — 두 경로 모두 게이트 재적용 | 상세 API 하나만 막으면 같은 정보가 형제 엔드포인트로 새어나간다 (KVE-2026-1914) |
| 게시글/댓글 삭제·복원 시 `posts_count`/`comments_count` 를 Service 에서 `increment()`/`decrement()` | 훅(`after_create`/`after_delete`/`after_restore`) 리스너의 count 동기화에 맡긴다 | 직접 증감은 훅 기반 동기화와 이중 집계되어 카운트가 어긋난다 |
| 게시판별 동적 권한/역할을 board 삭제와 별도 시점에 정리 | `BoardService` 삭제 흐름 안에서 즉시 정리(또는 stale cleanup 이 `getDynamicPermissionIdentifiers()` 로 정확히 판정하게 유지) | 정리가 늦으면 존재하지 않는 게시판의 권한이 역할에 남아 관리 화면에 유령 항목이 뜬다 |
| 새 콘텐츠 타입을 board 코드에 `if ($type === 'inquiry')` 로 직접 분기 | `EcommerceInquiryHookListener` 처럼 필터 훅으로 CRUD 를 위임 | board 코드가 알지 못하는 도메인이 늘어날수록 분기가 무한 증식한다 |
| 레이아웃 표현식에서 누산기에 멤버 대입 (`grouped[k] = []` · `.push()`) 하거나 rest 구조분해 (`const { a, ...rest } = obj`) 사용 | `map` / `filter` / `reduce(Object.assign)` / `Object.fromEntries` 로 누산 없이 구성 | 표현식 평가기가 그 구문을 거부해 **식 전체가 평가되지 않는다.** `iteration.source` 면 배열이 아니게 되어 그 목록이 통째로 렌더되지 않고, `apiCall` body 면 원문 문자열이 그대로 전송된다 — 둘 다 오류도 경고도 남지 않는다 (일괄 적용 확인 창에서 실제 발생) |
| 일괄 적용 대상 설정 키를 `sectionMap` 에만 추가 | 같은 키의 `bulk_apply.field_labels` 라벨을 ko/en(+번들 ja)에 함께 추가 | 라벨이 없으면 확인 창에 원시 다국어 키가 그대로 노출된다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 155개 | `modules/_bundled/sirsoft-board/tests` |
| Vitest | 33개 | `vitest.config.ts` |
| Playwright | 26개 | `tests/Playwright` |
| 시나리오 매니페스트 | 34개 | `tests/scenarios` |

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
