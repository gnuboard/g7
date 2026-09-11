# Core Update API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Core Update 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/core-update/changelog
<!-- @generated:start:api.admin.core-update.changelog -->
- **라우트명**: `api.admin.core-update.changelog`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@changelog`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| source | query | string | 아니오 | `active`, `bundled`, `github` | 어느 위치의 CHANGELOG를 조회할지 지정 (active: 활성 설치본, bundled: 번들 원본, github: 원격 릴리스). 미지정 시 기본 조회 경로를 사용 |
| from_version | query | string | 아니오 | — | 시작 버전 (범위 하한) |
| to_version | query | string | 아니오 | — | 대상 버전 (범위 상한) |

**요청 예시**

```http
GET /api/admin/core-update/changelog?source=active&from_version=%EC%98%88%EC%8B%9C%EA%B0%92&to_version=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| changelog | array | `[{"version":"7.0.6","date":"2026-07-19","categories":[{"n…` | 변경 이력 텍스트 (원격/파일 CHANGELOG 본문) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "...": "(2개 키 생략, 총 3개)"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->



**설명** 코어의 버전별 변경사항(CHANGELOG)을 구조화된 배열로 조회합니다. `source`(active/bundled/github)로 어느 위치의 CHANGELOG를 읽을지, `from_version`/`to_version`으로 조회 범위를 지정합니다. `core.settings.read` 권한이 필요하며, 업데이트 안내 화면에서 새 버전에 무엇이 바뀌는지 보여줄 때 사용합니다. 확장은 `core.extension.changelog_validation_rules` 훅으로 파라미터를 확장할 수 있습니다.


### POST /api/admin/core-update/check
<!-- @generated:start:api.admin.core-update.check -->
- **라우트명**: `api.admin.core-update.check`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@checkForUpdates`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/core-update/check HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| update_available | boolean | `false` | 새 버전 존재 여부 (최신 릴리스 버전이 현재 버전보다 높으면 `true`) |
| current_version | string | `7.0.3` | 현재 설치된 코어 버전 (프로세스 환경값이 아니라 설정의 버전 — `config('app.version')`) |
| latest_version | string | `7.0.3` | GitHub 릴리스에서 조회한 최신 코어 버전 (조회 값이 없으면 현재 버전과 동일) |
| github_url | string | `https://github.com/gnuboard/g7` | 업데이트 조회 대상 GitHub 저장소 URL (`config('app.update.github_url')`) |

> 조회 실패 시(GitHub 접속 불가 등)에는 422 응답이 반환되며, `error` 객체에 `reason`(실패 사유) · `current_version` · `github_url` 이 담깁니다.

**응답 예시**

```json
{
    "success": true,
    "message": "업데이트 확인이 완료되었습니다.",
    "data": {
        "update_available": false,
        "current_version": "7.0.3",
        "latest_version": "7.0.3",
        "github_url": "https://github.com/gnuboard/g7"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->



**설명** GitHub 릴리스를 기준으로 코어 업데이트 가능 여부를 확인합니다. 현재 버전과 최신 버전을 비교한 결과를 반환하며, 조회에 실패하면 실패 사유·현재 버전·github_url과 함께 422를 반환합니다. `core.settings.update` 권한이 필요하고, 관리자가 업데이트 확인 버튼을 눌러 새 버전 유무를 점검하는 시나리오에 사용합니다.


---

## 웹 단계 실행 (W6)

코어 업데이트는 **한 요청이 한 단계**만 수행한다. 화면이 `advance` 를 반복 호출하고,
서버는 매번 다음 단계 하나만 처리한 뒤 상태를 돌려준다. 요청 경계가 안전 요건인 이유는
파일을 교체한 프로세스가 사라진 클래스를 참조하면 치명 오류가 나기 때문이며, 특히
업그레이드 스텝은 파일 적용 **다음 요청**에서 실행돼야 한다. 진행 지점은 서버가 상태
파일로 보관하므로 새로고침·브라우저 종료 뒤에도 이어진다.

세 경로(`status` · `advance` · `dismiss`)는 **점검 모드에서도 열려 있다.** 업데이트가
스스로 켠 점검 모드에 진행·되돌리기가 갇히면 사이트를 세워 놓고 이어 갈 수단이 사라지기
때문이며, `advance` 응답은 관리자 화면 접근을 위한 점검 모드 우회 쿠키를 함께 싣는다.

### 안전 기본값 (웹 전용 고정)

명령행에 있는 파괴적 선택은 **웹 동선에 입력 표면 자체가 없다.**

| 명령행 옵션 | 웹 |
| --- | --- |
| `--force` | 미노출 — 버전 비교 필수 |
| `--prune` | 미노출 — 3-way 증분 고정 |
| `--no-backup` | 미노출 — 백업 필수 (자동 되돌리기의 전제) |
| `--no-maintenance` | 미노출 — 점검 모드 필수 |
| `--vendor-mode=` | 미노출 — 실행 환경 판정에 동조 |
| `--source=` / `--local` | 미노출 — 수동 소스는 ZIP 경로만 제공 |

### 단계

| 순서 | 키 | 내용 |
| --- | --- | --- |
| 1 | `preflight` | 시스템 요구사항 · `_pending` 경로 · **적용 대상 전 경로의 쓰기 가능성** · 여유 공간 |
| 2 | `maintenance` | 점검 모드 활성화 |
| 3 | `download` | 새 버전 내려받기 또는 운영자 ZIP 추출 |
| 4 | `backup` | 백업 생성 + 소유권 스냅샷 |
| 5 | `vendor` | `_pending` 의 vendor 구성 (변경 없으면 스킵) |
| 6 | `plan` | 신규 파일 manifest + 3-way 증분 적용 목록 산출 |
| 7 | `apply` | 코어 파일 적용 |
| 8 | `vendor_copy` | vendor 디렉토리 복사 |
| 9 | `migrate` | 마이그레이션 + 코어 설정 재로드·권한/메뉴 재동기 |
| 10 | `upgrade_steps` | 업그레이드 스텝 (파일 적용과 **다른 요청**) |
| 11 | `bundled` | 번들 확장·언어팩 일괄 업데이트 (선택) |
| 12 | `finalize` | 버전 기록 · 캐시 · 소유권 복원 · 정리 · 점검 모드 해제 |

### 실행 상태 값

| 값 | 의미 | 화면 |
| --- | --- | --- |
| `running` | 다음 단계를 기다리는 중 | 진행률 + 단계 목록 |
| `rolling_back` | 단계가 실패해 되돌리기를 기다리는 중 | 진행 표시 유지 |
| `rolled_back` | 되돌리기 완료 — 사이트 정상 | 실패 사유 + 「되돌렸습니다」 |
| `rollback_failed` | 되돌리기까지 실패 | 수동 복구 절차 · 백업 위치 |
| `completed` | 업데이트 완료 | 완료 안내 |

---

### GET /api/admin/core-update/status

- **라우트명**: `api.admin.core-update.status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@status`
- **인증/권한**: `auth:sanctum` + `permission:core.system.update`

**요청 파라미터** — 없음

**요청 예시**

```http
GET /api/admin/core-update/status HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**응답 필드**

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| run | object\|null | 진행 중이거나 끝난 실행. 없으면 `null` (화면은 착수 상태를 그린다) |
| run.run_id | string | 실행 식별자 |
| run.status | string | `running`·`rolling_back`·`rolled_back`·`rollback_failed`·`completed` |
| run.from_version | string | 업데이트 이전 버전 |
| run.to_version | string\|null | 대상 버전 (수동 ZIP 은 추출 후 결정되므로 그 전까지 `null`) |
| run.options | object | 착수 선택 (`update_bundled_extensions`·`rebuild_search_index`·`layout_strategy`·`zip_file`) |
| run.next_step | string\|null | 다음에 수행할 단계 키 |
| run.completed_steps | string[] | 완료한 단계 키 |
| run.completed_count | int | 완료 단계 수 (진행률 계산용) |
| run.current_step | string\|null | 다음에 수행할 단계 키 (`next_step` 과 같은 값 — 화면이 단계 목록에서 현재 위치를 찾을 때 쓴다) |
| run.current_step_label | string\|null | 다음 단계의 표시 이름 (활성 로케일) |
| run.logs | string[] | 진행 기록 (최근 500줄) |
| run.warnings | string[] | 운영자가 확인해야 할 사항 (권한 복원 실패·루트 보호 파일 등) |
| run.notices | string[] | 실행을 막지는 않지만 알려야 할 사실 (배포 설정 조각 갱신 안내 등) |
| run.started_at | string\|null | 착수 시각 (ISO 8601) |
| run.updated_at | string\|null | 마지막 갱신 시각 (ISO 8601) |
| run.error | object\|null | 실패 단계·사유·실패 기록 경로 |
| run.rollback | object\|null | 되돌리기 결과 (`restored`·`files_were_applied`·`failed_targets`·`backup_path`) |
| steps | object[] | 전체 단계 목록 (`key`·`label`·`position`) |
| total_steps | int | 단계 수 |
| manual_sources | object[] | 운영자가 올려 둔 ZIP 목록 (`name`·`size`·`modified_at`) |
| modified_layouts | object | 수정된 레이아웃 집계 (`has_modified`·`count`·`extensions`) — 진행 중에는 생략 |
| preflight | object\|null | 착수 전 사전 조건 진단 — 진행 중에는 생략되고, 진단 자체가 실패하면 `null` |
| preflight.ok | bool | 지금 착수할 수 있는지 |
| preflight.blocked | string[] | 쓰기 불가로 판정된 경로 |
| preflight.checked | int | 검사한 경로 수 |
| preflight.disk | object | 여유 공간 (`measurable`·`free_bytes`·`required_bytes`·`ok`) |
| preflight.errors | string[] | 운영자에게 보여 줄 사유 (화면이 그대로 그린다) |

> **진단의 깊이** — 이 응답의 `preflight` 는 **얕은** 판정이다(각 대상 경로의 쓰기 비트 + 여유 공간).
> 하위 전수 순회와 트리 크기 추정은 착수 1단계(`preflight` 단계)가 수행한다 — 그 순회는 저장소
> 규모에 따라 수 초에서 십수 초가 걸려 화면을 여는 요청에 실을 수 없다. 따라서 이 값이 `ok: true`
> 여도 1단계에서 막힐 수 있으며, **차단의 권위는 1단계에 있다.**

**응답 예시**

```json
{
    "success": true,
    "message": "업데이트 상태를 가져왔습니다.",
    "data": {
        "run": null,
        "steps": [
            { "key": "preflight", "label": "사전 점검", "position": 1 }
        ],
        "total_steps": 12,
        "manual_sources": [],
        "modified_layouts": { "has_modified": false, "count": 0, "extensions": [] }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.system.update` 권한이 없는 경우 |
| 500 | Server Error | 상태를 읽지 못한 경우 |

**설명** 화면이 네 상태(최신·새 버전 있음·진행 중·실패) 중 무엇을 그릴지 정하는 근거다.
진행 중인 실행이 없으면 `run` 이 `null` 이고, 착수 화면이 필요한 부가 정보(올려 둔 ZIP
목록·수정된 레이아웃 집계)가 함께 실린다.

---

### POST /api/admin/core-update/start

- **라우트명**: `api.admin.core-update.start`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@start`
- **인증/권한**: `auth:sanctum` + `permission:core.system.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| update_bundled_extensions | body | boolean | 아니오 | | 번들 확장·언어팩을 함께 업데이트할지 (기본 `true`) |
| rebuild_search_index | body | boolean | 아니오 | | 일괄 업데이트 후 검색 인덱스를 재생성할지 (기본 `false`, 그 실행에만 적용) |
| layout_strategy | body | string | 아니오 | `overwrite`, `keep` | 전역 레이아웃 처리 (기본 `overwrite` — 명령행 기본과 동일) |
| zip_file | body | string | 아니오 | `<이름>.zip` | 운영자가 `storage/app/core_pending` 에 올려 둔 파일 **이름**. 경로 성분은 허용하지 않는다 |

**요청 예시**

```http
POST /api/admin/core-update/start HTTP/1.1
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json

{
    "update_bundled_extensions": true,
    "rebuild_search_index": false,
    "layout_strategy": "overwrite"
}
```

**응답 필드** — `GET /api/admin/core-update/status` 와 같다 (`run` 이 채워진 상태).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.system.update` 권한이 없는 경우 |
| 422 | Unprocessable Entity | 이미 실행 중 · 새 버전 없음 · 지정한 ZIP 부재 · 파라미터 검증 실패 |
| 500 | Server Error | 착수 처리 중 예기치 못한 오류 |

**설명** 실행을 등록만 하고 **어떤 단계도 수행하지 않는다.** 실제 진행은 이어지는
`advance` 호출이 한 단계씩 한다. 착수 전에 새 버전 유무(자동 경로)나 지정한 ZIP 의
실재(수동 경로)를 확인하므로, 점검 모드를 켠 뒤에 되돌리는 왕복이 생기지 않는다.

---

### POST /api/admin/core-update/advance

- **라우트명**: `api.admin.core-update.advance`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@advance`
- **인증/권한**: `auth:sanctum` + `permission:core.system.update`

**요청 파라미터** — 없음

**요청 예시**

```http
POST /api/admin/core-update/advance HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**응답 필드** — `GET /api/admin/core-update/status` 와 같다.

점검 모드가 켜져 있으면 응답에 점검 모드 우회 쿠키(`laravel_maintenance`)가 함께 실린다 —
운영자가 진행 중에도 관리자 화면을 볼 수 있고, 실패로 멈췄을 때 복구 화면에 닿을 수 있어야
하기 때문이다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.system.update` 권한이 없는 경우 |
| 409 | Conflict | 진행 중인 실행이 없거나, 다른 요청이 같은 단계를 수행 중인 경우 |
| 500 | Server Error | 단계 수행 중 예기치 못한 오류 |

**설명** 다음 단계 하나를 수행한다. 단계가 실패하면 **응답은 성공**이고 `run.status` 가
`rolling_back` 이 된다 — 되돌리기는 조작 없이 이어지는 호출이 수행하므로, 화면은 같은
반복 호출을 계속하면 된다. 되돌리기까지 끝나면 `rolled_back` 또는 `rollback_failed` 로
멈춘다.

더블클릭 등으로 두 요청이 겹치면 뒤에 온 요청이 409 를 받는다. 진행 상태는 그대로
남으므로 잠시 뒤 다시 호출하면 이어진다.

---

### POST /api/admin/core-update/dismiss

- **라우트명**: `api.admin.core-update.dismiss`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\CoreUpdateController@dismiss`
- **인증/권한**: `auth:sanctum` + `permission:core.system.update`

**요청 파라미터** — 없음

**요청 예시**

```http
POST /api/admin/core-update/dismiss HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**응답 필드** — `GET /api/admin/core-update/status` 와 같다 (`run` 이 `null` 로 비워진 상태).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | `core.system.update` 권한이 없는 경우 |
| 409 | Conflict | 아직 진행 중인 실행이 있는 경우 |
| 500 | Server Error | 기록 정리 중 예기치 못한 오류 |

**설명** 끝난 실행의 기록(진행 상태·적용 목록 사이드카)을 지운다. 진행 중에는 거부되므로
실수로 재개 지점을 잃는 일이 없다.
