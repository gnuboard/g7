# 게시판 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `modules/_bundled/sirsoft-board/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 23 · 엔드포인트 샘플 4 · 페이지 상태 8
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
단일 파일로 둔 것은 분량 때문입니다. 게시판 스펙은 도메인 데이터 4블록뿐이라 분할할
이유가 없습니다 — 분할은 템플릿 스펙처럼 한 파일이 만 줄 단위로 커질 때의 장치입니다.

`스타일 시스템`·`다크 모드 전략` 이 비어 있는 것도 의도입니다. 그 둘은 화면을 **그리는**
쪽의 결정이라 템플릿 스펙이 소유합니다. 게시판이 여기에 값을 넣으면 어떤 템플릿을 깔든
게시판이 스타일 체계를 강제하는 셈이 됩니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 23 | `editor-spec.json (인라인)` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 4 | `editor-spec.json (인라인)` |
| `sampleGlobal` | `_global.*` 프리뷰 baseline 시드 | 1 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 8 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
이 네 블록은 "편집기가 게시판 화면을 실제 API 없이 그리려면 무엇이 필요한가" 에서
그대로 나옵니다. `byDataSourceId` 23종은 admin 레이아웃의 `data_source` ID 를 전수
스캔해 맞춘 것이고, `byEndpointPattern` 4종은 사용자 게시판 페이지처럼 ID 가 아니라
호출 주소로 붙는 자리를 덮습니다.

여기에 없는 것이 무엇인지가 더 중요합니다 — `roles`·`availableChannels`·
`identityProviders` 같은 공용 인프라 ID 는 게시판이 쓰지만 게시판이 선언하지 않습니다.
그것들은 admin 템플릿 스펙과 코어 프리셋이 채웁니다. 여기에 같이 넣으면 같은 ID 의
샘플이 두 곳에 생기고, 둘이 갈라져도 아무 오류가 나지 않습니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
컴포넌트를 만드는 것은 템플릿의 일입니다. 모듈·플러그인은 레이아웃 JSON 에서 템플릿이
제공하는 컴포넌트를 **쓰기만** 하므로, 편집기 팔레트에 새로 얹을 것이 없습니다. 그래서 이
확장의 스펙은 `componentPalette`·`controls`·`componentCapabilities`·`nesting` 을 비우고
**도메인 데이터**(`sampleData`·`states`)만 담습니다.

팔레트에 무언가를 추가하고 싶다면 그것은 이 확장이 아니라 활성 템플릿
(`sirsoft-admin_basic` / `sirsoft-basic`)의 스펙에 가야 합니다. 여기에 팔레트를 선언하면
템플릿 선언과 같은 자리를 두고 다투게 되고, 어느 쪽이 이기는지가 합본 순서에 좌우됩니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 23 | `posts` · `post` · `reports` · `report_detail` · `reporters_list` · `boards` · `boards_list` · `board_types` · `form_data` · `form_meta` · `settings` · `availableChannels` … 외 11개 |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 4 | `/api/modules/sirsoft-board/boards/*/posts*` · `/api/modules/sirsoft-board/boards/popular*` · `/api/modules/sirsoft-board/me/*` · `/api/modules/sirsoft-board/users/*/posts*` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 8 | `/board/:slug/:id` · `/board/:slug` · `/boards` · `/board/:slug/write` · `*/admin/boards/:slug/edit` · `*/admin/board/:slug/:id/edit` · `*/admin/boards/settings` · `*/admin/board/:slug/post/:id` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`states.groups` 8종은 게시판 화면 중 **상태에 따라 다르게 보이는 것**만 골랐습니다.
비밀글 잠금(`/board/:slug/:id`), 목록의 빈 상태(`/board/:slug`), 작성 폼 등입니다. 상태
변종이 없는 화면은 기본 샘플 하나로 충분하므로 등록하지 않습니다.

게시판 레이아웃에 `data_source` 를 새로 붙일 때는 그 ID 가 공용 인프라인지 게시판
도메인인지 먼저 가릅니다. 도메인이면 이 스펙의 `byDataSourceId` 에, 공용이면 템플릿
스펙에 갑니다. 잘못 판단해도 편집기 화면만 비므로 실행 중에는 드러나지 않습니다.
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
php artisan module:update sirsoft-board --force
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

게시판은 관리자 화면과 사용자 화면을 모두 갖습니다. 관리자 쪽 `data_source` 는
`byDataSourceId` 로 붙지만 사용자 게시판 페이지는 템플릿이 렌더하므로 ID 가 아니라
`byEndpointPattern` 으로 붙습니다 — 사용자 화면을 건드렸는데 관리자 쪽 자리만 고치면
그 화면은 편집기에서 계속 빈 채로 남습니다.
<!-- @intent END -->
