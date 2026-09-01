# Admin Basic — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `templates/_bundled/sirsoft-admin_basic/editor-spec.json` |
| 형태 | 분할 — manifest + `editor-spec/*.json` 13개 블록 |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | `tailwind` |
| 다크 모드 전략 | `ancestor-class` |

> 분할 13블록 · 팔레트 79 · 스타일 컨트롤 303 · 편집 역량 86 · 중첩 컨테이너 19 · 프리뷰 샘플 71 · 페이지 상태 10 · 액션 레시피 14
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
이 스펙만 **분할**되어 있는 데는 이유가 있습니다. 팔레트·컨트롤·컴포넌트 역량을 한 파일에
두면 만 줄을 넘겨 사람이 열어 보기 어려워지고, 블록 하나를 고칠 때마다 파일 전체가 diff 에
잡힙니다. `$include` 는 그 분할을 런타임에 되돌리는 장치이므로 서빙 형태는 단일 파일과
같습니다.

`다크 모드 전략: ancestor-class` 는 Tailwind 규약(`조상 .dark`)을 그대로 따른다는 뜻입니다.
편집기 프리뷰는 페이지 전체가 아니라 캔버스 안만 다크로 바꿔야 하므로, 코어 CSS 서빙이
`.dark` 셀렉터를 프리뷰 전용 마커로 치환해 내보냅니다 — 사용자 페이지 CSS 는 건드리지
않습니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `componentPalette.entries` | 편집기 "요소 추가" 팔레트에 나타나는 항목 | 79 | `editor-spec/componentPalette.json` |
| `componentPalette.groups` | 팔레트 좌측 목록의 묶음 | 2 | `editor-spec/componentPalette.json` |
| `controls` | 재사용 스타일 컨트롤 정의 | 303 | `editor-spec/controls.json` |
| `componentCapabilities` | 컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가) | 86 | `editor-spec/componentCapabilities.json` |
| `nesting.draggable` | 캔버스에서 끌어 옮길 수 있는 컴포넌트 | 84 | `editor-spec/nesting.json` |
| `nesting.containers` | 자식을 담을 수 있는 컴포넌트와 그 허용 규칙 | 19 | `editor-spec/nesting.json` |
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 71 | `editor-spec/sampleData.json` |
| `sampleGlobal` | `_global.*` 프리뷰 baseline 시드 | 5 | `editor-spec/sampleGlobal.json` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 10 | `editor-spec/states.json` |
| `stateLabels` | 상태값 친화 명칭 카탈로그 | 8 | `editor-spec/stateLabels.json` |
| `actionRecipes` | 친화 명칭 → 액션 JSON 레시피 | 14 | `editor-spec/actionRecipes.json` |
| `conditionRecipes.operators` | 조건 표현식에 쓸 수 있는 연산자 | 35 | `editor-spec/conditionRecipes.json` |
| `computedRecipes` | 계산값 레시피 | 4 | `editor-spec/computedRecipes.json` |
| `errorRecipes` | 오류 처리 레시피 | 7 | `editor-spec/errorRecipes.json` |
| `loadingComponents` | 로딩 표시 컴포넌트 후보 | 2 | `editor-spec/loadingComponents.json` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
블록 15행이 이 템플릿이 편집기에 제공하는 전부입니다. 수가 큰 셋(`controls` 303,
`componentCapabilities` 86, `nesting.draggable` 84)이 곧 "편집기로 무엇을 조작할 수
있는가" 의 상한입니다 — 여기 없는 속성은 편집기 속성 패널에 나타나지 않습니다.

컴포넌트를 새로 만들었는데 편집기에서 속성을 못 바꾸겠다면 `componentCapabilities` 에
그 컴포넌트가 없는 것입니다. 팔레트에 아예 안 보인다면 `componentPalette.entries` 입니다.
둘은 다른 자리라 한쪽만 고치면 증상이 절반만 사라집니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 그룹 | 종류 | 컴포넌트 수 |
|---|---|---|
| 디자인 요소 | `design` | 62 |
| DB 요소 | `data` | 17 |
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
그룹을 `디자인 요소`(62)와 `DB 요소`(17) 둘로만 나눈 것은 운영자가 편집기에서 하는
판단이 그 둘로 갈리기 때문입니다 — "모양을 만드는 것" 과 "데이터를 붙이는 것".

그룹을 늘리면 팔레트가 잘 정리된 것처럼 보이지만, 운영자는 찾으려는 컴포넌트가 어느
묶음에 있는지 매번 추측하게 됩니다. 컴포넌트를 추가할 때는 새 그룹을 만들기 전에 기존
두 그룹 중 어디에 속하는지 먼저 판단합니다.

팔레트에 **무엇이 보이는가**를 정하는 것은 `groups` 입니다. `entries` 는 그 컴포넌트의
친화 라벨과 신규 노드 골격(`defaultNode`)을 줄 뿐이라, `entries` 에만 있고 어느 묶음에도
없는 컴포넌트는 팔레트에 나타나지 않습니다. 반대로 `groups` 에만 있고 `entries` 가 없는
것은 정상이며, 라벨이 컴포넌트 정의의 설명으로 폴백됩니다.

지금은 두 수가 우연히 같지만(entries 79 · 그룹 합계 62+17=79), 같아야 한다는 규칙은 없습니다. 컴포넌트를
추가했는데 팔레트에 안 보인다면 먼저 `groups` 를 봅니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 71 | `trustedProxy` · `sitemap_status` · `sitemap_progress_ws` · `locales` · `me` · `installed_modules` · `active_plugins` · `users` · `roles` · `availableChannels` · `identityProviders` · `identityPurposes` … 외 59개 |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 10 | `_admin_base` · `*/admin/users` · `*/admin/users/:id/edit` · `*/admin/settings` · `*/admin/reset-password` · `*/admin/roles/:id/edit` · `*/admin/identity/challenge` · `*/admin/templates/:type` · `*/admin/login` · `*/admin/forgot-password` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`byDataSourceId` 70종은 코어 관리자 화면 전체를 덮습니다. 이 템플릿의 샘플이 코어뿐
아니라 **여러 확장의 공용 ID**(`settings`·`roles`·`me` 등)까지 담는 것은 설계입니다 —
확장마다 같은 ID 의 샘플을 각자 두면 어느 것이 쓰이는지가 합본 순서에 좌우됩니다.

`states.groups` 10종에 `_admin_base` 가 들어 있는 것은 모바일 드로어 때문입니다.
드로어는 햄버거를 눌러야 열리는데 편집기 캔버스에는 클릭이 없으므로, 열린 상태를 주입하지
않으면 드로어 **안쪽을 편집할 수 없습니다.**

미커버로 잡힌 `trustedProxy` 는 신뢰 프록시 진단 영역입니다. 이 영역은 서버 구성에 따라
내용이 달라지는 읽기 전용 진단이라 편집기에서 손댈 것이 없지만, 샘플이 없으면 그 자리가
빈 채로 보여 운영자가 레이아웃이 깨진 것으로 오해할 수 있습니다.
<!-- @intent END -->

## 수정 시 동반 의무

<!-- @generated:editor-spec-obligations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 이런 변경을 했다면 | 편집기 스펙에서 함께 할 일 |
|---|---|
| 컴포넌트를 새로 만들었다 | `componentPalette` 에 항목 추가 · `componentCapabilities` 에 편집 역량 선언 · `nesting` 에 담길 자리 규정 |
| 레이아웃에 `data_sources` 를 추가했다 | `sampleData` 에 같은 ID 로 프리뷰 응답 추가 (없으면 편집기 캔버스만 빈 화면) |
| `_global.*` 을 새로 읽는다 | `sampleGlobal` 에 baseline 값 추가 |
| 빈 목록·오류 같은 화면 변종을 추가했다 | `states` 에 변종 추가 · `stateLabels` 에 친화 명칭 |
| 새 액션·조건 패턴을 도입했다 | `actionRecipes` / `conditionRecipes` 에 친화 명칭 등록 |

편집기 스펙은 JSON 이므로 빌드가 필요 없습니다. 다만 편집기 서빙은 **활성 디렉토리만** 읽으므로(`_bundled` 폴백 없음) 편집 후 반드시 반영합니다:

```bash
php artisan template:update sirsoft-admin_basic --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
위 표는 "무엇을 함께 고치는가" 만 말합니다. 실제로 놓치는 자리는 **반영 절차**입니다 —
편집기가 읽는 것은 활성 디렉토리이고 `_bundled` 폴백이 없으므로, `_bundled` 에서 스펙을
고치고 update 커맨드를 돌리지 않으면 편집기에는 **직전 내용이 그대로 보입니다.** 파일은
고쳤는데 화면이 안 바뀌었다면 거의 이 경우입니다.

또 하나는 검증 시점입니다. 편집기 스펙은 스키마 검증을 통과해도 "레이아웃이 실제로 쓰는
ID 와 맞는가" 는 확인해 주지 않습니다. 그 어긋남은 편집기 캔버스에서만 빈 화면으로
나타나고 실제 화면은 정상이므로, 위 "샘플 데이터와 페이지 상태" 절의 미커버 목록이 유일한
통로입니다.

이 템플릿은 팔레트·역량·중첩을 모두 소유하므로 컴포넌트를 하나 추가할 때 손댈 자리가
가장 많습니다. `componentPalette.entries` 에 넣고, `componentPalette.groups` 중 하나에
이름을 넣고, `componentCapabilities` 에 편집 가능한 속성을 선언하고, `nesting` 에
어디에 담길 수 있는지를 적습니다. 넷 중 하나라도 빠지면 그 컴포넌트는 편집기에서
**절반만 동작**하고, 어느 단계가 빠졌는지는 증상으로 구분됩니다 (위 "선언 블록" 절 참조).
<!-- @intent END -->
