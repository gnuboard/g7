# GDPR (일반 데이터 보호 규정) — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-gdpr/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 9 · 페이지 상태 2
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
GDPR 플러그인은 관리자 설정·동의 이력 화면과 **사용자 화면에 얹히는 쿠키 배너**를
함께 갖습니다. 스펙이 두 블록만으로 끝나는 것은 이 플러그인이 컴포넌트를 만들지 않고
템플릿 컴포넌트로 배너를 조립하기 때문입니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 9 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 2 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
`byDataSourceId` 9종이 설정·정책 버전·동의 이력 세 갈래를 덮습니다. `gdprPublicSettings`
와 `gdprSettings` 가 따로 있는 것이 이 스펙의 핵심입니다 — 공개 응답과 관리자 응답은
같은 저장값에서 나오지만 **내보내는 항목이 다릅니다.** 샘플을 하나로 합치면 편집기에서
사용자 배너를 편집할 때 관리자만 볼 수 있는 항목까지 보이게 됩니다.
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
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 9 | `gdprSettings` · `gdprPolicyVersionCurrent` · `gdprPolicyVersionHistory` · `gdprPolicyVersionSnapshot` · `gdprConsentLog` · `gdprPublicSettings` · `gdprMyConsent` · `gdprMeConsents` · `gdprMyConsents` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 2 | `*/admin/plugins/sirsoft-gdpr/consent-log` · `_user_base` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`states.groups` 가 `_user_base` 를 범위로 갖는 것이 이 플러그인의 특징입니다. 쿠키
배너는 특정 라우트가 아니라 **모든 사용자 화면의 베이스 레이아웃**에 얹히므로, 편집기가
배너를 보여 주려면 베이스 레이아웃에 상태를 주입해야 합니다.

배너는 동의 전에만 보입니다. 편집기 캔버스는 정적 시뮬레이션이라 "아직 동의하지 않은
방문자" 상태를 만들어 주지 않으면 배너가 화면에 나타나지 않아 **편집 자체가 불가능**합니다.
`_user_base` 상태 변종이 그 역할입니다.
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
php artisan plugin:update sirsoft-gdpr --force
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

동의 항목을 추가·제거할 때는 `gdprPublicSettings` 와 `gdprSettings` 샘플을 **함께**
고칩니다. 한쪽만 고치면 편집기에서 관리자 화면과 사용자 배너가 서로 다른 항목 목록을
보여 주는데, 어느 쪽이 맞는지 화면만 봐서는 알 수 없습니다.
<!-- @intent END -->
