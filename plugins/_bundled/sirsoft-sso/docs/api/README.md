# API 레퍼런스 문서 목차

> **소유**: 플러그인 `sirsoft-sso` · **생성**: 손 작성(1.0.0, 컨트롤러와 FormRequest, 서비스 코드 근거). 다음 `php artisan api:docgen` 재생성 때 아래 표는 자동 생성 블록으로 갱신된다.
> 각 문서를 열면 엔드포인트별 파라미터, 응답, 예시를 볼 수 있다.

## 공통 규약

- **JSON API**: 접두 `/api/plugins/sirsoft-sso/`, 라우트명 접두 `api.plugins.sirsoft-sso.`. 응답은 코어 `ResponseHelper` 봉투 `{ "success", "message", "data" }` 이고, 실패는 `{ "success": false, "message", "errors": { "code": "…" } }` 다. 화면은 `errors.code` 로 분기한다. 입력 검증 실패(422)는 코어 규격대로 `errors` 에 칸별 문구가 실린다.
- **SCIM 2.0**(`scim.md`): 같은 `/api/` 접두지만 G7 봉투가 아니라 `application/scim+json` 자원 그대로 답하고, 오류도 SCIM 오류 스키마(`schemas`, `status`, `scimType`, `detail`)다. 인증은 IdP 마다 발급한 Bearer 토큰이다(Sanctum 토큰이 아니다).
- **웹 라우트**(`login.md`, `logout.md`, `oidc.md`, `saml.md`, `mock.md`, `test-result.md`): 접두 `/plugins/sirsoft-sso/`, 라우트명 접두 `web.plugins.sirsoft-sso.`. 브라우저와 IdP 가 오가는 주소라 JSON API 가 아니다. 302 이동, 자동 제출 폼 HTML, XML 로 답하고, 로그인 흐름의 오류는 로그인 화면 `/login?sso_error={코드}`(관리자 흐름은 `/admin/login?sso_error={코드}`)로 보낸다.
- **권한**: 관리자 API 는 `auth:sanctum` + `admin` + `permission:admin,sirsoft-sso.{권한}` 이다. 조회 `settings.view`, 변경과 테스트 `settings.update`, 회원 연결 열람 `accounts.view`, 연결 추가와 CSV `accounts.provision`, 연결 해제 `accounts.unlink`, 이력 `logs.view`. 정책의 사이트 전체 강제, 역할 강제, 비상 계정과 관리자 역할 대상 매핑은 권한과 별개로 최고관리자만 바꿀 수 있다(403 `super_only`).
- **요청 제한**: 라우트마다 버킷을 따로 센다(throttle 세 번째 인자). 초과하면 429.
- **브라우저 묶기**: 로그인 왕복과 대기 토큰, 시작 티켓은 시작한 브라우저의 묶음 쿠키 `g7_sso_bind`(HttpOnly, 30분, HTTPS 사이트는 SameSite=None; Secure, HTTP 개발 사이트는 Lax)와 대조한다. 다른 브라우저에서 오면 `bind_mismatch` 다.
- **로그인 인계**: 이 플러그인은 로그인 토큰을 직접 만들지 않는다. 코어 로그인 인계 코드(`login_handoff`)를 발급하고, 화면이 그 코드를 코어에 교환한다. API 응답으로 코드를 줄 때(`pending`, `link`)는 같은 응답에 코어 인계 묶음 쿠키 `g7_login_bind`(HttpOnly, SameSite=Lax, Path `/api/auth`, 120초)를 싣는다. 코드는 그 응답을 받은 브라우저에서만 교환된다.

### 관리자 API 실패 코드

| 코드 | 상태 | 뜻 |
| --- | --- | --- |
| `super_only` | 403 | 최고관리자만 바꿀 수 있는 칸을 바꾸려 했다(정책의 `enforce_site`, `enforce_roles`, `emergency_user_ids`, 관리자 역할 대상 역할 매핑과 동기화 방식) |
| `emergency_required` | 422 | 정책 저장 결과 비상 계정이 0명이다 |
| `site_enforce_requires_idp` | 422 | 사용 중인 IdP 가 없는데 사이트 전체 강제를 켜려 했다 |
| `admin_target_manual_only` | 403 | 관리자 계정에 연결을 붙이려 했는데 행위자가 최고관리자가 아니다 |
| `already_linked` | 409 | 같은 IdP 와 고유 식별자(subject)의 연결이 이미 있거나, 그 회원이 이 IdP 에 이미 연결돼 있다 |
| `last_method` | 409 | (관리자 해제) 비밀번호가 없는 회원의 마지막 연결이다. `confirm_last_method: true` 로 다시 보내면 해제한다 |
| `last_method` | 422 | (마이페이지 해제) 비밀번호가 없고 연결이 하나뿐이다. 본인은 해제할 수 없다 |
| `unlink_enforced` | 422 | (마이페이지 해제) 회사 계정으로만 로그인해야 하는 회원이다 |
| `account_not_found` | 404 | 연결이 없다(마이페이지는 본인 연결이 아닌 ID 도 404) |
| `user_not_found` | 404 | 연결을 붙일 회원이 없다 |
| `idp_not_found` | 404 | IdP 가 없다 |
| `email_required` | 422 | 사전 등록할 회원의 이메일이 비었거나 형식이 틀렸다 |
| `email_in_use` | 409 | 사전 등록하려는 이메일을 다른 회원이 쓴다 |
| `idp_disabled` | 422 | (마이페이지 연결 시작) 꺼졌거나 없는 IdP, 또는 리디렉션 로그인이 없는 LDAP IdP 다 |
| `idp_limit_reached` | 422 | IdP 가 이미 20개다 |
| `idp_key_reserved` | 422 | `mock_` 으로 시작하는 키는 모의 IdP 전용이다 |
| `idp_key_taken` | 422 | 이미 있는 키다 |
| `domain_owned_by_other_idp` | 422 | 저장하려는 소유 도메인을 다른 IdP 가 이미 소유한다(한 도메인 한 IdP). `errors.domains` 에 겹친 도메인 |
| `idp_has_accounts` | 409 | 연결이 남은 IdP 는 지울 수 없다 |
| `driver_unavailable` | 422 | 프로토콜 드라이버가 없거나 이 서버에서 쓸 수 없다(예: PHP `ldap` 확장 없음) |
| `config_invalid` | 422 | 켜려는 IdP 의 연결 정보가 드라이버 검증을 통과하지 못했다. `errors.fields` 에 칸별 문구가 실린다 |
| `browser_test_unsupported` | 422 | 리디렉션 로그인이 없는 프로토콜(LDAP)에 브라우저 로그인 테스트를 요청했다 |
| `sp_keys_saml_only` | 422 | SAML 이 아닌 IdP 에 SP 키를 만들려 했다 |
| `csv_empty`, `csv_header_invalid`, `csv_too_many_rows` | 422 | CSV 가 비었거나, 첫 줄이 `email,name,idp_key,subject` 를 포함하지 않거나, 1000줄을 넘는다 |

`message` 는 관리자 문구(`sirsoft-sso::admin.error.{코드}`)가 있으면 그것을, 없으면 흐름 문구(`sirsoft-sso::messages.error.{코드}`)를 쓴다.

### 로그인 흐름 오류 코드

로그인 화면 `?sso_error=`, 연결과 재확인 흐름의 출발 화면 `?sso_error=`, 대기 API 의 `errors.code` 에 실리는 값이다. 화면 문구는 `resources/lang/{로케일}.json` 의 `errors.{코드}`(대기 API 의 `message` 는 `lang/{로케일}/messages.php` 의 `error.{코드}`)다.

| 코드 | 뜻 |
| --- | --- |
| `access_denied` | 사용자가 IdP 에서 취소했거나 IdP 가 거절했다 |
| `state_expired` | 로그인 요청(state)이 없거나 만료됐다, 또는 콜백 값 형식이 비정상이다 |
| `bind_mismatch` | 시작한 브라우저와 다른 브라우저에서 돌아왔다 |
| `token_error` | IdP 토큰 교환, discovery 같은 서버 간 통신이 실패했다 |
| `id_token_invalid` | ID 토큰 또는 back-channel `logout_token` 검증 실패 |
| `saml_invalid` | SAML 응답 검증 실패 또는 형식 오류 |
| `saml_unsolicited` | 이 사이트가 시작하지 않은 SAML 응답이다(IdP-initiated 허용이 꺼짐) |
| `response_replayed` | 이미 처리한 응답을 다시 보냈다 |
| `ldap_invalid_credentials`, `ldap_unavailable`, `directory_unavailable` | 사내 디렉터리(LDAP) 로그인 실패 또는 연결 실패. LDAP 은 코어 로그인 칸으로 들어오므로 웹 라우트가 아니라 코어 로그인 응답과 시도 기록에 남는다 |
| `not_registered` | 사전 등록 방식 IdP 에 등록되지 않은 계정이다 |
| `email_in_use` | 같은 이메일 회원이 있어 새로 만들거나 연결하지 못했다 |
| `already_linked` | 이 회사 계정은 이미 다른 회원과 연결돼 있다 |
| `deprovisioned` | 퇴사 처리된 연결이다 |
| `idp_disabled` | IdP 가 꺼졌거나 없다(모의 IdP 는 게이트가 꺼진 경우 포함) |
| `driver_unavailable` | 서버에서 이 로그인 방식을 쓸 수 없다 |
| `pending_expired` | 처음 로그인 동의 대기 토큰이 없거나 만료됐다 |
| `link_expired` | 기존 회원 연결 확인 대기가 만료됐거나, 비밀번호를 3번 틀렸거나, 대상 회원이 사라졌다 |
| `ticket_required` | 로그인 외 의도(연결, 재확인)를 티켓 없이 시작했다 |
| `ticket_invalid` | 시작 티켓이 없거나, 이미 썼거나, 다른 브라우저 것이다. 재확인 반영 실패도 이 코드다 |
| `admin_target_manual_only` | 관리자 계정은 자동 연결, 본인 확인 연결, SCIM, CSV 로 붙지 않는다 |
| `password_mismatch`, `password_not_available`, `email_not_available`, `mail_failed`, `challenge_mismatch`, `invalid_code` | 기존 회원 연결 확인 API 의 실패(`link.md`) |

<!-- @generated:start:api-readme-index -->
- **문서 수**: 14 · **엔드포인트 수**: 66

| 문서 | 도메인 | 엔드포인트 |
| --- | --- | --- |
| [accounts.md](accounts.md) | `accounts` | 6 |
| [idps.md](idps.md) | `idps` | 9 |
| [link.md](link.md) | `link` | 3 |
| [links.md](links.md) | `links` | 1 |
| [login.md](login.md) | `login` | 1 |
| [logout.md](logout.md) | `logout` | 2 |
| [logs.md](logs.md) | `logs` | 2 |
| [mock.md](mock.md) | `mock` | 12 |
| [oidc.md](oidc.md) | `oidc` | 3 |
| [pending.md](pending.md) | `pending` | 2 |
| [policy.md](policy.md) | `policy` | 3 |
| [saml.md](saml.md) | `saml` | 4 |
| [scim.md](scim.md) | `scim` | 17 |
| [test-result.md](test-result.md) | `test-result` | 1 |

<!-- @generated:end -->

## 응답 예시에 대하여

이 문서의 응답은 실제 호출로 관측하지 않았다(미실측). 응답 필드 표의 `실측 예시값` 열은 `—` 이고, 응답 예시는 컨트롤러와 서비스 코드에서 옮긴 모양이다. 예시 안의 `…` 는 실제 값이 들어가는 자리이고, `message` 문구는 한국어 언어 파일의 값이다. 플러그인을 설치한 개발 사이트에서 `php artisan api:docgen --scope=plugin:sirsoft-sso --seed --examples-only` 로 실측 예시를 채울 수 있다.
