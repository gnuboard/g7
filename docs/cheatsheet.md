# 그누보드7 자주 쓰는 명령어 치트시트

## TL;DR (5초 요약)

```text
1. _bundled에서 레이아웃만 수정(`.g7.html`·`.json`) → 확장 업데이트(--force)만 실행 (빌드 불필요)
2. _bundled에서 TSX/TS 수정 → build 필수 + 확장 업데이트(--force) 필요
3. 프론트엔드 테스트 → PowerShell 래퍼 필수 (powershell -Command)
4. 백엔드 테스트 → Bash 직접 실행 (php artisan test)
5. 코드 스타일 → vendor/bin/pint --dirty
```

---

## 빌드 vs 확장 업데이트

```
_bundled에서 레이아웃만 수정한 경우(`.g7.html`·`.json`) → 빌드 불필요, 확장 업데이트(--force)만 필요
_bundled에서 TSX/TS 파일 수정한 경우    → 빌드 후 확장 업데이트(--force) 필요
```

| 수정 파일 유형 | 필요한 작업 |
|---------------|-------------|
| `*.g7.html`, `*.json` (레이아웃만) | `{type}:update {id} --force` 실행 (`.g7.html` 은 설치 시 JSON 컴파일 — 빌드 불필요) |
| `*.tsx`, `*.ts` (± 레이아웃) | `{type}:build` + `{type}:update {id} --force` |
| `*.tsx`, `*.ts`만 | `{type}:build` + `{type}:update {id} --force` |
| `modules/**/resources/js/**/*.ts` (핸들러) | `module:build` + `module:update {id} --force` |
| `templates/**/src/**/*.tsx` (컴포넌트) | `template:build` + `template:update {id} --force` |

> **확장 프론트엔드 번들**: 활성 모듈/플러그인 IIFE 는 서버측에서 종류별 1개 번들로 병합 서빙된다(`/api/{modules,plugins}/bundle.{js,css}`). `{type}:update` 후 확장 캐시 버전이 bump 되면 번들이 자동 재생성된다(prod 캐시, dev 매 요청 concat). 구버전 번들 파일은 `ext-bundles:cleanup` 또는 `{type}:cache-clear` 로 정리. 상세: [module-assets.md](extension/module-assets.md#서버측-번들-병합-server-side-bundle).

---

## 확장 업데이트 (_bundled → 활성 반영)

```bash
# 템플릿 업데이트 (templates/_bundled/** → templates/**)
php artisan template:update sirsoft-admin_basic --force

# 모듈 업데이트 (modules/_bundled/** → modules/**)
php artisan module:update sirsoft-ecommerce --force

# 플러그인 업데이트 (plugins/_bundled/** → plugins/**)
php artisan plugin:update sirsoft-payment --force
```

---

## 빌드 (Artisan 명령어 사용)

> **기본 빌드 경로**: `_bundled` 디렉토리. `--active` 옵션으로 활성 디렉토리 빌드 가능.
> `--watch` 모드는 자동으로 활성 디렉토리 사용 (실시간 브라우저 확인).
> 빌드 후 활성 반영: `module:update` / `template:update` / `plugin:update` 커맨드 사용.

```bash
# 코어 템플릿 엔진 (resources/js/core/template-engine/**)
php artisan core:build                    # 기본: 템플릿 엔진만 빌드
php artisan core:build --watch            # 파일 감시 모드

# 모듈 빌드 (기본: _bundled)
php artisan module:build sirsoft-ecommerce          # _bundled에서 빌드
php artisan module:build --all                      # 모든 _bundled 모듈 빌드
php artisan module:build sirsoft-ecommerce --watch   # 활성에서 watch
php artisan module:build sirsoft-ecommerce --active   # 활성에서 빌드

# 템플릿 빌드 (기본: _bundled)
php artisan template:build sirsoft-admin_basic        # _bundled에서 빌드
php artisan template:build --all                      # 모든 _bundled 템플릿 빌드
php artisan template:build sirsoft-admin_basic --watch # 활성에서 watch
php artisan template:build sirsoft-admin_basic --active # 활성에서 빌드

# 플러그인 빌드 (기본: _bundled)
php artisan plugin:build sirsoft-payment              # _bundled에서 빌드
php artisan plugin:build --all                        # 모든 _bundled 플러그인 빌드
php artisan plugin:build sirsoft-payment --watch       # 활성에서 watch
php artisan plugin:build sirsoft-payment --active       # 활성에서 빌드
```

---

## 테스트

```powershell
# 프론트엔드 (PowerShell 래퍼 필수)
powershell -Command "npm run test:run"
powershell -Command "npm run test:run -- DataGrid"
powershell -Command "npm run test:run -- template-engine"
```

```bash
# 백엔드
php artisan test
php artisan test --filter=TestName

# _bundled 확장 테스트 (활성 디렉토리 복사 불필요)
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests
php vendor/bin/phpunit --filter=TestName modules/_bundled/sirsoft-ecommerce/tests
```

---

## 코드 스타일

```bash
vendor/bin/pint --dirty
```

---

## 목록 조회 (컬럼 프루닝 · 지연 조인)

```php
// 계속 쌓이는 목록 = 지연 조인. 정렬 컬럼이 요청 값이면 닫힌 집합으로 해석.
use App\Repositories\Concerns\{PaginatesWithDeferredJoin, ResolvesSortSpec};

return $this->paginateWithDeferredJoin(
    query: $query,                                   // 필터/where 만. orderBy·with·select 금지
    columns: self::LIST_COLUMNS,                     // ['*'] 도 위반 아님
    sort: $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at'),
    perPage: $perPage,
    relations: ['user'],                             // 관계는 반드시 이 인자로 (with() 는 지워진다)
);
```

| 함정 | 결과 |
| ------ | ------ |
| `$query->with()` 만 하고 `relations:` 생략 | 관계가 조용히 사라짐 — 예외·오류 없음 |
| `SUBSTRING(content,1,N)` 을 inner 에 둠 | 오버플로 페이지 읽기 그대로 발생 (프루닝 아님) |
| 정렬을 비고유 컬럼으로만 끝냄 | 페이지 경계에서 행 중복·누락 (trait 이 PK 자동 append) |

상세: [service-repository.md](backend/service-repository.md#목록-조회-컬럼-프루닝과-지연-조인)

---

## 마이그레이션

```bash
php artisan make:migration create_[table]_table
php artisan migrate
php artisan migrate:rollback
```

---

## 확장 시스템 Artisan

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--yes] [--force] [--no-backup] [--no-maintenance] [--prune] [--vendor-mode=auto|composer|bundled] [--bundled-sources=none|all|id,…] [--rebuild-search-index]
php artisan core:update --zip=<파일> [--signature=<파일>] [--allow-unsigned]   # 수동 ZIP 업데이트 — 서명 파일 생략 시 ZIP 옆 <zip>.sig.json 또는 {base}.sig.json (7.2.0)
php artisan core:update --source=<디렉토리>|--local                # 압축 푼 디렉토리·로컬 코드베이스를 소스로 (GitHub 다운로드 생략)
php artisan core:bundled-sources list|place <id…>|prune [--force] [--include-modified] [--source=|--zip=]   # 공식 번들 소스 보고·배치·정리 (7.1.1+, prune 기본 미리보기)
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--yes|--force]   # 업그레이드 스텝 단독 실행 (HANDOFF 안내 또는 수동 복구용 — 단독 호출 시 migration·resync·.env·캐시·번들 확장 일괄 업데이트 자동 수행. CoreUpdateCommand 내부 spawn 은 --skip-* 5개 옵션 자동 전달)
php artisan core:deploy-ledger-seed                               # deploy/** 서버 설정 조각의 해시 원장을 지금 디스크 내용으로 씨앗 (이미 있는 항목은 유지)
                                                                  # 번들 출하 목록은 씨앗하지 않음 — 설치기만 --fresh-install 로 설치 직후 디스크에서 씨앗

# 관리자 계정 복구 (메일 없이 서버 터미널에서)
php artisan user:reset-password [email] [--password-file=<파일>] [--generate] [--require-change]   # 이메일 생략 시 최고관리자, --generate 는 무작위 비밀번호를 한 번만 출력(다음 로그인 때 변경 강제)

# 모듈
php artisan module:list
php artisan module:install [identifier] [--vendor-mode=auto|composer|bundled] [--force]
php artisan module:activate [identifier]
php artisan module:deactivate [identifier]
php artisan module:uninstall [identifier] [--yes] [--force] [--delete-data]
php artisan module:composer-install [identifier?] [--all]
php artisan module:cache-clear [identifier?]
php artisan module:seed [identifier] [--sample] [--count=key=value]
php artisan module:check-updates [identifier?]
php artisan module:update [identifier] [--yes] [--force] [--vendor-mode=auto|composer|bundled] [--layout-strategy=overwrite|keep] [--source=auto|bundled|github] [--dry-run [--json]] [--decisions=<파일>]
php artisan module:update [identifier] --zip=<파일> [--signature=<파일>] [--allow-unsigned]   # 외부 ZIP — 서명 파일 생략 시 ZIP 옆 <zip>.sig.json 또는 {base}.sig.json (7.2.0)
php artisan module:refresh-layout [identifier?] [--layout-strategy=keep|overwrite] [--dry-run [--json]] [--decisions=<파일>]   # 기본 keep(운영자 수정 유지)

# 플러그인
php artisan plugin:list
php artisan plugin:install [identifier] [--vendor-mode=auto|composer|bundled] [--force]
php artisan plugin:activate [identifier]
php artisan plugin:deactivate [identifier]
php artisan plugin:uninstall [identifier] [--yes] [--force] [--delete-data]
php artisan plugin:composer-install [identifier?] [--all]
php artisan plugin:cache-clear [identifier?]
php artisan plugin:seed [identifier] [--sample] [--count=key=value]
php artisan plugin:check-updates [identifier?]
php artisan plugin:update [identifier] [--yes] [--force] [--vendor-mode=auto|composer|bundled] [--layout-strategy=overwrite|keep] [--source=auto|bundled|github] [--dry-run [--json]] [--decisions=<파일>]
php artisan plugin:update [identifier] --zip=<파일> [--signature=<파일>] [--allow-unsigned]
php artisan plugin:refresh-layout [identifier?] [--layout-strategy=keep|overwrite] [--dry-run [--json]] [--decisions=<파일>]   # 기본 keep(운영자 수정 유지)

# 템플릿
php artisan template:list
php artisan template:install [identifier] [--force]
php artisan template:activate [identifier]
php artisan template:deactivate [identifier]
php artisan template:uninstall [identifier] [--yes] [--force] [--delete-data]
php artisan template:cache-clear [identifier?]
php artisan template:check-updates [identifier?]
php artisan template:update [identifier] [--layout-strategy=overwrite|keep] [--yes] [--force] [--source=auto|bundled|github] [--dry-run [--json]] [--decisions=<파일>]
php artisan template:update [identifier] --zip=<파일> [--signature=<파일>] [--allow-unsigned]
php artisan template:refresh-layout [identifier?] [--layout-strategy=keep|overwrite] [--dry-run [--json]] [--decisions=<파일>]   # 기본 keep(운영자 수정 유지)
# --dry-run: 화면별 판정(유지·새 판 적용·병합·삭제 예정)과 새 판에서 빠져 지워질 선언 항목을 무변경으로 출력. --decisions 는 화면별 {"<화면 키>": "take-theirs|keep-ours"} JSON (화면 키는 --dry-run 출력의 키 칸)
# --yes(-y): 확인 질문만 생략. --force(같은 판 재적용·다운그레이드·호환 검사 생략·같은 판 스텝 재실행·멈춘 업데이트 정리)와 별개
# 같은 판 스텝 재실행: 검수 서버(G7_RUNTIME_ROLE=stage)의 --force 는 같은 내용(지문)으로 이미 끝난 스텝을 건너뛰고 고친 스텝만 다시 돈다. 운영 서버는 늘 다시 돈다
# 이 서버의 업데이트가 끝나지 않은 채 남았으면(DB 판 기록 뒤 프로세스 종료) --force 없는 update 는 「업데이트 없음」 대신 안내 후 종료 코드 1 — --force 로 이어받는다
# --layout-strategy=keep: 운영자가 고친 부분은 유지하고 손대지 않은 조각·설정에 새 버전을 병합 + 결과 표 (3종 공통)
# update, uninstall 은 진행 확인을 묻는다. 확인에 답할 수 없는 실행(--no-interaction, cron, CI, 원격 명령)은 --yes(-y) 가 없으면 사유를 출력하고 종료 코드 1 (3종과 language-pack:uninstall 공통)
#   자동 실행에는 --yes 를 붙인다. --force 도 확인을 생략하지만 위 --force 효과가 함께 켜지므로 그 효과가 필요할 때만 쓴다 (core:update 의 --force 는 확인을 생략하지 않는다 — --yes)
# 미서명 패키지(GitHub·--zip·URL 소스, 7.2.0): 터미널에서는 한 번 더 묻고, 답할 수 없는 실행은 --allow-unsigned 가 없으면 아무것도 바꾸지 않고 종료 코드 1
#   --yes·--force 는 이 확인을 대신하지 않는다. 예약 실행에 넣는다면 --allow-unsigned 를 명시. 「미서명 패키지 차단」이 켜져 있으면 --allow-unsigned 도 무시 (core:update·3종 update·language-pack:install|update 공통)

# 언어팩
php artisan language-pack:list
php artisan language-pack:install [identifier] [--source=bundled|github|url] [--no-activate] [--allow-unsigned] [--signature=<서명 파일 https 주소>]   # --signature 는 --source=url 일 때 (생략하면 서명 없이 받음)
php artisan language-pack:activate [identifier] [--force]
php artisan language-pack:deactivate [identifier]
php artisan language-pack:uninstall [identifier] [--cascade] [--yes] [--force]
php artisan language-pack:cache-clear
php artisan language-pack:check-updates [identifier?]
php artisan language-pack:update [identifier] [--force] [--source=auto|bundled|github] [--yes|-y] [--allow-unsigned]   # 확인 질문 없음 — -y 는 다른 {type}:update 와 같은 형태로 부르기 위해 받음(효과 없음). GitHub 새 판이 미서명이면 --allow-unsigned

# 설치 상태 조회 (DB·캐시·파일 무변경, GitHub 조회 없음 — 스크립트는 --json, schema 1)
php artisan extension:list [--type=all|module|plugin|template|language-pack] [--json]

# 같은 판인데 파일이 다른 확장·언어팩의 활성 폴더를 번들 내용으로 덮어씀 (번들 → 활성 폴더, 직접 고친 파일은 사라짐, custom/ 보존, 판 번호·스텝·레이아웃 본문 그대로 — 빈 레이아웃 병합 기준만 교체 전 파일로 채움)
php artisan extension:reset-files-from-bundled [identifier?] [--type=module|plugin|template|language-pack] [--migrate] [--dry-run] [--json]   # 예전 이름 extension:sync-files 도 동작

# 실행 대기 마이그레이션의 SQL·파괴 여부·출처와 새 판의 업그레이드 스텝 (실행 없음)
php artisan g7:migrate-plan [--scope=all|core|extensions] [--json]

# storage 경로 분류 (운영 데이터·서버별 파일·업데이트 상태·임시 파일)
php artisan storage:paths [--json]

# 여러 서버 운영 (G7_RUNTIME_ROLE=stage 검수 서버 — docs/backend/runtime-role.md)
php artisan stage:apply-to-live [--dry-run] [--json] [--yes]        # 운영 서버에서: 검수 서버가 먼저 올린 판 마무리 — 파일 맞추기 → 레이아웃 갱신 keep → 미룬 정리 (번들 파일 → 이 서버, 검수 서버 데이터는 옮기지 않음, 실패 단계에서 멈춤)
# 운영 서버 배포 순서: 코드 배포 → (코어가 바뀌었으면) core:update → stage:apply-to-live --yes
php artisan extension:apply-deferred-cleanup [--dry-run] [--json]   # 운영 서버에서: 검수 서버가 미룬 선언 항목 정리 실행 (stage:apply-to-live 의 3단계, 파일 판·내용 지문이 같을 때만)
php artisan core:resync-declarations                               # 판과 무관하게 코어 선언(config/core.php)만 다시 맞춤 — 같은 판으로 코어 선언을 고친 배포에서
php artisan stage-layouts:reset-from-live [--yes]                   # 검수 서버에서: 운영 → 검수 서버로 레이아웃 덮어쓰기 (검수 서버 편집본·버전 기록 지워짐, 실행 전 건수 안내 — 검수 → 운영 올리기 기능은 없음)
# 검수 서버 갱신 순서: 업그레이드 스텝 → stage-layouts:reset-from-live --yes → <type>:refresh-layout (검수 서버를 처음 띄울 때도 reset-from-live 1회)

# Composer 의존성 (모듈/플러그인별 독립 vendor/)
php artisan extension:composer-install          # 모든 모듈+플러그인

# 오토로드
php artisan extension:update-autoload            # 오토로드 캐시 + 정적 훅 매핑 캐시 함께 재생성

# 정적 훅 매핑 캐시 (부팅 비용 절감 — route:cache 동형)
php artisan hooks:cache                           # bootstrap/cache/hooks.php 생성
php artisan hooks:clear                           # 캐시 삭제 (삭제 후 스캔 폴백 — 항상 안전)
```

### 종료 코드 (비대화형 실행·스크립트)

cron·스크립트는 화면 문구가 아니라 종료 코드로 성공을 판단한다. 코어 업데이트·확장 명령의 종료 코드는 다음과 같다.

| 코드 | 뜻 | 예 |
| --- | --- | --- |
| 0 | 성공 | 업데이트 완료, 업데이트 없음(GitHub 조회 성공 또는 릴리스 없음) |
| 1 | 실패 | 번들 확장 일괄 업데이트 중 하나라도 실패, 결과를 남기지 못한 일괄 업데이트 프로세스, `*:check-updates` 의 GitHub 조회 실패, `*:update --source=github` 의 GitHub 조회 실패, 답할 수 없는 확인 질문, 답할 수 없는 실행에서 미서명 패키지인데 `--allow-unsigned` 없음([패키지 서명](extension/package-signing.md#artisan-명령)), `extension:list` 설치 전 |
| 2 | 입력 오류 | 알 수 없는 옵션 값(`extension:list --type=…`·`extension:reset-files-from-bundled --type=…`) |
| 75 | 재개 필요 | `core:update`·`core:execute-upgrade-steps` 가 업그레이드 스텝 새 프로세스 재진입으로 멈춤 — 출력된 재개 명령(`core:execute-upgrade-steps --from=… --to=… --force`)을 이어서 실행 |

> 훅 캐시는 확장 install/update 및 코어 업데이트(`clearAllCaches` → `extension:update-autoload`) 시 자동 재생성됩니다. 코어 리스너 코드 배포 시(구독 훅·`sync` 옵션만 바뀐 경우 포함)에는 `hooks:cache` 수동 실행 — stale 캐시는 오류 없이 이전 구독으로 동작합니다. 상세: [extension/hooks.md "정적 훅 매핑 캐시"](extension/hooks.md).

### 운영 명령 (7.2.0)

```bash
# 패키지 서명 — 제작자 (서명 키는 한 번, 서명은 판마다)
php artisan extension:keygen --label=<서명자> [--out=<비밀키 파일>]            # 공개키(base64)·key_id 출력, 같은 경로에 파일이 있으면 실패
php artisan extension:sign <ZIP|패키지 디렉터리> --key=<비밀키 파일> [--type=] [--out=<디렉터리>] [--signed-at=]   # → <zip>.sig.json

# 패키지 서명 — 운영자 (다른 제작사의 공개키를 vendor 범위와 함께 신뢰)
php artisan extension:trust-key add "<공개키 base64>" --label=<서명자> --vendor=<vendor> [--vendor=…]   # vendor 1개 이상 필수, sirsoft 불가
php artisan extension:trust-key list
php artisan extension:trust-key remove <key_id>
php artisan settings:set security.extension_block_unsigned true   # 「미서명 패키지 차단」 — 쓰는 확장이 모두 서명된 판을 낸 뒤에 켠다

# 이미지 썸네일
php artisan thumbnails:prune [--days=N] [--dry-run]                  # 규격이 바뀐·원본이 사라진·N일 넘게 쓰이지 않은 썸네일 정리 (매일 05:20 자동)
php artisan thumbnails:pregenerate [--source=…] [--preset=…] [--limit=N] [--dry-run]   # 기존 이미지 썸네일 미리 만들기 (있는 것은 건너뜀)

# 큐 작업 기록 정리
php artisan queue:prune-records [--days=N]                           # 처리 기록·실패 작업 정리 (생략 시 환경설정 값, 기본 7일, 1~90 — 매일 04:10 자동)

# 예약 작업 러너 (서버 cron 에 걸 때 — 웹 크론과 같은 소급 규칙)
php artisan g7:schedule-run                                          # crontab: * * * * * cd /path/to/site && php artisan g7:schedule-run >> /dev/null 2>&1

# 개발자 화면 (custom 예약 자리)
php artisan custom:check [--json]                                    # 등록 상태 — 충돌·거부 라우트가 있으면 종료 코드 1
php artisan custom:example board                                     # 견본 파일 생성 (같은 이름 파일이 하나라도 있으면 아무것도 쓰지 않음)
php artisan custom:vendor-bundle [--check] [--force]                 # Composer 없는 서버용 custom/vendor-bundle.zip 만들기
php artisan custom:vendor-verify                                     # 묶음 무결성 확인

# 검색 인덱스
php artisan search:index [--json]                                    # 점검만 — 색인 누락이 남아 있으면 종료 코드 1
php artisan search:index --repair [--yes|-y]                         # 누락 인덱스 재생성 (잠금·재색인 — 유지보수 시간에). 재생성 뒤에도 남으면 표 재구축 명령을 안내

# 자산 주소 방식 (정적 최적화 블록이 .js·.css·.json 응답을 가로채 관리자 화면이 뜨지 않을 때)
php artisan g7:asset-url-mode [extension|extensionless]              # 생략하면 현재 방식과 진단 안내

# 점검 모드
php artisan down --secret=<값>                                       # /<값> 을 열면 우회 쿠키를 받고 들어간다 (7.2.0)
php artisan up

# 설정 때문에 관리 화면에 들어갈 수 없을 때
php artisan settings:set security.force_https false                  # 「HTTPS 강제 적용」 끄기 (프록시 뒤에서 이동이 끝없이 반복될 때)
php artisan settings:set drivers.file_delegation none                # 「웹서버 파일 위임」 끄기
php artisan security:ip-restriction --disable                        # 관리자 IP 접근 제한 끄기 (목록·규칙은 남음 — 아래 「IP 접근 제한」)
# 콘텐츠 보안 정책 강제 모드로 화면이 깨졌을 때: .env 에 G7_CSP_MODE=off(스크립트) · G7_CSP_STYLE_MODE=off(스타일, 7.1.6) 를 적고
php artisan config:clear                                             # 두 값은 서로 독립 — 관리자 설정보다 우선하고 카드의 모드 선택이 잠긴다
```

> 비대화형 실행(cron·CI·예약 작업·`--no-interaction`)의 확장·언어팩·코어 설치·업데이트는 미서명 패키지면 `--allow-unsigned` 가 있어야 진행합니다. `--force`·`--yes` 는 이 확인을 대신하지 않으며, 업그레이드 스텝 재진입으로 멈춘 코어 업데이트는 종료 코드 75 로 끝납니다([종료 코드](#종료-코드-비대화형-실행스크립트)).
>
> 상세: [패키지 서명](extension/package-signing.md) · [이미지 썸네일](backend/image-thumbnails.md#명령과-스케줄) · [큐 관리](backend/queue-management.md#3-보관-기간과-정리) · [스케줄 관리](backend/scheduling.md) · [개발자 화면](custom-development.md) · [검색 시스템](backend/search-system.md) · [점검 모드 통과 수단](backend/core-update-system.md#점검-모드-통과-수단과-해제-시점-720) · [HTTPS 강제 이동](backend/middleware.md#https-강제-이동-720) · [웹서버 파일 위임 복구](backend/file-serving-delegation.md#6-복구) · [CSP 탈출구](backend/content-security-policy.md#5-env-로-모드-고정-탈출구)

### 부트스트랩 리소스 정적 게시

```bash
# 상태 점검 — 이상 발견 시 비-0 종료 (실행 계정·버전·게시 여부·실패 마커·잔존 버전)
php artisan ext-static:status

# 수동 게시 (웹 계정으로 — root 로 실행하면 캐시 폴더가 root 소유가 되어 이후 웹 요청이 500 을 낼 수 있다)
sudo -u www-data php artisan ext-static:publish [--force]

# 구버전 게시 디렉토리 정리 (현재 + 직전 1개 보존, 스케줄 일 1회 자동)
php artisan ext-static:cleanup
```

> 관리자 화면에서는 환경설정 > 일반 「초기 화면 정적 파일」 카드에서 같은 상태를 보고 [지금 다시 만들기] 로 즉시 재게시할 수 있습니다. 상세: [backend/static-asset-publishing.md](backend/static-asset-publishing.md).

### 단발성 결함 보정 (hotfix)

`hotfix:*` prefix 는 특정 버전의 결함 회복을 위해 신설되는 단발성 도구를 위한 표준 prefix 다. `core:*` (영구 운영 도구) 와 명확히 구분되며 dev-dashboard 자동 노출 면제 대상이다.

```bash
# 코어 자동 롤백 후 활성 디렉토리에 잔존한 신 파일 진단/정리 (7.0.0-beta.6 신설)
php artisan hotfix:rollback-stale-files                              # 진단 모드 (실제 삭제 없음)
php artisan hotfix:rollback-stale-files --prune [--yes|-y]           # 정리 (확인 프롬프트 동반 — --yes 는 확인에 「예」로 답함. 답할 수 없는 실행은 --yes 가 없으면 정리하지 않고 종료 코드 1)
php artisan hotfix:rollback-stale-files --backup=<경로>              # 특정 백업 디렉토리 지정
```

진단 결과 / 정리 로그는 `storage/logs/hotfix_rollback_stale_files_<timestamp>.log` 에 기록.

### Vendor 번들 Artisan (공유 호스팅용 vendor/ 선탑재)

```bash
# 개별 빌드 (기존 *:build 패턴과 동일 — positional identifier + --all)
php artisan core:vendor-bundle [--check] [--force]
php artisan module:vendor-bundle [identifier] [--all] [--check] [--force]
php artisan plugin:vendor-bundle [identifier] [--all] [--check] [--force]

# 개별 무결성 검증
php artisan core:vendor-verify
php artisan module:vendor-verify [identifier] [--all]
php artisan plugin:vendor-verify [identifier] [--all]

# 일괄 알리아스 (운영/CI 전용)
php artisan vendor-bundle:build-all [--check] [--force]   # 코어 + 모든 _bundled
php artisan vendor-bundle:verify-all

# 옵션
# --check  : stale 여부만 확인 (CI 검증용, stale 시 종료 코드 1)
# --force  : 해시 체크 무시하고 강제 재빌드
# --all    : 모든 _bundled 확장 (식별자 대체, 미지정 시 명시적 에러)
```

> 상세 가이드: [docs/extension/vendor-bundle.md](extension/vendor-bundle.md)

### 학습용 샘플 확장 설치

```bash
# 학습용 최소 샘플 4종 (manifest.hidden=true → 관리자 UI 기본 제외)
php artisan module:install gnuboard7-hello_module
php artisan module:activate gnuboard7-hello_module
php artisan plugin:install gnuboard7-hello_plugin
php artisan plugin:activate gnuboard7-hello_plugin
php artisan template:install gnuboard7-hello_admin_template
php artisan template:install gnuboard7-hello_user_template

# 숨김 포함 목록 조회
php artisan module:list --hidden
php artisan plugin:list --hidden
php artisan template:list --hidden
```

> 상세: [extension/sample-extensions.md](extension/sample-extensions.md)

### SEO Artisan 커맨드

```bash
# SEO
php artisan seo:warmup              # SEO 캐시 워밍업
php artisan seo:warmup --layout=shop/show  # 특정 레이아웃만
php artisan seo:clear               # 전체 SEO 캐시 삭제
php artisan seo:clear --layout=home # 특정 레이아웃만
php artisan seo:stats               # 캐시 통계 출력
php artisan seo:generate-sitemap    # Sitemap 생성 (큐 디스패치, mode=auto)
php artisan seo:generate-sitemap --sync     # Sitemap 동기 생성
php artisan seo:generate-sitemap --rebuild  # 전체 재생성 (mode=full)
php artisan seo:generate-sitemap --mode=full|auto|incremental  # 재생성 모드 지정
```

### 성능 계측 Artisan 커맨드

```bash
# 4축(목록/화면/쓰기/배치) 성능 계측. 계측 대상은 코어 config/benchmark.php + 확장 getBenchmarkProfiles() 선언.
# 상세: docs/backend/benchmark.md
php artisan g7:bench --list-profiles                       # 등록된 프로파일 목록
php artisan g7:bench --profile=core/users_screen            # 화면 1장 응답 시간 + 쿼리 건수 + N+1 후보
php artisan g7:bench --axis=list                           # 축 단위
php artisan g7:bench --all --allow-write --report           # 전체 + 마크다운 리포트 (storage/app/benchmarks/)

# 깊은 OFFSET 계측 — 대량 합성 행을 시딩하므로 운영 데이터가 있는 환경에서 --seed/--fresh 를 쓰지 않는다.
php artisan --env=testing g7:bench --profile=sirsoft-board/board_posts --fresh --seed=200000
php artisan --env=testing g7:bench --profile=sirsoft-board/board_posts --offsets=0,20000,50000,199980 --runs=3 --explain
php artisan g7:bench --profile=sirsoft-ecommerce/orders --json   # 기계 판독용

# 데이터를 변경하는 축(write/batch/비-GET screen)은 --allow-write 없이 거부된다.
php artisan g7:bench --profile=sirsoft-ecommerce/order_create --allow-write
```

### 레이아웃 마크업 형식 Artisan 커맨드

```bash
# compile = JSON → HTML (소스 산출 · 이관용). 상세: docs/frontend/layout-markup.md
php artisan layout:compile <path>                  # 단일 .json → 짝 .g7.html 생성
php artisan layout:compile --all                   # 번들 확장·템플릿 전량 생성
php artisan layout:compile --all --roundtrip       # 생성 없이 왕복 동등성만 검사 (불일치·escape 집계)
php artisan layout:compile <path> --out=<file>     # 산출 경로 지정

# decompile = HTML → JSON (설치·검증). 짝 .json 은 손으로 고치지 않고 --sync 로 굽는다
php artisan layout:decompile <path>                # 단일 .g7.html 강검증 (기본이 검증 전용)
php artisan layout:decompile --all                 # 번들 확장·템플릿의 .g7.html 전량 강검증
php artisan layout:decompile <path> --sync         # 컴파일 산출을 짝 .json 에 기록 (짝이 없으면 생성)
php artisan layout:decompile --all --check         # 짝 .json 이 소스와 일치하는지 대조만 (불일치 시 non-zero)
php artisan layout:decompile <path> --out=<file>   # 진단용 단일 JSON 덤프 (--out 지정 시에만 기록)

# 설치된(DB) 레이아웃 점검
php artisan layout:check-installed [--template=<id>] [--json]   # 왕복 대조·조각 등록·routes.json requires 형식 오류 — 오류가 있으면 종료 코드 1 (requires 형식 오류는 경고, --json 의 route_requires_warnings)
php artisan layout:audit-expressions [--source=db|files|all] [--json] [--limit=50]   # 저장된 레이아웃·조각·버전 이력·미리보기와 레이아웃 파일을 강화된 보안 검사로 훑음 (값은 바꾸지 않음, 위반이 있으면 종료 코드 1, 7.1.0)
```

설치 소스는 기본 `auto`(`.g7.html` 우선 → 설치 시점 인메모리 컴파일)이며, 컴파일러 회귀가 의심될 때
`--layout-source=json` 으로 원본 `.json` 기준 설치로 되돌릴 수 있다 (install/update/refresh-layout 9개 커맨드 공통).

```bash
php artisan module:update <id> --force --layout-source=json     # 짝 .json 이 있으면 원본 JSON 으로 등록
php artisan template:refresh-layout <id> --layout-source=json   # 레이아웃만 원본 JSON 기준 재동기화
```

`{type}:update` 의 기존 `--source=auto|bundled|github` 는 **업데이트 패키지 출처**로 의미가 달라 별개 옵션이다.
짝 `.json` 이 없는 마크업 전용 레이아웃은 대안이 없으므로 `json` 강제에서도 컴파일되며, 로그에 미이행이 남는다
(조용히 누락시키면 등록이 사라져 빈 화면이 된다). 환경 기본값은 `.env` 의 `LAYOUT_INSTALL_SOURCE`.
### 의존성 취약점 점검 Artisan 커맨드

```bash
# 저장소의 모든 잠금파일(npm·composer) 운영 의존성 취약점 전수 점검.
# 루트만 감사하면 확장 전부가 사각이 되므로 하위 잠금파일까지 순회한다.
php artisan security:audit-dependencies                 # 전체 (npm + composer)
php artisan security:audit-dependencies --npm-only      # npm 잠금파일만
php artisan security:audit-dependencies --composer-only # composer 잠금파일만
php artisan security:audit-dependencies --json          # 기계 판독용
```

취약점이 발견되면 **비-0 으로 종료**한다. 이는 실행 실패가 아니라 조치 대상이 있다는 신호다.

### IP 접근 제한 점검·비상 해제 Artisan 커맨드

```bash
# 현재 설정과 신뢰 프록시 상태, 형식이 잘못돼 무시되는 항목까지 한 화면에 보여 준다.
php artisan security:ip-restriction --status

# 잠겼을 때 — 마스터 토글만 끈다. 허용 목록·규칙은 그대로 남아 다시 켜면 복구된다.
php artisan security:ip-restriction --disable
```

관리자 화면이 IP 제한으로 막혀 들어갈 수 없을 때의 **유일한 구제 경로**다(화면에도 같은 문장이 안내로 붙어 있다). 해제는 활동 로그에 시스템 기록으로 남는다. 설정 화면이 자기 잠금을 422 로 막지만, 프록시 구성이 바뀌어 접속 IP 자체가 달라진 경우처럼 저장 이후에 잠기는 길이 있어 이 커맨드를 둔다.

출력의 "대상 없음" 은 의존성이 없는 잠금파일이고, "점검 불가" 는 감사 도구가 실행되지 않은 것이다 — 후자는 "취약점 없음" 과 다르다.

동봉(vendored) 제3자 자산은 어떤 잠금파일에도 없어 감사 도구가 원리상 볼 수 없다. 그 축은 판정하지 않고 목록으로 함께 출력하므로 사람이 확인한다.

### API 문서 Artisan 커맨드

```bash
# API 레퍼런스 문서 생성/갱신 (실측 기반 스캐폴딩). 상세: docs/backend/api-documentation.md
php artisan api:docgen --scope=core                 # 코어 문서 생성 (범위: core|module:{id}|plugin:{id}|all)
php artisan api:docgen --scope=core --seed           # 실측용 완전 샘플 시드 후 생성 (개발 환경 전용)
php artisan api:docgen --scope=core --base-url=https://example.com  # 실측 기준 URL 지정
php artisan api:docgen --scope=core --examples-only  # 표·서술 불가침, 요청/응답 예시 블록만 in-place 삽입
php artisan api:docgen --scope=core --check           # 생성 없이 누락/drift 만 리포트 (자동 점검용)
php artisan api:docgen --scope=core --dry-run         # 생성 대상 파일/엔드포인트 목록만 출력

# 파라미터/응답 필드 설명 TODO 셀 in-place 백필 (재생성 없이 공통 필드 자동 서술, 멱등)
php artisan api:docgen-backfill-params               # 요청 파라미터 표 TODO 셀 백필
php artisan api:docgen-backfill-fields               # 응답 필드 표 TODO 셀 백필
```

---

## 확장 업데이트 (CLI + API)

```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--yes] [--force] [--no-backup] [--no-maintenance] # 코어 업데이트 실행 (cron, CI 처럼 확인에 답할 수 없으면 --yes)
php artisan core:bundled-sources list|place <id…>|prune [--force] [--include-modified] [--source=|--zip=]   # 공식 번들 소스 보고·배치·정리 (7.1.1+, prune 기본 미리보기)
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--yes|--force]   # 업그레이드 스텝 단독 실행 (HANDOFF 안내 또는 수동 복구용) — 비대화형 배포는 --yes(확인만), --force 는 같은 판 재실행·대장 무시

# CLI (Artisan 커맨드)
php artisan module:check-updates [identifier?]                   # 모듈 업데이트 확인
php artisan module:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 모듈 업데이트 실행
php artisan plugin:check-updates [identifier?]                   # 플러그인 업데이트 확인
php artisan plugin:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 플러그인 업데이트 실행
php artisan template:check-updates [identifier?]                 # 템플릿 업데이트 확인
php artisan template:update [identifier] [--force] [--layout-strategy=overwrite|keep]  # 템플릿 업데이트 실행
# 위 update 는 확인에 답할 수 없는 실행(cron, CI, 원격 명령)이면 --force 없을 때 사유를 출력하고 종료 코드 1

# API 엔드포인트
POST /api/admin/core-update/check                              # 코어 업데이트 확인
GET  /api/admin/core-update/changelog                          # 코어 변경 로그 조회
POST /api/admin/modules/check-updates                          # 모듈 업데이트 확인
POST /api/admin/modules/{moduleName}/update                    # 모듈 업데이트 실행
POST /api/admin/plugins/check-updates                          # 플러그인 업데이트 확인
POST /api/admin/plugins/{pluginName}/update                    # 플러그인 업데이트 실행
POST /api/admin/templates/check-updates                        # 템플릿 업데이트 확인
GET  /api/admin/templates/{templateName}/check-modified-layouts # 수정된 레이아웃 확인
POST /api/admin/templates/{templateName}/update                # 템플릿 업데이트 실행
```

> 상세: [extension/extension-update-system.md](extension/extension-update-system.md)

---

## 한국어 사용 규칙
```
한국어: 사용자 대상 텍스트, 주석, 문서, 커밋 메시지, DB comment
영어: 변수명, 함수명, 클래스명
Laravel 기본 메서드 주석은 영어 유지 (up(), down() 등)
```

### 한글 텍스트를 정규식으로 자를 때

PCRE 의 `\R` · `\v` · `\h` 는 **UTF-8 모드(`u` 수식자)가 아니면 바이트 단위로** 판정한다. 그래서 `\R` 은 CR·LF·CRLF 뿐 아니라 **0x85**(NEL)도 개행으로 본다. 3바이트 한글 중 마지막 바이트가 0x85 인 글자가 흔해(`종` = EC A2 85), 그 글자가 든 줄은 **글자 한가운데서 쪼개진다** — `preg_split('/\R/', "가\n종류\n나")` 는 3개가 아니라 **4개**를 돌려주고 앞 조각 끝에 깨진 2바이트가 남는다(실측).

예외도 경고도 나지 않는다. 운영자 입력을 줄 단위로 저장하는 자리에서는 깨진 항목이 그대로 저장되고, 소스를 줄로 갈라 주석을 걸러 내는 검사에서는 주석 뒷조각이 표식을 잃어 「주석은 보지 않는다」는 전제가 무너진다.

| 금지 | 올바른 사용 |
|------|------------|
| `preg_split('/\R/', $텍스트)` — 실제 줄 종결자만 볼 의도인데 `u` 수식자가 없음 | `preg_split('~\r\n|\r|\n~', $텍스트)` — 유니코드 개행까지 의도한 자리라면 `u` 수식자를 붙인다 |
| `\v` · `\h` 를 `u` 없이 공백 판정에 사용 | 같은 판단 — 셋 다 바이트 모드에서 비-ASCII 바이트를 삼킨다 |

정적 검사가 이 패턴을 차단한다. 의도적으로 필요한 자리는 그 이유를 주석으로 밝힌다.

### 소스에 원시 제어문자를 두지 않는다

같은 바이트 축의 함정이 한 겹 더 있다 — 이스케이프가 한 겹 풀려 **원시 제어문자**가 소스에 박히는 것이다. 문법은 그대로 유효하고 테스트도 린트도 통과하므로 **아무것도 울리지 않는다.** 그런데 도구 쪽에서 성질이 바뀐다: 원시 NUL(`0x00`)이 들어간 파일은 `grep`·`ripgrep` 이 **내용을 통째로 건너뛰고**(`Binary file … matches` 한 줄만 낸다) git 도 diff 를 `-	-` 로 가린다 — 그 파일이 이후 **모든 조사에서 조용히 사라진다**(실측: `grep -I` 가 0건으로 센다).

더 나쁜 것은 **의미가 조용히 달라지는** 경우다. PCRE 의 `\b`(단어 경계)가 `0x08`(백스페이스)로 풀리면 `(?![^\n]*\bbrowse\b)` 가 리터럴 백스페이스를 요구하게 되어 그 부정 선읽기는 **영원히 성립할 수 없다** — 가드가 통째로 무력화되는데 테스트는 계속 통과한다(실측).

| 금지 | 올바른 사용 |
|------|------------|
| 소스에 원시 제어문자(`0x00`–`0x08` · `0x0B` · `0x0C` · `0x0E`–`0x1F` · `0x7F` · 홀로 있는 CR) | 이스케이프 표기(`'\x00'` · `'\x01'` · `chr(8)`) — 런타임 값은 완전히 같고 소스만 읽을 수 있게 된다 |
| JSON 문자열의 **산문 자리**에 `\u000b` 같은 제어문자 이스케이프 | 설명 대상이 PCRE 이스케이프면 JSON 소스에 `\\v` 로 적는다(파싱되면 `\v`) — `\u000b` 는 파싱되면 **진짜 VT** 가 되어 같은 사고가 인코딩 뒤에 숨는다 |

정적 검사가 이 패턴을 차단한다. 의도적으로 필요한 자리는 그 이유를 주석으로 밝힌다.

## 코드 품질
### Laravel Pint

```bash
vendor/bin/pint --dirty
```

### PHPDoc

`/**`는 선언과 같은 깊이로 들여쓰고, 본문과 닫는 줄의 `*`는 그보다 한 칸 안쪽에 맞춘다. 주석을 일괄 생성하거나 수정한 뒤에도 변경 PHP 파일에 Pint를 적용하고 `--test`로 확인한다.

메서드는 접근 수준(public/protected/private)과 반환형(void 포함)에 관계없이 PHPDoc을 작성한다. 파라미터마다 이름이 일치하는 `@param`, 반환값에는 `@return`을 명시하고 한국어 설명을 붙인다. 생성자·소멸자는 `@return`만 생략한다. 상속 계약을 그대로 따르는 경우 `{@inheritDoc}`를 사용할 수 있다. 테스트는 테스트별 목적과 시나리오 매핑을 기록하며, 타입만 있는 태그가 Pint에서 제거되지 않도록 설명을 유지한다.

```php
/**
 * 상품을 생성합니다.
 *
 * @param array $data 상품 생성 데이터
 * @return Product 생성된 상품 모델
 * @throws \Exception 생성 실패 시
 */
public function createProduct(array $data): Product
```

## 빌드 vs 확장 업데이트 — 번들 구조·브라우저 하한·산출물 규칙
| 수정 파일 유형 | 필요한 작업 |
|---------------|-------------|
| `*.json` (레이아웃만) | `{type}:update {id} --force` 실행 |
| `*.tsx`, `*.ts` + `*.json` | `{type}:build` + `{type}:update {id} --force` |
| `*.tsx`, `*.ts`만 | `{type}:build` + `{type}:update {id} --force` |
| `lang-packs/_bundled/**` (번들 언어팩 콘텐츠/버전) | `language-pack:update {id} --force` (빌드 불필요) |

```bash
# 확장 업데이트 (_bundled → 활성 반영)
php artisan template:update sirsoft-admin_basic --force
php artisan module:update sirsoft-ecommerce --force
php artisan plugin:update sirsoft-payment --force
php artisan language-pack:update g7-core-ja --force
```

번들 언어팩도 `_bundled` 는 배포 원본일 뿐이다. 설치본(`lang-packs/{id}/`)을 갱신하지 않으면 새로 추가한 번역 키가 런타임에 존재하지 않아 해당 로케일이 조용히 기준 로케일로 폴백한다.

### 배포 산출물의 브라우저 하한

선언 하한은 **Chrome 111 / Safari 16.4 / Firefox 128** 이다 ([requirements.md §7](requirements.md)). 빌드 타깃(`target: 'es2020'`)은 이 하한을 강제하지 못한다 — **ES 연도와 브라우저 지원 연도가 다르기 때문**이다. ES2018 인 정규식 lookbehind 를 WebKit 은 Safari 16.4 에서야 구현했고, 타깃 검사는 그대로 통과시킨다.

정규식 **리터럴** 문법은 그중에서도 다운레벨이 원리상 불가능하다. 번들러는 lookbehind 를 `new RegExp(...)` 로 옮길 뿐이라 파싱 오류가 **런타임 오류로 이동**할 뿐 사라지지 않는다. 따라서 타깃 하향은 해법이 아니다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 배포 JS 산출물에 선언 하한 **초과** 문법·API (`Object.groupBy`·`Promise.withResolvers`·`Array.fromAsync`·`RegExp.escape`·정규식 `v` 플래그) | 하한 이하 문법으로 작성 |
| **부팅 임계 번들**(`public/build/core/template-engine.min.js`, `templates/_bundled/*/dist/js/components.iife.js`)에 정규식 리터럴 전용 문법(lookbehind `(?<!` `(?<=`, `v` 플래그) — 하한과 같은 버전이어도 | 그 두 파일만은 하한 미만 브라우저에서도 **파싱**돼야 한다 |
| 빌드 타깃을 낮춰 해결 시도 | 소스에서 그 문법을 쓰지 않는다 |
| 바이트 길이 비교식 번들러 검출기로 판정 | 문법·API 표 기반 검사 (프린터 표기 차이가 오탐을 낸다) |

부팅 임계 번들 둘은 `async`/`defer` 없는 동기 classic 스크립트다. 파싱에 실패하면 **폴백 안내 화면조차 렌더되지 않아** 사용자에게는 백지 또는 거짓 진단만 남는다. 하한 미만 브라우저에 "지원 범위 밖" 안내를 띄우려면 이 두 파일은 파싱에 성공해야 하므로, 하한과 **정확히 같은** 버전을 요구하는 문법(lookbehind = Safari 16.4)도 금지한다 — 하한 초과만 보는 검사로는 영원히 잡히지 않는 지점이다. 자동 검증이 이 규칙을 강제한다.

### 코어 3-번들 구조 + 공유 런타임 (engine-v1.51.0+)

`core:build` 는 코어 프론트엔드를 3개 IIFE 번들로 빌드한다:

| 번들 | 로드 시점 | vite config |
|------|----------|-------------|
| `template-engine.min.js` | 모든 페이지 (동기 `<script>`) | `vite.config.core.js` |
| `layout-editor.min.js` | `/admin/layout-editor/*` 진입 시 런타임 주입 | `vite.config.editor.js` |
| `devtools.min.js` | 디버그 모드에서만 런타임 주입 | `vite.config.devtools.js` |

lazy 번들(편집기/devtools)이 코어 런타임(DynamicRenderer·엔진 싱글톤·React Context·DevTools 코어)을 재사용할 때는 재번들하지 않고 `window.G7Core.__runtime` 을 빌려 쓴다 — React/컨텍스트/싱글톤 인스턴스 동일성이 강제되기 때문(사본이 둘이면 "Invalid hook call"·컨텍스트 미매칭). 메인 번들이 `G7CoreGlobals` 에서 공유 대상을 `G7Core.__runtime` 에 노출하고, lazy 번들 vite config 는 React 4종(`react`/`react-dom`/`react-dom/client`/`react/jsx-runtime`)을 external→window 로, 코어 런타임 모듈을 `resolveId` 플러그인으로 `__runtime-shims/` 로 치환한다.

### 확장 번들 병합 (서버측 concat)

활성 모듈/플러그인의 프론트엔드 IIFE JS·CSS 는 타입별로 서버에서 하나의 번들로 병합해 서빙한다(`/api/{modules,plugins}/bundle.{js,css}?v={version}`). `ExtensionBundleService` 가 정렬·필터·concat·캐시를 전담하고, 프론트는 `window.G7Config.bundleUrls` 를 읽어 모듈 번들 → 플러그인 번들 순으로 로드한다. 병합 규율:

- priority 순서는 선언형 — 실행 순서는 오직 manifest `loading.priority` 오름차순(`uasort`). 특정 확장 이름을 지목하는 분기를 두지 않는다.
- IIFE 사이는 `\n;\n`(JS)/`\n`(CSS) 로 잇는다. 미사용 시 ASI 경계가 깨져 번들 전체 파싱 에러가 난다.
- 소스맵은 prod strip, dev 는 개별 에셋 서빙 절대 URL 로 rewrite. 개별 에셋 서빙 라우트(`*.map` 포함)는 존치한다.
- 번들 URL 은 반드시 same-origin(`/api/...`). 외부 origin/CDN·protocol-relative 는 gdpr preblocker 에 자기 차단된다.
- 확장 에셋 절대경로는 `getBuiltAssetAbsolutePaths()`(=`getModulePath()`/`getPluginPath()`) 만 쓴다. `base_path("modules"|"plugins")` 직접 조립은 `_bundled` 경로 오해석 → 빈 번들.
- concat 루프는 확장별 try/catch — 실패 확장만 skip 하고 나머지 병합을 지속한다.
- 번들 파일명에 확장 캐시 버전을 포함(`{type}.{version}.{js,css}`). 조합 변경 시 version bump → 새 파일명 → 자동 재생성. 구파일 GC 는 `ext-bundles:cleanup` + `{module,plugin,template}:cache-clear` 가 담당한다. prod 은 version-in-path 디스크 캐시, 비프로덕션은 매 요청 concat.
- 프로덕션은 캐시 파일 존재를 **빌드보다 먼저** 확인한다. 캐시 키는 `(type, kind, version)` 만으로 계산되는데 빌드를 앞세우면 캐시 적중에도 매 요청 활성 확장 열거·파일 읽기가 일어나고, 원본이 소실되면 멀쩡한 캐시를 두고 503 이 된다. 캐시 미스는 같은 키의 잠금으로 1회 빌드에 수렴하고 잠금 뒤 캐시를 재확인하며, 잠금 대기 초과·저장소 장애는 실패가 아니라 각자 빌드로 폴백한다.
- 병합 결과가 비어도 선언 산출물이 전부 존재하거나 선언이 0이면 0바이트 캐시 파일을 만들어 정적 게시까지 간다. 만들지 않으면 그 구성의 자산 URL 이 API 로 폴백해 방문자의 모든 페이지 로드가 PHP 를 거친다. 캐시하지 않는 것은 산출물 소실(503 판정 보존)·병합 단계에서 건너뛴 확장이 있는 결과(굳지 않도록 매 요청 재시도)·디스크 쓰기 실패뿐이다.

### 빌드 명령어 (Artisan)

```bash
# 코어 템플릿 엔진 (resources/js/core/template-engine/**)
php artisan core:build                    # 기본: 템플릿 엔진만 빌드
php artisan core:build --watch            # 파일 감시 모드

# 모듈 빌드 (기본: _bundled 디렉토리)
php artisan module:build sirsoft-ecommerce          # _bundled에서 빌드
php artisan module:build --all                      # 모든 _bundled 모듈 빌드
php artisan module:build sirsoft-ecommerce --watch   # 활성 디렉토리에서 watch
php artisan module:build sirsoft-ecommerce --active   # 활성 디렉토리에서 빌드

# 템플릿 빌드 (기본: _bundled 디렉토리)
php artisan template:build sirsoft-admin_basic        # _bundled에서 빌드
php artisan template:build --all                      # 모든 _bundled 템플릿 빌드
php artisan template:build sirsoft-admin_basic --watch # 활성 디렉토리에서 watch
php artisan template:build sirsoft-admin_basic --active # 활성 디렉토리에서 빌드

# 플러그인 빌드 (기본: _bundled 디렉토리)
php artisan plugin:build sirsoft-payment              # _bundled에서 빌드
php artisan plugin:build --all                        # 모든 _bundled 플러그인 빌드
php artisan plugin:build sirsoft-payment --watch       # 활성 디렉토리에서 watch
php artisan plugin:build sirsoft-payment --active       # 활성 디렉토리에서 빌드
```

> **빌드 원칙**: 기본값은 `_bundled` 디렉토리. 빌드 결과물은 빌드 경로 내에만 남음.

`_bundled` 의 `dist/`(코어는 `public/build/core/`)는 Git 추적되는 배포 산출물이다 (`*.map` 만 ignore). src 변경 시 커밋 dist 를 `--production` 으로 동반 재빌드한다 — 신규 소스 리터럴이 dist 에 없으면 stale 빌드이며, 자동 검증이 이를 검출한다. 커밋 dist 에 `//# sourceMappingURL=` 참조를 남기지 않는다 — `.map` 은 배포본에 존재하지 않아 브라우저 개발자 도구에서 404 를 유발한다. 코어 3번들 재빌드는 `core:build --production` (`--full` 은 앱 번들 전용 — `public/build` 를 비워 코어 3번들을 지운다).

코어(`resources/js/core/**` → `public/build/core/*.js`)도 같은 의무를 진다. 소스만 고치고 번들을 다시 굽지 않으면 브라우저는 그 변경을 받지 못하고, 오류도 경고도 나지 않으며 변경 이력에는 「고쳤다」고 적힌 채 릴리즈된다. 코어는 리터럴이 아니라 **클래스 멤버 이름**으로 대조한다 — 최소화는 지역 이름만 줄이고 속성 키는 그대로 남으므로, 리터럴을 하나도 새로 만들지 않는 변경도 검출된다. 세 번들의 합집합과 대조하며, 그 파일의 이름 과반이 번들에 있을 때만 판정한다(번들에 실리지 않은 파일을 흔한 이름 하나로 오판하지 않기 위해서다).

### 빌드는 자기 산출물만 교체한다 (`emptyOutDir`)

모든 vite config 는 `build.emptyOutDir: false` 를 **명시**한다. 기본값 `true` 는 산출물 디렉토리를 통째로 비우는데, 그 디렉토리에는 vite 가 만들지 않는 서빙 자산이 함께 산다.

| 함께 지워지던 것 | 결과 |
|---|---|
| `public/build/core/` 3번들 | 폴백이 없다 — `template-engine.min.js` 는 동기 classic 스크립트라 소실 = **사이트 부팅 불가**, 안내 화면조차 렌더되지 않는다 |
| `public/build/ext/{v}/` 게시본 | 이미 배달된 HTML 의 immutable URL 이 404. 재게시로 새 버전이 생겨도 **그 URL 은 복구되지 않는다** |
| 확장 `dist/vendor/` | 확장이 동봉한 구동 제3자 자산 소실 (자체 제공 원칙 위반) |

소실은 예외도 서버 로그도 남기지 않는다 — 브라우저 404 로만 나타나므로 운영자에게는 흔적이 없다. 잔존하는 구 해시 산출물은 `manifest.json`(또는 고정 파일명)이 선택하므로 참조되지 않는 사표이고, 정리 책임은 빌드 커맨드가 진다.

빌드 커맨드의 산출물 정리는 **활성 디렉토리를 건너뛴다.** 정리는 빌드 *전에* 돌므로 웹이 서빙 중인 `dist/` 를 비우면 빌드 완료까지가 통째로 서빙 공백이 되고, 빌드가 실패하면 빈 채로 남는다. 정리 대상은 `_bundled` / `_pending` 소스 디렉토리뿐이다.

자동 검증이 모든 vite config 의 명시 선언을 강제한다 (기본값 의존 금지 — 규약이 코드에 남지 않으면 다음 편집자가 같은 결함을 재도입한다).
> 활성 디렉토리 반영은 `update` 커맨드로만 수행. `--watch` 모드는 실시간 개발용으로 활성 디렉토리를 자동 사용.

## 확장 시스템 Artisan 명령어 (요약)
```bash
# 코어 업데이트
php artisan core:check-updates                                    # 코어 업데이트 확인
php artisan core:update [--yes] [--force] [--no-backup] [--no-maintenance] # 코어 업데이트 실행 (cron, CI 처럼 확인에 답할 수 없으면 --yes)
php artisan core:bundled-sources list|place <id…>|prune [--force] [--include-modified] [--source=|--zip=]   # 공식 번들 소스 보고·배치·정리 (7.1.1+, prune 기본 미리보기)
php artisan core:execute-upgrade-steps --from=X.Y.Z --to=A.B.C [--yes|--force]  # 업그레이드 스텝 단독 실행 (HANDOFF 안내/수동 복구용 — 사전·사후 단계 자동 수행)

# 모듈
php artisan module:list
php artisan module:install [identifier]
php artisan module:activate [identifier]
php artisan module:deactivate [identifier]
php artisan module:uninstall [identifier]
php artisan module:composer-install [identifier?] [--all]
php artisan module:cache-clear [identifier?]
php artisan module:seed [identifier] [--sample] [--count=key=value]
php artisan module:check-updates [identifier?]
php artisan module:update [identifier] [--force] [--source=auto|bundled|github]

# 플러그인
php artisan plugin:list
php artisan plugin:install [identifier]
php artisan plugin:activate [identifier]
php artisan plugin:deactivate [identifier]
php artisan plugin:uninstall [identifier]
php artisan plugin:composer-install [identifier?] [--all]
php artisan plugin:cache-clear [identifier?]
php artisan plugin:seed [identifier] [--sample] [--count=key=value]
php artisan plugin:check-updates [identifier?]
php artisan plugin:update [identifier] [--force] [--source=auto|bundled|github]

# 템플릿
php artisan template:list
php artisan template:install [identifier]
php artisan template:activate [identifier]
php artisan template:deactivate [identifier]
php artisan template:uninstall [identifier]
php artisan template:cache-clear
php artisan template:check-updates [identifier?]
php artisan template:update [identifier] [--layout-strategy=overwrite|keep] [--force] [--source=auto|bundled|github]

# Composer 의존성 (모듈/플러그인별 독립 vendor/)
php artisan extension:composer-install

# 오토로드
php artisan extension:update-autoload
```

## SEO Artisan 커맨드
```bash
php artisan seo:warmup [--layout=]
php artisan seo:clear [--layout=]
php artisan seo:stats
php artisan seo:generate-sitemap [--sync]
```

## 코드 스타일/마이그레이션 명령어
```bash
# 코드 스타일 (Laravel Pint)
vendor/bin/pint --dirty

# 마이그레이션
php artisan make:migration create_[table]_table
php artisan migrate
php artisan migrate:rollback
```

## 참고 파일 위치
- **AbstractModule**: `app/Extension/AbstractModule.php`
- **HookManager**: `app/Extension/HookManager.php`
- **ModuleManager**: `app/Extension/ModuleManager.php`
- **PluginManager**: `app/Extension/PluginManager.php`
- **TemplateManager**: `app/Extension/TemplateManager.php`
- **CoreStorageDriver**: `app/Extension/Storage/CoreStorageDriver.php`
- **ResponseHelper**: `app/Helpers/ResponseHelper.php`
- **ExtensionStatusGuard**: `app/Extension/Helpers/ExtensionStatusGuard.php`
- **ExtensionBackupHelper**: `app/Extension/Helpers/ExtensionBackupHelper.php`
- **ExtensionPendingHelper**: `app/Extension/Helpers/ExtensionPendingHelper.php`
- **ExtensionRoleSyncHelper**: `app/Extension/Helpers/ExtensionRoleSyncHelper.php`
- **ExtensionMenuSyncHelper**: `app/Extension/Helpers/ExtensionMenuSyncHelper.php`
- **SettingsMigrator**: `app/Extension/Helpers/SettingsMigrator.php`
- **UpgradeStepInterface**: `app/Contracts/Extension/UpgradeStepInterface.php`
- **UpgradeContext**: `app/Extension/UpgradeContext.php`
- **SeoRenderer**: `app/Seo/SeoRenderer.php`
- **SeoMiddleware**: `app/Seo/SeoMiddleware.php`
- **SeoCacheManager**: `app/Seo/SeoCacheManager.php`
- **SeoServiceProvider**: `app/Seo/SeoServiceProvider.php`
- **SitemapContributorInterface**: `app/Seo/Contracts/SitemapContributorInterface.php`
- **SitemapGenerator**: `app/Seo/SitemapGenerator.php`
- **ActivityLogChannel**: `app/ActivityLog/ActivityLogChannel.php`
- **ActivityLogHandler**: `app/ActivityLog/ActivityLogHandler.php`
- **ActivityLogProcessor**: `app/ActivityLog/ActivityLogProcessor.php`
- **ResolvesActivityLogType**: `app/ActivityLog/Traits/ResolvesActivityLogType.php`
- **ChangeDetector**: `app/ActivityLog/ChangeDetector.php`
- **CoreActivityLogListener**: `app/Listeners/CoreActivityLogListener.php`
- **BenchmarkProfileRegistry**: `app/Benchmark/BenchmarkProfileRegistry.php`
- **성능 계측 DTO**: `app/Benchmark/DTO/{BenchmarkProfile,BenchmarkRunOptions,BenchmarkResult}.php`
- **BenchmarkAxisRunner**: `app/Benchmark/Contracts/BenchmarkAxisRunner.php`
- **성능 계측 축 실행기**: `app/Benchmark/Axes/{List,Screen,Write,Batch}AxisRunner.php`
- **BenchmarkAxis**: `app/Enums/BenchmarkAxis.php`
