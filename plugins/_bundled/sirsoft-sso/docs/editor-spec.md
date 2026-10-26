# SSO 연동 — 레이아웃 편집기 스펙

> 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 · 진입점: [AGENTS.md](../AGENTS.md)

## 선언 요약

<!-- @generated:editor-spec-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 항목 | 값 |
|---|---|
| manifest | `plugins/_bundled/sirsoft-sso/editor-spec.json` |
| 형태 | 단일 파일 (인라인) |
| 스펙 버전 | `1.0.0` |
| 스타일 시스템 | - |
| 다크 모드 전략 | - |

> 단일 파일 · 페이지 상태 5
<!-- @generated:editor-spec-summary END -->

<!-- @intent START -->
로그인 화면 확장 조각(`auth-login-sso-modals`, `admin-login-sso-modals`)과 마이페이지 카드의 창 상태만 선언합니다. 동의 창과 연결 확인 창은 창을 연 화면이 `pending/{token}`, `link/{token}` 응답을 `_global.ssoPending`, `_global.ssoLink` 에 담아 두고 읽는데, 편집기는 IdP 왕복을 하지 않으므로 상태 표본이 그 값을 대신 담습니다. 표본은 릴리즈에 실리므로 실제 IdP, 회원 정보를 싣지 않습니다(「Corp SSO」, `corp.example`).
<!-- @intent END -->

## 선언 블록

<!-- @generated:editor-spec-blocks START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 블록 | 역할 | 항목 수 | 출처 |
|---|---|---|---|
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 5 | `editor-spec.json (인라인)` |
<!-- @generated:editor-spec-blocks END -->

<!-- @intent START -->
`states.groups` 다섯 묶음은 모두 창(`scope.kind = modal`) 단위입니다.

| 묶음(`scope.match`) | 화면 | 변종 |
|---|---|---|
| `sso_consent` | 사용자 로그인, 처음 로그인 약관 동의 | 대기 중(기본), 만료 |
| `sso_link_verify` | 사용자 로그인, 기존 계정 연결 확인 | 비밀번호(기본), 메일 인증번호, 만료 |
| `sso_admin_consent` | 관리자 로그인, 약관 동의 | 대기 중(기본), 만료 |
| `sso_admin_link_verify` | 관리자 로그인, 연결 확인 | 비밀번호(기본), 메일 인증번호, 만료 |
| `sso_unlink_confirm` | 마이페이지, 연결 해제 확인 | 확인(카드의 [연결 해제]가 `_global.ssoUnlinkTarget` 에 담는 연결 한 줄) |

없으면 편집기 캔버스의 창 제목과 안내에 자리표시 원문이 그려집니다.
<!-- @intent END -->

## 컴포넌트 팔레트

<!-- @generated:editor-spec-palette START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다._
<!-- @generated:editor-spec-palette END -->

<!-- @intent START -->
이 플러그인은 컴포넌트를 내보내지 않으므로(`components.json` 이 비어 있음) 팔레트에 올릴 것이 없습니다. 로그인 버튼과 마이페이지 카드는 레이아웃 확장 조각이 템플릿의 기본 컴포넌트로 그립니다. 플러그인 컴포넌트를 만들면 `componentPalette`, `componentCapabilities` 를 함께 선언합니다.
<!-- @intent END -->

## 샘플 데이터와 페이지 상태

<!-- @generated:editor-spec-samples START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 자리 | 역할 | 개수 | ID |
|---|---|---|---|
| `sampleData.byDataSourceId` | 레이아웃 `data_sources` ID 로 붙는 프리뷰 응답 | 미선언 | - |
| `sampleData.byEndpointPattern` | 엔드포인트 패턴으로 붙는 프리뷰 응답 | 미선언 | - |
| `states.groups` | 상태 변종을 적용할 범위(라우트·베이스 레이아웃) | 5 | `sso_consent` · `sso_link_verify` · `sso_admin_consent` · `sso_admin_link_verify` · `sso_unlink_confirm` |

**프리뷰 샘플이 없는 `data_source` 7개** — 편집기 캔버스에서 이 자리만 빈 화면이 됩니다. 실제 화면은 정상이라 오류도 경고도 남지 않습니다.

`ssoAccounts` · `ssoAttempts` · `ssoIdp` · `ssoIdps` · `ssoLogIdps` · `ssoPolicy` · `ssoScimEvents`
<!-- @generated:editor-spec-samples END -->

<!-- @intent START -->
위 데이터소스 7개(`ssoIdps`, `ssoIdp`, `ssoPolicy`, `ssoAccounts`, `ssoLogIdps`, `ssoAttempts`, `ssoScimEvents`)는 모두 관리자 화면(설정, 회원 연결, 이력)의 것입니다. 이 화면들은 관리자 권한 API 를 부르는 운영 화면이라 프리뷰 샘플을 두지 않았고, 편집기 캔버스에서 빈 화면이 되는 것이 정상입니다. 관리자 화면에 프리뷰가 필요해지면 같은 ID 로 `sampleData.byDataSourceId` 를 더합니다.
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
php artisan plugin:update sirsoft-sso --force
```
<!-- @generated:editor-spec-obligations END -->

<!-- @intent START -->
로그인 모달과 마이페이지 카드가 읽는 값의 모양(`LinkVerificationService::consentInfo()`, `linkInfo()`, `MyAccountService::overview()` 응답)을 바꾸면 이 파일의 상태 표본도 같이 고칩니다. 창을 새로 만들면 `states.groups` 에 그 창 이름(`scope.match`)으로 묶음을 더하고 `ext:docgen` 을 다시 돌립니다.
<!-- @intent END -->
