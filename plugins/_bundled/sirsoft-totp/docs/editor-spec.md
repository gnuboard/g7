# 인증 앱 2단계 인증 (TOTP) — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-totp/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 프리뷰 샘플 2 · 페이지 상태 1
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
이 플러그인은 `editor-spec.json` 을 **둡니다**. 등록 화면
(`resources/layouts/admin/plugin_settings.g7.html`)이 `GET /api/plugins/sirsoft-totp/enrollment`
를 `totpEnrollment` 라는 `data_source` 로 선언하기 때문입니다 — 이 문서가 예고했던
「필요해지는 시점」이 그 화면과 함께 도래했습니다.

편집기 스펙이 필요해지는 조건은 레이아웃이 편집기에서 다뤄야 할 고유한 것을 갖는
경우입니다 — 자기 컴포넌트를 팔레트에 올리거나, 레이아웃이 `data_source` 로 서버 응답을
읽어 프리뷰 샘플이 있어야 하거나, `_global.*` 을 새로 읽거나, 빈 목록·오류 같은 화면
변종을 편집기에서 전환해 봐야 하는 경우입니다.

이 플러그인에 해당하는 것은 **두 번째 하나**입니다. 팔레트에 올리는 자기 컴포넌트도,
새로 읽는 `_global.*` 도 없고, 화면이 다루는 것은 호출자 본인의 등록 상태 하나뿐이라
목록·필터·정렬 같은 프리뷰 변종도 없습니다. 그래서 스펙에는 `sampleData` 한 블록만
있습니다. 설정 화면은 여전히 코어 「플러그인 관리 > 설정」 공통 진입점이
`getSettingsSchema()` 선언만으로 렌더하므로 `data_source` 를 쓰지 않습니다.

샘플 데이터에는 한 가지 제약이 따릅니다 — 발급·확정 응답에는 평문 시크릿과 백업
코드가 실리므로, 프리뷰 샘플에는 **실제 형식만 흉내 낸 더미**를 넣습니다. 편집기 샘플은
릴리즈 페이로드에 실려 배포되는 공개 파일입니다. 그래서 `totpEnrollment` 샘플은 등록을
마친 상태(`enrolled: true`)의 표시용 필드 4개만 담고, 시크릿·백업 코드 평문은 담지
않습니다 — 그 둘은 애초에 이 조회 응답에 실리지 않습니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 2 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 1 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
선언된 블록은 `sampleData` 하나입니다 — 일부 블록만 빠진 상태가 아니라, 이 플러그인이
필요로 하는 것이 그 하나뿐입니다(위 「선언 요약」 참조).

팔레트·컨트롤·변종은 선언하지 않습니다. 편집기는 그 셋에 대해서는 코어 기본 팔레트와
활성 템플릿의 스펙만으로 이 플러그인의 화면을 다룹니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
팔레트에 항목을 추가하지 않습니다.

등록 화면이 필요로 하는 것은 QR 이미지·키 표시·코드 입력·백업 코드 목록인데, 이들은 전부
코어 기본 컴포넌트와 활성 템플릿 컴포넌트의 조합으로 만들 수 있습니다. 인증 앱 등록에만
쓰이는 컴포넌트를 팔레트에 올리면, 다른 화면을 만드는 사람에게는 평생 쓰지 않을 항목이
목록에 남습니다.

`components.json` 도 두지 않았습니다 — 레이아웃 작성자가 읽을 props 계약이 없다는 뜻이며,
이 플러그인이 제공하는 계약은 컴포넌트가 아니라 API 입니다([api/totp.md](api/totp.md)).
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 2 | `totpEnrollment` · `totpSettings` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 1 | `*/admin/plugins/sirsoft-totp/settings` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
샘플 데이터는 `totpEnrollment` 하나이고, 페이지 상태(`states`)는 선언하지 않습니다.

위 생성 블록의 마지막 문장("이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이
붙습니다")은 이제 **실제 커버리지**를 뜻합니다 — 레이아웃이 선언한 `data_source` 1종에
같은 ID 의 샘플이 붙어 있다는 뜻입니다. 레이아웃이나 `data_source` 를 더하면 이 커버리지가
깨지므로 생성기를 다시 돌려 확인하세요.

`states` 를 두지 않은 것은 등록 화면이 상태를 갖지 않아서가 아니라, 세 상태
(미등록·발급 후 미확정·확정)가 모두 같은 응답의 필드 조합으로 표현되어 `sampleData` 의
값을 바꾸는 것으로 충분하기 때문입니다. 편집기에서 변종을 전환해 볼 필요가 생기면 그때
「수정 시 동반 의무」의 `states` 행을 따릅니다.
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
php artisan plugin:update sirsoft-totp --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
위 표는 편집기 스펙을 신설할 때의 일반 규칙이고, 이 플러그인에는 한 가지 고유 의무가
더 붙습니다.

**샘플 데이터에 실제 시크릿·백업 코드를 넣지 않습니다.** 등록 API 의 응답 형태를 그대로
복사해 `sampleData` 에 붙이면 형식만 흉내 낸 더미가 아니라 실제로 유효한 시크릿이 공개
파일에 남을 수 있습니다. `editor-spec.json` 은 릴리즈 페이로드에 실려 배포됩니다.

`states` 를 두게 된다면 등록 화면이 실제로 갖는 상태는 셋입니다 — 미등록(`enrolled:false`,
`pending:false`) · 발급했으나 미확정(`pending:true`) · 확정(`enrolled:true`). 이 구분은
`GET /api/plugins/sirsoft-totp/enrollment` 응답 필드와 같으므로, 상태 변종을 임의로 만들지
말고 그 응답을 기준으로 맞추세요.
<!-- @intent END -->
