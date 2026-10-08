# Member Notifications API 레퍼런스

> **소유**: 코어 · 이 문서는 **손으로 작성**했습니다. `api:docgen` 의 파괴적 재생성 가드가 걸려 있어
> (`docs/backend/api-documentation.md`) 스캐폴딩 덮어쓰기를 하지 않았습니다. 값은 개발 환경에서
> 실제 호출해 관측한 것입니다.

---

## TL;DR (5초 요약)

```text
1. 회원이 «받은» in-app 알림(수신함)을 전 회원 범위로 조회·삭제하는 관리자 API 입니다
2. 축이 셋이라 헷갈리기 쉽습니다 — 아래 「세 알림 축의 구분」을 먼저 보세요
3. 식별자는 uuid 문자열입니다 (integer 아님 — 일괄 삭제 규칙도 uuid)
4. 제목·본문은 표준 notifications 테이블의 data JSON 안에 있어 서버가 JSON 추출로 검색합니다
5. 권한: core.member-notifications.read / .delete
```

---

## 세 알림 축의 구분

| 엔드포인트 | 주어 | 삭제하면 |
| --- | --- | --- |
| `GET /api/admin/notifications` | **관리자 본인**의 수신함 | 관리자 자기 알림이 사라짐 |
| `GET /api/admin/notification-logs` | **무엇을 보냈나**(발송 이력) | 기록만 사라짐 — 회원 화면은 그대로 |
| `GET /api/admin/member-notifications` | **회원 화면에 지금 무엇이 남아 있나** | **그 회원의 알림함에서 사라짐** |

세 번째가 이 문서의 대상입니다. 삭제의 무게가 달라서 화면에도 채널 탭·재발송을 두지 않습니다.

---

### GET /api/admin/member-notifications

- **라우트명**: `api.admin.member-notifications.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\MemberNotificationController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.member-notifications.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| search_type | query | string | 아니오 | `all`, `recipient`, `subject`, `body` | 검색 대상. **넷 다 실제로 동작합니다** — `recipient` 는 회원 이름·이메일, `subject`/`body` 는 `data` JSON 안의 값 |
| search | query | string | 아니오 | max 255 | 검색어 (부분 일치). `%`·`_` 는 글자 그대로 찾습니다(회원 목록 검색과 같은 기준) |
| read | query | string | 아니오 | `all`, `read`, `unread` | 읽음 상태 필터 |
| user_id | query | integer | 아니오 | min 1 | 회원 ID 로 한정 |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 uuid 로 한정. 없는 uuid 면 **빈 결과**(전체로 되돌아가지 않음) |
| created_from | query | date | 아니오 | `Y-m-d` | 수신일 시작 (보는 사람의 시간대 하루로 해석) |
| created_to | query | date | 아니오 | `Y-m-d`, `created_from` 이상 | 수신일 끝 (그날 끝까지, 보는 사람의 시간대 하루로 해석) |
| sort_by | query | string | 아니오 | `created_at`, `read_at` | 정렬 기준 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 |

> 확장이 파라미터를 추가할 수 있습니다 — 검증 규칙 `core.member_notification.index_validation_rules`, 항목명 `core.member_notification.index_validation_attributes`. 항목명을 더하지 않으면 422 문구에 내부 키가 그대로 나온다.

**요청 예시**

```http
GET /api/admin/member-notifications?user_uuid=a2be5d84-7a25-40c1-b921-5afe26bc2fac&per_page=2 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.abilities` + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `3` | 목록에서의 순번 (페이지네이션 반영 행 번호) |
| id | string(uuid) | `fe113064-3aaa-41c7-bf31-2e8527b0c480` | 알림 식별자. **uuid 문자열** — 삭제·일괄 삭제가 이 값을 받습니다 |
| member | object\|null | `{...}` | 수신 회원. 회원이 이미 지워졌으면 `null` (다형 관계라 외래키가 없습니다) |
| member.uuid | string(uuid) | `a2be5d84-…` | 회원 uuid |
| member.name | string | `알림함회원E2E` | 회원 이름. 탈퇴 회원이면 익명 표기로 치환 |
| member.email | string\|null | `e2e-inbox-member@example.test` | 회원 이메일. 노출 게이트를 통과한 관리자 응답에만 실립니다 |
| member.is_withdrawn | boolean | `false` | 탈퇴 여부 |
| type | string | `new_order_admin` | 알림 종류 키 (`data.type`, 알림 정의의 type). 없으면 알림 클래스명 |
| type_label | string | `신규 주문 관리자 알림` | 표시 라벨. 알림 정의에 등록된 종류면 요청 로케일의 정의 이름, 없으면 종류 키 그대로 |
| subject | string\|null | `다른 제목 2` | 제목 (`data.subject`) |
| body_excerpt | string\|null | `엡실론 알림본문 이 든 본문입니다.` | 본문 발췌 (태그 제거 후 120자, 넘으면 `…`). 전문은 싣지 않습니다 |
| url | string\|null | `/mypage/notifications` | 연결 주소 (`data.click_url`) |
| read_at | string\|null | `2026-09-14 19:22:44` | 읽은 시각 (뷰어 시간대 적용). 안 읽었으면 `null` |
| is_read | boolean | `true` | 읽음 여부 (화면 배지가 이 값을 읽습니다) |
| created_at | string | `2026-09-14 20:22:44` | 수신 시각 (뷰어 시간대 적용) |
| abilities.can_delete (행) | boolean | `true` | 행마다 실리는 삭제 권한(컬렉션 값과 같음). 표의 행 메뉴 「삭제」가 이 값으로 켜집니다 |
| abilities.can_delete | boolean | `true` | 삭제 권한 보유 여부. 화면의 체크 칸·일괄 버튼이 이 값으로 열립니다 |

**응답 예시**

```json
{
  "success": true,
  "message": "회원 알림함 목록을 조회했습니다.",
  "data": {
    "data": [
      {
        "number": 3,
        "id": "fe113064-3aaa-41c7-bf31-2e8527b0c480",
        "member": {
          "uuid": "a2be5d84-7a25-40c1-b921-5afe26bc2fac",
          "name": "알림함회원E2E",
          "email": "e2e-inbox-member@example.test",
          "is_withdrawn": false
        },
        "type": "new_order_admin",
        "type_label": "신규 주문 관리자 알림",
        "subject": "다른 제목 2",
        "body_excerpt": "엡실론 알림본문 이 든 본문입니다.",
        "url": "/mypage/notifications",
        "read_at": null,
        "is_read": false,
        "created_at": "2026-09-14 20:22:44",
        "abilities": { "can_delete": true }
      }
    ],
    "abilities": { "can_delete": true },
    "pagination": {
      "current_page": 1,
      "per_page": 2,
      "from": 1,
      "to": 2,
      "has_more_pages": true,
      "last_page": 2,
      "total": 3,
      "total_relation": "exact",
      "total_is_exact": true,
      "result_cap": 10000
    }
  }
}
```

---

### DELETE /api/admin/member-notifications/{id}

- **라우트명**: `api.admin.member-notifications.destroy`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.member-notifications.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- | --- |
| id | path | string(uuid) | 예 | 알림 식별자 |

없는 id 면 `404` 와 `member_notification.not_found` 메시지를 돌려줍니다.

---

### POST /api/admin/member-notifications/bulk-delete

- **라우트명**: `api.admin.member-notifications.bulk-destroy`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.member-notifications.delete`

> 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk-delete` 가 uuid 로 잡힙니다.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 알림 식별자 목록 |
| ids.* | body | string(uuid) | 예 | uuid, 중복 불가, 존재하는 알림 | 알림 식별자. **integer 가 아닙니다** — 발송 이력의 규칙을 복사하면 모든 요청이 422 로 막힙니다 |

**요청 예시**

```http
POST /api/admin/member-notifications/bulk-delete HTTP/1.1
Content-Type: application/json

{ "ids": ["fe113064-3aaa-41c7-bf31-2e8527b0c480", "ad05aff1-52d3-4186-b4ef-628e64c90a57"] }
```

**응답 필드**

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| deleted_count | integer | 실제로 지워진 건수 |

**훅**

| 훅 키 | 시점 | 인자 |
| --- | --- | --- |
| `core.member_notification.before_bulk_delete` | 루프 **앞에서 한 번** | `$ids` |
| `core.member_notification.after_bulk_delete` | 루프 뒤에서 한 번 | `$ids`, `$count` |

건마다 발화하지 않습니다 — 항목마다 발화하면 활동 로그가 폭주합니다.

---

## 회원 상세 응답의 요약

`GET /api/admin/users/{uuid}` 의 관리자 응답에 `notifications_summary` 가 실립니다.

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| notifications_summary.total | integer | 총 알림 수 |
| notifications_summary.unread | integer | 안 읽은 알림 수 |

**목록 응답(`toListArray`)에는 넣지 않습니다** — 행마다 집계가 돌아 N+1 이 됩니다.

---

## 성능 메모

목록은 지연 조인(`PaginatesWithDeferredJoin`)으로 페이지네이션합니다. PK 가 uuid 문자열이라
`keyName` 을 `notifications.id` 로 명시해야 합니다(기본값 `id` 는 조인된 `users.id` 와 모호해집니다).

`notifications` 는 Laravel 표준 테이블이라 인덱스가 `(notifiable_type, notifiable_id)` 하나뿐입니다.
관리자 목록은 회원을 특정하지 않고 `created_at` 으로 정렬하므로
`2026_09_14_000002_add_admin_indexes_to_notifications_table` 이 두 인덱스를 더합니다.
