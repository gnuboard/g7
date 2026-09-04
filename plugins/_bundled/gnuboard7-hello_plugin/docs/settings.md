# Hello 플러그인 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 키 | 타입 | 기본값 | 설명 |
|---|---|---|---|
| `log_enabled` | `boolean` | `true` | 로그 기록 사용 |

기본값 파일: `config/settings/defaults.json` · 설정 화면 레이아웃: `resources/layouts/admin/plugin_settings.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
하나뿐이며 그 하나가 규약의 본보기입니다.

`log_enabled` 는 이 플러그인의 부가 동작(로그 기록)을 끄는 토글입니다. **부가 동작은 설정으로
끌 수 있어야 한다**는 것이 규약이며, 설정 없이 무조건 동작하면 그 확장을 설치한 사이트는 멈출
방법이 없습니다.

읽기는 `plugin_setting()`(또는 `PluginSettingsService`)으로 하며, 리스너가 동작 **직전에**
확인합니다 — 등록 시점에 확인하면 설정을 바꿔도 다음 재부팅까지 반영되지 않습니다.

설정 화면은 `resources/layouts/admin/plugin_settings.json` 이 그립니다. **파일 이름이
계약**이므로 코어가 이 고정 경로를 찾으며, 이름을 바꾸면 설정 화면 자체가 사라집니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_선언된 권한이 없습니다._
<!-- @generated:permissions END -->

<!-- @intent START -->
선언하지 않습니다. 이 샘플에는 접근을 나눌 화면도 데이터도 없습니다.

설정 변경은 코어의 플러그인 설정 권한이 관장합니다. 플러그인이 자기 권한을 선언할 때는
`{확장식별자}.{카테고리}.{액션}` 으로 이름이 조립되므로 다른 확장과 겹칠 걱정이 없습니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 메뉴가 없습니다._
<!-- @generated:menus END -->

<!-- @intent START -->
등록하지 않습니다. 이 샘플에는 자체 관리 화면이 없습니다.

설정은 코어의 플러그인 목록에서 이 플러그인의 설정으로 들어가는 공통 경로를 씁니다 — 코어가
`resources/layouts/admin/plugin_settings.json` 을 찾아 그리므로 자체 메뉴가 필요 없습니다.

메뉴를 등록할 때는 권한과 짝을 이뤄야 합니다. 권한만 추가하고 메뉴를 빠뜨리면 화면에 도달할
길이 없고, 반대면 눌러도 403 입니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `web` | `src/routes/web.php` | `/plugins/gnuboard7-hello_plugin/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
`web.php` 하나뿐이며, **플러그인도 라우트를 가질 수 있다**는 사실을 보이기 위한 예시입니다.

URL prefix 가 `/plugins/{식별자}/` 로 고정되는 것에 주의합니다. 확장이 다른 확장이나 코어의
경로를 침범하지 않도록 코어가 강제하는 규칙이며, 이 네임스페이스 밖의 경로를 선언하면 어느
쪽이 이기는지가 설치 순서에 좌우됩니다.

모든 라우트에 `name()` 이 필요합니다. 라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다 —
확장 라우트는 활성 상태인 확장의 것만 등록되고, 캐시에 없는 라우트는 예외도 경고도 없이
404 가 됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

| 확장 | 유형 | 버전 제약 | 번들 |
|---|---|---|---|
| `gnuboard7-hello_module` | 모듈 | `>=0.1.0` | ✅ |

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

없음.
<!-- @generated:dependencies END -->

<!-- @intent START -->
`gnuboard7-hello_module` 에 의존합니다(`>=0.1.0`).

**이 의존 선언 자체가 학습 포인트**입니다. 훅 구독은 상대가 없으면 발화하지 않을 뿐이라 보통은
manifest 의존으로 올리지 않습니다 — "없으면 그 기능만 비는" 관계이기 때문입니다. 그런데 이
샘플은 **그 모듈의 훅을 보는 것 자체가 목적**이라 모듈 없이는 존재 이유가 없습니다.

실제 플러그인에서는 이 둘을 구분해 판단합니다:

| 관계 | 의존 선언 |
|---|---|
| 없으면 그 기능만 비고 나머지는 정상 | 선언하지 않는다 (훅 구독으로 충분) |
| 없으면 확장이 성립하지 않는다 | manifest 의존으로 선언 |

의존을 과하게 선언하면 그 확장을 비활성화할 때 이쪽까지 함께 막히고, 부족하게 선언하면 상대가
없을 때 조용히 아무 일도 하지 않습니다.
<!-- @intent END -->
