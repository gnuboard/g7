# 팝업 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Popup` | `popups` | 17 | images→PopupImage, pcImage→PopupImage, mobileImage→PopupImage | - |
| `PopupImage` | `popup_images` | 12 | popup→Popup | - |
<!-- @generated:models END -->

<!-- @intent START -->
`Popup::statusAt()` 은 PHP 판정이고 목록 필터·정렬·공개 노출은 `PopupRepository` 의 SQL 판정을 쓴다 — 두 판정이 같은지 `PopupStatusParityTest` 가 잠근다. 다국어 값(`image_alt`·`body_html`)은 `{locale: 값}` JSON, 관리용 `title` 은 다국어가 아니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `popup_images` | `PopupImage` |
| `popups` | `Popup` |
<!-- @generated:tables END -->

<!-- @intent START -->
`popups` 하나에 이미지형·직접 작성형을 함께 둔다(유형에 따라 쓰는 컬럼이 갈린다). 이미지는 `popup_images` 로 팝업당 기기별 1장, 저장 전에는 `temp_key` 묶음으로 남는다. 데이터까지 지우는 삭제는 `getDynamicTables()`(자식 먼저)와 이미지 폴더 전체를 지운다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 2개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_10_27_000001_create_popups_table.php` | `popups` | - | ✅ |
| `2026_10_27_000002_create_popup_images_table.php` | `popup_images` | - | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
1.0.0 신규 테이블 두 개. 테스트 DB 에는 `php artisan migrate --env=testing --path=plugins/_bundled/sirsoft-popup/database/migrations` 로 미리 적용한다(테스트는 DatabaseTransactions).
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `Audience` | `string` | 3 | `all`, `guest`, `member` |
| `ContentType` | `string` | 2 | `image`, `html` |
| `DismissMode` | `string` | 3 | `today`, `days`, `close` |
| `DisplayPeriod` | `string` | 2 | `always`, `range` |
| `ImageDevice` | `string` | 2 | `pc`, `mobile` |
| `MobileStyle` | `string` | 2 | `center`, `sheet` |
| `PageScope` | `string` | 2 | `all`, `routes` |
| `PcPosition` | `string` | 2 | `center`, `coords` |
| `PopupDevice` | `string` | 3 | `all`, `pc`, `mobile` |
| `PopupStatus` | `string` | 4 | `active`, `scheduled`, `ended`, `inactive` |
<!-- @generated:enums END -->

<!-- @intent START -->
고정 목록은 모두 Enum 이 SSoT 다 — FormRequest 는 `Rule::enum`, 화면 문구 키는 Enum 값을 그대로 쓴다(`admin.status.{값}` 등).
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `PopupImageRepository` | 구현 | 팝업 이미지 Repository |
| `PopupImageRepositoryInterface` | 인터페이스 | 팝업 이미지 Repository 계약. |
| `PopupRepository` | 구현 | 팝업 Repository |
| `PopupRepositoryInterface` | 인터페이스 | 팝업 Repository 계약 — 팝업 쿼리는 모두 여기를 거친다. |
<!-- @generated:repositories END -->

<!-- @intent START -->
쿼리는 Repository 안에서만 한다. 상태 조건은 `applyStatusFilter`·`applyStatusSort` 한 곳, 노출 후보·다음 상태 전환 시각(캐시 만료 상한)도 Repository 가 SQL 로 구한다.
<!-- @intent END -->
