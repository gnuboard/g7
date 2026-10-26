# 히어로섹션 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `Banner` | `hero_section_banners` | 20 | images→BannerImage, pcImage→BannerImage, mobileImage→BannerImage | - |
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
마이그레이션 3개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_10_24_000001_create_hero_section_banners_table.php` | `hero_section_banners` | - | ✅ |
| `2026_10_24_000002_create_hero_section_banner_images_table.php` | `hero_section_banner_images` | - | ✅ |
| `2026_10_24_000003_create_hero_section_position_settings_table.php` | `hero_section_position_settings` | - | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
신규 설치 마이그레이션 3개. 일시는 UTC 로 저장한다.
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
