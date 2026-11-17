# 실행 역할과 서버별 스케줄 스위치

> 같은 DB·캐시·저장소를 쓰는 서버를 여러 대 둘 때, 서버마다 「무엇을 돌리는지」를 서버 로컬 `.env` 로 정한다 (7.2.0).

## TL;DR

- `G7_RUNTIME_ROLE=live|stage` (기본 `live`) — `stage` 는 공유 데이터를 읽기만 하는 **검수 서버**다. 판정은 `App\Support\RuntimeRole` 하나.
- `G7_SCHEDULER=on|off` (기본 `on`) — 이 서버가 스케줄을 실행하는지. 판정은 `RuntimeRole::schedulerEnabled()` 하나(stage 면 늘 꺼짐).
- 두 값 모두 서버 로컬이고 공유 설정(JSON·DB)보다 우선한다. `live`·`stage` 외 값이면 부팅 때 예외다.
- 설정 캐시(`php artisan config:cache`)를 쓰는 서버는 두 값을 바꾼 뒤 `config:cache` 를 다시 실행한다. 캐시가 있으면 명령 하나에만 환경 변수를 붙여(`G7_RUNTIME_ROLE=stage php artisan …`) 역할을 바꿀 수 없고, 확장·코어 업데이트는 끝날 때 설정 캐시를 다시 구우므로 임시로 바꾼 값이 그대로 굳는다 — 역할은 `.env` 로만 정한다.

## stage 역할에서 달라지는 것

| 대상 | 동작 |
| --- | --- |
| 스케줄 | 등록 0건. `schedule:run`·`schedule:work`·`g7:schedule-run` 은 안내 후 종료 0 (cron 을 지우지 않아도 된다) |
| 큐 워커·신호 | `queue:work`·`queue:listen`·`reverb:start`·`queue:restart` 는 안내 후 종료 1. 설정 저장·코어/확장 업데이트의 내부 재시작 신호도 건너뛴다 — 공유 캐시 신호가 다른 서버의 워커까지 재시작시킨다 |
| 큐 연결 | `queue.default=sync` 강제 (공유 설정보다 우선) |
| 사이트맵 | 공개 사이트맵 요청은 생성 잡을 넣지 않는다. 관리자 재생성은 미들웨어 `deny.stage:sitemap` 이 409 `errors.stage_role_locked_sitemap`(사이트맵 전용 안내), `seo:generate-sitemap` 은 종료 1, 생성 잡은 무동작 |
| SEO | 봇 판정 false, SEO 캐시 쓰기 무동작 |
| 방문 분석 | 수집 무동작(수집 주소는 같은 204) |
| 캐시 | 코드 범위 키(레이아웃 해석·레이아웃 마크업·시스템 정보 캐시 등)는 `g7:stage:` 아래에 따로 둔다 — [cache-driver.md](../extension/cache-driver.md) 「캐시 범위」 |
| 레이아웃 | `stage_` 접두 테이블에 둔다 — 아래 「검수 서버의 레이아웃 테이블」 |
| 관리자 화면 | 셸 `G7Config.appConfig.runtime_role`(레이아웃에서는 `_global.appConfig.runtime_role`, 공개 설정 API 는 `app_config.runtime_role` 과 최상위 `runtime_role`)로 상단 띠·레이아웃 편집기 띠를 그리고 확장·코어·언어팩 수명주기 버튼을 끈다(시각 비활성 + 안내) |
| 수명주기 API | 확장 설치·활성화·비활성화·제거·업데이트(미리보기 포함)·호환성 복구(대시보드 [복구])·코어 업데이트·언어팩 설치/제거는 미들웨어 `deny.stage`(`DenyOnStageRole`)가 409 `errors.stage_role_locked`. CLI(`*:install`·`*:update`·`*:refresh-layout`·`extension:reset-files-from-bundled`·`core:execute-upgrade-steps`)는 허용. 운영 서버 마무리 `stage:apply-to-live` 는 종료 1(「운영 서버 배포 순서」) |
| 수명주기 CLI | 모듈·플러그인·템플릿·언어팩의 `activate`·`deactivate`·`uninstall`(`module:activate`·`template:deactivate`·`language-pack:uninstall` 등 12개)은 안내(`common.console.runtime_role.lifecycle_stage`) 후 종료 1 — 공유 DB 의 확장 상태를 바꿔 운영 서버에 바로 보인다(같은 종류의 운영 활성 템플릿 끄기, `--delete-data` 로 운영 테이블 삭제). 새 확장은 `*:install` 로 설치해 시험하고, 켜기·끄기·제거는 운영 서버에서 한다 |
| 언어팩 업데이트·재설치 | 이미 설치된 언어팩을 다시 까는 경로 — `language-pack:update`(와 그 안의 재설치), 같은 팩을 다시 설치하는 `language-pack:install`, `language-pack:provision` 의 어긋남 복구 재설치 — 는 공유 상태 칸을 쓰지 않는다 — `updating` 기록도, 재설치의 `installed` 기록 → 다시 켜기도 거치지 않아 운영 서버에서 그 로케일이 빠지지 않는다. 판·파일·매니페스트만 새로 한다. 켜기를 거치지 않으므로 활성화 훅의 엔티티 번역 재적용(게시판 유형·클레임 사유 등 다국어 DB 행)은 검수 서버에서 돌지 않는다 — 운영 서버의 업데이트가 한다 |
| 업데이트 확인 | 관리자 「업데이트 확인」(모듈·플러그인·템플릿·언어팩)과 `module\|plugin\|template:check-updates`(전체·단일 모두)는 확인 결과만 돌려주고 공유 DB 의 업데이트 표시(`update_available`·`latest_version`·`update_source`, 언어팩은 `latest_version`)를 쓰지 않는다 — 운영 서버 화면의 「업데이트 있음」을 검수 서버의 번들·코드 기준 판정으로 덮지 않게. 버튼은 켜 둔다(결과는 응답으로 본다) |

## 서버별 스케줄 스위치

| 값 | 범위 | 뜻 |
| --- | --- | --- |
| `system.cron_mode` | 공유 설정 | 스케줄을 서버 cron·웹 크론 주소·방문 트리거 중 무엇으로 깨울지 |
| `G7_SCHEDULER` | 서버 로컬 `.env` | 이 서버가 스케줄을 실행하는지 |

`G7_SCHEDULER=off` 인 서버는 `schedule:run`·`schedule:work`·`g7:schedule-run` 을 안내 후 종료 0 으로 끝내고, 웹 크론 주소와 방문 트리거
(`WebCronPolicy::webCallAllowed()`)도 실행하지 않는다 — 웹 크론 응답은 `schedule.cron.disabled` 에 `reason: scheduler_disabled_on_server`.
관리자 설정 API(`GET /api/admin/settings`)는 그 요청을 처리한 서버의 값 `scheduler_enabled_on_this_server` 를 싣는다.
두 값의 비교(꺼진 쪽의 동작·웹 크론 응답 `reason`)는 [scheduling.md](scheduling.md) 「5. 중복 방지·다중 서버·점검 모드」의 표를 본다.

## 코드 판이 다른 서버가 같은 DB 를 볼 때

검수 서버가 새 판을 먼저 받으면 공유 DB 에는 운영 서버 코드에 없는 행이 생긴다. 아래 두 가지는 실행 역할과 무관하게 각 서버가 자기 코드 기준으로 거른다.

| 대상 | 동작 |
| --- | --- |
| 스케줄 | 이 서버에 없는 Artisan 명령(콘솔 커널·행의 `command_class`·활성 확장 명령 디렉터리 어디에도 없음)이나 작업(Job) 클래스의 행은 실행 시점에 건너뛴다(건너뛰기 필터 — 서버 크론·웹 크론 모두). 실패 이력·오류 로그·실패 알림이 쌓이지 않고, 경고 로그 `DB 스케줄 실행 건너뜀` 을 행·대상마다 하루 한 번 남긴다. `command_class`·명령 디렉터리의 클래스는 실행기와 같이 커맨드이고 명령 이름이 같을 때만 있다고 본다. 판정 자체가 예외면(콘솔 커널의 명령 하나가 생성 중 예외 등) 건너뛰지 않고 실행하며 경고 `DB 스케줄 대상 판정 실패` 를 같은 예외마다 하루 한 번 남긴다. 판정이 등록이 아니라 실행 시점인 것은 등록이 콘솔 커널 부팅 도중(`routes/console.php`)이라 그때 명령 목록을 읽으면 부팅이 다시 돌기 때문이다 |
| 관리자 메뉴 | 사이드바(`GET /api/admin/menus/active`)와 메뉴 관리 목록은 DB 에서 활성인 모듈·플러그인 가운데 이 서버가 불러온(활성 디렉터리에 파일이 있는) 확장의 메뉴만 보인다 — 검수 서버에만 설치한 확장의 메뉴가 운영 사이드바에 보이고 누르면 404 이던 것을 막는다 |

검수 서버에서 시험할 때의 한계: 이미 있는 확장에 새 판이 추가한 메뉴는 운영 서버도 그 확장을 불러와 있으므로 거르지 않는다 — 검수 서버에서 새 판을 설치·업데이트하면 그 메뉴가 운영 사이드바에도 보인다(누르면 운영 서버의 옛 코드에 라우트가 없을 수 있다).

## 검수 서버의 레이아웃 테이블

검수 서버(stage)는 레이아웃을 `stage_` 접두 테이블에 둔다 — `stage_template_layouts`·`stage_template_layout_extensions`·
`stage_template_layout_versions`·`stage_template_layout_extension_versions`(원 테이블과 같은 스키마·인덱스, ID 는 10억부터).
검수 서버에서 레이아웃을 갱신·편집해도 운영 화면은 바뀌지 않는다. 반대 방향(검수 서버 편집본을 운영으로 올리기)은 기능이 없다 —
검수 서버에서 확인한 레이아웃 변경은 운영 서버에서 다시 하거나 코드(확장 레이아웃 파일)로 배포한다.

- 모델 `TemplateLayout`·`LayoutExtension`·`TemplateLayoutVersion`·`TemplateLayoutExtensionVersion` 은 트레이트(`UsesRuntimeRoleLayoutTable`)로
  역할에 맞는 테이블을 쓴다. 테이블 이름을 문자열로 적는 질의·검증은 `App\Support\LayoutTable::name('template_layouts')` 를 쓴다
  — 테이블 이름을 직접 적으면 정적 검사가 막는다.
- 쓰기 방어: stage 가 운영 테이블에, live 가 스테이지 테이블에 쓰려 하면 `StageLayoutWriteException`.
- 레이아웃 미리보기(`template_layout_previews.runtime_role`)는 만든 서버의 역할로 가른다 — 각 서버는 자기 역할의 미리보기만 보고 지운다.
  만료 정리·템플릿 제거는 역할과 무관하게 지운다.
- 템플릿을 제거하면 다른 역할 테이블의 그 템플릿 행도 명시적으로 지운다(`StageLayoutTableService::deleteOtherRoleRowsForTemplate()`).

### 검수 서버 갱신 순서

이미 출시된 업그레이드 스텝은 실행 역할을 모르므로 검수 서버에서 돌아도 운영 레이아웃 테이블을 고친다. 그래서 검수 서버의 갱신은
다음 순서로 한다 — `stage-layouts:reset-from-live` 가 운영 행을 다시 복사하므로 결과가 일관된다.

1. 업그레이드 스텝 (`core:execute-upgrade-steps` 등). 판 번호를 올리지 않고 `config/core.php` 의 코어 선언(역할·권한·메뉴·알림 정의·스케줄·회원 항목)을
   고쳤으면 `php artisan core:resync-declarations` — 판과 무관하게 코어 선언만 다시 맞춘다(추가·갱신은 곧바로, 빠진 항목의 삭제는 장부로 미룸).
   코어 갱신은 판이 바뀔 때만 돌아 같은 판의 선언 수정은 이 명령 없이는 DB 에 반영되지 않는다.
2. `php artisan stage-layouts:reset-from-live --yes` — 방향은 늘 운영 → 검수 서버다. 스테이지 4개 테이블을 비우고 운영 레이아웃·조각을
   ID 그대로 복사(본문·원본·해시 포함), 검수 서버의 레이아웃 병합본·해석·역변환 마크업 캐시를 버린다. 복사 전에(`--yes` 여도) 방향과 지워질 검수 서버 편집본 수
   (운영과 다른 레이아웃·조각, 검수 서버 버전 기록)를 출력하고, 복사 뒤 행 수와 운영 대비 체크섬 일치를 출력한다. 운영 서버에서는 종료 1.
   검수 서버를 처음 띄울 때도 한 번 실행한다 — 스테이지 테이블은 마이그레이션이 빈 채로 만든다.
3. `php artisan <type>:refresh-layout …`

## 검수 서버의 확장 업데이트

- `module|plugin|template:update`(CLI)는 공유 상태 칸에 `updating` 을 쓰지 않는다 — 운영 서버 화면에서 그 확장이 업데이트 중으로 빠지지 않게.
  진행 표식은 서버 로컬 진행 기록과 작업 잠금만 쓴다.
- 그래서 DB 판을 새 판으로 적은 뒤(스텝·레이아웃 갱신 도중) 프로세스가 죽으면 멈춘 업데이트 판정이 잡지 못한다. 이 서버의 진행 기록이
  남아 있으면 `--force` 없는 `*:update` 는 「업데이트 없음」 대신 끝나지 않은 업데이트(시작 판 → 목표 판)와 `--force` 이어받기 명령을
  알리고 종료 1 로 끝난다(역할과 무관 — 운영 서버도 상태를 되돌린 뒤 죽으면 같다). 그 업데이트가 이 서버에서 아직 돌고 있으면 진행 중이라고 알린다.
- 같은 판 번호로 다시 배포해 `--force` 로 돌리면 업그레이드 스텝 실행 대장(`upgrade_step_runs`)의 내용 지문을 본다 — 같은 내용으로 이미 끝난
  스텝은 공유 DB 에서 다시 돌지 않고(「같은 내용 이미 실행됨」), 스텝 파일이나 그 판의 `data/<판>/` 파일을 고친 스텝만 다시 돈다. 지문이 없는
  스텝(클로저·지문 도입 전 행)은 다시 돈다. 운영 서버의 `--force` 는 지문과 무관하게 다시 돈다. 범위: [upgrade-step-guide.md](../extension/upgrade-step-guide.md) 「내용 지문」.
- 새 판 선언에서 빠진 항목을 지우는 선언 정리(메뉴·권한·역할·알림 정의/템플릿·본인확인 정책·시드 엔티티·스케줄·회원 항목과 그 값)와
  역할에서 권한을 빼는 회수는 지우지 않고, 대장 `extension_deferred_cleanups` 에 「이 확장(코어)의 정리가 필요하다」만 적는다
  (범위·식별자·종류·대상 유니크 — 검수 서버에 여러 번 배포해도 한 줄). 지울 대상 목록은 적지 않는다.
- 운영 서버에서 `php artisan extension:apply-deferred-cleanup [--dry-run] [--json]` 을 실행하면 지울 대상을 **그 순간의 이 서버 선언과
  공유 DB 로 다시 정한다** — 설치·업데이트가 쓰는 선언 동기화(`syncDeclarativeArtifacts`, 코어는 `reloadCoreConfigAndResync`)를 그대로 부르고,
  시드 엔티티는 그 정리를 부른 시더를 다시 돌린다. 그래서 검수 서버 시점의 목록이 낡아도(여러 번 배포, 배포 뒤 운영 서버에서 만든 게시판의
  권한·메뉴·역할, 그 뒤 주문이 쓰기 시작한 시드 택배사) 그것을 지우지 않는다. 운영자 데이터가 있으면 지우기 직전 활동 로그에 남기고 자식은
  명시적으로 지운 뒤 대장 행을 지운다. 지우기 전에 실행할 정리와 건수를 알리고, `--dry-run` 은 아무것도 지우지 않고 그 목록만 보인다.
  - 이 서버의 확장 파일 판이 공유 DB 판과 다르면(운영 서버가 아직 새 파일을 받지 않음, 검수에서 반려한 판) 실행하지 않고 「기다림」으로
    남긴다. 코어 정리는 이 서버 코어 판(코드 판과 `APP_VERSION`)이 적을 때의 판 이상일 때만 실행한다.
  - 판 번호가 같아도 파일 내용이 다르면 기다린다 — 검수 서버는 정리를 적을 때 코드 내용 지문(확장은 활성 폴더, `extension:reset-files-from-bundled`
    와 같은 정의로 `vendor`·`node_modules`·`.git`·`custom` 제외 / 코어는 `config/core.php`)을 함께 적고, 운영 서버는 자기 파일 지문이 같을 때만
    실행한다. 판 번호를 올리지 않고 고쳐 검수 서버에 여러 번 배포해도, 마지막 검수 빌드와 같은 파일을 받은 운영 서버에서만 다시 계산한다. 공유 DB 에 설치돼 있지 않은 확장의 정리는
    그 확장 소유 선언 항목을 모두 지운다(검수 서버의 설치 실패 되돌리기 등).
  - 검수 서버에서는 종료 1. 실패나 기다림이 있으면 종료 1.
- 대장의 시더 클래스는 DB 에서 읽는 값이라 그 확장이 선언한 시더(`getSeeders()`)일 때만 실행하고, 그 밖의 값·알 수 없는 범위는 실행하지
  않고 실패로 남긴다(행 유지). 검수 서버가 미룰 수 있는 정리 메서드는 `DeferredDeclarativeCleanup::ALLOWED` 허용 목록(9개)에 있는 것뿐이다 —
  새 선언 정리 메서드가 대장을 쓰려면 허용 목록에 먼저 올린다. 대장의 범위·식별자는 메서드마다 정해 둔 인자 자리에서 읽고, 읽을 수 없으면 core 로 둔다.

## 운영 서버 배포 순서

검수 서버가 새 코드로 확장·언어팩을 먼저 업데이트하면 공유 DB 의 판 번호가 먼저 새 판이 된다. 그래서 운영 서버의
`*:update`·`language-pack:update` 는 「업데이트 없음/이미 최신」으로 끝나고, 업데이트가 하던 파일 교체·레이아웃 갱신·정리는 운영 서버에서
따로 해야 한다. 운영 서버에 같은 코드를 배포한 뒤 다음 순서로 한다.

1. 코어가 바뀌었으면 코어를 이 서버에서 먼저 올린다(아래 명령은 코어를 다루지 않는다). 비대화형 배포는
   `php artisan core:execute-upgrade-steps --from=<옛> --to=<새> --yes` 로 확인만 넘긴다 — `--force` 는 쓰지 않는다. 운영 서버의 `--force` 는
   업그레이드 스텝 실행 대장을 무시한다(검수 서버가 공유 DB 에서 이미 끝낸 스텝·그 안의 데이터 백필을 다시 돌림). 이어지는 번들 확장 일괄
   업데이트는 번들 판이 공유 DB 판보다 높은 확장만 대상이라 검수 서버가 이미 올린 확장은 돌지 않고, 대상이 있으면 두 옵션 모두 비대화형에서
   레이아웃 전략을 묻지 않고 기본값 overwrite 로 돈다. 스키마 마이그레이션은 `migrations` 표에 기록된 것을 어느 옵션으로도 다시 돌리지 않는다.
2. `php artisan stage:apply-to-live [--dry-run] [--json] [--yes]` — 이 서버에서 다음 세 단계를 차례로 실행한다.

| 단계 | 실행하는 명령 | 하는 일 |
| --- | --- | --- |
| 1. 파일 맞추기 | `extension:reset-files-from-bundled` (전 종류) | 판이 공유 DB 와 같고 파일이 다른 모듈·플러그인·템플릿·언어팩의 활성 폴더를 번들 내용으로 바꾼다. 비어 있는 레이아웃 병합 기준을 교체 전 파일로 채운다 |
| 2. 레이아웃 갱신(keep) | 켜진 사용자 템플릿 → 켜진 관리자 템플릿의 `template:refresh-layout`, 레이아웃·조각을 가진 켜진 모듈·플러그인의 `module\|plugin:refresh-layout` | 이 서버의 레이아웃 표에 새 판을 반영한다(운영자 수정본은 병합·보존). 관리자 템플릿 갱신이 설치된 템플릿의 설정 화면을 다시 등록한다 — 검수 서버에서 돈 업그레이드 스텝은 검수 서버 표에만 등록하므로 |
| 3. 정리 실행 | `extension:apply-deferred-cleanup` | 검수 서버가 미뤄 둔 선언형 정리를 실행한다. 종료 1(실패·기다림)이면 파일 맞추기를 먼저 끝냈는지 확인한다 |

- 방향은 늘 「이 서버의 번들 파일 → 이 서버의 활성 폴더·이 서버의 레이아웃 표」 다. 검수 서버의 데이터(검수 서버에서 고친 레이아웃 등)를
  운영으로 옮기는 명령이 아니며 그런 기능은 없다. 실행 전에(`--yes` 여도) 방향과 단계를 출력한다.
- 확인 질문은 `--yes` 로 미리 답한다. 답할 수 없는 실행(`--yes` 없는 비대화형)은 시작하지 않고 종료 1.
- 한 단계가 실패하면 그 단계에서 멈추고 종료 1 — 단계 안의 다른 확장은 끝까지 처리해 결과 표에 보이고, 뒤 단계는 「실행 안 함」 으로 남긴다.
- `--dry-run` 은 각 단계를 미리보기로 부른다. 레이아웃 미리보기는 지금 활성 폴더를 읽으므로 1단계가 바꿀 파일은 반영되지 않는다.
- `--json` 은 `{"dry_run", "failed_step", "steps": [{"step", "status", "exit_code", "items"}]}` 를 낸다(`status`: `ok`·`failed`·`skipped`).
- 검수 서버에서는 실행하지 않는다(종료 1).
