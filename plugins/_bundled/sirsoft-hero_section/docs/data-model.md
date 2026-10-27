# 히어로섹션 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Banner` | `hero_section_banners` | 21 | images→BannerImage, pcImage→BannerImage, mobileImage→BannerImage | - |
| `BannerImage` | `hero_section_banner_images` | 12 | banner→Banner | - |
| `PositionSetting` | `hero_section_position_settings` | 5 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
`Banner`(배너)·`BannerImage`(PC·모바일 이미지)·`PositionSetting`(위치별 슬라이더 설정)을 둔다. 노출 상태는 컬럼이 아니라 `Banner::status` 접근자(조회 시점 계산)다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `hero_section_banner_images` | `BannerImage` |
| `hero_section_banners` | `Banner` |
| `hero_section_position_settings` | `PositionSetting` |
<!-- @generated:tables END -->

<!-- @intent START -->
`hero_section_banners`·`hero_section_banner_images`·`hero_section_position_settings` 를 소유한다. 다국어 문구는 `text` 컬럼에 `{ko:…, en:…}` JSON 으로 저장한다. 플러그인 삭제(데이터 삭제 선택) 때만 지운다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 4개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_10_24_000001_create_hero_section_banners_table.php` | `hero_section_banners` | - | ✅ |
| `2026_10_24_000002_create_hero_section_banner_images_table.php` | `hero_section_banner_images` | - | ✅ |
| `2026_10_24_000003_create_hero_section_position_settings_table.php` | `hero_section_position_settings` | - | ✅ |
| `2026_10_27_000004_add_presentation_and_lock_version_to_hero_section_banners.php` | - | `hero_section_banners` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
신규 설치는 마이그레이션 4개를 순서대로 적용하고, 기존 설치는 미적용 마이그레이션만 적용한다. 일시는 UTC 로 저장한다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `BannerDevice` | `string` | 2 | `pc`, `mobile` |
| `BannerStatus` | `string` | 4 | `active`, `scheduled`, `ended`, `inactive` |
| `DisplayPeriod` | `string` | 2 | `always`, `range` |
| `HeroPosition` | `string` | 2 | `home_hero`, `shop_hero` |
| `SlideEffect` | `string` | 3 | `slide`, `fade`, `none` |
| `TextAlign` | `string` | 3 | `left`, `center`, `right` |
| `TextOverlay` | `string` | 3 | `none`, `dark`, `light` |
<!-- @generated:enums END -->

<!-- @intent START -->
위치(`home_hero`·`shop_hero`), 상태(노출중·예약·종료·비활성), 노출 기간(상시·기간 지정), 정렬(좌·중·우), 전환 효과(슬라이드·페이드·없음), 기기(pc·mobile)를 Enum 으로 둔다. 위치 Enum 이 노출 위치 목록의 SSoT 다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `BannerImageRepository` | 구현 | 배너 이미지 Repository |
| `BannerImageRepositoryInterface` | 인터페이스 | 배너 이미지 Repository 인터페이스 |
| `BannerRepository` | 구현 | 배너 Repository |
| `BannerRepositoryInterface` | 인터페이스 | 배너 Repository 인터페이스 — 배너 쿼리는 모두 여기서 만든다. |
| `PositionSettingRepository` | 구현 | 위치별 슬라이더 설정 Repository |
| `PositionSettingRepositoryInterface` | 인터페이스 | 위치별 슬라이더 설정 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
모든 쿼리는 Repository 안에서 만든다. 상태 필터·정렬·공개 노출은 Repository 의 같은 기간 조건 하나를 쓴다 — PHP 에서 거르면 쪽 수가 어긋난다.
<!-- @intent END -->

## 편집 표시 설정과 동시 수정

추가 마이그레이션은 `hero_section_banners.presentation`(nullable JSON)과 `lock_version`(unsigned integer, 기본 0)을 더합니다. 기존 배너는 중앙 초점·배경 없음으로 해석합니다. 모델은 presentation을 배열로, 세대를 정수로 캐스팅하며 클라이언트의 lock_version 직접 대입을 허용하지 않습니다. 일반 수정·일괄 사용 여부 변경은 세대를 증가시킵니다. 수정·단건 및 일괄 삭제는 부모 행 잠금 순서를 공유합니다.
