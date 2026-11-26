# Frontend Config API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. G7 셸이 window.G7Config 로 내리는 값과 같은 공개 설정을 JSON 으로 받는다 (7.2.0)
2. 사용자 범위: GET /api/frontend-config (공개, 회원 토큰이 있으면 회원 맥락)
3. 관리자 범위: GET /api/admin/frontend-config (관리자 인증)
4. 외부 화면(Next.js·HTML+jQuery·custom Blade)이 사이트 이름·로케일·모듈 공개 설정·custom 라우트 패턴을 읽는 용도
5. 민감 설정(frontend_schema expose:false)은 셸과 똑같이 빠진다
```

---


### GET /api/frontend-config
<!-- @generated:start:api.public.frontend-config -->
- **라우트명**: `api.public.frontend-config`
- **컨트롤러**: `App\Http\Controllers\Api\Public\FrontendConfigController@show`
- **인증/권한**: 공개 (인증 불필요, `optional.sanctum` — Bearer 토큰이 있으면 회원 맥락)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/frontend-config HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| scope | string | `user` | 응답 범위 (`user`) |
| locale | string | `ko` | 이 요청에 적용된 로케일 |
| settings | object | `{"general":{"site_name":"그누보드7",…}}` | 코어 환경설정 중 공개 항목 (탭별 묶음). 셸 `G7Config.settings` 와 같다 |
| modules | object | `{"sirsoft-ecommerce":{"basic_info":{…}}}` | 활성 모듈의 공개 설정 (식별자별). 셸 `G7Config.modules` 와 같다 |
| plugins | object | `{"sirsoft-ckeditor5":{…}}` | 활성 플러그인의 공개 설정 (식별자별). 셸 `G7Config.plugins` 와 같다 |
| template_settings | object | `{"branding":{"favicon_url":null,"accent":"blue",…},"header":{…},"footer":{…},"home":{…}}` | 활성 사용자 템플릿 하나의 공개 설정 — 템플릿 `defaults.json` 의 `frontend_schema` 가 노출한 칸을 섹션별로(모듈 설정과 같은 섹션 중첩). 첨부·이미지 칸은 계산 키 `{칸}_url` 만, 비밀값(`sensitive`)은 싣지 않는다. 셸 `G7Config.templateSettings` 와 같다 |
| app_config | object | `{"supportedTimezones":[…]}` | 앱 상수 (지원 타임존·로케일 등). 셸 `G7Config.appConfig` 와 같다. `runtime_role`(`live`·`stage`, 7.2.0) 도 싣는다 — 관리자 화면이 검수 서버 띠·수명주기 버튼 잠금에 쓴다 |
| websocket | object\|null | `null` | 실시간 연결 설정 (방송이 꺼져 있으면 null) |
| custom_routes | object | `{"user":[],"admin":[]}` | `routes/custom.php` 의 GET 주소 패턴 (범위별, `*` = 제약 없는 한 구간, `{정규식}` = 라우트 where 제약이 걸린 구간). 엔진은 이 주소로 갈 때 전체 페이지 이동 |
| consent_initial | object | `{"contract_version":1,"status":"unknown",…}` | 쿠키 동의 초기 상태 (사용자 범위에만) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "scope": "user",
        "locale": "ko",
        "settings": {
            "general": {
                "site_name": "그누보드7",
                "site_description": "",
                "timezone": "Asia/Seoul",
                "site_logo": [],
                "site_logo_url": null,
                "asset_url_mode": "extensionless",
                "layout_edit_button_enabled": true
            }
        },
        "modules": {
            "sirsoft-ecommerce": {
                "basic_info": {
                    "route_path": "shop",
                    "no_route": false
                }
            }
        },
        "plugins": {},
        "app_config": {},
        "websocket": null,
        "custom_routes": {
            "user": [],
            "admin": []
        },
        "consent_initial": {
            "contract_version": 1,
            "status": "unknown",
            "categories": {
                "necessary": true,
                "functional": false,
                "analytics": false,
                "marketing": false
            },
            "needs_renewal": false
        }
    }
}
```

_응답 예시는 실측 응답에서 `settings`·`modules`·`plugins`·`app_config` 일부를 줄였다._

**에러 응답**

| 상태 | 조건 |
| --- | --- |
| 429 | 공개 데이터 요청 한도(`throttle`) 초과 |

<!-- @generated:end -->

**설명** G7 사용자 셸(`resources/views/app.blade.php`)이 `window.G7Config` 로 내리는 값과 같은 배열을 JSON 으로 돌려준다. 두 곳이 같은 서비스(`FrontendConfigService::build('user')`)를 쓰므로 값이 갈리지 않는다. 사용자 템플릿이 없어도 동작하며, 외부 화면은 이 응답으로 사이트 이름·로케일·통화·쿠키 동의 초기값을 읽는다. 민감 설정(`frontend_schema` 의 `expose:false`)은 셸과 똑같이 빠진다.


### GET /api/admin/frontend-config
<!-- @generated:start:api.admin.frontend-config -->
- **라우트명**: `api.admin.frontend-config`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\FrontendConfigController@show`
- **인증/권한**: 관리자 인증 (관리자 API 그룹)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/frontend-config HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {token}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 키는 사용자 범위와 같고 `consent_initial` 이 없다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| scope | string | `admin` | 응답 범위 (`admin`) |
| locale | string | `ko` | 이 요청에 적용된 로케일 |
| settings | object | `{"general":{"site_name":"그누보드7",…}}` | 관리자 셸 `G7Config.settings` 와 같은 공개 설정 |
| modules | object | `{…}` | 활성 모듈의 공개 설정 |
| plugins | object | `{…}` | 활성 플러그인의 공개 설정 |
| template_settings | object | `{…}` | 활성 관리자 템플릿 하나의 공개 설정 |
| app_config | object | `{…}` | 앱 상수 |
| websocket | object\|null | `null` | 실시간 연결 설정 |
| custom_routes | object | `{"user":[],"admin":[]}` | `routes/custom.php` 의 GET 주소 패턴 (범위별) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "scope": "admin",
        "locale": "ko",
        "settings": {
            "general": {
                "site_name": "그누보드7"
            }
        },
        "modules": {},
        "plugins": {},
        "app_config": {},
        "websocket": null,
        "custom_routes": {
            "user": [],
            "admin": []
        }
    }
}
```

**에러 응답**

| 상태 | 조건 |
| --- | --- |
| 401 | 인증 토큰 없음·만료 |
| 403 | 관리자가 아닌 회원 |

<!-- @generated:end -->

**설명** G7 관리자 셸(`resources/views/admin.blade.php`)이 내리는 `window.G7Config` 와 같은 값이다(`FrontendConfigService::build('admin')`). custom 관리자 화면(예: `php artisan custom:example board` 의 `/admin/custom-example`)이 `InternalApiClient` 로 불러 사이트 이름을 읽는 용도로 쓴다.
