# 페이지 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `modules/_bundled/sirsoft-page/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 6 · 엔드포인트 샘플 3 · 페이지 상태 3
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
페이지 모듈의 스펙은 세 블록뿐입니다. 화면이 "목록 · 편집 · 공개 보기" 로 단순하고,
운영자가 편집기에서 손대는 대상이 페이지 **내용**이 아니라 그것을 감싸는 레이아웃이기
때문입니다.

`sampleGlobal` 을 두지 않은 것은 누락이 아닙니다 — 페이지 도메인은 `_global` 키를
자기 것으로 쓰지 않습니다. 필요 없는 블록을 빈 값으로라도 선언해 두면 다음 사람이 그
빈 값을 채워야 할 자리로 오해합니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 6 | `editor-spec.json (인라인)` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 3 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
`byDataSourceId` 6종 중 `termsContent`·`privacyContent` 는 다른 넷과 성격이 다릅니다.
약관·개인정보 페이지는 슬러그가 고정된 특수 페이지라 편집기에서 그 자리에 무엇이 들어갈지
미리 보여 줘야 합니다. `byEndpointPattern` 3종도 같은 이유로 이 둘을 따로 덮습니다.

`states.groups` 3종은 공개 페이지와 관리자 편집·상세를 하나씩 맡습니다. 페이지는 상태
변종이 적은 도메인이라 이 수가 늘어난다면 화면이 복잡해지고 있다는 신호입니다.
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
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 6 | `pages` · `page` · `pageData` · `versions` · `termsContent` · `privacyContent` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 3 | `/api/modules/sirsoft-page/pages/terms` · `/api/modules/sirsoft-page/pages/privacy` · `/api/modules/sirsoft-page/pages/*` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `/page/:slug` · `*/admin/pages/:id/edit` · `*/admin/pages/:id` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
페이지 모듈에서 주의할 것은 `/p/:slug` 처럼 **슬러그가 열려 있는 라우트**입니다.
편집기는 특정 슬러그 하나를 골라 프리뷰를 그리므로, 그 샘플이 실제 운영 페이지 중
가장 단순한 것을 닮아 있으면 복잡한 페이지에서 레이아웃이 깨지는 것을 편집기에서
미리 볼 수 없습니다.

샘플을 고를 때는 가장 짧은 페이지가 아니라 **가장 많은 요소를 가진 페이지**를 기준으로
삼습니다.
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
php artisan module:update sirsoft-page --force
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
<!-- @intent END -->
