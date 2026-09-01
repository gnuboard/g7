# Hello 모듈 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

기본값 파일: `config/settings/defaults.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
설정이 없습니다. 이 샘플은 설정 화면 없이도 모듈의 계층 구조를 보여줄 수 있어 일부러 두지
않았습니다.

설정 스키마와 설정 화면 레이아웃의 예시는 함께 제공되는 `gnuboard7-hello_plugin` 에 있습니다 —
`getSettingsSchema()` 선언과 `resources/layouts/admin/plugin_settings.json` 이 짝을 이루는
형태입니다.
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `memos` | 메모 관리 | `read`, `create`, `update`, `delete` | `memo` |
<!-- @generated:permissions END -->

<!-- @intent START -->
`memos` 하나에 `read`/`create`/`update`/`delete` 네 액션입니다. 라우트 키 `memo` 가 선언되어
있어 관리자 라우트에 스코프 미들웨어가 걸립니다.

권한 이름은 코어가 `{확장식별자}.{카테고리}.{액션}` 으로 조립합니다
(`gnuboard7-hello_module.memos.read`). 확장 식별자가 앞에 붙으므로 다른 확장과 이름이 겹칠
걱정이 없습니다.

**권한만 추가하고 메뉴를 빠뜨리면 화면에 도달할 길이 없고, 반대면 눌러도 403 입니다.** 새
화면을 더할 때는 권한·메뉴·라우트 이름 셋이 서로를 정확히 가리키는지 함께 확인합니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `gnuboard7-hello_module` | Hello 메모 | `/admin/memos` | - |
<!-- @generated:menus END -->

<!-- @intent START -->
관리자 메뉴 하나(`/admin/memos`)입니다. 하위 메뉴가 없어 최상위 항목이 바로 목록 화면으로
갑니다.

메뉴는 **권한과 짝을 이룰 때만 보입니다** — 그 역할에 `memos.read` 가 없으면 렌더되지
않습니다. 설치 직후 메뉴가 보이지 않는다면 대부분 권한 부여가 빠진 것입니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/modules/gnuboard7-hello_module/...` |
| `web` | `src/routes/web.php` | `/modules/gnuboard7-hello_module/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
파일이 둘(`api.php` · `web.php`)인 것이 이 샘플의 학습 포인트입니다. 실제 도메인 모듈은
대개 `api.php` 만 두지만, **모듈이 web 라우트도 가질 수 있다**는 사실을 보이기 위해 둘 다
둡니다.

두 파일의 URL prefix 가 다릅니다 — API 는 `/api/modules/{id}/`, web 은 `/modules/{id}/`.
확장이 다른 확장의 경로를 침범하지 않도록 코어가 강제하는 규칙입니다.

모든 라우트에 `name()` 이 필요합니다. 이름이 없으면 미들웨어 self-gate 의 `targets` 패턴과
IDV 정책의 라우트명 인덱스가 그 라우트를 찾지 못해, 보호가 걸린 것처럼 보이지만 실제로는
통과합니다.

라우트를 바꾼 뒤에는 라우트 캐시를 다시 굽습니다. 확장 라우트는 활성 상태인 확장의 것만
등록되고, 캐시에 없는 라우트는 예외도 경고도 없이 404 가 됩니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `gnuboard7-hello_plugin` | 플러그인 | `>=0.1.0` |
| `gnuboard7-hello_user_template` | 템플릿 | `>=0.1.0` |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈은 아무 확장에도 의존하지 않습니다. 관계는 한 방향으로 들어옵니다 — 학습용 플러그인과
학습용 사용자 템플릿이 이 모듈을 요구합니다.

**두 의존의 성격이 다른 것이 학습 포인트**입니다:

| 확장 | 어떻게 묶이는가 |
|---|---|
| `gnuboard7-hello_plugin` | 이 모듈이 발행하는 훅(`memo.created`)을 구독 — 확장이 다른 확장의 흐름에 끼어드는 형태 |
| `gnuboard7-hello_user_template` | 이 모듈의 공개 API 를 `data_sources` 로 소비 — 모듈이 데이터를, 템플릿이 화면을 담당하는 경계 |

넷을 모두 설치하면 이 세 역할(데이터·화면·부가 동작)이 어떻게 나뉘는지 실제로 확인할 수
있습니다.

발행 훅 이름이나 공개 API 응답 형태를 바꾸면 두 확장이 조용히 끊깁니다 — 샘플에서도 그 규율은
같습니다.
<!-- @intent END -->
