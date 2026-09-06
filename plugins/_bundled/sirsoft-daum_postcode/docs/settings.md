# Daum 우편번호 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `display_mode` | `enum` | `layer` | 표시 방식 |
| `popup_width` | `integer` | `500` | 팝업 너비 (px) |
| `popup_height` | `integer` | `600` | 팝업 높이 (px) |
| `theme_color` | `string` | `#1D4ED8` | 테마 색상 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
4개 전부 **표시 방식**에 관한 것입니다. 기능을 켜고 끄는 토글이 없는 것은 플러그인 활성화
자체가 곧 기능 활성화이기 때문입니다.

| 키 | 기본값 | 왜 그 기본값인가 |
|---|---|---|
| `display_mode` | `layer` | 팝업은 브라우저·확장 프로그램에 차단될 수 있고, 차단되면 사용자에게는 "버튼을 눌러도 아무 일이 없는" 것으로 보입니다. 레이어는 그 위험이 없습니다 |
| `popup_width` · `popup_height` | 500 × 600 | 팝업 모드에서만 쓰입니다 |
| `theme_color` | `#1D4ED8` | 검색 창의 강조 색 |

`getConfigValues()` 가 같은 값을 한 번 더 선언합니다 — 스키마의 `default` 는 설정 화면의
초기값이고, 이쪽은 설정이 아직 저장되지 않은 상태에서 코드가 읽는 폴백입니다. **두 곳이
어긋나면** 설정을 한 번도 저장하지 않은 사이트와 저장한 사이트의 동작이 달라지므로 함께
고칩니다.

설정 화면은 `resources/layouts/admin/plugin_settings.json` 이 그립니다 — 코어가 플러그인
디렉토리의 이 고정 경로를 찾으므로 파일 이름을 바꾸면 설정 화면 자체가 사라집니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
선언하지 않습니다. 주소 검색은 그 화면을 볼 수 있는 사람이면 누구나 쓰는 보조 기능이고,
저장하는 데이터가 없어 접근을 나눌 대상 자체가 없습니다.

설정 변경은 코어의 플러그인 설정 권한이 이미 관장합니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
등록하지 않습니다. 자체 관리 화면이 없기 때문입니다.

설정은 코어의 플러그인 목록에서 이 플러그인의 설정으로 들어가는 공통 경로를 씁니다 — 코어가
`resources/layouts/admin/plugin_settings.json` 을 찾아 그리므로 자체 메뉴가 필요 없습니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_라우트 파일이 없습니다._
<!-- @generated:routes END -->

<!-- @intent START -->
없습니다. `resources/routes.json` 의 `routes` 가 빈 배열이고 서버 라우트 파일도 없습니다.

이 플러그인은 서버와 통신하지 않습니다 — 주소 데이터는 브라우저가 Daum 서버에서 직접
받아옵니다. 그래서 라우트 캐시·미들웨어·인증 같은 서버측 관심사가 전부 해당하지 않습니다.

만약 서버 라우트가 필요해진다면(예: 검색 결과 프록시) 그 순간 이 플러그인의 성격이 바뀝니다 —
외부 서비스 호출이 서버에서 일어나면 타임아웃·요율 제한·자격 증명 관리가 따라옵니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `sirsoft-basic` | 템플릿 | `>=1.0.0` |
<!-- @generated:dependencies END -->

<!-- @intent START -->
양방향 모두 비어 있습니다. 코어만으로 동작하고, manifest 상 이 플러그인을 요구하는 확장도
없습니다.

**실제 관계는 확장점으로 맺어집니다.** 이커머스의 배송지 입력 화면이 `address_search_slot`
을 열어 두고 있고, 이 플러그인이 그 자리를 채웁니다. manifest 의존이 아닌 것은 방향이
맞습니다 — 이 플러그인이 없어도 그 화면은 주소를 직접 입력받아 정상 동작합니다.

대신 그 대가로 **대상 화면이 확장점을 없애면 이 플러그인은 오류 없이 무력해집니다.** 조각이
붙는 확장점 이름은 상대가 소유하므로, 상대 확장을 업그레이드한 뒤에는 검색 버튼이 여전히
보이는지 확인합니다.

manifest 의 `trusted_script_hosts` 는 의존이 아니라 **외부 서비스에 대한 신뢰 선언**입니다.
이 목록을 늘릴 때는 반드시 `trusted_script_hosts_reason` 에 사유를 함께 적습니다.
<!-- @intent END -->
