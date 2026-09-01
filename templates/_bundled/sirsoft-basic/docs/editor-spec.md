# Basic — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `templates/_bundled/sirsoft-basic/editor-spec.json` |
| 형태 | 분할 — manifest + `editor-spec/*.json` 13개 블록 |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | `tailwind` |
| 다크 모드 전략 | `ancestor-class` |

> 레이아웃 편집기 스펙 — Phase 3 (nesting + componentPalette 블록).  controls/componentCapabilities/actionRecipes 등은 Phase 4/5 에서 추가.
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
사용자 템플릿의 스펙도 관리자 템플릿과 같은 분할 형태입니다. 두 템플릿이 형태를 공유하는
것은 의도입니다 — 편집기는 어느 템플릿이 활성이든 같은 방식으로 스펙을 읽어야 하고,
템플릿마다 형태가 다르면 편집기가 템플릿별 분기를 갖게 됩니다.

`다크 모드 전략: ancestor-class` 역시 같습니다. 프리뷰 격리 규칙을 두 템플릿이 공유하므로
편집기 캔버스의 다크 표현이 템플릿에 따라 달라지지 않습니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `componentPalette.entries` | 편집기 "요소 추가" 팔레트에 나타나는 항목 | 45 | `editor-spec/componentPalette.json` |
| `componentPalette.groups` | 팔레트 좌측 목록의 묶음 | 2 | `editor-spec/componentPalette.json` |
| `controls` | 재사용 스타일 컨트롤 정의 | 173 | `editor-spec/controls.json` |
| `componentCapabilities` | 컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가) | 51 | `editor-spec/componentCapabilities.json` |
| `nesting.draggable` | 캔버스에서 끌어 옮길 수 있는 컴포넌트 | 45 | `editor-spec/nesting.json` |
| `nesting.containers` | 자식을 담을 수 있는 컴포넌트와 그 허용 규칙 | 14 | `editor-spec/nesting.json` |
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 59 | `editor-spec/sampleData.json` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 1 | `editor-spec/sampleData.json` |
| `sampleGlobal` | `_global.*` 프리뷰 baseline 시드 | 6 | `editor-spec/sampleGlobal.json` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 17 | `editor-spec/states.json` |
| `stateLabels` | 상태값 친화 명칭 카탈로그 | 7 | `editor-spec/stateLabels.json` |
| `actionRecipes` | 친화 명칭 → 액션 JSON 레시피 | 20 | `editor-spec/actionRecipes.json` |
| `conditionRecipes.operators` | 조건 표현식에 쓸 수 있는 연산자 | 37 | `editor-spec/conditionRecipes.json` |
| `computedRecipes` | 계산값 레시피 | 4 | `editor-spec/computedRecipes.json` |
| `errorRecipes` | 오류 처리 레시피 | 7 | `editor-spec/errorRecipes.json` |
| `loadingComponents` | 로딩 표시 컴포넌트 후보 | 2 | `editor-spec/loadingComponents.json` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
블록 16행 중 팔레트가 관리자 템플릿보다 작습니다(45 대 79). 사용자 화면은 관리자 화면보다
쓰는 컴포넌트가 좁기 때문이고, 이 차이가 곧 두 템플릿이 별개로 존재하는 이유입니다 —
사용자 편집기에 관리자 전용 컴포넌트를 늘어놓으면 운영자가 쓸 수 없는 것을 고르게 됩니다.

컴포넌트를 추가할 때 "관리자에도 있으니 여기도" 라는 판단은 하지 않습니다. 그 컴포넌트가
사용자 화면에서 실제로 쓰이는지가 기준입니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 그룹 | 종류 | 컴포넌트 수 |
|---|---|---|
| 디자인 요소 | `design` | 36 |
| DB 요소 | `data` | 9 |
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
그룹은 관리자 템플릿과 같은 둘(`디자인 요소` 36 · `DB 요소` 9)입니다. 그룹 체계를
공유하는 것은 운영자가 두 편집기를 오갈 때 같은 자리에서 같은 종류를 찾게 하기
위해서입니다.

팔레트에 **무엇이 보이는가**를 정하는 것은 `groups` 입니다. `entries` 는 그 컴포넌트의
친화 라벨과 신규 노드 골격(`defaultNode`)을 줄 뿐이라, `entries` 에만 있고 어느 묶음에도
없는 컴포넌트는 팔레트에 나타나지 않습니다. 반대로 `groups` 에만 있고 `entries` 가 없는
것은 정상이며, 라벨이 컴포넌트 정의의 설명으로 폴백됩니다.

지금은 두 수가 우연히 같지만(entries 45 · 그룹 합계 36+9=45), 같아야 한다는 규칙은 없습니다. 컴포넌트를
추가했는데 팔레트에 안 보인다면 먼저 `groups` 를 봅니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 59 | `mileage_balance` · `mileage_history` · `user` · `userNotifications` · `searchResults` · `profile` · `userProfile` · `addresses` · `userAddresses` · `boardList` · `boards` · `home_boards` … 외 47개 |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 1 | `/api/modules/sirsoft-page/pages/*` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 17 | `/login` · `/search` · `/mypage/notifications` · `/mypage/profile/edit` · `/forgot-password` · `/reset-password` · `/identity/challenge` · `/mypage/change-password` · `/mypage/wishlist` · `/mypage/addresses` · `/users/:userId` · `/users/:userId/posts` … 외 5개 |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`byDataSourceId` 59종이 사용자 화면 전반을 덮고, `byEndpointPattern` 1종이
`sirsoft-page` 의 공개 페이지를 덮습니다. 다른 확장 소유 경로를 이 템플릿이 덮는 것은
그 화면을 **렌더하는 쪽이 템플릿**이기 때문입니다 — 페이지 모듈은 데이터를 주고, 그리는
것은 템플릿입니다.

`states.groups` 17종은 로그인·검색·마이페이지처럼 로그인 여부와 데이터 유무로 화면이
갈리는 자리입니다. 사용자 화면은 "비어 있는 상태" 가 관리자 화면보다 흔하므로, 새 화면을
만들 때는 데이터가 있는 경우보다 **없는 경우**를 먼저 상태로 등록합니다.
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
php artisan template:update sirsoft-basic --force
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

사용자 화면은 모듈이 데이터를 주고 템플릿이 그립니다. 그래서 모듈 레이아웃에
`data_source` 가 늘었을 때 고칠 자리가 모듈 스펙일 수도, 이 템플릿 스펙일 수도 있습니다.
기준은 그 ID 가 그 모듈만 쓰는가(모듈 스펙), 여러 확장이 함께 쓰는가(템플릿 스펙)
입니다.
<!-- @intent END -->
