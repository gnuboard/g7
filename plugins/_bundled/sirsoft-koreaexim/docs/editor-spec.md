# 수출입은행 환율 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-koreaexim/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 6 · 페이지 상태 3
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
이 플러그인은 새로운 컴포넌트를 제공하지 않고 관리자 템플릿의 표준 컴포넌트를 조합합니다. editor-spec은 API 연결 없이도 설정 화면을 미리 볼 수 있는 응답 샘플을 소유합니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 6 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
sampleData.byDataSourceId는 실제 레이아웃의 다섯 데이터소스와 이름을 맞춥니다. 응답 envelope를 유지하여 편집기에서도 목록·상태·폼 바인딩이 동일하게 해석됩니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
표준 Input·Select·Toggle·DataGrid·Modal을 사용하므로 플러그인 전용 팔레트가 없습니다. 새 컴포넌트가 필요한 경우에만 팔레트와 편집 역량을 함께 선언합니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 6 | `settings` · `koreaeximStatus` · `koreaeximRuns` · `koreaeximRecentRuns` · `koreaeximNotificationDefinitions` · `koreaeximNotificationChannels` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `*/admin/plugins/sirsoft-koreaexim/settings` · `modal_koreaexim_notification_template_edit` · `modal_koreaexim_notification_definition_preview` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
샘플 인증키는 빈 값으로 두며 실행·알림 응답은 실제 API와 같은 data 구조를 사용합니다. 원본 은행 응답이나 실제 인증 정보를 프리뷰 샘플에 넣지 않습니다.
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
php artisan plugin:update sirsoft-koreaexim --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
API 응답이나 데이터소스가 바뀌면 샘플도 같은 작업에서 갱신합니다. 편집기 프리뷰 샘플은 운영 설정의 기본값을 대체하지 않습니다.
<!-- @intent END -->
