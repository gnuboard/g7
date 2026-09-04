# 페이지 — 에이전트 가이드

> 이 문서는 이 모듈을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 모듈 (sirsoft-page) — 고정 주소를 갖는 단일 문서(회사소개·약관 등). slug 가 주소이고 모든 수정이 버전으로 쌓인다. 관리자 CRUD + 공개 조회 API 만 소유
2. 확장 방식: 발행 훅 21개(`page` 14 · `attachment` 7 의 before/filter/after 3단). 본문 썸네일 추출은 `page.filter_content_thumbnail`, 색인 조건은 `search.page.index_should_update`
3. 건드리면 안 되는 것: 버전 스냅샷을 남기지 않는 저장 경로, 현재 행 덮어쓰기식 버전 복원, 첨부 `preview`/`download` 중 한쪽만 거는 발행 게이트, 소프트 삭제 재도입
4. 작업 위치: `modules/_bundled/sirsoft-page` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan module:update sirsoft-page --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
회사소개·이용약관·개인정보처리방침처럼 **고정된 주소를 갖는 단일 문서**를 관리하는 모듈입니다.
게시판이 "여러 글이 목록을 이루는 것"이라면 이 모듈은 "글 하나가 곧 하나의 주소"이며, 그
차이가 설계의 대부분을 설명합니다 — 목록·댓글·신고·카테고리가 없고 대신 **slug 와 버전 이력**이
있습니다.

**소유 범위는 관리자 CRUD + 공개 조회 API 까지입니다.** 레이아웃 3개가 전부 관리자 화면이며,
방문자가 보는 페이지 화면은 템플릿(`sirsoft-basic`)이 `GET /pages/{slug}` 를 호출해 그립니다.

**설계 원칙 셋**:

1. **모든 수정이 버전을 남긴다.** 저장할 때마다 `page_versions` 에 스냅샷이 쌓이고
   `current_version` 이 올라갑니다. 과거 버전으로 되돌리는 것도 **덮어쓰기가 아니라 새 버전
   생성**입니다(복원 후 `current_version` 이 또 1 증가) — 되돌린 사실 자체가 이력에 남아야
   하기 때문입니다.
2. **소프트 삭제를 쓰지 않는다.** 초기 스키마에는 있었지만 마이그레이션 두 개
   (`2026_06_29_*`)로 걷어냈습니다. slug 가 주소이므로, 지운 페이지가 보이지 않게 남아 있으면
   같은 slug 를 다시 쓸 수 없습니다. 되돌리기의 책임은 삭제가 아니라 버전 이력이 집니다.
3. **검색·SEO 는 코어에 붙는다.** 이 모듈은 자기 검색 화면을 만들지 않고 코어 통합 검색
   (`core.search.*` 훅 3종)에 페이지를 얹으며, 봇 화면 캐시도 코어 SEO 가 관리합니다.

**의도적으로 하지 않는 것**: 관리자 설정 화면(첨부 제한은 파일 설정이며 UI 가 없습니다)·
알림·브로드캐스트·미들웨어·레이아웃 확장. 이 모듈은 다른 확장 화면에 무엇도 주입하지 않습니다.
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
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `src/lang/` | 백엔드 다국어 | ko·en 동시 반영 + 번들 ja 팩 동기화 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `database/seeders/` | 시더 | composer autoload 등록 + `extension:update-autoload` |
| `upgrades/` | 업그레이드 스텝 | DB·설정 구조 변경 시 작성 (모듈/플러그인 전용) |
| `resources/layouts/` | 레이아웃 JSON | `php artisan module:update sirsoft-page --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan module:update sirsoft-page --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan module:build` → `php artisan module:update sirsoft-page --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan module:update sirsoft-page --force` |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**페이지 저장 → 버전 적재**: `Admin\PageController` → `Store`/`UpdatePageRequest`(slug 유일성·
다국어 제목) → `PageService::create()`/`update()`(`before_*` → `filter_*_data` → `after_*`) →
`PageRepository` 로 `pages` 갱신 + **같은 트랜잭션에서 `page_versions` 스냅샷 적재 +
`current_version` 증가**. 이후는 리스너 레인입니다 — `PageActivityLogListener`(활동 로그) ·
`SeoPageCacheListener`(봇 화면 캐시 무효화)가 `after_*` 를 받아 처리합니다.

**버전 복원**: `POST /admin/pages/{page}/versions/{versionId}/restore` →
`PageService::restoreVersion()` → 그 버전의 `title`/`content`/`content_mode`/`seo_meta` 를
현재 페이지에 쓰고 **`current_version` 을 다시 +1** 한 뒤 스냅샷을 한 번 더 남깁니다. 그래서
"3번 버전으로 되돌림"은 3번이 되는 것이 아니라 3번의 내용을 담은 5번이 생기는 것입니다.

**첨부 업로드 → 공개 서빙**: `Admin\PageAttachmentController` → `UploadPageAttachmentRequest`
(설정 `attachment.max_size_mb` · `allowed_types`) → `PageAttachmentService`
(`before_upload` → `filter_upload_file` → `after_upload`, 개수 상한 `attachment.max_count`
초과 시 `AttachmentLimitExceededException`) → 저장. 공개 서빙은 `PublicPageAttachmentController`
가 **해시**로 받습니다(`/pages/attachment/{hash}`, `/preview`) — 순번 ID 를 노출하지 않기
위한 선택이며, 그래서 이 두 경로는 각각 발행 상태 게이트를 **자기 자리에서** 확인해야 합니다.

**공개 조회**: `PublicPageController::show(slug)` — `optional.sanctum` 이라 비로그인도
접근하며, 미발행 페이지는 **읽기 권한을 가진 운영자에게만 미리보기로** 열리고 그 외에는
404 입니다. 첨부 서빙 두 경로도 같은 판정을 각자 재적용합니다. 목록 API 는 없습니다(페이지는
목록을 이루지 않습니다). 통합 검색 결과에 페이지가 섞이는 것은 `SearchPagesListener` 가 코어
검색 훅에 응답을 얹기 때문입니다.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 21개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 17개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 5개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 0개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
발행 훅 21종은 두 도메인(`page` 14 · `attachment` 7)의 3단 패턴
(`before_*` → `filter_*_data` → `after_*`)이 거의 전부입니다. 그 밖의 것 셋만 성격이 다릅니다:

| 훅 | 무엇을 열어 주는가 |
|---|---|
| `page.filter_content_thumbnail` | 본문에서 대표 이미지를 뽑는 규칙. 본문 형식이 특이한 사이트가 자기 방식으로 바꿀 수 있습니다 |
| `search.page.index_should_update` | 어떤 변경에 검색 색인을 다시 태울지. 색인 비용이 큰 설치가 조건을 좁히는 자리입니다 |
| `attachment.filter_upload_file` | 업로드 파일을 저장 전에 가공(리사이즈·변환) |

**구독 방향이 이 모듈의 성격을 더 잘 보여줍니다.** 17개 구독 중 12개는 자기 훅이고, 나머지
5개가 바깥을 향합니다 — 코어 검색 3종(`core.search.results` · `build_response` ·
`index_validation_rules`)에 페이지 결과를 얹고, 코어 활동 로그 1종에 설명 변수를 제공하며,
`sirsoft-ckeditor5.image.filter_reference_sources` 로 편집기가 고를 수 있는 이미지 출처에
페이지 첨부를 더합니다.

이 셋은 전부 **상대가 없으면 발화하지 않을 뿐**이라 manifest 의존에 없습니다. 대신 상대가 훅
이름을 바꾸면 예외 없이 조용히 끊기므로, 코어 검색이나 ckeditor5 를 손댈 때는 이 구독이 함께
확인 대상입니다.

미들웨어·브로드캐스트 채널·알림·레이아웃 확장은 0개입니다. 이 모듈은 다른 화면에 개입하지
않습니다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan module:update sirsoft-page --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=module:sirsoft-page` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 페이지 저장 경로를 추가·변경했다면 버전 스냅샷 적재와 `current_version` 증가가 같은 트랜잭션에 있는지 확인
- [ ] 첨부를 내보내는 경로를 추가하면 발행 상태 게이트를 그 자리에서 재적용 (부모에서 한 번 판정하고 끝나지 않는다)
- [ ] 코어 검색·SEO·ckeditor5 의 훅 이름이 바뀌면 이 모듈의 구독 5종이 조용히 끊기므로 함께 확인
- [ ] 첨부 제한(`attachment.*`)은 `config/settings/defaults.json` 이 SSoT — 서비스에서 리터럴로 재클램프하지 않는다
- [ ] 활동 로그 항목을 추가하면 코어 `lang/{ko,en}/activity_log.php` 의 action 라벨·description 과 번들 ja 팩까지 동반
- [ ] 레이아웃·컴포넌트·`data_source` 를 건드렸다면 [`docs/editor-spec.md`](docs/editor-spec.md) 의 동반 의무 표를 따라 `editor-spec.json` 을 함께 갱신 — 샘플이 없는 `data_source` 는 편집기 캔버스에서만 빈 화면이 되고 실제 화면은 정상이라 오류도 경고도 남지 않는다. 반영은 `php artisan module:update sirsoft-page --force`

## 6. 금지 패턴

<!-- @intent START -->
| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 페이지를 저장하면서 버전 스냅샷 적재를 건너뛰기 | `PageService` 의 저장 경로를 거친다 (스냅샷 + `current_version` 증가가 같은 트랜잭션) | 버전이 빠진 수정은 되돌릴 수 없다. 소프트 삭제를 걷어낸 뒤로 **되돌리기 수단이 버전 이력뿐**이다 |
| 버전 복원을 현재 행 덮어쓰기로 구현 | 복원도 새 버전을 만든다 (`current_version` +1 후 스냅샷) | 되돌린 사실이 이력에서 사라지면 "누가 언제 무엇으로 되돌렸는가"를 추적할 수 없다 |
| 첨부 공개 서빙(`download`)에만 발행 상태를 확인하고 `preview` 는 그대로 노출 | 두 경로 모두 같은 게이트를 재적용 | 한쪽만 막으면 같은 파일이 형제 엔드포인트로 새어나간다 |
| 첨부 URL 을 순번 ID 로 조립 | 해시 경로(`/pages/attachment/{hash}`) | ID 노출은 다른 페이지의 첨부를 훑을 수 있는 열쇠가 된다 |
| 소프트 삭제를 다시 도입 | 삭제는 실삭제, 되돌리기는 버전 이력 | 지운 페이지가 남아 있으면 같은 slug 를 다시 쓸 수 없고, slug 는 이 도메인에서 주소 그 자체다 |
| 첨부 개수·용량 상한을 서비스에 리터럴로 재클램프 | 설정(`attachment.*`) 을 읽고 검증은 FormRequest 에 둔다 | 이중 클램프가 생기면 설정을 올려도 반영되지 않는다 |
| 페이지 목록을 만들기 위해 공개 목록 API 를 추가 | 목록이 필요하면 게시판 모듈을 쓴다 | 페이지는 "주소 하나 = 문서 하나" 도메인이다. 목록을 들이면 게시판과 역할이 겹치면서 둘 다 애매해진다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 30개 | `modules/_bundled/sirsoft-page/tests` |
| Vitest | 4개 | `vitest.config.ts` |
| Playwright | 7개 | `tests/Playwright` |
| 시나리오 매니페스트 | 9개 | `tests/scenarios` |

기저 TestCase: `tests/ModuleTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit modules/_bundled/sirsoft-page/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd modules/_bundled/sirsoft-page && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd modules/_bundled/sirsoft-page && npm run test:e2e -- specs/<대상>.spec.ts

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
