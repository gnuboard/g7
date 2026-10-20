# 소셜 로그인 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-social_login/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 팔레트 1 · 편집 역량 1 · 프리뷰 샘플 3 · 페이지 상태 3
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
로그인, 가입 화면에 붙는 제공자 버튼 부품(`sirsoft-social_login.ProviderButtons`)을 편집기 팔레트에 올리고, 사용자 화면 확장 조각이 부르는 데이터소스 3개에 프리뷰 샘플을 줍니다. 샘플은 릴리즈에 실리므로 실제 제공자 키나 회원 정보를 싣지 않습니다.
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `componentPalette.entries` | 편집기 "요소 추가" 팔레트에 나타나는 항목 | 1 | `editor-spec.json (인라인)` |
| `componentPalette.groups` | 팔레트 좌측 목록의 묶음 | 1 | `editor-spec.json (인라인)` |
| `componentCapabilities` | 컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가) | 1 | `editor-spec.json (인라인)` |
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 3 | `editor-spec.json (인라인)` |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
팔레트 항목과 편집 역량은 제공자 버튼 하나뿐입니다(연결 목록 줄의 제공자 표시 `ProviderMark` 는 팔레트에 올리지 않습니다). 편집기가 바꿀 수 있는 것은 바깥 여백과 너비이고, 버튼 모양(제공자 색, 기호)은 제공자의 `brand` 가 정합니다. 제공자 설정의 `button_style` 은 커스텀(일반 OIDC) 제공자의 색에만 쓰입니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 그룹 | 종류 | 컴포넌트 수 |
|---|---|---|
| $t:sirsoft-social_login.editor.palette.group | `content` | 1 |
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
제공자 버튼을 넣으면 기본 노드가 `socialProviders` 데이터소스를 읽고 로그인 의도(`intent: login`)와 복귀 주소(`query.redirect`)를 받습니다. 가입 화면에서는 `intent` 를 `register` 로 바꿉니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 3 | `socialProviders` · `socialAccounts` · `socialReauthAccounts` |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 3 | `social_signup_complete` · `social_existing_account` · `social_unlink_confirm` |

_이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버)._
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
`socialProviders` 는 공개 제공자 목록(`GET /providers`), `socialAccounts` 는 마이페이지 연결 관리(`GET /me/accounts`, 비밀번호 없는 회원 1개 연결), `socialReauthAccounts` 는 같은 응답에서 `reauth_via_social: true` 인 대체 이메일 회원 변종입니다. 응답 모양은 [API 레퍼런스](api/social-login.md)와 같습니다.

창 상태 묶음 3개는 창을 연 화면이 담아 두는 값을 대신 담습니다. 가입 모달(`social_signup_complete`)은 이메일 입력, 인증번호 보낸 뒤(남은 시간 표시), 제공자 확인 이메일, 시간 만료 4변종, 이미 가입된 이메일 모달(`social_existing_account`)은 비밀번호 있음과 없음, 연결 해제 확인 창(`social_unlink_confirm`)은 일반 확인과 마지막 로그인 수단 안내입니다. 없으면 편집기 캔버스의 제목과 안내에 자리표시 원문이 그려집니다.
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
php artisan plugin:update sirsoft-social_login --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
선언이 생기면 `editor-spec.json` 과 이 문서를 함께 고치고 `ext:docgen` 을 다시 돌립니다.
<!-- @intent END -->
