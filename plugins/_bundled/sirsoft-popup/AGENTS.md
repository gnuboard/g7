# 그누보드7 팝업 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-popup) — 사용자 화면 위에 덮는 공지·프로모션 팝업 레이어(등록·예약 노출·전역 표시 방식·캐러셀)
2. 확장 방식: 발행 훅(`sirsoft-popup.popup.*`·`sirsoft-popup.popup.filter_sanitize_config`)을 구독하거나, 템플릿이 `src/components/overrides/sirsoft-popup/PopupLayer.tsx` 로 레이어를 대체한다
3. 건드리면 안 되는 것: 사용자 템플릿·GDPR 플러그인 수정(공통 확장점·계약 훅으로만 붙는다), 상태 컬럼 저장(조회 시점 SQL 판정), 경로 판정 규칙을 한쪽(PHP `RoutePath` / TS `popupVisibility`)만 고치기
4. 작업 위치: `plugins/_bundled/sirsoft-popup` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-popup --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
운영자가 휴무·배송 지연·점검 공지나 기간 한정 프로모션을 개발자 도움 없이 등록하고 기간에 맞춰 자동 노출·종료하게 한다(#812).

- **소유 구조**: 팝업 데이터·관리 화면·전역 표시 방식(플러그인 설정)·레이어 컴포넌트를 모두 이 플러그인이 가진다. 사용자 템플릿은 공통 확장점 `user_global_overlay` 만 열어 두면 되고 템플릿을 고치지 않는다(GDPR 쿠키 배너와 같은 방식).
- **노출 판정 분담**: 서버는 사용 여부·기간·노출 대상(손님/회원)만 거르고, 노출 페이지(경로 경계 하위 포함, `/` 는 홈만)·기기(폭 767px 이하 모바일)·「보지 않기」·이번 페이지에서 닫음은 브라우저가 거른다 — SPA 경로 이동마다 서버에 묻지 않고, 공개 응답을 대상·언어 단위로 캐시하기 위해서다.
- **상태는 저장하지 않는다**: 비활성 > 예약 > 종료 > 노출중을 조회 시점에 SQL 로 판정한다(#810 과 같은 규칙, cron 없음, UTC 저장).
- **「보지 않기」**: 그때 레이어에 보이던 팝업 id 마다 만료를 쿠키 하나(`g7_popup_dismissed`)에 기록한다 — 그 뒤 새로 올린 팝업은 바로 뜬다. 쿠키 이름은 GDPR 계약 훅으로 필수 저장 항목에 등록한다(GDPR 은 고치지 않는다).
- **직접 작성형 본문**: 저장 때 HTMLPurifier(플러그인 composer 의존성)로 정화하고 출력 때 DOMPurify 로 한 번 더 거른다.
- **의도적으로 하지 않는 것**: 노출·클릭 통계(2차), 팝업별 위치·크기(표시 방식은 전역), 페이지명으로 노출 페이지 고르기, 관리자 메뉴 그룹, 소유자 범위(owner_key), 휴지통.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Http/Resources/` | API 리소스 | 목록 응답은 화면이 실제로 그리는 것만 싣는다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/Enums/` | 상태·타입·분류 | 문자열 리터럴 대신 Enum 을 SSoT 로 둔다 |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-popup --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-popup --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-popup --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-popup --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-popup --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `config/` | 확장 config | 설정 기본값은 settings 스키마와 어긋나지 않게 |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-popup --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-popup --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
- **등록**: `PopupController::store` → `StorePopupRequest`(유형별 필수·경로 정규화·기간 UTC 변환) → `PopupService::create`(before → filter → 트랜잭션: 맨 뒤 순서·본문 정화·임시 묶음 이미지 연결 → after) → `PopupRepository::create` → `Popup`. after 훅을 `PopupActivityLogListener`(활동 로그)·`PopupCacheListener`(공개 캐시 비우기)가 받는다.
- **사용자 화면**: 주입 조각 `resources/extensions/popup_layer` 의 data-source → `Public\PopupController::index`(봇이면 빈 목록) → `PublicPopupService::forRequest`(대상·언어 캐시, 꺼낼 때 기간 재판정) → `PopupRepository::exposureCandidates` → 브라우저 `PopupLayer` 가 `route.path`·폭·쿠키로 거른 뒤 캐러셀로 그린다.
- **복사**: `PopupController::bulkCopy` → `PopupIdsRequest` → `PopupService::bulkCopy`(건마다 트랜잭션: 비활성·맨 뒤·제목 접미 + `PopupImageService::duplicate` 로 파일 복제, 실패한 건만 되돌림) → after_bulk_copy.
- **이미지 업로드**: `PopupImageController::store` → `PopupImageUploadRequest` → `PopupImageService::upload`(파일 쓰기 → 임시 묶음 행). 파일 쓰기가 실패하거나 행 저장이 실패하면 쓴 파일을 지우고 `PopupImageUploadException` → 500 `messages.image.upload_failed`. 복제(`duplicate`)도 행 저장이 실패하면 그 파일을 지운다.
- **수정의 다국어 값**: `UpdatePopupRequest` 는 언어팩을 꺼서 지원 목록에서 빠진 언어의 **저장된** 번역을 검증에서 빼 두었다가 저장된 값으로 되돌려 넣는다(보내도 막지 않고 빼도 지우지 않음). 등록과 새 지원 밖 언어는 `strictLocales` 로 422. 본문은 `SanitizedBodyNotEmpty` 가 저장 때와 같은 정화기로 미리 걸러, 정화 뒤 비는 언어가 있으면 422.
<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 27개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 21개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 5개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 1개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 0개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 1개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 0개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
- 노출 후보를 더하거나 빼려면 `sirsoft-popup.popup.filter_public_list_result`(filter, 인자 `$popups, $context` — `user`·`locale`).
- 본문 정화 허용 목록을 바꾸려면 `sirsoft-popup.popup.filter_sanitize_config`(filter, HTMLPurifier 설정 키 → 값).
- 등록·수정 값·검증 규칙은 `sirsoft-popup.popup.filter_create_data`·`filter_update_data`·`store_validation_rules`·`update_validation_rules`.
- 레이어 모양을 템플릿에 맞추려면 템플릿이 `PopupLayer` 를 오버라이드한다 — props 계약은 [docs/extension-points.md](docs/extension-points.md).
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-popup --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-popup` 재실행 + `docs/api/**` 갱신
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 빌드 없이 update 만, 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인
- [ ] TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 경로 판정을 바꾸면 PHP `Support/RoutePath` 와 TS `resources/js/lib/popupVisibility.ts` 를 함께 바꾸고 양쪽 표본 테스트(`RoutePathTest`·`popupVisibility.test.ts`)를 같이 고친다
- [ ] 「보지 않기」 쿠키 이름은 `Plugin::DISMISS_COOKIE` 와 `resources/js/lib/dismissCookie.ts` 가 같아야 한다(GDPR 필수 저장 등록 이름)
- [ ] `composer.json` 을 바꾸면 `php artisan plugin:vendor-bundle sirsoft-popup` 으로 vendor 번들을 다시 만든다

## 6. 금지 패턴

<!-- @intent START -->
| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 상태(노출중·예약…)를 컬럼에 저장하거나 목록을 받은 뒤 PHP 로 상태를 거름 | `PopupRepository::applyStatusFilter`·`applyStatusSort` 한 곳의 SQL 판정(쪽 수가 맞는다) |
| 공개 API 요청에 현재 경로를 넣어 서버가 경로를 거름 | 경로·기기·「보지 않기」는 `PopupLayer` 가 거른다 — 공개 응답은 대상·언어 단위 캐시 |
| 링크 팝업에서 `<a>` 안에 단추·스크롤 영역을 넣음 | 이미지 위를 덮는 빈 `<a>` + 그 바깥 형제로 넘김 단추·띠 |
| 직접 작성형 본문을 정화 없이 저장·출력 | 저장 때 `PopupHtmlSanitizer`, 출력 때 DOMPurify |
| GDPR 플러그인 코드를 참조해 쿠키를 등록 | 계약 훅 `sirsoft-gdpr.necessary_storage.filter_provided_keys` 에 이름만 더한다 |
| 스토리지에 파일을 쓴 뒤 행 저장 실패를 그대로 던짐 | 실패하면 쓴 파일을 지우고 던진다(`PopupImageService::upload`·`duplicate`) — 행이 없는 파일은 임시 이미지 정리 명령이 찾지 못한다 |
| 수정 요청에 지원 밖 언어 키가 있다고 422 | 저장된 번역이면 보존(`UpdatePopupRequest`) — 언어팩을 끄는 순간 그 언어로 쓴 팝업을 고칠 수 없게 된다 |

| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 레이어 폭 판정에 `window.matchMedia`·`<picture media>` | `G7Core.useResponsive()` 폭(없을 때만 `window.innerWidth`), 이미지는 레이어가 판정한 기기로 `src` 를 고른다 | 경계 폭 1px 어긋남과 편집기·관리자 미리보기(창이 넓다)에서 기기가 갈린다 |
| `PopupLayer`·`PopupPreview` 를 템플릿 basic 컴포넌트(`Div`·`Button`)로 옮김 | 이 두 컴포넌트만 HTML 태그 + `g7pp-` 소유 CSS(**예외**). 관리 화면 조각(`PopupConflictResolver`·`PopupPeriodHint`)은 basic 컴포넌트 | 레이어는 어떤 사용자 템플릿에 붙어도 같은 모양·같은 접근성 구조(dialog·포커스 가둠)여야 하고, 템플릿마다 basic 컴포넌트의 클래스·마크업이 다르다 |
| 미리보기 틀 안 높이를 `100%`·`vh` 로 잼 | 틀이 넘긴 `--g7pp-frame-h` 로 잰다(`.g7pp-root--preview` 규칙) | 대화상자 높이가 정해져 있지 않아 `100%` 는 풀리지 않고 본문이 0px 로 접힌다. `vh` 는 관리자 창 기준이다 |
| 레이어 문구에 한국어 대체 문구를 박음 | `support/editor.ts` 의 `translate()`(전체 키) — 번역이 없으면 키가 보인다 | 번역 누락이 한국어로 가려지면 다른 언어 방문자에게만 드러난다 |
| 장 전환에 모든 장을 한 줄로 놓은 트랙을 되감거나, 외부 슬라이더 라이브러리(Swiper 등)를 싣음 | 현재 장 + 들어올 옆 장 하나만 ±100% 자리에 두고 `transform` 으로 옮긴다(`PopupLayer` 의 `moveTo`·끌기 처리) | 끝 → 처음이 N장을 거꾸로 되감는 움직임이 된다. 같은 페이지의 다른 확장(히어로 섹션 등)이 자기 번들에 슬라이더 라이브러리를 이미 실어 두 번 받게 된다 |
| 끌기 영역에서 올라온 `lostpointercapture` 로 끌기를 끝냄 | 영역 자신이 포인터를 잃었을 때(`event.target === event.currentTarget`)만 끝낸다 | 터치는 브라우저가 누른 자식에 포인터를 잡아 두므로, 영역이 넘겨받는 순간 자식의 `lostpointercapture` 가 올라와 휴대폰에서만 끌기가 끊긴다 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 16개 | `plugins/_bundled/sirsoft-popup/tests` |
| Vitest | 10개 | `vitest.config.ts` |
| Playwright | 2개 | `tests/Playwright` |
| 시나리오 매니페스트 | 11개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-popup/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-popup && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-popup && npm run test:e2e -- specs/<대상>.spec.ts

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
