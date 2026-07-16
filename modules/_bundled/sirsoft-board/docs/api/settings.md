# Settings API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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


### GET /api/modules/sirsoft-board/admin/settings
<!-- @generated:start:api.modules.sirsoft-board.admin.settings.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.settings.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| basic_defaults | object | `{"type":"basic","per_page":20,"per_page_mobile":15,"order…` | 게시판 생성 시 적용되는 기본 설정 카테고리. 게시판 타입, 페이지당 글 수(PC/모바일), 정렬 기준/방향, 댓글·답글 사용 여부와 깊이, 제목·내용·댓글 길이 제한, 파일 업로드 허용/용량/개수/확장자, 새 글 표시 시간, 게시판 기본 권한(default_board_permissions) 등을 포함합니다. |
| report_policy | object | `{"auto_hide_threshold":5,"auto_hide_target":"both","daily…` | 신고 정책 카테고리. 자동 숨김 임계치(auto_hide_threshold)와 대상(auto_hide_target: post/comment/both), 사용자별 일일 신고 한도, 신고 거부 누적 제한(횟수/기간), 관리자·작성자 신고 알림 발송 여부와 채널을 포함합니다. |
| spam_security | object | `{"post_cooldown_seconds":0,"comment_cooldown_seconds":0,"…` | 스팸·보안 카테고리. 글·댓글·신고 작성 사이의 도배 방지 쿨다운 시간(초)과 조회수 캐시 TTL을 포함합니다. |
| display | object | `{"date_display_format":"standard"}` | 표시 설정 카테고리. 날짜 표시 형식(date_display_format: standard 절대 표기 / relative 상대 표기)을 포함합니다. |
| seo | object | `{"meta_boards_title":"{site_name}","meta_boards_descripti…` | SEO 메타 태그 설정 카테고리. 게시판 목록/개별 게시판/글 상세 페이지의 메타 제목·설명 템플릿과 각 페이지의 SEO 생성 활성화 여부(seo_boards, seo_board, seo_post_detail)를 포함합니다. |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":0…` | 알림 채널 설정 카테고리. 각 채널(mail, database 등)의 식별자, 활성화 여부(is_active), 정렬 순서(sort_order)를 담은 channels 배열을 포함합니다. |
| report_permissions | object | `{"view_roles":["admin","manager"],"manage_roles":["admin"]}` | 신고 관리 권한 역할. 신고 내역을 조회할 수 있는 역할(view_roles)과 신고를 처리·관리할 수 있는 역할(manage_roles)의 식별자 배열이며, 설정값이 아닌 DB 권한 데이터로 관리됩니다. |
| abilities | object | `{"can_update":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| _meta | object | `{"limits":{"per_page_min":5,"per_page_max":100,"min_title…` | 편집 UI 보조 메타데이터. `limits`에 `config('sirsoft-board.limits')` 기반 입력 제한값(페이지당 글 수·답글/댓글 깊이의 최소·최대 등)이 담겨 프론트 입력 검증 범위로 사용됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.settings.fetch_success",
    "data": {
        "basic_defaults": {
            "type": "basic",
            "per_page": 20,
            "per_page_mobile": 15,
            "order_by": "created_at",
            "order_direction": "DESC",
            "secret_mode": "{MASKED}",
            "use_comment": true,
            "use_reply": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "comment_order": "ASC",
            "show_view_count": true,
            "use_report": false,
            "min_title_length": 2,
            "max_title_length": 200,
            "min_content_length": 2,
            "max_content_length": 10000,
            "min_comment_length": 2,
            "max_comment_length": 1000,
            "use_file_upload": false,
            "max_file_size": 10,
            "max_file_count": 5,
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
                "ppt",
                "pptx",
                "hwp",
                "txt",
                "zip"
            ],
            "notify_admin_on_post": true,
            "notify_author": true,
            "new_display_hours": 24,
            "blocked_keywords": [
                "시발",
                "씨발",
                "ㅅㅂ",
                "ㅆㅂ",
                "좆",
                "ㅈㄴ",
                "존나",
                "씹",
                "지랄",
                "ㅈㄹ",
                "개새끼",
                "새끼",
                "ㅅㄲ",
                "개자식",
                "병신",
                "ㅂㅅ",
                "미친놈",
                "미친년",
                "느금마",
                "니애미",
                "느금",
                "엠창",
                "엠뒤",
                "빡대가리",
                "개같은",
                "개년",
                "개놈",
                "개돼지",
                "개씹",
                "개쓰레기",
                "닥쳐",
                "꺼져",
                "죽어",
                "자살해",
                "뒤져",
                "저능아",
                "정신병자",
                "썅",
                "쌍놈",
                "쌍년",
                "쉬발",
                "쒸발",
                "시팔",
                "씨팔",
                "한남",
                "한녀",
                "김치녀",
                "된장녀",
                "맘충",
                "틀딱",
                "느개비",
                "장애인놈",
                "병신새끼",
                "흑형",
                "짱깨",
                "쪽바리",
                "왜놈",
                "튀기",
                "똥남아",
                "야동",
                "포르노",
                "섹스",
                "야사",
                "음란",
                "성인사이트",
                "조건만남",
                "원나잇",
                "섹파",
                "오피",
                "키스방",
                "매춘",
                "성매매",
                "몸파는"
            ],
            "default_board_permissions": {
                "admin.posts.read": [
                    "admin"
                ],
                "admin.posts.write": [
                    "admin"
                ],
                "admin.posts.read-secret": [
                    "admin"
                ],
                "admin.comments.read": [
                    "admin"
                ],
                "admin.comments.write": [
                    "admin"
                ],
                "admin.attachments.upload": [
                    "admin"
                ],
                "admin.attachments.download": [
                    "admin"
                ],
                "admin.manage": [
                    "admin"
                ],
                "posts.read": [
                    "admin",
                    "user",
                    "guest"
                ],
                "posts.write": [
                    "admin",
                    "user"
                ],
                "posts.read-secret": [
                    "admin"
                ],
                "comments.read": [
                    "admin",
                    "user",
                    "guest"
                ],
                "comments.write": [
                    "admin",
                    "user"
                ],
                "attachments.upload": [
                    "admin",
                    "user"
                ],
                "attachments.download": [
                    "admin",
                    "user",
                    "guest"
                ],
                "manager": [
                    "admin"
                ]
            }
        },
        "report_policy": {
            "auto_hide_threshold": 5,
            "auto_hide_target": "both",
            "daily_report_limit": 10,
            "rejection_limit_count": 5,
            "rejection_limit_days": 30,
            "notify_admin_on_report": true,
            "notify_admin_on_report_scope": "per_case",
            "notify_admin_on_report_channels": [
                "mail",
                "database"
            ],
            "notify_author_on_report_action": true,
            "notify_author_on_report_action_channels": [
                "mail",
                "database"
            ]
        },
        "spam_security": {
            "post_cooldown_seconds": 0,
            "comment_cooldown_seconds": 0,
            "report_cooldown_seconds": 60,
            "view_count_cache_ttl": 86400
        },
        "display": {
            "date_display_format": "standard"
        },
        "seo": {
            "meta_boards_title": "{site_name}",
            "meta_boards_description": "",
            "meta_board_title": "{board_name}",
            "meta_board_description": "{board_description}",
            "meta_post_title": "{board_name} - {post_title}",
            "meta_post_description": "",
            "seo_boards": true,
            "seo_board": true,
            "seo_post_detail": true
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 1
                }
            ]
        },
        "report_permissions": {
            "view_roles": [
                "admin",
                "apidoc-sample-role"
            ],
            "manage_roles": [
                "admin",
                "apidoc-sample-role"
            ]
        },
        "abilities": {
            "can_update": true
        },
        "_meta": {
            "limits": {
                "per_page_min": 5,
                "per_page_max": 100,
                "min_title_length_min": 0,
                "min_title_length_max": 200,
                "max_title_length_min": 1,
                "max_title_length_max": 1000,
                "min_content_length_min": 0,
                "min_content_length_max": 10000,
                "max_content_length_min": 1,
                "max_content_length_max": 100000,
                "min_comment_length_min": 0,
                "min_comment_length_max": 1000,
                "max_comment_length_min": 1,
                "max_comment_length_max": 10000,
                "max_file_size_min": 1,
                "max_file_size_max": 200,
                "max_file_count_min": 1,
                "max_file_count_max": 20,
                "category_max": 50,
                "max_reply_depth_min": 1,
                "max_reply_depth_max": 10,
                "max_comment_depth_min": 0,
                "max_comment_depth_max": 10
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 모듈의 전체 환경설정을 조회합니다. `auth:sanctum` 인증과 `sirsoft-board.settings.read` 권한이 필요합니다. 기본값(basic_defaults)/신고정책(report_policy)/스팸·보안(spam_security)/표시(display)/SEO/알림(notifications) 등 모든 카테고리 설정과 신고 권한 역할(report_permissions)을 함께 반환하며, 현재 사용자의 수정 가능 여부(abilities)와 입력 제한값(`_meta.limits`, `config('sirsoft-board.limits')`)을 포함합니다.


### PUT /api/modules/sirsoft-board/admin/settings
<!-- @generated:start:api.modules.sirsoft-board.admin.settings.store -->
- **라우트명**: `api.modules.sirsoft-board.admin.settings.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| _tab | body | string | 아니오 | `basic_defaults`, `report_policy`, `spam_security`, `general`, `seo`, `notifications`, `notification_definitions` | 현재 편집 중인 탭을 나타내는 메타 값. 탭 단위 부분 저장의 컨텍스트를 식별하는 용도이며 설정값으로는 저장되지 않습니다. |
| notifications | body | array | 아니오 | — | 알림 채널 설정. `channels` 배열의 각 항목에 채널 식별자(id), 활성화 여부(is_active), 정렬 순서(sort_order)를 담아 저장합니다. |
| basic_defaults | body | array | 아니오 | — | 기본 설정 카테고리 값. 게시판 타입·페이지당 글 수·정렬·댓글/답글·길이 제한·파일 업로드·기본 권한 등 basic_defaults 하위 키를 저장합니다. |
| report_policy | body | array | 아니오 | — | 신고 정책 카테고리 값. 자동 숨김 임계치/대상, 일일 신고 한도, 거부 누적 제한, 관리자·작성자 신고 알림 설정을 저장합니다. |
| report_permissions | body | array | 아니오 | — | 신고 관리 권한 역할. `view_roles`(조회 역할)와 `manage_roles`(관리 역할)의 역할 식별자 배열이며, 포함 시 설정 저장과 별개로 DB 권한 역할이 동기화됩니다. |
| display | body | array | 아니오 | — | 표시 설정 카테고리 값. 날짜 표시 형식(date_display_format: standard/relative) 등을 저장합니다. |
| spam_security | body | array | 아니오 | — | 스팸·보안 카테고리 값. 글·댓글·신고 작성 쿨다운 시간(초)과 조회수 캐시 TTL을 저장합니다. |
| seo | body | array | 아니오 | — | SEO 설정 카테고리 값. 게시판 목록/개별/글 상세 페이지의 메타 제목·설명 템플릿과 각 페이지 SEO 활성화 여부를 저장합니다. |

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "_tab": "basic_defaults",
    "notifications": [
        "예시값"
    ],
    "basic_defaults": [
        "예시값"
    ],
    "report_policy": [
        "예시값"
    ],
    "report_permissions": [
        "예시값"
    ],
    "display": [
        "예시값"
    ],
    "spam_security": [
        "예시값"
    ],
    "seo": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| basic_defaults | object | `{"type":"basic","per_page":20,"per_page_mobile":15,"order…` | 저장 후 갱신된 기본 설정 카테고리. 게시판 타입, 페이지당 글 수(PC/모바일), 정렬 기준/방향, 댓글·답글 사용 여부와 깊이, 제목·내용·댓글 길이 제한, 파일 업로드 허용/용량/개수/확장자, 새 글 표시 시간, 게시판 기본 권한(default_board_permissions) 등을 포함합니다. |
| report_policy | object | `{"auto_hide_threshold":5,"auto_hide_target":"both","daily…` | 저장 후 갱신된 신고 정책 카테고리. 자동 숨김 임계치(auto_hide_threshold)와 대상(auto_hide_target: post/comment/both), 사용자별 일일 신고 한도, 신고 거부 누적 제한(횟수/기간), 관리자·작성자 신고 알림 발송 여부와 채널을 포함합니다. |
| spam_security | object | `{"post_cooldown_seconds":0,"comment_cooldown_seconds":0,"…` | 저장 후 갱신된 스팸·보안 카테고리. 글·댓글·신고 작성 사이의 도배 방지 쿨다운 시간(초)과 조회수 캐시 TTL을 포함합니다. |
| display | object | `{"date_display_format":"standard"}` | 저장 후 갱신된 표시 설정 카테고리. 날짜 표시 형식(date_display_format: standard 절대 표기 / relative 상대 표기)을 포함합니다. |
| seo | object | `{"meta_boards_title":"{site_name}","meta_boards_descripti…` | 저장 후 갱신된 SEO 메타 태그 설정 카테고리. 게시판 목록/개별 게시판/글 상세 페이지의 메타 제목·설명 템플릿과 각 페이지의 SEO 생성 활성화 여부(seo_boards, seo_board, seo_post_detail)를 포함합니다. |
| notifications | object | `{"channels":[{"id":"mail","is_active":true,"sort_order":0…` | 저장 후 갱신된 알림 채널 설정 카테고리. 각 채널(mail, database 등)의 식별자, 활성화 여부(is_active), 정렬 순서(sort_order)를 담은 channels 배열을 포함합니다. |
| report_permissions | object | `{"view_roles":[],"manage_roles":[]}` | 저장 후 동기화된 신고 관리 권한 역할. 신고 내역을 조회할 수 있는 역할(view_roles)과 신고를 처리·관리할 수 있는 역할(manage_roles)의 식별자 배열이며, 요청에 포함된 경우 DB 권한 역할로 동기화되어 반환됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.settings.save_success",
    "data": {
        "basic_defaults": {
            "type": "basic",
            "per_page": 20,
            "per_page_mobile": 15,
            "order_by": "created_at",
            "order_direction": "DESC",
            "secret_mode": "{MASKED}",
            "use_comment": true,
            "use_reply": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "comment_order": "ASC",
            "show_view_count": true,
            "use_report": false,
            "min_title_length": 2,
            "max_title_length": 200,
            "min_content_length": 2,
            "max_content_length": 10000,
            "min_comment_length": 2,
            "max_comment_length": 1000,
            "use_file_upload": false,
            "max_file_size": 10,
            "max_file_count": 5,
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
                "ppt",
                "pptx",
                "hwp",
                "txt",
                "zip"
            ],
            "notify_admin_on_post": true,
            "notify_author": true,
            "new_display_hours": 24,
            "blocked_keywords": [
                "시발",
                "씨발",
                "ㅅㅂ",
                "ㅆㅂ",
                "좆",
                "ㅈㄴ",
                "존나",
                "씹",
                "지랄",
                "ㅈㄹ",
                "개새끼",
                "새끼",
                "ㅅㄲ",
                "개자식",
                "병신",
                "ㅂㅅ",
                "미친놈",
                "미친년",
                "느금마",
                "니애미",
                "느금",
                "엠창",
                "엠뒤",
                "빡대가리",
                "개같은",
                "개년",
                "개놈",
                "개돼지",
                "개씹",
                "개쓰레기",
                "닥쳐",
                "꺼져",
                "죽어",
                "자살해",
                "뒤져",
                "저능아",
                "정신병자",
                "썅",
                "쌍놈",
                "쌍년",
                "쉬발",
                "쒸발",
                "시팔",
                "씨팔",
                "한남",
                "한녀",
                "김치녀",
                "된장녀",
                "맘충",
                "틀딱",
                "느개비",
                "장애인놈",
                "병신새끼",
                "흑형",
                "짱깨",
                "쪽바리",
                "왜놈",
                "튀기",
                "똥남아",
                "야동",
                "포르노",
                "섹스",
                "야사",
                "음란",
                "성인사이트",
                "조건만남",
                "원나잇",
                "섹파",
                "오피",
                "키스방",
                "매춘",
                "성매매",
                "몸파는"
            ],
            "default_board_permissions": {
                "admin.posts.read": [
                    "admin"
                ],
                "admin.posts.write": [
                    "admin"
                ],
                "admin.posts.read-secret": [
                    "admin"
                ],
                "admin.comments.read": [
                    "admin"
                ],
                "admin.comments.write": [
                    "admin"
                ],
                "admin.attachments.upload": [
                    "admin"
                ],
                "admin.attachments.download": [
                    "admin"
                ],
                "admin.manage": [
                    "admin"
                ],
                "posts.read": [
                    "admin",
                    "user",
                    "guest"
                ],
                "posts.write": [
                    "admin",
                    "user"
                ],
                "posts.read-secret": [
                    "admin"
                ],
                "comments.read": [
                    "admin",
                    "user",
                    "guest"
                ],
                "comments.write": [
                    "admin",
                    "user"
                ],
                "attachments.upload": [
                    "admin",
                    "user"
                ],
                "attachments.download": [
                    "admin",
                    "user",
                    "guest"
                ],
                "manager": [
                    "admin"
                ]
            }
        },
        "report_policy": {
            "auto_hide_threshold": 5,
            "auto_hide_target": "both",
            "daily_report_limit": 10,
            "rejection_limit_count": 5,
            "rejection_limit_days": 30,
            "notify_admin_on_report": true,
            "notify_admin_on_report_scope": "per_case",
            "notify_admin_on_report_channels": [
                "mail",
                "database"
            ],
            "notify_author_on_report_action": true,
            "notify_author_on_report_action_channels": [
                "mail",
                "database"
            ]
        },
        "spam_security": {
            "post_cooldown_seconds": 0,
            "comment_cooldown_seconds": 0,
            "report_cooldown_seconds": 60,
            "view_count_cache_ttl": 86400
        },
        "display": {
            "date_display_format": "standard"
        },
        "seo": {
            "meta_boards_title": "{site_name}",
            "meta_boards_description": "",
            "meta_board_title": "{board_name}",
            "meta_board_description": "{board_description}",
            "meta_post_title": "{board_name} - {post_title}",
            "meta_post_description": "",
            "seo_boards": true,
            "seo_board": true,
            "seo_post_detail": true
        },
        "notifications": {
            "channels": [
                {
                    "id": "mail",
                    "is_active": true,
                    "sort_order": 0
                },
                {
                    "id": "database",
                    "is_active": true,
                    "sort_order": 1
                }
            ]
        },
        "report_permissions": {
            "view_roles": [],
            "manage_roles": []
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시판 모듈의 환경설정을 저장합니다. `auth:sanctum` 인증과 `sirsoft-board.settings.update` 권한이 필요합니다. `_tab`으로 지정한 탭 단위로 검증된 설정을 저장하며, `report_permissions`가 포함된 경우 신고 권한 역할도 함께 동기화합니다. 저장 성공 시 갱신된 전체 설정과 신고 권한 역할을 반환합니다.


### POST /api/modules/sirsoft-board/admin/settings/bulk-apply
<!-- @generated:start:api.modules.sirsoft-board.admin.settings.bulk-apply -->
- **라우트명**: `api.modules.sirsoft-board.admin.settings.bulk-apply`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController@bulkApply`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| fields | body | array | 예 | min 1 | 대상 게시판에 일괄 적용할 필드 목록(최소 1개). boards 테이블 컬럼(type, per_page, use_comment, allowed_extensions 등)이나 권한 필드(default_board_permissions, manager), 점(.)을 포함한 개별 권한 키(예: `posts.read`)를 허용합니다. |
| apply_all | body | boolean | 예 | — | 전체 게시판 적용 여부. true면 모든 게시판에 적용하고, false면 `board_ids`로 지정한 게시판에만 적용합니다(false 시 board_ids 필수). |
| board_ids | body | array | 아니오 | — | board 식별자 배열 |
| override_values | body | array | 아니오 | — | 환경설정 기본값 대신 사용할 재정의 값 맵. 지정한 필드에 대해 기본값이 아닌 임의의 값으로 일괄 적용할 때 사용합니다. |

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/settings/bulk-apply HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "fields": [
        "예시값"
    ],
    "apply_all": true,
    "board_ids": [
        "예시값"
    ],
    "override_values": [
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 환경설정의 기본값을 기존 게시판들에 일괄 적용합니다. `auth:sanctum` 인증과 `sirsoft-board.settings.update` 권한이 필요합니다. `fields`(적용할 필드, 최소 1개)와 `apply_all`(전체 적용 여부)을 받으며, `apply_all`이 false이면 `board_ids`로 대상을 지정하고 `override_values`로 값을 재정의할 수 있습니다. 적용 도중 실패하면 전체가 롤백되며, 이 경우에도 HTTP 200으로 `rolled_back: true`와 실패 지점 정보를 반환하여 프론트에서 안내 처리합니다.


### POST /api/modules/sirsoft-board/admin/settings/clear-cache
<!-- @generated:start:api.modules.sirsoft-board.admin.settings.clear-cache -->
- **라우트명**: `api.modules.sirsoft-board.admin.settings.clear-cache`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/settings/clear-cache HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 모듈 설정 캐시를 초기화합니다. `auth:sanctum` 인증과 `sirsoft-board.settings.update` 권한이 필요합니다. ModuleSettings 캐시와 게시판 캐시를 모두 초기화하며, 응답으로 `cleared: true`를 반환합니다.


### GET /api/modules/sirsoft-board/admin/settings/{category}
<!-- @generated:start:api.modules.sirsoft-board.admin.settings.show -->
- **라우트명**: `api.modules.sirsoft-board.admin.settings.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardSettingsController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| category | path | string | 예 | — | 분류 필터 (해당 분류의 항목만 조회) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/settings/{category} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.settings.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 카테고리의 설정만 조회합니다. `auth:sanctum` 인증과 `sirsoft-board.settings.read` 권한이 필요합니다. 경로의 `category`에 해당하는 설정만 반환하며, 응답에는 카테고리명(category), 설정값(settings), 현재 사용자의 수정 가능 여부(abilities)가 포함됩니다.


