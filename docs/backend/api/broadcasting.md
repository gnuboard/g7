# Broadcasting API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Broadcasting 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/broadcasting/auth
<!-- @generated:start:api.broadcasting.auth -->
- **라우트명**: `api.broadcasting.auth`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/broadcasting/auth HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-403 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** WebSocket 프라이빗/프레즌스 채널 구독 시 Laravel Broadcast 채널 인증을 수행하는 엔드포인트입니다. `auth:sanctum` 토큰으로 인증하며, 웹소켓 사용이 OFF(`broadcasting.default === 'null'`)이면 채널 인증을 거부해 403을 반환합니다(reverb.key 무력화를 우회한 직접 연결 시도까지 차단). 컨트롤러 없이 라우트 클로저가 토글 가드를 적용한 뒤 `Broadcast::auth`에 위임하며, 실시간 이벤트 구독을 위해 클라이언트 브로드캐스팅 라이브러리가 자동 호출합니다.


