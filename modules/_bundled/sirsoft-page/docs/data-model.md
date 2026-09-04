# 페이지 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Page` | `pages` | 11 | creator→User, updater→User, versions→PageVersion, attachments→PageAttachment | 검색 색인 |
| `PageAttachment` | `page_attachments` | 13 | page→Page, creator→User | - |
| `PageVersion` | `page_versions` | 8 | page→Page, creator→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
세 모델의 관계는 `Page` ─1:N─ `PageVersion` / `PageAttachment` 하나뿐입니다.

- **`Page`** — `slug` 가 사실상의 주소이고 `current_version` 이 이력의 현재 위치입니다.
  `title` 과 `content` 는 `AsUnicodeJson` 캐스팅이라 다국어 값을 담습니다(로케일별 문자열
  맵). `content_thumbnail_url` 은 본문에서 뽑은 대표 이미지를 **저장해 둔 것**이라, 본문을
  고치면 함께 갱신되어야 합니다.
- **`PageVersion`** — 저장 시점의 `title`/`content`/`content_mode`/`seo_meta` 스냅샷입니다.
  복원은 이 값을 현재 페이지에 쓰고 `current_version` 을 **또 1 올린** 뒤 스냅샷을 한 번 더
  남깁니다.
- **`PageAttachment`** — 공개 서빙은 순번 ID 가 아니라 **해시**로 합니다. 부모 페이지의 발행
  상태가 곧 첨부의 공개 여부이며, 내려받기와 미리보기 **두 경로가 각자** 그 판정을 합니다.

셋 다 **SoftDeletes 를 쓰지 않습니다.** `Page` 의 소프트 삭제는 마이그레이션
`2026_06_29_000001` 이, `PageAttachment` 는 `2026_06_29_000002` 가 걷어냈습니다. 삭제된
페이지가 보이지 않게 남아 있으면 같은 slug 를 다시 쓸 수 없기 때문이며, 되돌리기의 책임은
버전 이력이 집니다.

`Page` 만 검색 색인 대상입니다. 색인에 실리는 컬럼과 가중치는 모델의 `searchableColumns()` ·
`searchableWeights()` 가 선언하고, 다시 태울지 여부는 `searchIndexShouldBeUpdated()` 와
`search.page.index_should_update` 필터가 정합니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `page_attachments` | `PageAttachment` |
| `page_versions` | `PageVersion` |
| `pages` | `Page` |
<!-- @generated:tables END -->

<!-- @intent START -->
세 테이블 모두 모델과 1:1 이며 피벗이 없습니다. 접두사가 `page_` 로 짧은 것은 이 모듈이
코어에 가까운 기본 기능이라는 초기 판단 때문이며, 다른 확장이 같은 이름을 쓰지 않도록
주의합니다.

`pages` 에는 FULLTEXT 인덱스(`2026_04_01_000004`)와 발행 정렬 인덱스(`2026_08_02_000001`)가
따로 붙어 있습니다. 목록·검색 쿼리를 새로 만들 때 이 두 인덱스를 쓰는 형태인지 확인합니다 —
컬럼에 함수를 씌우거나(`whereDate` 등) 정렬 컬럼을 바꾸면 인덱스가 쓰이지 않습니다.

삭제는 **DB CASCADE 에 맡기지 않습니다.** 페이지를 지울 때 `PageService` 가 첨부를 하나씩
`PageAttachmentService::deleteAttachment()` 로 지웁니다 — 물리 파일 삭제와 훅 발행이 함께
일어나야 하는데, CASCADE 로 지우면 그 둘이 통째로 건너뛰어지고 아무 오류도 남지 않습니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 8개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_01_000001_create_pages_table.php` | `pages` | `pages` | ✅ |
| `2026_04_01_000002_create_page_versions_table.php` | `page_versions` | `page_versions` | ✅ |
| `2026_04_01_000003_create_page_attachments_table.php` | `page_attachments` | `page_attachments` | ✅ |
| `2026_04_01_000004_add_fulltext_indexes_to_pages_table.php` | - | `pages` | ✅ |
| `2026_06_29_000001_drop_soft_deletes_from_pages_table.php` | - | `pages` | ✅ |
| `2026_06_29_000002_drop_soft_deletes_from_page_attachments_table.php` | - | `page_attachments` | ✅ |
| `2026_08_02_000001_add_published_sort_index_to_pages_table.php` | - | - | ✅ |
| `2026_08_22_000001_add_content_thumbnail_url_to_pages_table.php` | - | `pages` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
8개 중 3개가 초기 스키마이고 5개는 이후의 변경입니다. 그 5개가 이 모듈이 겪은 설계 변경을
그대로 보여줍니다:

| 마이그레이션 | 무엇이 바뀌었나 |
|---|---|
| `add_fulltext_indexes_to_pages_table` | 통합 검색 편입을 위해 FULLTEXT 인덱스 추가 |
| `drop_soft_deletes_from_pages_table` · `..._page_attachments_table` | 소프트 삭제 철회 — slug 재사용을 막기 때문 |
| `add_published_sort_index_to_pages_table` | 발행 목록 정렬의 인덱스 확보 |
| `add_content_thumbnail_url_to_pages_table` | 본문 대표 이미지를 조회 때마다 뽑지 않고 저장 |

새 컬럼을 더할 때 초기 `create_*` 파일을 고치지 않습니다 — 이미 설치된 사이트는 그 파일을
다시 실행하지 않으므로 반영되지 않습니다. 컬럼 기본값·comment·데이터 형태를 바로잡는 변경은
마이그레이션과 함께 `upgrades/` 의 업그레이드 스텝 백필이 필요합니다.

한국어 `comment` 와 `down()` 은 필수이고, FK 컬럼의 `->comment()` 는 `->constrained()` **앞**에
둡니다(뒤에 두면 comment 가 FK 정의에 붙어 조용히 사라집니다).
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 이 도메인의 상태는 `published` 불리언 하나뿐이라 분류 어휘가 생기지 않았습니다.

`content_mode` 는 문자열 컬럼입니다 — 편집기(위지윅/평문)가 무엇을 저장했는지를 나타내며,
편집기 확보에 실패했을 때의 폴백 계약(`text`)과 짝을 이룹니다. 값의 가짓수가 늘어나
분기가 생기기 시작하면 그때 Enum 으로 올리는 것이 맞습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `PageAttachmentRepository` | 구현 | 페이지 첨부파일 Repository |
| `PageAttachmentRepositoryInterface` | 인터페이스 | 페이지 첨부파일 Repository 인터페이스 |
| `PageRepository` | 구현 | 페이지 Repository |
| `PageRepositoryInterface` | 인터페이스 | 페이지 Repository 인터페이스 |
| `PageVersionRepository` | 구현 | 페이지 버전 Repository |
| `PageVersionRepositoryInterface` | 인터페이스 | 페이지 버전 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
세 Repository 모두 인터페이스와 1:1 이며 서비스는 **인터페이스만 주입**받습니다(구체 클래스
타입힌트 금지).

이 모듈에서 특히 걸리는 것 둘:

- **버전 조회는 반드시 페이지 스코프로.** `PageVersionRepository::findForPage($pageId, $versionId)`
  처럼 상위 리소스 ID 를 where 절에 반영합니다. 버전 ID 만으로 찾으면 다른 페이지의 버전을
  현재 페이지에 복원할 수 있는 교차 접근 경로가 생기는데, 정상 응답이 나가므로 오류도 로그도
  남지 않습니다.
- **목록 쿼리의 컬럼 프루닝과 정렬 화이트리스트.** 페이지 본문은 큰 컬럼이라 목록에 실으면
  오버플로 페이지 읽기가 발생합니다. 정렬은 `PageService::SEARCH_SORT_MAP` 이 닫힌 집합을
  정하며, 화면 정렬 옵션 ⊆ 검증 게이트 ⊆ 이 선언 순서로 포함 관계가 유지되어야 합니다.
<!-- @intent END -->
