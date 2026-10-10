# Hello 모듈 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `modules/_bundled/gnuboard7-hello_module/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `0.2.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 팔레트 1 · 편집 역량 1 · 프리뷰 샘플 2
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
이 스펙은 한 가지만 보여 줍니다 — 확장이 **직접 만든** 컴포넌트(`gnuboard7-hello_module.MemoCard`)를
레이아웃 편집기 팔레트에 올리는 최소 사례입니다. 확장 컴포넌트는 `{확장식별자}.{이름}` 으로
등록되므로 그 팔레트 항목과 편집 역량은 이 확장만 알고, 그래서 이 확장의 스펙에 둡니다.
템플릿이 만든 컴포넌트의 어휘는 여기 두지 않습니다 — 그것은 템플릿 스펙의 몫이고, 두면
어느 선언이 이기는지가 병합 순서에 좌우됩니다.

샘플 데이터·페이지 상태는 일부러 두지 않았습니다. 이 모듈의 본래 목적은 모듈의 최소
구조(라우트 → 컨트롤러 → 서비스 → 저장소)를 보여 주는 것이고, 아래 "샘플 데이터와
페이지 상태" 절이 보여 주듯 그 결과로 프리뷰가 비는 자리가 실제로 있습니다. 스펙이 없어도
되는 상태와 스펙이 필요한데 없는 상태는 다릅니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `componentPalette.entries` | 편집기 "요소 추가" 팔레트에 나타나는 항목 | 1 | `editor-spec.json (인라인)` |
| `componentPalette.groups` | 팔레트 좌측 목록의 묶음 | 1 | `editor-spec.json (인라인)` |
| `componentCapabilities` | 컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가) | 1 | `editor-spec.json (인라인)` |
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 2 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
팔레트와 편집 역량 두 블록만 있고, 둘 다 `gnuboard7-hello_module.MemoCard` 하나만 담습니다.
샘플 데이터 블록이 없다는 것은 "편집기가 이 모듈을 다루지 않는다" 가 아니라 "이 모듈이
자기 화면의 데이터 모양을 편집기에 알려 주지 않는다" 는 뜻입니다 — 편집기는 여전히 이
모듈의 레이아웃을 열 수 있고, 다만 데이터가 붙지 않은 채로 엽니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 그룹 | 종류 | 컴포넌트 수 |
|---|---|---|
| $t:gnuboard7-hello_module.editor.palette.group | `content` | 1 |
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
`MemoCard` 한 항목뿐이고, 그것이 이 확장이 만든 유일한 컴포넌트입니다. 팔레트 키는
레지스트리 등록 이름(`gnuboard7-hello_module.MemoCard`)을 그대로 쓰고, 라벨은 이 모듈의
언어팩 네임스페이스(`$t:gnuboard7-hello_module.editor.palette.*`)에 둡니다 — 코어의
`editor.*` 를 침범하지 않습니다. 템플릿 컴포넌트를 여기 얹지 않는 이유는 위 「의도」와 같습니다.

팔레트에 올린 컴포넌트는 컴포넌트 쪽도 편집기 계약을 지켜야 합니다. 편집기는 캔버스의
`[data-editor-path]` 로 노드를 찾는데, composite 컴포넌트는 도메인 prop 만 받으므로 코어가
주입하는 `editorAttrs` 를 받아 시각적 루트에 펼쳐야 합니다. 펼치지 않으면 팔레트에서 꺼낸
카드가 캔버스에 그려지기만 하고 선택·이동·삭제가 되지 않습니다. 편집 역량의 `coreProps: ["id"]`
가 기입하는 요소 ID 도 `id` prop 으로 받아 같은 루트에 둡니다. 두 판본(`resources/js/components/composite/MemoCard.tsx`,
`resources/js/components/for/gnuboard7-hello_admin_template/MemoCard.tsx`)과 Hello 사용자 템플릿의 대체본이 모두 그렇게
합니다(규약: `docs/frontend/components-types.md` 「editorAttrs 패스스루」).
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 2 | `memos` · `memoData` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 미선언 | - |

**프리뷰 샘플이 없는 `data_source` 1개** — 편집기 캔버스에서 이 자리만 빈 화면이 됩니다. 실제 화면은 정상이라 오류도 경고도 남지 않습니다.

`memoCreateAbility`
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`memoData` · `memos` 두 ID 가 미커버입니다. 메모 목록과 폼이 편집기 캔버스에서 빈
채로 보인다는 뜻입니다.

샘플 모듈이므로 이 상태를 그대로 두는 것도 선택입니다 — 다만 그것은 "편집기 스펙이
없으면 어떤 화면이 되는가" 를 보여 주는 교보재로서 의도적으로 남긴 것이지, 문제가
없다는 뜻이 아닙니다. 스펙을 하나 만들어 보는 것이 이 모듈로 할 수 있는 좋은 연습입니다.
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
php artisan module:update gnuboard7-hello_module --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
편집기 스펙을 신설해야 하는 시점은 하나입니다 — **이 확장이 소유한 레이아웃에
이 확장만 쓰는 `data_source` 가 생겼을 때**. 그 순간부터 편집기 캔버스의 그 영역은
빈 화면이 되고, 실제 화면은 정상 동작하므로 오류도 경고도 남지 않습니다.

신설 절차는 확장 루트에 `editor-spec.json` 을 만들고 `sampleData.byDataSourceId` 에
그 ID 를 넣는 것으로 시작합니다. 팔레트·컨트롤은 템플릿의 일이므로 넣지 않습니다.
파일을 만든 뒤 update 커맨드로 활성 디렉토리에 반영해야 편집기가 읽습니다 —
`_bundled` 폴백이 없습니다.
<!-- @intent END -->
