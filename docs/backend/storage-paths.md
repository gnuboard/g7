# storage 경로 분류

> `storage/` 아래 경로를 성격에 따라 네 목록으로 나눈 선언(`config/g7-storage.php`)과 그 목록을 보는 명령(`storage:paths`).

## TL;DR

- 백업 대상을 고르거나, 같은 DB 를 쓰는 서버를 여러 대 둘 때 서버끼리 함께 쓸 경로와 서버마다 따로 둘 경로를 정할 때 쓰는 목록이다.
- 네 목록: `shared_data`(운영 데이터) · `server_local`(서버별 파일) · `update_state`(업데이트 상태) · `temporary`(임시 파일).
- 확인: `php artisan storage:paths` (표) · `php artisan storage:paths --json` (스크립트용).
- 코어 동작은 이 목록으로 바뀌지 않는다. 코어 업데이트는 분류와 관계없이 `storage` 전체를 덮지도 지우지도 않는다.
- 코드에 새 저장 경로를 만들면 네 목록 중 하나에 넣는다 — 빠지면 아키텍처 테스트가 실패한다.

## 네 목록

| 목록 | 뜻 | 대표 경로 |
| --- | --- | --- |
| `shared_data` | 운영 데이터. DB 행과 짝이 맞아야 하므로 서버가 여러 대면 함께 쓰고, 백업에 넣는다 | `app/settings` · `app/modules` · `app/plugins` · `app/templates` · `app/attachments` · `app/public` |
| `server_local` | 서버마다 따로 두는 파일. 다시 만들 수 있거나 그 서버의 실행 상태다 | `app/private`(사이트맵 파일 포함) · `app/g7_installed` · 스케줄 보안 덮어쓰기 파일(`storage/app/schedule_security.json`) · `app/schedule/*` · `app/geoip` · `app/ext-bundles` · `app/thumbnails` · `framework` · `logs` |
| `update_state` | 코어·확장 업데이트가 쓰는 백업·대기 파일·진행 기록. 업데이트를 실행한 서버의 것이다 | `app/extension_backups` · `app/extension_update_journal` · `app/core_pending` · `app/core_backups` · `app/core-update` · `app/core-update.lock` |
| `temporary` | 작업 중에만 쓰는 파일. 언제 지워도 된다 | `app/temp` · `app/tmp` · `app/vendor-bundle-staging` · `app/benchmarks` |

전체 목록은 `config/g7-storage.php` 가 단일 출처다. 값은 `storage/` 기준 상대 경로이고, 디렉터리는 그 아래 전부를, `-`·`_` 로 끝나는 값은
그 이름으로 시작하는 파일·디렉터리(시각이 붙는 백업 등)를 뜻한다. 한 경로는 한 목록에만 들고, 다른 목록의 항목 아래에 놓이지 않는다.

### 분류할 때 볼 것

- **DB 행과 짝인가** — 설정 JSON·첨부·확장 저장소처럼 DB 가 가리키는 파일은 `shared_data` 다. 서버마다 따로 두면 다른 서버에서 만든 행이 없는 파일을 가리킨다.
- **다시 만들 수 있는가** — 번들 병합 결과·썸네일·사이트맵 파일은 `server_local` 이다. 사이트맵은 파일은 서버마다 만들고 메타 정보만 공유 캐시에 있으므로,
  여러 서버가 한 캐시를 쓰면 파일과 메타가 서로 다른 서버의 것일 수 있다.
- **그 서버의 실행 기록인가** — 설치 표식·스케줄 실행 기록·로그는 `server_local` 이다.
- **업데이트가 만들고 지우는가** — 백업·대기 파일·업데이트 진행 기록은 `update_state` 다.

## storage:paths

```bash
php artisan storage:paths          # 분류·경로·존재 여부 표
php artisan storage:paths --json   # {"storage_root": "...", "categories": {"shared_data": [{"path": "app/settings", "prefix": false, "exists": true}, ...], ...}}
```

접두사 항목(`-`·`_` 로 끝남)은 표에 `*` 를 붙여(예: `app/public-storage-broken-*`) 보이고, JSON 은 `"prefix": true` 다. 이때 「있음」은 그 이름으로 시작하는 파일·폴더가 하나라도 있는지다.

파일·설정을 바꾸지 않으며 종료 코드는 언제나 0 이다. 코드에서는 `App\Support\StoragePaths::lists()`(분류별 목록)·`StoragePaths::classify($relativePath)`(경로가 드는 분류)로 읽는다.

## 코어 업데이트 보호 경로와의 관계

코어 업데이트 보호 경로(`config/app.php` 의 `update.protected_paths`)는 `storage` 전체를 담는다. 그래서 네 목록의 어떤 경로도 코어 업데이트가 덮거나
지우지 않는다. 업데이트가 함께 백업하는 storage 경로(`update.backup_extra`)는 `shared_data` 에, 업데이트 대기 경로(`update.pending_path`)는 `update_state` 에 든다.

## 회귀 테스트

| 테스트 | 지키는 것 |
| --- | --- |
| `tests/Unit/Config/StoragePathsConfigTest.php` | 목록이 상대 경로이고 서로 겹치지 않음, 보호 경로와 모순 없음, `storage:paths --json` 형식 |
| `tests/Unit/Architecture/StoragePathsClassifiedTest.php` | 코어·번들 확장 코드의 `storage_path(…)` 정적 접두와 `config/filesystems.php` 디스크 루트가 모두 네 목록 중 하나에 듦 |

## 관련 문서

- [core-update-system.md](core-update-system.md) — 코어 업데이트 보호 경로·백업
- [../extension/storage-driver.md](../extension/storage-driver.md) — 확장의 파일 저장(StorageInterface)
