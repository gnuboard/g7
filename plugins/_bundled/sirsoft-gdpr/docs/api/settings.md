# Settings API 레퍼런스

> **소유**: plugin `sirsoft-gdpr` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-gdpr/admin/settings
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.settings.show -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.settings.show`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminSettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| settings | object | `{"privacy_policy_slug":"privacy","legal_entity_name":"","…` | GDPR 플러그인의 관리자 설정 전체 객체. 정책 메타데이터(slug/법인명/저장 위치), 배너 설정, 쿠키 카테고리 카탈로그, 차단 도메인을 담으며 `cookie_categories` 등 JSON 필드는 디코드되어 객체/배열로 노출됩니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "settings": {
            "privacy_policy_slug": "privacy",
            "legal_entity_name": "",
            "data_storage_location": "",
            "banner_enabled": true,
            "banner_position": "bottom_bar",
            "blocked_domains": {
                "functional": [
                    "*.crisp.chat",
                    "widget.intercom.io"
                ],
                "analytics": [
                    "google-analytics.com"
                ],
                "marketing": [
                    "facebook.com"
                ]
            },
            "cookie_categories": [
                {
                    "key": "necessary",
                    "required": true,
                    "label": {
                        "ko": "필수 쿠키",
                        "en": "Strictly Necessary"
                    },
                    "description": {
                        "ko": "세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 가입 시 선택한 언어 설정, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.",
                        "en": "Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference at registration, cookie consent record. Cannot be disabled."
                    }
                },
                {
                    "key": "functional",
                    "required": false,
                    "label": {
                        "ko": "기능 쿠키",
                        "en": "Functional"
                    },
                    "description": {
                        "ko": "사용자 선호도(다크모드, 표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.",
                        "en": "Cookies that remember user preferences such as dark mode and display currency. If declined, defaults are used on every visit."
                    }
                },
                {
                    "key": "analytics",
                    "required": false,
                    "label": {
                        "ko": "분석 쿠키",
                        "en": "Analytics"
                    },
                    "description": {
                        "ko": "방문자가 사이트를 어떻게 이용하는지 익명으로 측정해 더 나은 서비스를 만드는 데 사용됩니다. (예: Google Analytics, Hotjar)",
                        "en": "Used to anonymously measure how visitors use the site so we can improve it. (e.g. Google Analytics, Hotjar)"
                    }
                },
                {
                    "key": "marketing",
                    "required": false,
                    "label": {
                        "ko": "마케팅 쿠키",
                        "en": "Marketing"
                    },
                    "description": {
                        "ko": "관심사에 맞는 광고를 보여주거나, 광고가 얼마나 효과적이었는지 측정하는 데 사용됩니다. SNS 영상 임베드 등도 포함됩니다. (예: Facebook 픽셀, Google 광고, YouTube 영상)",
                        "en": "Used to show ads relevant to your interests, measure ad performance, and embed social media content. (e.g. Facebook Pixel, Google Ads, YouTube embeds)"
                    }
                }
            ]
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** GDPR 플러그인의 관리자 설정 전체를 반환해 관리자 설정 화면 폼에 바인딩합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.view` 권한이 필요합니다. `cookie_categories` 같은 JSON 필드는 디코드하여 객체/배열로 노출합니다. 조회 전용이며 정책 버전 발행 등 부수 효과는 없습니다.


### PUT /api/plugins/sirsoft-gdpr/admin/settings
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.settings.update -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.settings.update`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminSettingsController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| privacy_policy_slug | body | string | 아니오 | max 100 | 개인정보 처리방침 페이지 slug(소문자·숫자·하이픈만). 비면 처리방침 링크가 비활성화되며 배너/마이페이지의 방침 링크 대상이 됩니다 |
| legal_entity_name | body | string | 아니오 | max 200 | legal entity 이름 (식별자) |
| data_storage_location | body | string | 아니오 | max 200 | 데이터 저장 위치 표기(GDPR Art.13(1)(f)/PIPA 국가 단위 안내용). IP/CIDR·클라우드 리전 코드 등 보안 민감 식별자는 검증에서 차단됩니다 |
| banner_enabled | body | boolean | 아니오 | — | 쿠키 배너와 자동 차단 엔진의 통합 활성 토글. `true`일 때만 배너 노출과 도메인 기반 차단이 동작합니다 |
| banner_position | body | string | 아니오 | `bottom_bar`, `bottom_left_popup`, `bottom_right_popup`, `centered_modal` | 쿠키 배너 노출 위치(하단 바/좌하단 팝업/우하단 팝업/중앙 모달) |
| cookie_categories | body | string | 아니오 | — | 쿠키 카테고리 카탈로그(JSON 문자열 또는 배열). 각 항목의 `key`는 necessary/functional/analytics/marketing 중 하나이며 `label.ko`/`label.en`이 필수입니다 |
| blocked_domains | body | array | 아니오 | — | 카테고리별 차단 도메인 패턴 배열(necessary 제외). FQDN 및 `*.` 와일드카드 prefix만 허용되며 textarea 줄바꿈 입력도 배열로 정규화됩니다 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-gdpr/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "privacy_policy_slug": "example-key",
    "legal_entity_name": "예시 이름",
    "data_storage_location": "예시값",
    "banner_enabled": true,
    "banner_position": "bottom_bar",
    "cookie_categories": "예시값",
    "blocked_domains": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 검증된 GDPR 관리자 설정을 저장합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.update` 권한이 필요합니다. 설정만 저장할 뿐 정책 버전은 자동 발행되지 않으며, 재동의가 필요한 변경이라면 운영자가 「+ 새 버전 발행」을 별도로 눌러야 합니다. 배너 문구·위치, 쿠키 카테고리, 차단 도메인 등을 갱신하는 쓰기 엔드포인트입니다.


### GET /api/plugins/sirsoft-gdpr/settings
<!-- @generated:start:api.plugins.sirsoft-gdpr.settings -->
- **라우트명**: `api.plugins.sirsoft-gdpr.settings`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Public\GdprSettingsController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/settings HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cookie_policy_version | string | `10` | 현재 발행된 최신 정책 버전 문자열(`gdpr_policy_versions` 테이블이 SSoT, 최신 row의 version 정수). 게스트/회원 동의 시점 비교 및 배너 재노출 판정에 사용됩니다 |
| privacy_policy_slug | string | `privacy` | 개인정보 처리방침 페이지 slug. 설정이 비어 있으면 null로 반환됩니다 |
| privacy_policy_available | boolean | `true` | 처리방침 slug가 설정되어 링크 노출이 가능한지 여부. slug가 비면 `false`입니다 |
| legal_entity_name | string | `` | 개인정보 처리 법인/사업자명. 미설정 시 빈 문자열입니다 |
| data_storage_location | string | `` | 데이터 저장 위치 표기 문자열(국가 단위 안내). 미설정 시 빈 문자열입니다 |
| banner_enabled | boolean | `true` | 쿠키 배너와 자동 차단의 통합 활성 여부. `true`일 때만 게스트에게 배너가 노출되고 도메인 차단 엔진이 동작합니다 |
| banner_position | string | `bottom_bar` | 쿠키 배너 노출 위치(bottom_bar / bottom_left_popup / bottom_right_popup / centered_modal) |
| cookie_categories | array | `[{"key":"necessary","required":true,"label":{"ko":"필수 쿠키"…` | 배너·마이페이지 렌더링용 쿠키 카테고리 카탈로그 배열. 각 항목은 `key`, `required`(필수 여부), 다국어 `label`/`description`을 담습니다 |
| blocked_domains | object | `{"functional":["*.crisp.chat","widget.intercom.io"],"anal…` | 현재 설정된 카테고리별 차단 도메인 패턴(키→도메인 배열). 게스트도 차단이 동작해야 하므로 공개 응답에 노출되며 본 컨트롤러가 노출 SSoT입니다 |
| default_blocked_domains_preview | object | `{"functional":["*.crisp.chat","client.crisp.chat","*.inte…` | 플러그인 기본 차단 도메인 카탈로그 미리보기(변경 불가 기본값). 관리자가 커스텀 차단 목록을 편집할 때 참고용 기본 패턴을 보여줍니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "cookie_policy_version": "1",
        "privacy_policy_slug": "privacy",
        "privacy_policy_available": true,
        "legal_entity_name": "",
        "data_storage_location": "",
        "banner_enabled": true,
        "banner_position": "bottom_bar",
        "cookie_categories": [
            {
                "key": "necessary",
                "required": true,
                "label": {
                    "ko": "필수 쿠키",
                    "en": "Strictly Necessary"
                },
                "description": {
                    "ko": "세션·CSRF·로그인 토큰, 장바구니 식별자, 사용자가 가입 시 선택한 언어 설정, 쿠키 동의 기록 등 사이트 운영에 반드시 필요한 항목입니다. 비활성화할 수 없습니다.",
                    "en": "Strictly necessary for site operation: session/CSRF/auth tokens, shopping basket identifier, user-selected language preference at registration, cookie consent record. Cannot be disabled."
                }
            },
            {
                "key": "functional",
                "required": false,
                "label": {
                    "ko": "기능 쿠키",
                    "en": "Functional"
                },
                "description": {
                    "ko": "사용자 선호도(다크모드, 표시 통화 등)를 기억하는 쿠키입니다. 거부 시 매 방문마다 기본값으로 표시됩니다.",
                    "en": "Cookies that remember user preferences such as dark mode and display currency. If declined, defaults are used on every visit."
                }
            },
            {
                "key": "analytics",
                "required": false,
                "label": {
                    "ko": "분석 쿠키",
                    "en": "Analytics"
                },
                "description": {
                    "ko": "방문자가 사이트를 어떻게 이용하는지 익명으로 측정해 더 나은 서비스를 만드는 데 사용됩니다. (예: Google Analytics, Hotjar)",
                    "en": "Used to anonymously measure how visitors use the site so we can improve it. (e.g. Google Analytics, Hotjar)"
                }
            },
            {
                "key": "marketing",
                "required": false,
                "label": {
                    "ko": "마케팅 쿠키",
                    "en": "Marketing"
                },
                "description": {
                    "ko": "관심사에 맞는 광고를 보여주거나, 광고가 얼마나 효과적이었는지 측정하는 데 사용됩니다. SNS 영상 임베드 등도 포함됩니다. (예: Facebook 픽셀, Google 광고, YouTube 영상)",
                    "en": "Used to show ads relevant to your interests, measure ad performance, and embed social media content. (e.g. Facebook Pixel, Google Ads, YouTube embeds)"
                }
            }
        ],
        "blocked_domains": {
            "functional": [
                "*.crisp.chat",
                "widget.intercom.io"
            ],
            "analytics": [
                "google-analytics.com"
            ],
            "marketing": [
                "facebook.com"
            ]
        },
        "default_blocked_domains_preview": {
            "functional": [
                "*.crisp.chat",
                "client.crisp.chat",
                "*.intercom.io",
                "widget.intercom.io",
                "*.tawk.to",
                "embed.tawk.to",
                "cdn.weglot.com",
                "*.weglot.com",
                "*.usercentrics.eu"
            ],
            "analytics": [
                "google-analytics.com",
                "*.google-analytics.com",
                "googletagmanager.com",
                "*.googletagmanager.com",
                "ssl.google-analytics.com",
                "*.hotjar.com",
                "static.hotjar.com",
                "*.mixpanel.com",
                "cdn.mxpnl.com",
                "*.amplitude.com",
                "cdn.amplitude.com",
                "*.segment.io",
                "*.segment.com",
                "wcs.naver.net",
                "wcs.naver.com",
                "*.beusable.net"
            ],
            "marketing": [
                "facebook.net",
                "connect.facebook.net",
                "facebook.com",
                "*.facebook.com",
                "doubleclick.net",
                "*.doubleclick.net",
                "googleadservices.com",
                "googlesyndication.com",
                "ads.google.com",
                "*.criteo.com",
                "static.criteo.net",
                "*.adnxs.com",
                "*.taboola.com",
                "cdn.taboola.com",
                "*.outbrain.com",
                "*.kakao.com",
                "analytics.ad.daum.net",
                "platform.twitter.com",
                "*.twitter.com",
                "platform.linkedin.com",
                "*.linkedin.com"
            ]
        }
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명** 게스트도 접근할 수 있는 완전 공개 엔드포인트로, 인증 없이 호출됩니다. 공개 쿠키 동의 배너와 마이페이지 카드 렌더링에 필요한 쿠키 카테고리·배너 설정·차단 도메인과 현재 `cookie_policy_version`(정책 버전 서비스 기준)을 반환합니다. 차단 도메인은 게스트도 차단이 동작해야 하므로 공개 응답에 노출되며, 이 컨트롤러가 응답 노출의 기준(SSoT)입니다. 조회 전용입니다.


