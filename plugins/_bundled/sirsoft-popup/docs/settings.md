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

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.g7.html`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
표시 방식 키(`pc_*`·`mobile_style`·`dim_background`·`close_on_backdrop`·`autoplay*`·`dismiss_*`)는 모든 팝업에 똑같이 적용된다. 사용자 화면은 이 값을 `_global.plugins` 로 노출하지 않고 공개 API 응답에 팝업과 함께 싣는다(같은 캐시 세대). 설정을 저장하면 공개 캐시를 비운다. 읽을 때 `Support\DisplaySettings` 가 범위를 한 번 더 맞춘다(설정 파일을 직접 고쳐도 레이어가 깨지지 않게).
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
