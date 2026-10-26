# 히어로섹션 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `max_upload_mb` | `integer` | `5` | 이미지 최대 용량(MB) |
| `public_cache_minutes` | `integer` | `10` | 사용자 화면 배너 캐시 시간(분) |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
실제로 받을 수 있는 업로드 크기는 `max_upload_mb` 와 서버 PHP `upload_max_filesize`·`post_max_size` 중 가장 작은 값이다. `public_cache_minutes` 는 사용자 화면 공개 응답 캐시 시간이며, 예약 시작·종료 시각을 넘기지 않도록 그보다 먼저 만료되고 배너가 바뀌면 바로 지워진다. 0 이면 캐시를 쓰지 않는다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `banners` | 배너 관리 | `read`, `update`, `delete` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
`sirsoft-hero_section.banners.read`(조회)·`update`(등록·수정·순서·슬라이더 설정)·`delete`(삭제) 3개를 둔다. 배너는 사이트 공용 자원이라 owner_key 범위를 두지 않는다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-hero_section` | 히어로섹션 관리 | `/admin/plugins/sirsoft-hero_section/banners` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
관리자 메뉴 「히어로섹션 관리」(`/admin/plugins/sirsoft-hero_section/banners`)를 등록한다. 환경설정 화면은 플러그인 관리의 설정 단추로 연다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-hero_section/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-hero_section/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
웹 라우트는 쓰지 않는다. `src/routes/api.php` 에 관리자 API(`/api/plugins/sirsoft-hero_section/admin/...`)와 공개 API(`/hero`, `/images/{hash}`)를 둔다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
다른 확장에 의존하지 않는다. sirsoft-basic 의 `home_hero`·`shop_hero` 는 템플릿 계약 확장점이라 의존 선언 대상이 아니다.
<!-- @intent END -->
