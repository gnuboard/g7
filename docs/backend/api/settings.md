# Settings API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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


### GET /api/admin/settings
<!-- @generated:start:api.admin.settings.index -->
- **라우트명**: `api.admin.settings.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| general | object | `{"site_name":"Test Site","site_url":"https:\/\/test.examp…` | 일반 탭 설정 그룹 (사이트명·사이트 URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고 첨부). site_logo 는 SettingsService 가 별도 주입한 첨부 정보 |
| security | object | `{"force_https":true,"login_attempt_enabled":true,"auth_to…` | 보안 탭 설정 그룹 (HTTPS 강제·로그인 시도 제한 사용·인증 토큰 유지시간(분, 0=무한)·최대 로그인 시도 횟수·잠금 시간) |
| mail | object | `{"mailer":"smtp","host":"","port":587,"username":"","pass…` | 메일 탭 설정 그룹 (메일러 종류(smtp/mailgun/ses)·SMTP 호스트/포트/인증 정보·암호화 방식·발신자 주소/이름·Mailgun/SES 자격 정보) |
| upload | object | `{"max_file_size":10,"allowed_extensions":["jpg","jpeg","p…` | 업로드 탭 설정 그룹 (최대 파일 크기(MB)·허용 확장자 목록·이미지 최대 가로/세로·이미지 품질) |
| seo | object | `{"meta_title_suffix":"","meta_description":"","meta_keywo…` | SEO 탭 설정 그룹 (메타 타이틀 접미사·메타 설명/키워드·검색엔진 인증 코드·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기 설정) |
| advanced | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 고급 탭 설정 그룹 (캐시·디버그·코어 업데이트·GeoIP 설정을 한 탭으로 합친 병합 뷰). cache/debug 카테고리 값이 함께 노출됨 |
| cache | object | `{"cache_enabled":true,"cache_default_ttl":86400,"layout_c…` | 캐시 원본 카테고리 (전역 캐시 사용·기본 TTL·레이아웃/통계/SEO 캐시 사용 및 TTL). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| debug | object | `{"debug_mode":true,"sql_query_log":false,"log_level":"err…` | 디버그 원본 카테고리 (디버그 모드·SQL 쿼리 로그·로그 레벨). advanced 탭에 병합되면서 개별 접근용으로 별도 노출된 파생 뷰 |
| drivers | object | `{"storage_driver":"local","s3_bucket":null,"s3_region":"a…` | 드라이버 탭 설정 그룹 (스토리지/캐시/세션/큐/로그 드라이버 선택 + S3·Redis·Memcached·WebSocket·검색엔진 접속 파라미터) |
| core_update | object | `{"core_update_github_url":"https:\/\/github.com\/custom\/…` | 코어 업데이트 원본 카테고리 (코어 업데이트를 받아올 GitHub 저장소 URL·비공개 저장소 접근용 토큰). advanced 탭에 병합된 파생 뷰 |
| geoip | object | `{"geoip_enabled":false,"geoip_license_key":null,"geoip_au…` | GeoIP 원본 카테고리 (GeoIP 사용 여부·MaxMind 라이선스 키·DB 자동 갱신 사용). advanced 탭에 병합된 파생 뷰 |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":1…` | 알림 탭 설정 그룹. channels 는 알림 채널 목록으로 각 원소가 id(채널 식별자)·is_active(활성 여부)·sort_order(표시 순서)를 가짐 |
| identity | object | `{"default_provider":"g7:core.mail","purpose_providers":{"…` | 본인인증(IDV) 탭 설정 그룹 (기본 provider·목적별 provider 매핑(purpose_providers)·챌린지 유효시간(분)·최대 시도 횟수) |
| available_drivers | object | `{"storage":[{"id":"local","label":{"ko":"로컬","en":"Local"…` | 드라이버 선택지 카탈로그 (DriverRegistryService 산물). 종류별(storage/cache/session/queue 등) 선택 가능한 드라이버 목록을 id/다국어 label 형태로 제공 |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "설정을 성공적으로 가져왔습니다.",
    "data": {
        "general": {
            "site_name": "Test Site",
            "site_url": "https://test.example.com",
            "site_description": "",
            "admin_email": "admin@example.com",
            "timezone": "Asia/Seoul",
            "language": "ko",
            "currency": "KRW",
            "maintenance_mode": false,
            "site_logo": []
        },
        "security": {
            "force_https": true,
            "login_attempt_enabled": true,
            "auth_token_lifetime": "{MASKED}",
            "max_login_attempts": 5,
            "login_lockout_time": 30,
            "two_factor_auth": false,
            "password_min_length": "{MASKED}",
            "require_password_special_char": "{MASKED}"
        },
        "mail": {
            "mailer": "smtp",
            "host": "",
            "port": 587,
            "username": "",
            "password": "{MASKED}",
            "encryption": "tls",
            "mailgun_domain": "",
            "mailgun_secret": "{MASKED}",
            "mailgun_endpoint": "api.mailgun.net",
            "ses_key": "",
            "ses_secret": "{MASKED}",
            "ses_region": "ap-northeast-2",
            "from_address": "heuristing@gmail.com",
            "from_name": "그누보드7"
        },
        "upload": {
            "max_file_size": 10,
            "allowed_extensions": [
                "jpg",
                "jpeg",
                "png",
                "gif",
                "webp",
                "pdf",
                "doc",
                "docx",
                "xls",
                "xlsx",
                "zip"
            ],
            "image_max_width": 2000,
            "image_max_height": 2000,
            "image_quality": 85
        },
        "seo": {
            "meta_title_suffix": "",
            "meta_description": "",
            "meta_keywords": "",
            "google_analytics_id": "",
            "google_site_verification": "",
            "naver_site_verification": "",
            "bot_user_agents": [
                "kakaotalk-scrap",
                "Meta-ExternalAgent",
                "ChatGPT-User"
            ],
            "bot_detection_enabled": true,
            "bot_detection_library_enabled": true,
            "og_default_site_name": "",
            "og_image_default_width": 1200,
            "og_image_default_height": 630,
            "twitter_default_card": "summary_large_image",
            "twitter_default_site": "",
            "cache_enabled": true,
            "cache_ttl": 7200,
            "sitemap_enabled": true,
            "sitemap_cache_ttl": 86400,
            "sitemap_schedule": "daily",
            "sitemap_schedule_time": "02:00",
            "sitemap_last_updated_at": "2026-07-08T03:14:49+00:00",
            "generator_enabled": true,
            "generator_content": ""
        },
        "advanced": {
            "cache_enabled": true,
            "cache_default_ttl": 86400,
            "layout_cache_enabled": true,
            "layout_cache_ttl": 3600,
            "seo_cache_enabled": true,
            "seo_cache_ttl": 7200,
            "seo_sitemap_cache_ttl": 86400,
            "stats_cache_enabled": true,
            "stats_cache_ttl": 1800,
            "notification_cache_ttl": 3600,
            "extension_status_cache_ttl": 86400,
            "geoip_cache_enabled": true,
            "geoip_cache_ttl": 86400,
            "version_check_cache_ttl": 3600,
            "debug_mode": true,
            "sql_query_log": false,
            "log_level": "error",
            "core_update_github_url": "https://github.com/custom/repo",
            "core_update_github_token": "{MASKED}",
            "geoip_enabled": false,
            "geoip_license_key": null,
            "geoip_auto_update_enabled": true,
            "geoip_last_updated_at": ""
        },
        "cache": {
            "cache_enabled": true,
            "cache_default_ttl": 86400,
            "layout_cache_enabled": true,
            "layout_cache_ttl": 3600,
            "seo_cache_enabled": true,
            "seo_cache_ttl": 7200,
            "seo_sitemap_cache_ttl": 86400,
            "stats_cache_enabled": true,
            "stats_cache_ttl": 1800,
            "notification_cache_ttl": 3600,
            "extension_status_cache_ttl": 86400,
            "geoip_cache_enabled": true,
            "geoip_cache_ttl": 86400,
            "version_check_cache_ttl": 3600
        },
        "debug": {
            "debug_mode": true,
            "sql_query_log": false,
            "log_level": "error"
        },
        "drivers": {
            "storage_driver": "local",
            "s3_bucket": null,
            "s3_region": "ap-northeast-2",
            "s3_access_key": null,
            "s3_secret_key": null,
            "s3_url": null,
            "cache_driver": "file",
            "redis_host": "127.0.0.1",
            "redis_port": 6379,
            "redis_password": null,
            "redis_database": "1",
            "memcached_host": "127.0.0.1",
            "memcached_port": 11211,
            "session_driver": "file",
            "session_lifetime": 120,
            "queue_driver": "sync",
            "log_driver": "daily",
            "log_level": "error",
            "log_days": 14,
            "websocket_enabled": true,
            "websocket_app_id": "test-app-id",
            "websocket_app_key": "{MASKED}",
            "websocket_app_secret": "{MASKED}",
            "websocket_host": "localhost",
            "websocket_port": 8080,
            "websocket_scheme": "https",
            "websocket_verify_ssl": true,
            "websocket_server_host": "127.0.0.1",
            "websocket_server_port": 8080,
            "websocket_server_scheme": "http",
            "search_engine_driver": "mysql-fulltext"
        },
        "core_update": {
            "core_update_github_url": "https://github.com/custom/repo",
            "core_update_github_token": "{MASKED}"
        },
        "geoip": {
            "geoip_enabled": false,
            "geoip_license_key": null,
            "geoip_auto_update_enabled": true,
            "geoip_last_updated_at": ""
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 1
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 2
                }
            ]
        },
        "identity": {
            "default_provider": "g7:core.mail",
            "purpose_providers": {
                "signup": null,
                "password_reset": null,
                "inicis": {
                    "adult_verification": "inicis"
                }
            },
            "challenge_ttl_minutes": 15,
            "max_attempts": 5
        },
        "available_drivers": {
            "storage": [
                {
                    "id": "local",
                    "label": {
                        "ko": "로컬",
                        "en": "Local",
                        "fr": "로컬"
                    }
                },
                {
                    "id": "s3",
                    "label": {
                        "ko": "Amazon S3",
                        "en": "Amazon S3",
                        "fr": "Amazon S3"
                    }
                }
            ],
            "cache": [
                {
                    "id": "file",
                    "label": {
                        "ko": "파일",
                        "en": "File",
                        "fr": "파일"
                    }
                },
                {
                    "id": "redis",
                    "label": {
                        "ko": "Redis",
                        "en": "Redis",
                        "fr": "Redis"
                    }
                }
            ],
            "session": [
                {
                    "id": "file",
                    "label": {
                        "ko": "파일",
                        "en": "File",
                        "fr": "파일"
                    }
                },
                {
                    "id": "database",
                    "label": {
                        "ko": "데이터베이스",
                        "en": "Database",
                        "fr": "데이터베이스"
                    }
                },
                {
                    "id": "redis",
                    "label": {
                        "ko": "Redis",
                        "en": "Redis",
                        "fr": "Redis"
                    }
                }
            ],
            "queue": [
                {
                    "id": "sync",
                    "label": {
                        "ko": "동기",
                        "en": "Sync",
                        "fr": "동기"
                    }
                },
                {
                    "id": "database",
                    "label": {
                        "ko": "데이터베이스",
                        "en": "Database",
                        "fr": "데이터베이스"
                    }
                },
                {
                    "id": "redis",
                    "label": {
                        "ko": "Redis",
                        "en": "Redis",
                        "fr": "Redis"
                    }
                }
            ],
            "log": [
                {
                    "id": "single",
                    "label": {
                        "ko": "단일 파일",
                        "en": "Single File",
                        "fr": "단일 파일"
                    }
                },
                {
                    "id": "daily",
                    "label": {
                        "ko": "일별 파일",
                        "en": "Daily File",
                        "fr": "일별 파일"
                    }
                }
            ],
            "websocket": [
                {
                    "id": "reverb",
                    "label": {
                        "ko": "Laravel Reverb",
                        "en": "Laravel Reverb",
                        "fr": "Laravel Reverb"
                    }
                }
            ],
            "mail": [
                {
                    "id": "smtp",
                    "label": {
                        "ko": "SMTP",
                        "en": "SMTP",
                        "fr": "SMTP"
                    }
                },
                {
                    "id": "mailgun",
                    "label": {
                        "ko": "Mailgun",
                        "en": "Mailgun",
                        "fr": "Mailgun"
                    }
                },
                {
                    "id": "ses",
                    "label": {
                        "ko": "SES (Amazon)",
                        "en": "SES (Amazon)",
                        "fr": "SES (Amazon)"
                    }
                }
            ]
        },
        "abilities": {
            "can_update": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 통합 환경설정 화면(`admin_settings.json`)이 사용하는 전체 설정 조회 엔드포인트입니다. 각 탭에 해당하는 설정 그룹(general/security/mail/upload/seo/advanced/drivers/geoip/notifications/identity 등)과 드라이버 선택지 카탈로그(`available_drivers`)를 한 번에 반환합니다. 응답은 Eloquent 모델이 아니라 SettingsService 가 여러 설정 소스를 병합해 만든 집계 배열이며, 일부 그룹(cache/debug 등)은 원본 카테고리 값을 별도 키로 함께 노출한 파생 뷰입니다.


### POST /api/admin/settings
<!-- @generated:start:api.admin.settings.store -->
- **라우트명**: `api.admin.settings.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | — | 활성 탭 식별자 (general/mail/upload/seo/security/drivers/advanced/notifications/identity). 지정 시 해당 탭 필드만 필수 검증되고 나머지 탭은 nullable 처리되어 탭 단위 부분 저장을 가능케 함 |
| general | body | array | 아니오 | — | 일반 탭 설정 묶음 (사이트명·URL·설명·관리자 이메일·타임존·기본 언어·통화·점검 모드·사이트 로고) |
| mail | body | array | 아니오 | — | 메일 탭 설정 묶음 (메일러 종류·SMTP 호스트/포트/인증·암호화·발신자 정보·Mailgun/SES 자격 정보) |
| upload | body | array | 아니오 | — | 업로드 탭 설정 묶음 (최대 파일 크기·허용 확장자·이미지 최대 크기 및 품질) |
| seo | body | array | 아니오 | — | SEO 탭 설정 묶음 (메타 태그·검색엔진 인증·봇 감지·OG/Twitter 기본값·SEO 캐시·사이트맵·생성기) |
| security | body | array | 아니오 | — | 보안 탭 설정 묶음 (HTTPS 강제·로그인 시도 제한·인증 토큰 유지시간·최대 시도 횟수·잠금 시간) |
| drivers | body | array | 아니오 | — | 드라이버 탭 설정 묶음 (스토리지/캐시/세션/큐/로그 드라이버 및 S3·Redis·Memcached·WebSocket·검색엔진 접속 정보) |
| advanced | body | array | 아니오 | — | 고급 탭 설정 묶음 (캐시·디버그·코어 업데이트·GeoIP 설정) |
| notifications | body | array | 아니오 | — | 알림 탭 설정 묶음. channels 배열로 각 알림 채널의 id·is_active(활성 여부)·sort_order(표시 순서)를 저장 |
| identity | body | array | 아니오 | — | 본인인증(IDV) 탭 설정 묶음 (기본 provider·목적별 provider 매핑·챌린지 유효시간·최대 시도 횟수) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.save_validation_rules`, `core.search.engine_drivers`).

**요청 예시**

```http
POST /api/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "_tab": "예시값",
    "general": [
        "예시값"
    ],
    "mail": [
        "예시값"
    ],
    "upload": [
        "예시값"
    ],
    "seo": [
        "예시값"
    ],
    "security": [
        "예시값"
    ],
    "drivers": [
        "예시값"
    ],
    "advanced": [
        "예시값"
    ],
    "notifications": [
        "예시값"
    ],
    "identity": [
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
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

통합 환경설정 화면에서 한 탭의 설정을 일괄 저장합니다. `_tab` 으로 활성 탭을 지정하면 해당 탭의 필드만 필수 검증되고 다른 탭 필드는 nullable 로 처리되므로, 탭 단위로 부분 저장할 수 있습니다. 저장 성공 시 응답 `data.settings` 에 갱신된 전체 설정과 `available_drivers` 를 함께 반환하여, 프론트엔드가 새로고침 없이 전역 상태를 갱신할 수 있습니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.


### GET /api/admin/settings/app-key
<!-- @generated:start:api.admin.settings.app-key -->
- **라우트명**: `api.admin.settings.app-key`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@getAppKey`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/app-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| app_key | string | `base64:97gZH*************************…` | 현재 애플리케이션 키(`APP_KEY`)를 마스킹한 문자열. 앞부분 일부만 노출하고 나머지는 별표로 가려 전체 원문은 반환하지 않음 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "app_key": "{MASKED}"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 애플리케이션 키(`APP_KEY`)를 마스킹된 형태로 조회합니다. 관리자 화면에서 앱 키 존재/일부만 표시하는 용도이며, 전체 키 원문은 반환하지 않습니다.


### POST /api/admin/settings/backup
<!-- @generated:start:api.admin.settings.backup -->
- **라우트명**: `api.admin.settings.backup`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@backup`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/backup HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

현재 설정을 백업 파일로 저장합니다. 응답 `data.backup_path` 에 생성된 백업 경로를 반환하며, 이 경로는 이후 `POST /restore` 의 `backup_path` 로 사용할 수 있습니다. 설정 변경 전 스냅샷을 남길 때 사용합니다.


### POST /api/admin/settings/backup-database
<!-- @generated:start:api.admin.settings.backup-database -->
- **라우트명**: `api.admin.settings.backup-database`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@backupDatabase`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/backup-database HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

데이터베이스를 백업합니다. SettingsService 에 위임하며, 성공/실패를 메시지로 반환합니다. 설정 백업(`POST /backup`)이 설정 파일만 다루는 것과 달리, 이 엔드포인트는 DB 데이터를 백업 대상으로 합니다.


### POST /api/admin/settings/clear-cache
<!-- @generated:start:api.admin.settings.clear-cache -->
- **라우트명**: `api.admin.settings.clear-cache`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/clear-cache HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

시스템 캐시를 정리합니다. 시스템 정보 캐시를 지원 로케일별로 비운 뒤 `cache:clear`, `route:clear`, `view:clear` 를 실행하고, config 캐시는 비운 직후 즉시 재생성합니다(비워 두면 이후 모든 요청이 config 를 재파싱하므로). 설정/코드 변경 후 오래된 캐시를 초기화할 때 사용합니다.


### POST /api/admin/settings/geoip/update
<!-- @generated:start:api.admin.settings.geoip.update -->
- **라우트명**: `api.admin.settings.geoip.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\GeoIpController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/geoip/update HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

MaxMind GeoLite2-City DB 를 즉시 재다운로드합니다. GeoIpDatabaseService 에 위임하며 동기(즉시) 실행되므로 웹서버/PHP-FPM 타임아웃(90초 이상)이 필요합니다. 라이선스 키 미설정 시 400, 키가 잘못된 경우 401, 연결 실패/기타 오류 시 500 을 반환합니다. 정기 갱신은 스케줄(`geoip:update`)이 담당하고, 이 엔드포인트는 수동 갱신 트리거입니다.


### POST /api/admin/settings/optimize-system
<!-- @generated:start:api.admin.settings.optimize-system -->
- **라우트명**: `api.admin.settings.optimize-system`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@optimizeSystem`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/settings/optimize-system HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "시스템이 성공적으로 최적화되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

시스템을 최적화합니다. `config:cache`, `route:cache`, `view:cache` 를 실행해 설정·라우트·뷰 캐시를 생성함으로써 이후 요청의 부팅 비용을 줄입니다. 캐시를 비우는 `clear-cache` 와 반대로, 캐시를 사전 생성하는 프로덕션 성능용 작업입니다.


### POST /api/admin/settings/regenerate-app-key
<!-- @generated:start:api.admin.settings.regenerate-app-key -->
- **라우트명**: `api.admin.settings.regenerate-app-key`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@regenerateAppKey`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| password | body | string | 예 | — | 비밀번호 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.regenerate_app_key_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/regenerate-app-key HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

애플리케이션 키(`APP_KEY`)를 재생성합니다. FormRequest 단계에서 `super_admin` 역할만 허용하고, Service 단계에서 요청자 본인의 비밀번호가 일치하는지 다시 확인합니다(불일치 시 401). 성공 시 새 키를 `.env` 의 `APP_KEY` 에 기록하고 config 캐시를 재생성하며, 응답 `data.app_key` 에 새 키를 반환합니다. 앱 키 변경은 기존 암호화 값/서명 무효화를 동반하므로 주의가 필요합니다.


### POST /api/admin/settings/restore
<!-- @generated:start:api.admin.settings.restore -->
- **라우트명**: `api.admin.settings.restore`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@restore`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| backup_path | body | string | 예 | — | 복원할 백업 파일 경로. `POST /api/admin/settings/backup` 응답의 `backup_path` 로 받은 값을 그대로 지정 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.restore_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/restore HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "backup_path": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

이전에 만든 설정 백업에서 설정을 복원합니다. `backup_path` 로 `POST /backup` 이 반환한 백업 경로를 지정합니다. 복원 성공 시 시스템 설정 캐시를 무효화합니다. 잘못된 설정을 되돌릴 때 사용합니다.


### GET /api/admin/settings/system-info
<!-- @generated:start:api.admin.settings.system-info -->
- **라우트명**: `api.admin.settings.system-info`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@systemInfo`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/settings/system-info HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| os_info | string | `Windows NT 10.0` | 운영체제 종류와 버전 (`php_uname` 산물). probe 차단 시 "알 수 없음" 폴백 |
| web_server | string | `Apache/2.4.62 (Win64) OpenSSL/3.0.16 …` | 웹서버 소프트웨어 식별 문자열 (`$_SERVER['SERVER_SOFTWARE']`) |
| php_version | string | `8.3.26` | 실행 중인 PHP 버전 (`PHP_VERSION`) |
| mysql_version | string | `Mysql 8.4.3` | 연결된 데이터베이스 서버 종류와 버전 (DB 조회 산물). probe 실패 시 "알 수 없음" 폴백 |
| g7_version | string | `7.0.1` | G7 코어 버전 (`config('app.version')`) |
| g7_release_year | string | `2026` | G7 릴리즈 연도 (`config('app.release_year')`, 저작권 표기 등에 사용) |
| laravel_version | string | `12.54.1` | 프레임워크 Laravel 버전 (`app()->version()`) |
| environment | string | `local` | 현재 실행 환경 (`app()->environment()` — local/production/testing 등) |
| cpu_info | string | `Intel(R) Core(TM) Ultra 5 225H` | CPU 모델명 (OS별 시스템 probe 산물). 수집 실패 시 "알 수 없음" 폴백 |
| memory_usage | object | `{"total":"31.49 GB","used":"29.63 GB","free":"1.86 GB","p…` | 물리 메모리 사용량. total/used/free 는 사람이 읽기 쉬운 단위 문자열, percentage 는 사용률(%) |
| disk_usage | object | `{"total":"474.72 GB","used":"360.2 GB","free":"114.51 GB"…` | 설치 볼륨 디스크 사용량. total/used/free 단위 문자열 + percentage 사용률(%) |
| php_memory_limit | string | `512M` | PHP `memory_limit` ini 값 |
| max_execution_time | string | `36000초` | PHP `max_execution_time` ini 값 (초 단위 접미사 부착) |
| upload_max_filesize | string | `2G` | PHP `upload_max_filesize` ini 값 |
| install_path | string | `C:\Users\HeuJung\htdocs\g7_2` | 애플리케이션 설치 루트 경로 (`base_path()`) |
| config_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 설정 파일 저장 경로 (`storage/app/settings`) |
| log_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 로그 파일 저장 경로 (`storage/logs`) |
| upload_path | string | `C:\Users\HeuJung\htdocs\g7_2\storage\…` | 공개 업로드 파일 저장 경로 (`storage/app/public`) |
| php_extensions | object | `{"required":{"openssl":true,"pdo":true,"mbstring":true,"t…` | PHP 확장 로드 상태. required(필수)·optional(선택) 두 그룹으로 나뉘며 각 확장명→로드 여부(bool) 매핑 |
| database_config | object | `{"has_read_write_split":false,"write":{"host":"localhost"…` | DB 연결 구성 요약. has_read_write_split(읽기/쓰기 분리 여부)·write(쓰기 연결 정보)·read(읽기 replica 목록, write 와 동일하면 제외) |
| timezone | string | `UTC` | 애플리케이션 기본 타임존 (`config('app.timezone')`) |
| server_time | string | `2026-07-07 05:08:58` | 서버 현재 시각 (Y-m-d H:i:s) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "os_info": "Windows NT 10.0",
        "web_server": "Apache/2.4.62 (Win64) OpenSSL/3.0.16 PHP/8.3.26",
        "php_version": "8.3.26",
        "mysql_version": "Mysql 8.4.3",
        "g7_version": "7.0.1",
        "g7_release_year": "2026",
        "laravel_version": "12.54.1",
        "environment": "local",
        "cpu_info": "Intel(R) Core(TM) Ultra 5 225H",
        "memory_usage": {
            "total": "31.49 GB",
            "used": "26.92 GB",
            "free": "4.57 GB",
            "percentage": 85.49
        },
        "disk_usage": {
            "total": "474.72 GB",
            "used": "362.59 GB",
            "free": "112.12 GB",
            "percentage": 76.38
        },
        "php_memory_limit": "512M",
        "max_execution_time": "36000초",
        "upload_max_filesize": "2G",
        "install_path": "C:\\Users\\HeuJung\\htdocs\\g7_2",
        "config_path": "C:\\Users\\HeuJung\\htdocs\\g7_2\\storage\\app/settings",
        "log_path": "C:\\Users\\HeuJung\\htdocs\\g7_2\\storage\\logs",
        "upload_path": "C:\\Users\\HeuJung\\htdocs\\g7_2\\storage\\app/public",
        "php_extensions": {
            "required": {
                "openssl": true,
                "pdo": true,
                "mbstring": true,
                "tokenizer": "{MASKED}",
                "xml": true,
                "curl": true,
                "json": true,
                "zip": true,
                "fileinfo": true,
                "bcmath": true
            },
            "optional": {
                "gd": true,
                "imagick": false,
                "redis": true,
                "memcached": false,
                "sodium": true,
                "exif": true,
                "intl": true,
                "ldap": false,
                "zlib": true
            }
        },
        "database_config": {
            "has_read_write_split": false,
            "write": {
                "host": "localhost",
                "port": 3306,
                "database": "g7_2",
                "username": "g7_2"
            },
            "read": []
        },
        "timezone": "UTC",
        "server_time": "2026-07-08 03:14:54"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

서버 실행 환경 정보를 한 번에 조회합니다. OS/웹서버/PHP/DB/Laravel/코어 버전, CPU·메모리·디스크 사용량, PHP 주요 설정값(memory_limit·max_execution_time·upload_max_filesize), 주요 경로, PHP 확장 로드 상태, DB 연결 구성 요약 등을 포함합니다. 관리자 시스템 정보 화면과 요구사항 점검용 진단 데이터로 사용됩니다.


### POST /api/admin/settings/test-driver
<!-- @generated:start:api.admin.settings.test-driver -->
- **라우트명**: `api.admin.settings.test-driver`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testDriverConnection`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| storage_driver | body | string | 아니오 | — | 스토리지 드라이버 (local/s3) |
| cache_driver | body | string | 아니오 | — | 캐시 드라이버 (file/redis/memcached) |
| session_driver | body | string | 아니오 | — | 세션 드라이버 (file/database/redis) |
| queue_driver | body | string | 아니오 | — | 큐 드라이버 (sync/database/redis) |
| websocket_enabled | body | boolean | 아니오 | — | WebSocket 사용 여부 |
| s3_bucket | body | string | 아니오 | max 255 | S3 버킷명 |
| s3_region | body | string | 아니오 | — | S3 리전 |
| s3_access_key | body | string | 아니오 | max 255 | S3 액세스 키 |
| s3_secret_key | body | string | 아니오 | max 255 | S3 시크릿 키 |
| s3_url | body | string | 아니오 | max 500 | S3 엔드포인트 URL |
| redis_host | body | string | 아니오 | max 255 | Redis 호스트 주소 |
| redis_port | body | integer | 아니오 | min 1, max 65535 | Redis 포트 번호 |
| redis_password | body | string | 아니오 | max 255 | Redis 비밀번호 |
| redis_database | body | integer | 아니오 | min 0, max 15 | Redis 데이터베이스 번호 |
| memcached_host | body | string | 아니오 | max 255 | Memcached 호스트 주소 |
| memcached_port | body | integer | 아니오 | min 1, max 65535 | Memcached 포트 번호 |
| websocket_app_key | body | string | 아니오 | max 255 | WebSocket 앱 키 |
| websocket_host | body | string | 아니오 | max 255 | WebSocket 호스트 주소 |
| websocket_port | body | integer | 아니오 | min 1, max 65535 | WebSocket 포트 번호 |
| websocket_scheme | body | string | 아니오 | — | WebSocket 스킴 (http/https) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_driver_connection_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-driver HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "storage_driver": "예시값",
    "cache_driver": "예시값",
    "session_driver": "예시값",
    "queue_driver": "예시값",
    "websocket_enabled": true,
    "s3_bucket": "예시값",
    "s3_region": "예시값",
    "s3_access_key": "예시값",
    "s3_secret_key": "예시값",
    "s3_url": "https://example.com",
    "redis_host": "예시값",
    "redis_port": 1,
    "redis_password": "Password123!",
    "redis_database": 1,
    "memcached_host": "예시값",
    "memcached_port": 1,
    "websocket_app_key": "예시값",
    "websocket_host": "예시값",
    "websocket_port": 1,
    "websocket_scheme": "예시값"
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
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

폼에 입력한 드라이버 접속 정보(S3·Redis·Memcached·Websocket 등)로 실제 연결을 시도해 결과를 반환합니다. 설정을 저장하기 전에 접속 정보가 유효한지 확인하는 용도입니다. 모든 테스트 통과 시 성공 메시지, 일부 실패 시에도 HTTP 성공 응답으로 항목별 결과(`all_passed=false` 포함)를 함께 반환합니다.


### POST /api/admin/settings/test-mail
<!-- @generated:start:api.admin.settings.test-mail -->
- **라우트명**: `api.admin.settings.test-mail`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@testMail`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| to_email | body | email | 예 | max 255 | 테스트 수신 주소 |
| mailer | body | string | 아니오 | `smtp`, `mailgun`, `ses` | 메일 발송 드라이버 (smtp/mailgun/ses) |
| from_address | body | email | 예 | max 255 | 발신자 주소 |
| from_name | body | string | 예 | max 255 | 발신자 이름 |
| host | body | string | 예 | max 255 | 호스트 주소 |
| port | body | integer | 예 | min 1, max 65535 | 포트 번호 |
| username | body | string | 아니오 | max 255 | 사용자명 (로그인/인증 아이디) |
| password | body | string | 아니오 | max 255 | 비밀번호 |
| encryption | body | string | 아니오 | `tls`, `ssl`, `null` | 전송 암호화 방식 (tls/ssl) |
| mailgun_domain | body | string | 아니오 | max 255 | Mailgun 도메인 |
| mailgun_secret | body | string | 아니오 | max 255 | Mailgun 시크릿 키 |
| mailgun_endpoint | body | string | 아니오 | max 255 | Mailgun 엔드포인트 |
| ses_key | body | string | 아니오 | max 255 | SES 액세스 키 |
| ses_secret | body | string | 아니오 | max 255 | SES 시크릿 키 |
| ses_region | body | string | 아니오 | max 255 | SES 리전 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.test_mail_validation_rules`).

**요청 예시**

```http
POST /api/admin/settings/test-mail HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "to_email": "user@example.com",
    "mailer": "smtp",
    "from_address": "user@example.com",
    "from_name": "예시 이름",
    "host": "예시값",
    "port": 1,
    "username": "예시 이름",
    "password": "Password123!",
    "encryption": "tls",
    "mailgun_domain": "예시값",
    "mailgun_secret": "예시값",
    "mailgun_endpoint": "예시값",
    "ses_key": "예시값",
    "ses_secret": "예시값",
    "ses_region": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

폼에 입력한 메일 설정으로 지정한 주소에 테스트 메일을 발송합니다. 요청에서 전달한 값(호스트·포트·인증 정보 등)을 저장된 메일 설정 위에 임시로 덮어써 그 값으로만 발송을 시도하므로, 설정을 저장하기 전에 실제 발송 가능 여부를 검증할 수 있습니다. 성공 시 발송한 제목/본문을 응답에 포함하고, 실패 시 오류 사유와 함께 500 을 반환합니다.


### GET /api/admin/settings/{key}
<!-- @generated:start:api.admin.settings.show -->
- **라우트명**: `api.admin.settings.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |

**요청 예시**

```http
GET /api/admin/settings/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

단일 설정 키의 값을 조회합니다. 응답의 `data.key` 는 요청한 키, `data.value` 는 해당 설정 값입니다. 통합 조회(`GET /api/admin/settings`)와 달리 특정 키 하나만 필요할 때 사용합니다.


### PUT /api/admin/settings/{key}
<!-- @generated:start:api.admin.settings.update -->
- **라우트명**: `api.admin.settings.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SettingsController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| key | path | string | 예 | — | 대상 설정/항목의 키 |
| value | body | string | 예 | max 1000 | 값 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.settings.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/settings/{key} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "value": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

단일 설정 키의 값을 업데이트합니다. 경로의 `key` 로 대상 설정을, 본문의 `value` 로 새 값을 지정합니다. 탭 단위 일괄 저장(`POST /api/admin/settings`)과 달리 개별 키 하나만 변경할 때 사용합니다. 검증 실패 시 422, 그 외 오류 시 500 을 반환합니다.


