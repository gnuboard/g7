# Thumbnails API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 이미지 썸네일 캐시 관리 엔드포인트 레퍼런스입니다 (환경설정 > 업로드 탭 「저장 위치 · 캐시」)
2. 저장 위치는 원본 분류별 두 줄입니다 — public(완전 공개 이미지) · gated(권한이 걸린 이미지)
3. 두 위치가 같은 디스크를 써도 원본 제공자 판정으로 따로 세고 따로 지웁니다
4. 비우기는 같은 디스크를 나눠 쓰는 위치나 규격 하나를 지울 때, 그리고 원격 디스크를 통째로 비울 때 시간 예산(업로드 탭 「고급」 `upload.thumbnail_purge_time_budget`, 기본 10초, 서버 실행 시간 한도가 더 짧으면 그 절반)만큼만 걷습니다 — next_cursor 가 null 이 될 때까지 다시 부릅니다
5. 기존 이미지 썸네일 만들기는 한 번에 core.thumbnail.pregenerate_batch(기본 20, 업로드 탭 「고급」 칸이나 `.env` G7_THUMBNAIL_PREGENERATE_BATCH 로 바꾼다)건 — next_cursor 가 null 이 될 때까지 다시 부릅니다
6. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---


### POST /api/admin/thumbnails/pregenerate
<!-- @generated:start:api.admin.thumbnails.pregenerate -->
- **라우트명**: `api.admin.thumbnails.pregenerate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ThumbnailController@pregenerate`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| cursor | body | string | 아니오 | `{제공자 순번}:{제공자 커서}` 형식, 최대 200자 | 직전 응답의 `next_cursor` 를 그대로 넘긴다. 처음 호출이면 생략하거나 null. |
| sources | body | array | 아니오 | — | 원본 종류 키 목록(stats 응답의 `sources[].key`). 비우면 등록된 원본 종류 전부. |
| sources.* | body | string | 아니오 | 등록된 원본 제공자 키 (`core.attachment`, `core.layout_attachment`, 확장 등록 키) | 원본 종류 키. 목록 밖 값은 422 — 「전부」로 해석하지 않는다. |
| presets | body | array | 아니오 | — | 만들 규격 이름 목록. 비우면 사용 중인 규격 전부(og 제외). |
| presets.* | body | string | 아니오 | 정의된 규격 이름 (stats 응답의 `presets`) | 규격 이름. 꺼 둔 규격은 이름이 맞아도 만들지 않는다. |

**요청 예시**

```http
POST /api/admin/thumbnails/pregenerate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "cursor": null,
    "sources": ["core.attachment"],
    "presets": ["sm", "md"]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| processed | integer | `20` | 이번 호출에서 확인한 원본 수 (배치 크기 `core.thumbnail.pregenerate_batch` 이하). |
| generated | integer | `38` | 이번 호출에서 새로 만든 파생본 수. 이미 있는 파생본·원본으로 돌려보낸 경우(작은 원본·움직이는 이미지 등)는 세지 않는다. |
| next_cursor | string \| null | `"0:20"` | 다음 호출에 넘길 커서. null 이면 끝. |
| total | integer | `135` | 고른 원본 종류의 래스터 이미지 원본 전체 수 (진행률 분모). |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "기존 이미지 썸네일을 만들었습니다.",
    "data": {
        "processed": 20,
        "generated": 38,
        "next_cursor": "0:20",
        "total": 135
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 모르는 원본 종류·규격 이름, 형식이 틀린 커서 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 원본 목록 조회 중 예외가 발생한 경우 (`messages.error_occurred`) |

<!-- @generated:end -->



**설명**

「기존 이미지 썸네일 만들기」 한 번분을 처리합니다. 화면은 `next_cursor` 가 null 이 될 때까지 같은 요청을 반복하며 `processed` 누계와 `total` 로 진행률을 그립니다. 창을 닫으면 멈추고, 다시 누르면 처음부터 돌지만 이미 있는 파생본은 건너뛰므로 이어서 하는 것과 같습니다. 같은 일을 하는 명령은 `php artisan thumbnails:pregenerate` 입니다.


### POST /api/admin/thumbnails/purge
<!-- @generated:start:api.admin.thumbnails.purge -->
- **라우트명**: `api.admin.thumbnails.purge`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ThumbnailController@purge`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| location | body | string | 예 | `public`, `gated`, `all` | 비울 저장 위치. `all` 은 두 위치를 차례로 비운다. |
| preset | body | string | 아니오 | 정의된 규격 이름 (stats 응답의 `presets`, 꺼 둔 규격 포함) | 주면 그 규격 파일만 지운다. 생략하면 전 규격. |
| cursor | body | string | 아니오 | `{위치 순번 0·1}:{base64url}` 형식, 최대 2048자 | 직전 응답의 `next_cursor` 를 그대로 넘긴다. 처음 호출이면 생략하거나 null. 위치 순번은 `all` 일 때 public 0·gated 1, 한 위치면 0. |

**요청 예시**

```http
POST /api/admin/thumbnails/purge HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "location": "public",
    "preset": "sm"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | object | `{"public": 42}` | 위치별 지운 파일 수. 요청한 위치의 키만 있다(`all` 이면 `public`·`gated` 둘 다). |
| deleted.public | integer | `42` | 공개 위치에서 지운 파일 수. 로컬 디스크를 통째로 비운 경우는 개수를 세지 않아 `-1`(원격 디스크는 센다). |
| deleted.gated | integer | `7` | 권한 위치에서 지운 파일 수. 로컬 디스크를 통째로 비운 경우 `-1`. |
| next_cursor | string \| null | `"0:dGh1bWJuYWlscy9hdHRhY2htZW50cy9h"` | 다음 호출에 넘길 커서. null 이면 끝. `deleted` 는 이번 호출에서 지운 수라 화면이 호출마다 더한다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "썸네일을 비웠습니다.",
    "data": {
        "deleted": {
            "public": 42
        },
        "next_cursor": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | `location` 이 허용값이 아니거나 없는 규격 이름, 형식이 틀린 커서 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 디스크 삭제 중 예외가 발생한 경우 (`messages.error_occurred`) |

<!-- @generated:end -->



**설명**

고른 저장 위치(·규격)의 썸네일 파생본만 지웁니다. 두 위치가 같은 디스크를 쓰거나 규격 하나만 지우면 파일마다 판정해야 해서, 요청 하나가 디스크 전체 목록을 만들지 않고 `thumbnails/` 트리를 디렉터리 단위로 이름 순서대로 걷습니다. 시간 예산을 넘기면 마지막으로 본 파일 자리를 `next_cursor` 로 돌려주고, 화면은 null 이 될 때까지 같은 요청을 이어 부르며 위치별 수를 더합니다. 다른 위치와 디스크를 나눠 쓰고 전 규격을 지우면 판정 없이 지웁니다. 로컬 디스크는 `thumbnails/` 를 한 번에 지우고(`-1`), 원격 디스크(S3 등)는 같은 방식으로 걸어 지우며 파일 수를 셉니다(한 번에 지우면 파일 1000개마다 목록·일괄 삭제 요청이 나가 요청 하나가 길어집니다). 원격 통째 비우기의 CDN 무효화는 끝나는 호출에 루트 와일드카드 하나로 실립니다. 시작 훅 `core.thumbnail.before_purge` 는 첫 호출(커서 없음)에만 발화합니다.

지우는 범위는 썸네일 디스크의 고정 루트 `thumbnails/` 아래뿐이라, 공용 디스크(public·s3)를 썸네일 디스크로 골라도 원본은 건드리지 않습니다. 두 위치가 같은 디스크를 쓰면 파일마다 원본 제공자로 분류해 그 위치 것만 지웁니다 — 공개만 비워도 권한 이미지 썸네일은 남습니다. 원본 제공자가 모르는 원본의 파생본은 권한 쪽으로 분류합니다.

지운 접두는 요청 끝에 액션 훅 `core.thumbnail.after_purge` 로 한 번에 발화합니다(CDN 퍼지 플러그인이 받는다). 페이로드의 `public_url_prefix` 는 그 위치의 디스크가 직접 URL 을 내는 공개 자산 디스크일 때만 값이 있고, 비공개 디스크(`thumbnails`)는 null 입니다. 비운 썸네일은 다음 요청 때 다시 만들어집니다.


### GET /api/admin/thumbnails/stats
<!-- @generated:start:api.admin.thumbnails.stats -->
- **라우트명**: `api.admin.thumbnails.stats`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ThumbnailController@stats`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/thumbnails/stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| locations | array | `[{…}, {…}]` | 저장 위치 두 항목 — 언제나 `public`, `gated` 순. 같은 디스크를 가리켜도 합치지 않는다. |
| locations[].kind | string | `"public"` | 저장 위치 종류 (`public` 완전 공개 이미지 · `gated` 권한이 걸린 이미지). |
| locations[].disk | string | `"thumbnails"` | 그 위치가 쓰는 썸네일 디스크 이름 (업로드 설정 `thumbnail_disk_public` / `thumbnail_disk_gated`). |
| locations[].disk_label | string | `"서버 비공개 저장소"` | 디스크 표시 이름. 썸네일 전용 디스크는 다국어 라벨, 그 밖은 공개 자산 디스크 카탈로그 라벨, 둘 다 없으면 디스크 이름. |
| locations[].remote | boolean | `false` | 원격 디스크(S3·CDN 등 local 이 아닌 드라이버)면 true — 목록 조회 비용 때문에 세지 않는다. |
| locations[].count | integer \| null | `128` | 그 위치의 파생본 파일 수. 원격이면 null. |
| locations[].bytes | integer \| null | `5242880` | 파생본 용량(바이트). 원격이면 null. |
| locations[].by_preset | object \| null | `{"sm": 64, "md": 64}` | 규격 이름별 파일 수. 원격이면 null. |
| presets | array | `["xs", "sm", "md", "lg", "xl", "og"]` | 정의된 규격 이름 전부(꺼 둔 규격 포함) — 비우기 규격 선택지. |
| sources | array | `[{…}]` | 원본 종류 — 미리 만들기 선택지. |
| sources[].key | string | `"core.attachment"` | 원본 제공자 키 (pregenerate 의 `sources[]` 값). |
| sources[].label | string | `"첨부파일 이미지"` | 현재 로케일로 번역된 표시 이름. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "썸네일 사용량을 불러왔습니다.",
    "data": {
        "locations": [
            {
                "kind": "public",
                "disk": "thumbnails",
                "remote": false,
                "count": 1,
                "bytes": 10,
                "by_preset": {
                    "sm": 1
                },
                "disk_label": "서버 비공개 저장소"
            },
            {
                "kind": "gated",
                "disk": "thumbnails",
                "remote": false,
                "count": 2,
                "bytes": 20,
                "by_preset": {
                    "sm": 1,
                    "md": 1
                },
                "disk_label": "서버 비공개 저장소"
            }
        ],
        "presets": ["xs", "sm", "md", "lg", "xl", "og"],
        "sources": [
            {
                "key": "core.attachment",
                "label": "첨부파일 이미지"
            },
            {
                "key": "core.layout_attachment",
                "label": "레이아웃 편집기 이미지"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 500 | Internal Server Error | 디스크 목록 조회 중 예외가 발생한 경우 (`messages.error_occurred`) |

<!-- @generated:end -->



**설명**

저장 위치(공개·권한)별 썸네일 사용량과 화면 선택지를 조회합니다. 로컬 디스크만 파일 수·용량을 세고, 원격 디스크는 `remote: true` 에 count·bytes·by_preset 이 null 입니다(화면은 「집계 안 함」). 두 위치가 같은 디스크를 써도 원본 제공자 판정으로 분류별로 따로 셉니다. 저장 위치 디스크를 바꾼 직후에는 설정을 저장한 뒤 다시 조회해야 새 디스크 기준으로 셉니다.
