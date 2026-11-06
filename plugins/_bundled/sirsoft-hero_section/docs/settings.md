# 히어로섹션 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `max_upload_mb` | `integer` | `5` | 이미지 최대 용량(MB) |
| `max_video_upload_mb` | `integer` | `30` | 영상 최대 용량(MB) |
| `public_cache_minutes` | `integer` | `10` | 사용자 화면 배너 캐시 시간(분) |
| `public_asset_disk` | `string` | - | 공개 자산 디스크 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`slot_banners`(위치 → 노출 중·예약 배너 유무)는 환경설정 화면에 없는 **배너 유무 힌트**다 — 첫 접속에도 배너가 없는 위치에 스켈레톤을 그리지 않게 한다. 원본은 배너 테이블이고 플러그인 설정 저장 직전 필터가 다시 채우며, 배너 등록·수정·삭제·일괄 처리로 유무가 달라질 때만 다시 저장한다. 기간이 끝나 저절로 빠진 배너는 다음 저장 때 반영된다 — 그 사이 브라우저 저장소를 쓸 수 있는 재방문자는 직전 방문 기억이 스켈레톤을 막고, 첫 방문자와 저장소가 막힌 방문자(동의 관리 플러그인의 기능 쿠키 미동의 포함)에게는 받는 동안 잠깐 보인다. 힌트나 기억이 틀려도 있는 배너는 그대로 그려지고 스켈레톤만 건너뛴다.

공개 배너 API(`GET /api/plugins/sirsoft-hero_section/hero`)의 속도 제한은 접속 주소당 분당 600회다. 프록시·CDN 뒤에서는 `TRUSTED_PROXIES` 를 설정해야 방문자 주소로 센다 — 설정이 없으면 방문자 전체가 프록시 주소 하나로 묶여 429 가 날 수 있다.

`slot_widths`(위치 → `content`·`full`)는 환경설정 화면에 없는 **표시 폭 힌트**다. 원본은 위치 설정이고, 플러그인 설정을 저장할 때마다 저장 직전 필터(`core.plugin_settings.filter_save_data`)가 위치 설정 값으로 다시 채운다(표시 폭이 바뀐 위치 설정 저장·설정 초기화·활성화·업데이트 때 다시 저장). 사용자 화면은 첫 로드 때 받는 플러그인 설정(`frontend_schema` 로 이 값만 노출)으로 배너를 받기 전 스켈레톤 폭을 고른다. 그래서 표시 폭이 바뀐 위치 설정 저장에는 코어 활동 로그 「플러그인 설정 변경」도 한 건 남는다. `slot_overlays`(위치 → boolean)도 같은 방식의 **메뉴 겹침 힌트**다 — 위치 설정의 메뉴 겹치기가 켜져 있고 표시 폭이 화면 전체일 때만 true 이며, 사용자 화면은 배너를 받기 전에도 스켈레톤을 메뉴 밑으로 넣어 배너가 와도 화면이 위아래로 밀리지 않게 한다. 표시 폭이나 메뉴 겹치기가 바뀐 위치 설정 저장에서 다시 저장한다.

실제로 받을 수 있는 업로드 크기는 `max_upload_mb` 와 서버 PHP `upload_max_filesize`·`post_max_size` 중 가장 작은 값이다. `public_cache_minutes` 는 사용자 화면 공개 응답 캐시 시간이며, 예약 시작·종료 시각을 넘기지 않도록 그보다 먼저 만료되고 배너가 바뀌면 바로 지워진다. 0 이면 캐시를 쓰지 않는다.

`public_asset_disk`(#878)는 배너 이미지·영상(카테고리 `images`)을 둘 공개 자산 디스크다. 비우면 코어 환경설정 > 드라이버 > 공개 자산 디스크를 따르고, 카탈로그(설정 응답의 `available_public_asset_disks`, 코어 카탈로그 + 확장이 등록한 디스크) 밖 값은 422 다. 바꾸면 **새로 올리는 파일부터** 그 디스크에 저장되고 행 `disk` 에 기록된다 — 이미 올린 파일은 행 disk 에서 그대로 서빙·삭제된다. 공개 디스크(public·CDN)에 올린 행은 응답에 `direct_url` 이 실리고 사이트 슬라이더가 원본을 그 주소로 받는다(PHP 를 거치지 않는다). 공개 디스크(public)는 서버에 `php artisan storage:link` 가 있어야 직접 주소가 웹서버로 나간다(없으면 코어 대체 라우트가 PHP 로 내보낸다). 설정을 다시 비워도 그 디스크에 올린 파일은 계속 직접 주소로 열린다 — 공개 위치에 있는 파일이므로 배너를 지워야 주소가 닫힌다. 저장했던 디스크를 등록하던 확장이 꺼지면 설정 화면이 「코어 설정 따름」이 아닌 실제 동작(스트리밍)으로 바꿔 보이고 옛 이름을 안내한다.
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

## 설정 화면 권한

- 들어가기: 「플러그인 보기」(`core.plugins.read`). 「플러그인 수정」 권한이 없으면 읽기 전용 안내와 함께 보이고 입력·저장·변경 단추가 잠깁니다 — 화면 계산값 `isPluginReadOnly` 는 조회 응답 `abilities.can_update !== true` 입니다.
- 저장·변경: 서버가 수정 권한으로 막습니다(아래 「라우트」 표의 권한). 화면 진입 권한을 「수정」으로 올리지 마세요 — 보기 권한만 있는 운영자가 「레이아웃 접근 권한이 없습니다」 오류 화면만 보게 됩니다(#434, 잠금 시험 `tests/Unit/Plugins/PluginSettingsLayoutPermissionTest.php`).

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
