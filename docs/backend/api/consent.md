# Consent API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 방문자 동의 스냅샷 공개 API 입니다 — 운영자가 고른 동의 제공자의 답을 공개 5키로 돌려줍니다
2. 비로그인도 부를 수 있고, Bearer 토큰이 있으면 회원 기준으로 답합니다
3. 판단할 수 없으면 status unknown, 동의 관리 확장이 없으면 none 입니다
4. 브라우저 코어 동의 런타임이 이 응답으로 _global.consent 를 채웁니다
5. 제공자 계약은 docs/extension/consent-provider.md 를 봅니다
```

---

### GET /api/consent
<!-- @generated:start:api.public.consent -->
- **라우트명**: `api.public.consent`
- **컨트롤러**: `App\Http\Controllers\Api\Public\ConsentController@show`
- **인증/권한**: 공개 (`optional.sanctum` — Bearer 토큰이 있으면 회원 기준으로 답한다) · `throttle:60,1,consent`

**요청 파라미터**

_없음._

**요청 예시**

```http
GET /api/consent HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| contract_version | integer | 공개 계약 버전 (현재 `1`) — 브라우저 런타임은 다른 값이면 `unknown` 으로 다룬다 |
| status | string | `ready`(제공자가 답함) · `unknown`(모름 — 조회 실패·제공자 모호·고른 제공자 없음) · `none`(사이트에 동의 관리 제공자가 없음) |
| categories | object | 카테고리 → 동의 여부. 표준 어휘 `necessary`·`functional`·`analytics`·`marketing` 은 항상 실리고 `necessary` 는 언제나 `true`. 제공자가 카테고리를 더 낼 수 있다 |
| needs_renewal | boolean | 정책이 바뀌어 다시 동의를 받아야 하는지 |
| policy_version | string\|null | 현재 동의 정책 버전 (`ready` 일 때 제공자가 알려 준 값) |

**응답 예시** (200)

```json
{
    "success": true,
    "message": "방문자 동의 상태를 확인했습니다.",
    "data": {
        "contract_version": 1,
        "status": "ready",
        "categories": {
            "necessary": true,
            "functional": true,
            "analytics": true,
            "marketing": false
        },
        "needs_renewal": false,
        "policy_version": "1.0"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 429 | Too Many Requests | 같은 방문자가 1분에 60회를 넘겨 부른 경우 |

<!-- @generated:end -->

**설명**

브라우저의 코어 동의 런타임이 이 응답으로 전역 상태 `_global.consent`(camelCase: `contractVersion`·`status`·`categories`·`needsRenewal`·`policyVersion`)를 채웁니다. 다른 확장은 그 상태를 읽거나 `G7Core.consent` 로 구독하므로 동의를 관리하는 확장의 경로를 몰라도 됩니다.

회원 번호·게스트 식별자·제공자 내부 값은 싣지 않습니다. 게스트는 운영자가 고른 동의 제공자가 요청(쿠키 등)에서 구한 식별자로 찾고, 같은 브라우저라도 로그인하면 회원 기준으로 답이 바뀝니다. 판단할 수 없으면 `unknown` 이며, 소비자는 그것을 동의하지 않은 것으로 다뤄야 합니다(fail-closed). 서버의 다른 판정(방문자 통계 수집 등)도 같은 매니저를 쓰므로 이 응답은 보안 경계가 아닙니다. 제공자 계약은 [동의 제공자 계약](../../extension/consent-provider.md)을 참조하세요.
