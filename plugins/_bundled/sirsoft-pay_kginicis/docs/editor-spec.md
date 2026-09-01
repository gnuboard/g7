# KG 이니시스 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-pay_kginicis/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> KG이니시스 결제 플러그인 레이아웃 편집기 샘플 데이터 (가상계좌 입금통보 URL).
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
KG이니시스 결제 플러그인이 소유한 화면은 관리자 설정 하나뿐입니다. 결제 자체는 PG 결제창에서
일어나고 그 창은 이 확장이 그리는 화면이 아니므로, 편집기가 다룰 표면이 설정 화면으로
좁혀집니다.

`스타일 시스템`·`다크 모드 전략` 이 비어 있는 것은 정상입니다 — 화면을 그리는 규칙은
템플릿이 정하고, 결제 플러그인은 그 위에 자기 설정 항목만 얹습니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 1 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 1 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언한 것은 `vbank_info` 하나, 그리고 설정 화면의 상태 변종 하나입니다. 다른 설정 항목
(`settings` 등)은 여러 확장이 공유하는 공용 ID 라 admin 템플릿 스펙이 채웁니다 —
여기에 다시 선언하면 같은 ID 의 샘플이 두 곳에 생기고, 둘이 갈라져도 오류가 나지
않습니다.

가상계좌 안내(`vbank_info`)를 굳이 넣은 것은 그 영역이 **결제 수단에 따라 나타났다
사라지는** 자리라, 샘플이 없으면 편집기에서 그 영역 자체를 볼 수 없기 때문입니다.
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
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 1 | `vbank_info` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 1 | `*/admin/plugins/sirsoft-pay_kginicis/settings` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
상태 변종의 범위가 `*/admin/plugins/sirsoft-pay_kginicis/settings` 하나인 것은 이 플러그인이 자기
설정 화면만 소유하기 때문입니다. 주문·결제 화면은 `sirsoft-ecommerce` 가 소유하므로
그 화면의 프리뷰는 이커머스 스펙이 그립니다.

결제 흐름을 편집기에서 확인하려던 것이라면 이 문서가 아니라 이커머스 모듈의 편집기 스펙
문서를 봅니다.
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
php artisan plugin:update sirsoft-pay_kginicis --force
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

이 플러그인의 결제 수단이 이커머스 관리자 화면에 어떻게 보이는지는 편집기 스펙이 아니라
결제수단 카탈로그 선언(`needs_pg`·`pg_provider`·`pg_locked`)이 정합니다. 배지가 이상하게
보인다면 스펙이 아니라 그 선언을 봅니다.
<!-- @intent END -->
