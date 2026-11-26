# 팝업 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `pc_position` | `enum` | `center` | PC 위치 |
| `pc_left` | `integer` | `80` | 왼쪽에서(px) |
| `pc_top` | `integer` | `120` | 위에서(px) |
| `pc_width` | `integer` | `480` | 너비(px) |
| `pc_height` | `integer` | `520` | 높이(px) |
| `mobile_style` | `enum` | `sheet` | 모바일 표시 |
| `dim_background` | `boolean` | `true` | 팝업 뒤 배경 어둡게 |
| `close_on_backdrop` | `boolean` | `false` | 배경을 누르면 닫기 |
| `autoplay` | `boolean` | `true` | 여러 개일 때 자동 넘김 |
| `autoplay_interval` | `enum` | `6` | 넘김 간격(초) |
| `dismiss_mode` | `enum` | `today` | 닫기 옵션 |
| `dismiss_days` | `integer` | `7` | 보지 않을 일수 |
| `max_upload_mb` | `integer` | `5` | 이미지 최대 용량(MB) |
| `public_cache_minutes` | `integer` | `10` | 사용자 화면 팝업 캐시 시간(분) |
| `public_asset_disk` | `string` | - | 공개 자산 디스크 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
표시 방식 키(`pc_*`·`mobile_style`·`dim_background`·`close_on_backdrop`·`autoplay*`·`dismiss_*`)는 모든 팝업에 똑같이 적용된다. 사용자 화면은 이 값을 `_global.plugins` 로 노출하지 않고 공개 API 응답에 팝업과 함께 싣는다(같은 캐시 세대). 설정을 저장하면 공개 캐시를 비운다. 읽을 때 `Support\DisplaySettings` 가 범위를 한 번 더 맞춘다(설정 파일을 직접 고쳐도 레이어가 깨지지 않게).

`public_asset_disk`는 팝업 이미지(카테고리 `images`)를 둘 공개 자산 디스크다(환경설정 「저장소」 카드). 비우면 코어 환경설정 > 드라이버 > 공개 자산 디스크를 따르고, 카탈로그(설정 응답의 `available_public_asset_disks`) 밖 값은 422 다. 바꾸면 **새로 올리는 이미지부터** 그 디스크에 저장되고 행 `disk` 에 기록된다 — 이미 올린 이미지는 행 disk 에서 그대로 서빙·삭제되고, 팝업 복사는 원본 디스크에서 읽어 지금 디스크에 쓴다. 공개 디스크(public·CDN) 행은 응답에 `direct_url` 이 실리고 사용자 레이어가 원본을 그 주소로 받는다. 공개 디스크(public)는 서버에 `php artisan storage:link` 가 있어야 직접 주소가 웹서버로 나간다. 설정을 다시 비워도 그 디스크에 올린 이미지는 계속 직접 주소로 열린다.

설정 화면이 업로드 한도 안내를 받는 노출 설정 조회(`GET admin/display-settings`)는 팝업 조회 권한 또는 「플러그인 보기」(`core.plugins.read`)로 열린다(OR) — 플러그인 설정 화면은 코어 계약대로 보기 권한으로 읽기 전용 진입하므로, 이 조회가 팝업 권한만 받으면 들어갈 때마다 403 알림이 뜬다. 팝업 조회·편집은 계속 팝업 권한만.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `popups` | 팝업 관리 | `read`, `update`, `delete` | - |
<!-- @generated:permissions END -->

<!-- @intent START -->
권한은 조회 / 등록·수정 / 삭제 3개, 소유 범위(owner_key)가 없다 — 팝업은 사이트 공용 자원이다. 표시 방식 저장은 코어 플러그인 설정 권한(`core.plugins.update`)이고, 팝업 담당자는 조회 권한으로 `display-settings` API 를 읽어 미리보기·권장 비율 안내(PC 는 너비:높이, 모바일은 `mobile_style` 에 따라 하단 시트 약 1:1·화면 중앙 약 4:5)에 쓴다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-popup` | 팝업 관리 | `/admin/plugins/sirsoft-popup/popups` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
메뉴는 그룹 없는 최상위 「팝업 관리」(order 56, 히어로섹션 55 바로 아래)다. 「운영 도구」 메뉴 그룹은 별도 이슈로 뺐다.
<!-- @intent END -->

## 설정 화면 권한

- 들어가기: 「플러그인 보기」(`core.plugins.read`). 「플러그인 수정」 권한이 없으면 읽기 전용 안내와 함께 보이고 입력·저장·변경 단추가 잠깁니다 — 화면 계산값 `isPluginReadOnly` 는 조회 응답 `abilities.can_update !== true` 입니다.
- 저장·변경: 서버가 수정 권한으로 막습니다(아래 「라우트」 표의 권한). 화면 진입 권한을 「수정」으로 올리지 마세요 — 보기 권한만 있는 운영자가 「레이아웃 접근 권한이 없습니다」 오류 화면만 보게 됩니다(잠금 시험 `tests/Unit/Plugins/PluginSettingsLayoutPermissionTest.php`).

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/plugins/sirsoft-popup/...` |
| `web` | `src/routes/web.php` | `/plugins/sirsoft-popup/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
관리자 화면 경로는 플러그인 네임스페이스(`/admin/plugins/sirsoft-popup/popups…`) 안에 둔다. API 의 정적 경로(`summary`·`order`·`bulk-*`)는 `{popup}` 보다 먼저 선언한다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
다른 확장에 의존하지 않는다(`dependencies` 비움). GDPR·에디터 플러그인과는 계약 훅·확장점(`html_editor`)으로만 만난다 — 없으면 쿠키 등록·에디터 대체가 일어나지 않을 뿐 팝업은 동작한다. 직접 작성형 본문 정화를 위해 composer 의존성 `ezyang/htmlpurifier` 를 vendor 번들로 싣는다.
<!-- @intent END -->
