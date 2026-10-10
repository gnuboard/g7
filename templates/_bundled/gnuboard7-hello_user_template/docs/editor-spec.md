# Hello 사용자 템플릿 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `templates/_bundled/gnuboard7-hello_user_template/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 1
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
학습용 사용자 템플릿이라 편집기 스펙을 두지 않았습니다. 실제 사용자 템플릿이 무엇을
선언하는지는 `sirsoft-basic` 의 같은 문서를 봅니다 — 팔레트 45 · 상태 범위 17 이 그
규모입니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 1 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언한 블록이 없습니다. 팔레트·중첩을 선언하지 않았으므로 이 템플릿을 활성화한 상태에서는
편집기가 놓을 수 있는 컴포넌트가 없습니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
컴포넌트를 만드는 것은 템플릿의 일이므로, 이 확장이 팔레트에 얹을 것은 원래 없습니다.
편집기 팔레트는 활성 템플릿의 스펙이 정합니다 — 이 확장에 편집기 스펙이 생기더라도
`componentPalette` 는 여전히 비어 있을 것입니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 1 | `memos` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 미선언 | - |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`memos` 가 미커버입니다. 이 템플릿은 `gnuboard7-hello_module` 의 메모 목록을 화면에
그리는데, 그 데이터의 프리뷰 샘플을 어느 쪽도 선언하지 않았기 때문입니다.

이 한 건이 모듈과 템플릿의 역할 분담을 그대로 보여 줍니다 — 데이터를 주는 것은 모듈이고
그리는 것은 템플릿이라, 샘플을 어느 쪽에 둘지 판단이 필요합니다. `memos` 를 이 템플릿만
쓴다면 템플릿 스펙에, 여러 템플릿이 쓴다면 모듈 스펙에 둡니다.
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
php artisan template:update gnuboard7-hello_user_template --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
이 템플릿을 실제 사용 템플릿으로 발전시킨다면 편집기 스펙 신설이 필요합니다. 템플릿
스펙은 **컴포넌트 어휘**(팔레트·중첩·역량·컨트롤)를 담고, 거기에 이 템플릿이 그리는
화면의 `sampleData` 를 더합니다.

신설 순서는 `componentPalette` → `nesting` → `componentCapabilities` → `controls`
입니다. `sirsoft-basic/editor-spec/` 의 13개 블록 파일이 완성된 형태의 선례입니다.
<!-- @intent END -->
