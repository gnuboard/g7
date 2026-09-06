# CKEditor 5 WYSIWYG 에디터 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Ckeditor5ImageUpload` | `ckeditor5_image_uploads` | 7 | uploader→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
모델 하나뿐입니다. `Ckeditor5ImageUpload` 는 편집기로 올린 이미지의 **기록**이며, 파일 자체는
설정된 디스크(`public_asset_disk`)에 있습니다.

이 기록이 존재하는 이유는 두 가지입니다 — 관리자 화면에서 업로드 이미지를 목록으로 보여주기
위해서, 그리고 미참조 정리 판정의 대상 목록을 얻기 위해서입니다. 본문은 이 기록의 ID 를
참조하지 않고 **URL 문자열**을 담으므로, 기록을 지운다고 본문의 이미지 태그가 사라지지는
않습니다(그 자리가 깨질 뿐입니다).

`uploader→User` 관계 하나만 있고 콘텐츠와의 관계는 없습니다. 이미지가 어느 글에 쓰이는지는
관계가 아니라 **본문 문자열 검색**으로 판정합니다 — 본문을 가진 확장이 늘 때마다 이 플러그인이
그 관계를 알아야 한다면 결합이 무한히 늘어나기 때문입니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `ckeditor5_image_uploads` | `Ckeditor5ImageUpload` |
<!-- @generated:tables END -->

<!-- @intent START -->
`ckeditor5_image_uploads` 하나입니다. `plugin.php` 의 `getDynamicTables()` 가 이 이름을
선언하는데, 플러그인 제거 시 정리 대상임을 코어에 알리기 위해서입니다.

기록을 지우는 것과 **파일을 지우는 것은 별개**입니다. 관리 화면의 삭제는 둘 다 수행하지만,
DB 행만 사라지고 파일이 남는 경로를 만들지 않도록 주의합니다 — 남은 파일은 어떤 목록에도
뜨지 않아 영영 정리되지 않습니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 2개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_13_000001_create_ckeditor5_uploads_table.php` | `ckeditor5_image_uploads` | `ckeditor5_image_uploads` | ✅ |
| `2026_08_14_000001_add_created_at_index_to_ckeditor5_image_uploads.php` | - | `ckeditor5_image_uploads` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
2개입니다. 초기 테이블 생성 하나와 `created_at` 인덱스 추가 하나.

인덱스가 나중에 추가된 것은 정리 커맨드가 보존기간으로 대상을 고르기 때문입니다 —
`created_at` 범위 조건이 인덱스를 타지 못하면 업로드가 쌓일수록 정리 배치가 느려집니다.

새 컬럼을 더할 때 초기 `create_*` 파일을 고치지 않습니다. 이미 설치된 사이트는 그 파일을 다시
실행하지 않으므로 반영되지 않으며, 기존 행을 손봐야 하는 변경은 `upgrades/` 의 업그레이드 스텝
백필이 함께 필요합니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 이 플러그인에는 상태 전이가 없습니다 — 이미지는 올라오거나 지워질 뿐입니다.

설정의 `toolbar` 만 닫힌 어휘(`standard`/`minimal`/`full`)를 갖는데, 이는 설정 스키마의
`enum` 타입으로 선언되어 있어 별도 PHP Enum 을 두지 않았습니다. 이 어휘를 코드에서 분기로
비교하는 자리가 늘어나면 그때 Enum 으로 올리는 것이 맞습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `ImageReferenceSourceRepository` | 구현 | 에디터 이미지 참조 소스 조회 Repository 구현체 |
| `ImageReferenceSourceRepositoryInterface` | 인터페이스 | 에디터 이미지 참조 소스 조회 Repository 인터페이스 |
| `ImageUploadRepository` | 구현 | CKEditor5 이미지 업로드 Repository 구현체 |
| `ImageUploadRepositoryInterface` | 인터페이스 | CKEditor5 이미지 업로드 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
두 갈래입니다.

- **`ImageUploadRepository`** — 자기 테이블(`ckeditor5_image_uploads`) 접근.
- **`ImageReferenceSourceRepository`** — **다른 확장의 콘텐츠 테이블**을 읽습니다. 이 플러그인이
  소유하지 않은 테이블을 훑는 유일한 자리이며, 그래서 소스 목록의 유효성(테이블·컬럼이 실제로
  존재하는가)을 스스로 검증합니다.

두 번째 Repository 의 쿼리는 **본문 문자열 검색**이라 비용이 큽니다. 대상은 보존기간이 지난
업로드로 한정되고, 검색 토큰은 해시와 저장 파일명 **두 개를 OR** 로 겁니다 — 본문에 박히는
URL 형태가 둘(API 폴백형·디스크 직접형)이라 한쪽만 보면 다른 형태를 미참조로 오판합니다.

서비스는 인터페이스만 주입받습니다(구체 클래스 타입힌트 금지).
<!-- @intent END -->
