# 페이지 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

기본값 파일: `config/settings/defaults.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`getSettingsSchema()` 도 관리자 설정 화면도 없습니다. 조정 가능한 값은 첨부 제한 셋뿐이며
`config/settings/defaults.json` 이 SSoT 입니다.

| 키 | 기본값 | 쓰이는 곳 |
|---|---|---|
| `attachment.max_count` | 5 | `PageAttachmentService` — 초과 시 `AttachmentLimitExceededException` |
| `attachment.max_size_mb` | 10 | `UploadPageAttachmentRequest` 검증 규칙 |
| `attachment.allowed_types` | 이미지 4종 + PDF + ZIP | 같은 FormRequest (미설정 시 클래스 상수 폴백) |

파일 형태가 다른 확장과 다릅니다 — 이커머스·게시판은 `{_meta, defaults, frontend_schema}` 3단
구조지만 여기는 **평평한 값 트리**입니다. 관리자 화면이 없어 `frontend_schema` 가 필요 없고,
그래서 `_meta` 도 두지 않았습니다. 읽기는 `g7_module_settings('sirsoft-page', 'attachment.…')`
로 하고, `PageSettingsService` 가 설정이 아직 동기화되지 않은 환경을 위해 파일 직접 읽기를
폴백으로 갖습니다.

**서비스에서 상한을 리터럴로 재클램프하지 않습니다.** 계산은 서비스가, 상한 검증은
FormRequest 가 단일 책임으로 갖습니다 — 이중 클램프가 생기면 설정을 올려도 반영되지 않습니다.

설정 화면을 나중에 추가한다면 `_meta.categories` 와 `frontend_schema` 를 더하는 방식이며, 그때
값의 위치(`attachment.*`)는 바꾸지 않아야 기존 설치의 값이 유지됩니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `pages` | 페이지 관리 | `read`, `create`, `update`, `delete` | `page` |
<!-- @generated:permissions END -->

<!-- @intent START -->
`pages` 하나에 `read`/`create`/`update`/`delete` 네 액션이 전부입니다. 라우트 키 `page` 가
선언되어 있어 관리자 라우트에 스코프 미들웨어가 걸립니다.

`read` 가 관장하는 범위에 주의가 필요합니다 — 관리자 목록·상세뿐 아니라 **미발행 페이지의
공개 화면 미리보기**와 **미발행 페이지 첨부의 서빙**까지 이 권한이 판정합니다. 그래서 이
권한을 넓게 주면 아직 공개하지 않은 문서가 그 계정에 열립니다.

역할(`getRoles()`)은 선언하지 않습니다. 게시판처럼 대상마다 담당자가 갈리는 도메인이 아니라
페이지 전체를 한 사람이 관리하는 경우가 대부분이므로, 코어 역할에 이 권한을 부여하는 것으로
충분하다고 보았습니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-page` | 페이지 관리 | `/admin/pages` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
최상위 메뉴 하나(`/admin/pages`)뿐이고 하위 메뉴가 없습니다. 화면이 목록·작성/수정·상세 셋뿐이며
셋 다 목록에서 이어지므로 별도 진입점이 필요 없습니다.

메뉴는 **권한과 짝을 이룰 때만 보입니다.** `pages.read` 가 없는 역할에는 이 메뉴가 렌더되지
않습니다. 새 화면을 더한다면 권한·메뉴·라우트 이름 셋이 서로를 정확히 가리키는지 함께
확인합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/modules/sirsoft-page/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
17개가 파일 하나(`src/routes/api.php`)에 있고 세 무리로 갈립니다:

| 무리 | prefix | 인증 |
|---|---|---|
| 관리자 페이지 CRUD·버전 | `admin/pages` | `auth:sanctum` + 권한 스코프 |
| 관리자 첨부 | `admin/attachments` | `auth:sanctum` |
| 공개 조회·첨부 서빙 | `pages` | `optional.sanctum` (비로그인 접근, 발행 상태는 컨트롤러가 판정) |

화면용 라우트는 없습니다 — 관리자 화면은 레이아웃 JSON 이 이 API 를 호출해 그리고, 방문자
화면은 템플릿이 `GET /pages/{slug}` 를 씁니다.

**공개 첨부 경로가 해시 기반**(`/pages/attachment/{hash}`, `.../preview`)인 것에 주의합니다.
새 서빙 경로를 더하면 그 자리에서 발행 상태·권한 게이트를 **다시** 걸어야 합니다 — 한쪽만
막으면 같은 파일이 형제 엔드포인트로 새어나가고, 정상 응답이라 오류도 로그도 남지 않습니다.

모든 라우트에 `name()` 이 필요하고, 라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다. 캐시에
없는 라우트는 예외도 경고도 없이 404 가 됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `sirsoft-marketing` | 플러그인 | `>=1.0.0` |
| `sirsoft-basic` | 템플릿 | `>=1.1.0` |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈은 아무 확장에도 의존하지 않습니다. 관계는 한 방향으로 들어옵니다 —
`sirsoft-marketing` 플러그인과 `sirsoft-basic` 템플릿이 이 모듈을 요구합니다.

manifest 에는 없지만 **훅으로 맞물리는 확장이 하나 더** 있습니다: `sirsoft-ckeditor5` 가
없으면 편집기 이미지 출처 제공만 비고 나머지는 정상 동작하므로, 의존으로 올리지 않는 것이
맞습니다.

이 모듈의 공개 표면(Service·Repository·Contracts·라우트·발행 훅)을 바꿀 때는 위 확장들의
`dependencies` 최소 버전 상향이 필요한지 검토합니다. 특히 공개 조회 API 의 응답 형태는
템플릿이 그대로 화면에 그리므로, 필드를 빼면 그 템플릿의 페이지 화면이 빈 채로 렌더됩니다.
<!-- @intent END -->
