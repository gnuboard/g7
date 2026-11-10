# 히어로섹션 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-hero_section/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 팔레트 1 · 편집 역량 1 · 프리뷰 샘플 13 · 페이지 상태 4
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
단일 파일 `editor-spec.json` 이 세 가지를 선언한다 — 사용자 슬라이더(`sirsoft-hero_section.HeroSlider`)의 팔레트 항목, 관리자 화면·주입 조각의 data-source 샘플(편집기 캔버스가 빈 화면이 되지 않게), 창 상태 표본(삭제·일괄 삭제·순서 변경 창이 「창을 연 화면이 담는 값」을 읽기 때문에 편집기가 그 값을 대신 담는다).
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `componentPalette.entries` | 편집기 "요소 추가" 팔레트에 나타나는 항목 | 1 | `editor-spec.json (인라인)` |
| `componentPalette.groups` | 팔레트 좌측 목록의 묶음 | 1 | `editor-spec.json (인라인)` |
| `componentCapabilities` | 컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가) | 1 | `editor-spec.json (인라인)` |
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 13 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 4 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
분할(`$include`)하지 않는다 — 선언이 작아 한 파일로 충분하다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 그룹 | 종류 | 컴포넌트 수 |
|---|---|---|
| $t:sirsoft-hero_section.editor.palette.group | `content` | 1 |
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
`HeroSlider` 만 올린다(소셜 로그인 `ProviderButtons` 선례). 기본 노드는 사이트 홈 조각과 같이 `heroSectionHome` 응답에 묶여 있어, 그 data-source 가 없는 화면에 끼우면 배너가 없어 그리지 않는다. 관리자 미리보기(`HeroBannerPreview`)는 관리자 화면 전용이라 올리지 않는다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 13 | `banners` · `preview` · `position_settings` · `upload_limit` · `banner` · `settings` · `heroSectionHome` · `heroSectionShop` · `target_info` · `heroSectionBoard` · `heroSectionProduct` · `heroSectionCategory` … 외 1개 |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 4 | `*/admin/plugins/sirsoft-hero_section/banners` · `hero_delete_modal` · `hero_bulk_delete_modal` · `hero_reorder_modal` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
샘플 이미지는 외부 요청 없이 그리도록 data URI 다. 주입 조각 샘플은 배너 2장(버튼 있음/없음), 목록은 노출중·예약·종료 3건, 목록 라우트에는 「배너 없음」 상태를 둔다. 창 상태 표본은 `_global.targetBanner`·`bulkSelectedIds`·`reorderItems`/`reorderOriginal` 을 담는다 — 창 문구가 번역 인자로 이 값을 읽는다(코어 정적 가드 `stateModalSeedsOpenerValues`).
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
php artisan plugin:update sirsoft-hero_section --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
편집기 스펙을 추가하면 컴포넌트 props 계약(`docs/extension-points.md`)과 샘플 데이터를 함께 고친다.
<!-- @intent END -->
