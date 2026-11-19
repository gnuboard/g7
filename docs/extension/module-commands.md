# 모듈 Artisan 커맨드

> **관련 문서**: [README.md](README.md) | [module-basics.md](module-basics.md) | [module-routing.md](module-routing.md)

---

## TL;DR (5초 요약)

```text
1. 목록: php artisan module:list
2. 설치: php artisan module:install [identifier] (Composer 의존성 자동 설치 포함)
3. 활성화: php artisan module:activate [identifier]
4. 비활성화: php artisan module:deactivate [identifier]
5. Composer: php artisan module:composer-install [identifier] (수동 의존성 설치)
6. 삭제: php artisan module:uninstall [identifier] (--delete-data 시 vendor/ 삭제 포함)
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [사용 가능한 커맨드](#사용-가능한-커맨드)
- [커맨드 구현 규칙](#커맨드-구현-규칙)
- [커맨드 테스트](#커맨드-테스트)

---

## 핵심 원칙

### 모듈 Artisan 커맨드 규칙

```
필수: 모듈 관련 모든 작업은 Artisan 커맨드로 수행
✅ 규칙: 직접 DB 조작 또는 파일 시스템 변경 금지
```

모듈의 설치, 활성화, 비활성화, 삭제 등 모든 생명주기 관리는 반드시 Artisan 커맨드를 통해 수행해야 합니다. 이를 통해:
- 일관된 상태 관리
- 적절한 이벤트 발생
- 권한/메뉴 등 연관 데이터 관리
- 로그 기록

### 종료 코드와 경고 표식 (7.1.3~)

설치·활성화·비활성화·제거·업데이트·레이아웃 갱신 명령(`module:*`·`plugin:*`·`template:*`·`language-pack:*`)은 작업을 마쳤어도 일부 단계(레이아웃 등록, 언어팩 시더, 정리 단계 등)가 실패했으면 완료 줄을 ✅ 대신 ⚠️ 로 찍고 「단계·대상·내용」 표를 출력한다.

| 결과 | 종료 코드 | 출력 |
| --- | --- | --- |
| 완료 | 0 | — |
| 완료했지만 실패한 단계가 있음 | 1 | 경고 표 뒤에 `[g7-lifecycle-warnings] count=N` 한 줄 |
| 실패 | 1 | 표식 없음 (경고가 있어도 명령이 실패로 끝났으면 표식을 내지 않는다) |

- 배포 스크립트는 종료 코드 1 이면서 출력에 `[g7-lifecycle-warnings]` 가 있으면 「경고와 함께 완료」, 없으면 실패로 가른다.
- 실패가 아닌 안내만 있으면 표만 보이고 종료 코드는 0 이다.
- 코어 업데이트(`core:update`)와 그 자식 명령은 같은 표를 보이되 종료 코드를 바꾸지 않는다 — 끝난 업데이트를 자동화가 실패로 보지 않게 하기 위해서다.

### 확장이 만든 커맨드를 예약 작업으로 돌릴 때

예약 작업은 확장이 `getSchedules()` 로 선언한다. 그 선언에 적힌 커맨드는 **서버 명령(SSH)을 쓸 수
있는 환경인지와 무관하게** 실행된다 — 코어가 선언된 이름을 그 확장의 커맨드 클래스로 해석해 Artisan
이 뜰 때 함께 등록하기 때문이다.

```php
public function getSchedules(): array
{
    return [
        [
            'command' => 'vendor-module:prune-temp',   // 이 이름만 등록 대상이 된다
            'schedule' => 'daily',
            'description' => '임시 파일 정리',
        ],
    ];
}
```

확장 쪽에서 따로 할 일은 없다. ServiceProvider 에서 `$this->app->runningInConsole()` 안에 커맨드를
등록하는 Laravel 관례를 그대로 써도 된다 — 그 관례만 따르면 웹 요청 안에서 도는 예약 실행(주소 호출
방식)에서는 커맨드가 등록되지 않아 작업이 매번 실패하는데, 코어가 그 자리를 메운다.

**등록 대상은 선언한 이름뿐이다.** 확장 소스에 있는 다른 커맨드는 등록되지 않는다. 선언하지 않은
커맨드를 예약 실행에서 쓰려면 `getSchedules()` 에 적어야 한다.

커맨드 클래스를 관례 밖 위치에 두었거나 조회를 건너뛰고 싶으면 선언에 클래스를 직접 적는다.

```php
[
    'command' => 'vendor-module:prune-temp',
    'schedule' => 'daily',
    'command_class' => \Modules\Vendor\Module\Console\PruneTempCommand::class,
]
```

선언한 이름을 코어가 찾지 못하면 그 사실이 기록으로 남는다(조용히 건너뛰지 않는다). 코어가 소유한
커맨드를 확장이 예약하는 경우도 그 목록에 오르는데, 그 커맨드는 코어가 이미 등록하므로 정상이다.

---

## 사용 가능한 커맨드

### 모듈 관리 커맨드

> 검수 서버(`G7_RUNTIME_ROLE=stage`)에서는 `module:activate`·`module:deactivate`·`module:uninstall`(플러그인도 같음)이 안내 후 종료 1 이다 — 공유 DB 의 확장 상태를 바꾼다. 설치·업데이트·레이아웃 갱신은 실행한다 ([runtime-role.md](../backend/runtime-role.md)).

#### 모듈 목록 조회

```bash
php artisan module:list
```

**옵션**:
- `--status=active`: 활성화된 모듈만 표시
- `--status=inactive`: 비활성화된 모듈만 표시
- `--status=uninstalled`: 미설치 모듈만 표시

**출력 예시**:
```
+--------------------+----------+---------+
| Identifier         | Version  | Status  |
+--------------------+----------+---------+
| sirsoft-ecommerce  | 1.0.0    | active  |
| sirsoft-blog       | 1.2.0    | inactive|
| vendor-sample      | 0.1.0    | -       |
+--------------------+----------+---------+
```

#### 모듈 설치

```bash
php artisan module:install [identifier] [--vendor-mode=auto|composer|bundled] [--force] [--layout-source=auto|json] [--with-dependencies] [--with-language-packs=none|all|식별자,…] [--rebuild-search-index]
```

**옵션**:
- `--vendor-mode`: vendor 설치 방식 (`auto` 기본, `composer`, `bundled`)
- `--force`: 이미 설치된 경우에도 `_bundled`/`_pending` 원본으로 활성 디렉토리를 덮어쓰고 재설치 (불완전 설치 복구)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`, 생략하면 설정 `LAYOUT_INSTALL_SOURCE` 를 따름)
- `--with-dependencies` (7.1.4): 필요한 의존 확장(의존의 의존 포함)을 같은 vendor 방식으로 먼저 설치·활성화한다. 이 옵션 없이 의존이 모자라면 무엇이 왜 모자란지와 이 옵션 안내를 출력하고 종료 코드 1. 본 확장 설치가 실패해도 앞서 설치·활성화한 의존 확장은 되돌리지 않고 그 사실을 알린다
- `--with-language-packs` (7.1.4): 함께 설치할 번들 언어팩 — `none`(기본) · `all` · 쉼표로 나눈 식별자. 후보는 관리자 설치 창과 같다. `all` 은 지금 설치할 수 없는 언어팩(같은 언어의 코어 언어팩이 꺼져 있음, 대상 확장 버전이 언어팩 요구보다 낮음 등)을 사유와 함께 건너뛴다. 후보에 없는 식별자를 주면 설치 전에 실패한다. 고른 언어팩이 하나라도 설치에 실패하면 모듈 설치는 유지한 채 종료 코드 1
- `--rebuild-search-index`: 완료 후 색인이 누락된 검색 인덱스를 재생성 (운영 중에는 유지보수 시간에)

**수행 작업**:
- `_pending/_bundled` 디렉토리에서 활성 디렉토리로 복사 (ExtensionPendingHelper)
- `modules` 테이블에 모듈 등록
- 역할(Role) 등록
- 권한(Permission) 등록
- 메뉴(Menu) 등록
- 마이그레이션 실행 (있는 경우)
- 시더 실행 (있는 경우)
- Composer 의존성 설치 (외부 패키지가 있는 경우, Phase 4.5)

**예시**:
```bash
php artisan module:install sirsoft-ecommerce
php artisan module:install sirsoft-ecommerce --with-dependencies --with-language-packs=all
```

> `plugin:install` 도 같은 `--with-dependencies`·`--with-language-packs` 옵션을 받는다.

#### 모듈 활성화

```bash
php artisan module:activate [identifier]
```

**수행 작업**:
- `modules.status`를 `active`로 변경
- soft deleted된 레이아웃 복원
- 모듈 레이아웃 등록 (admin 템플릿에)
- 모듈의 라우트 활성화
- 모듈의 훅 리스너 활성화
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**
- 이 모듈을 끌 때 함께 꺼진 언어팩을 함께 켜고 개수와 언어를 출력 (`--without-language-packs` 를 붙이면 켜지 않고 목록만 출력 — 관리자 화면 「언어팩 다시 켜기」 창에서 나중에 켤 수 있음)

**옵션**:
- `--force`: 필요한 의존 확장이 없거나 꺼져 있거나 버전이 모자라도 강제로 활성화
- `--without-language-packs`: 함께 꺼진 언어팩을 다시 켜지 않음

#### 모듈 비활성화

```bash
php artisan module:deactivate [identifier]
```

**수행 작업**:
- `modules.status`를 `inactive`로 변경
- 모듈 레이아웃 soft delete
- 모듈의 라우트 비활성화
- 모듈의 훅 리스너 비활성화
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**

#### 모듈 삭제

```bash
php artisan module:uninstall [identifier]
```

**옵션**:
- `--force`: 확인 프롬프트 없이 즉시 삭제
- `--yes`: 확인 질문만 생략 (7.2.0 — 자동화용)
- `--delete-data`: 데이터도 함께 삭제 (동적 테이블 + 마이그레이션 롤백 + 스토리지)

**수행 작업**:
- 확인 프롬프트 표시 (--force 미사용 시). 확인에 답할 수 없는 실행(cron, CI, 원격 명령, `--no-interaction`)은 삭제하지 않은 사유와 `--yes`·`--force` 안내를 출력하고 종료 코드 1 로 끝남. 터미널에서 「아니오」를 답하면 취소 문구와 종료 코드 0
- `--delete-data` 사용 시 (트랜잭션 외부, DDL):
  - `getDynamicTables()` 반환 테이블 삭제 (Manager가 처리)
  - 마이그레이션 롤백 (정적 테이블 삭제)
  - 모듈 스토리지 디렉토리 삭제 (`storage/app/modules/{identifier}/`)
  - Composer vendor/ 디렉토리 및 composer.lock 삭제
- 역할(Role) 삭제
- 권한(Permission) 삭제
- 메뉴(Menu) 삭제
- 레이아웃(Layout) 영구 삭제
- 활성 디렉토리 삭제 (`_bundled` 원본은 보존)
- `modules` 테이블에서 완전 삭제 (hard delete)
- **템플릿 언어/routes 캐시 무효화**
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**

**예시**:
```bash
# 확인 프롬프트 표시
php artisan module:uninstall sirsoft-ecommerce

# 강제 삭제
php artisan module:uninstall sirsoft-ecommerce --force
```

#### 모듈 Composer 의존성 설치

```bash
php artisan module:composer-install [identifier]
```

**옵션**:

- `--all`: 모든 설치된 모듈의 Composer 의존성 설치
- `--no-dev`: dev 의존성 제외

**예시**:

```bash
# 특정 모듈 의존성 설치
php artisan module:composer-install sirsoft-ecommerce

# 모든 모듈 의존성 설치
php artisan module:composer-install --all

# dev 의존성 제외
php artisan module:composer-install sirsoft-ecommerce --no-dev
```

**수행 작업**:

- 모듈의 `composer.json`에 외부 패키지(`require`)가 있는지 확인
- `modules/{identifier}/` 디렉토리에서 `composer install` 실행
- `modules/{identifier}/vendor/` 디렉토리 생성
- 오토로드 캐시 파일 갱신 (`vendor_autoloads` 반영)

**사용 시점**:

- 모듈 설치 시 자동 실행되지만, 수동으로 재설치가 필요한 경우
- vendor/ 디렉토리가 손상되거나 삭제된 경우
- 새로운 패키지를 composer.json에 추가한 후

#### 모듈 업데이트 확인

```bash
php artisan module:check-updates [identifier?]
```

**인수**:
- `identifier` (선택): 특정 모듈만 확인

**기능**:

- **전체 확인** (인수 없음): 설치된 모든 모듈의 업데이트 가용 여부를 테이블로 출력
  - 감지: GitHub·`_bundled` 중 높은 버전(같으면 GitHub, 저장소 URL 이 없으면 `_bundled`), `_pending` 미참여
  - DB에 `update_available`, `latest_version`, `update_source` 갱신
- **단일 확인** (인수 있음): 특정 모듈의 업데이트 가용 여부 확인
  - `checkModuleUpdate()`는 DB 갱신 안 함 → 커맨드에서 `updateByIdentifier()` 직접 호출
- **GitHub 조회 실패** (7.2.0~): 연결 실패·요청 한도 초과(403)·5xx 등으로 조회하지 못한 모듈이 있으면 실패 표(식별자·사유)를 출력하고 **종료 코드 1**. 그 모듈의 DB 업데이트 정보(`update_available`·`latest_version`·`update_source`)는 덮지 않는다 — `_bundled` 에 더 새 판이 있어도 그 판은 출력에만 보인다. 릴리스가 아직 없는 저장소(404)는 실패가 아니라 「업데이트 없음」이다. 상세: [GitHub 조회 실패](extension-update-system.md#github-조회-실패-check_error-720)

**예시**:
```bash
# 모든 모듈 업데이트 확인
php artisan module:check-updates

# 특정 모듈만 확인
php artisan module:check-updates sirsoft-ecommerce
```

#### 모듈 업데이트

```bash
php artisan module:update [identifier] [--force] [--yes] [--source=auto|bundled|github] [--zip=] [--signature=] [--allow-unsigned] [--layout-strategy=overwrite|keep] [--vendor-mode=auto|composer|bundled] [--layout-source=auto|json] [--dry-run [--json]] [--decisions=<파일>] [--rebuild-search-index]
```

**인수**:
- `identifier` (필수): 업데이트할 모듈 식별자

**옵션**:
- `--force`: 강제 업데이트 — 확인 생략·같은 판 재적용·다운그레이드 허용·코어 호환 검사 생략·같은 판 업그레이드 스텝 재실행·멈춘 업데이트 정리 (파일 손상, 수동 수정 복원 시 사용)
- `--yes`(`-y`): 확인 질문만 생략 (`--force` 의 다른 효과 없음, 자동화용)
- `--source`: 업데이트 소스 강제 (`auto` 기본 = GitHub·`_bundled` 중 높은 버전(같으면 GitHub, URL 없음이면 `_bundled`, GitHub 조회에 실패하면 `_bundled` 기준으로 판정하고 경고 한 줄), `bundled` = `_bundled` 단독(GitHub 안 봄), `github` = GitHub 단독(`_bundled` 폴백 없음 — 조회에 실패하면 「업데이트 없음」 대신 사유를 출력하고 **종료 코드 1**, 7.2.0~))
- `--zip`: 외부 ZIP 파일을 소스로 사용 (GitHub·번들 우회, `--source` 와 함께 쓸 수 없음 — [extension-update-system.md](extension-update-system.md#외부-zip-직접-지정---zip))
- `--signature` (7.2.0): `--zip` 의 서명 파일(`.sig.json`) 경로. 생략하면 ZIP 옆 `<zip>.sig.json` 을 자동으로 쓴다
- `--allow-unsigned` (7.2.0): 미서명(또는 이 서버에서 검증할 수 없는) 패키지를 확인 없이 계속. 환경설정 「미서명 패키지 차단」이 켜져 있으면 무시
- `--layout-strategy`: 운영자 수정 레이아웃·확장 조각 처리 (`overwrite` = 전체 덮어쓰기, `keep` = 고친 부분은 유지하고 손대지 않은 부분에 새 버전을 병합 — 결과 표 출력)
- `--vendor-mode`: vendor 설치 방식 (`auto` 기본, `composer`, `bundled`)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`, 생략하면 설정 `LAYOUT_INSTALL_SOURCE` 를 따름 — 문제 해결용)
- `--dry-run` (7.2.0): 업데이트하지 않고 관리자 [업데이트] 창과 같은 판정만 출력 — 화면별 레이아웃 판정(유지·새 판 반영·충돌 등)과 새 판에서 빠져 지워질 선언 항목(메뉴·권한 등, 운영자 수정 여부 표시). DB·파일은 바꾸지 않는다. `--json` 을 함께 주면 JSON 한 줄(`dry_run: true`)로 출력
- `--decisions=<파일>` (7.2.0): 화면별 결정 JSON — `{"<화면 키>": "take-theirs|keep-ours"}`. 화면 키는 `--dry-run` 출력의 키 칸(JSON 은 `entries[].key`). `take-theirs` 는 그 화면만 새 판으로 덮고(덮기 직전 운영자 판은 버전 기록에 남음), `keep-ours` 는 운영자 판을 그대로 둔다. 값 상세: [extension-update-system.md 「화면별 결정 값」](extension-update-system.md#keep--조각-단위-3자-병합)
- `--rebuild-search-index`: 완료 후 색인이 누락된 검색 인덱스를 재생성 (인덱스가 잠기거나 재색인되므로 운영 중에는 유지보수 시간에)

**수행 작업**:
- 업데이트 가용 여부 확인 (없으면 info + SUCCESS, `--force` 시 무시하고 진행)
- 확인 프롬프트 → 업데이트 실행 (`--force`·`--yes` 면 묻지 않음. 확인에 답할 수 없는 실행(cron, CI, 원격 명령, `--no-interaction`)은 시작하지 않은 사유와 `--yes` 안내를 출력하고 종료 코드 1, 터미널에서 「아니오」를 답하면 종료 코드 0)
- GitHub·`--zip` 소스면 백업 전에 패키지 서명 검증 (7.2.0). 미서명이면 계속할지 한 번 더 묻고, 답할 수 없는 실행은 `--allow-unsigned` 없이 종료 코드 1 — `--force` 는 이 확인을 대신하지 않는다. 서명 불일치·신뢰하지 않는 키는 늘 실패. 상세: [패키지 서명](package-signing.md#artisan-명령)
- 상태 가드 확인 → 백업 → status: Updating → 파일 교체 → 마이그레이션 → 업그레이드 스텝 → 복원
- 실패 시 백업 복원 안내

**`keep` 결과 표** — 운영자가 고친 화면이 있으면 업데이트 뒤 표와 요약 한 줄이 출력된다(보고할 화면이 없으면 출력 없음):

```text
레이아웃 처리 결과 (수정 유지)
+------------------------------------------+----------+----------------------------+-----------+------+
| 화면                                     | 종류     | 결과                       | 자동 반영 | 겹침 |
+------------------------------------------+----------+----------------------------+-----------+------+
| sirsoft-admin_basic · admin_module_list  | 레이아웃 | 새 버전 변경 일부 반영     | 1         | 0    |
| sirsoft-admin_basic · admin_user_detail:board_tab | 확장 조각 | 겹쳐서 운영자 것 유지 | 0         | 1    |
+------------------------------------------+----------+----------------------------+-----------+------+
레이아웃: 새 버전 변경 1곳 자동 반영, 운영자 수정과 겹친 1곳은 운영자 것 유지.
```

`overwrite` 는 업데이트 전에 덮어쓸 운영자 수정 화면(레이아웃·확장 조각) 목록을 경고로 보여 준다. 병합 규칙: [extension-update-system.md §10](extension-update-system.md#10-레이아웃-충돌-전략-3종-공통)

**예시**:
```bash
php artisan module:update sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --force  # 강제 재설치
php artisan module:update sirsoft-ecommerce --source=bundled  # GitHub 장애/잘못된 태그 우회
php artisan module:update sirsoft-ecommerce --source=github --force  # 긴급 핫픽스 태그로만 강제 설치
php artisan module:update acme-shop --zip=/tmp/acme-shop-1.2.0.zip  # ZIP 옆 acme-shop-1.2.0.zip.sig.json 으로 서명 검증
php artisan module:update acme-shop --force --allow-unsigned --no-interaction  # 예약 실행 — 미서명 판도 받는다
php artisan module:update sirsoft-ecommerce --layout-strategy=keep --dry-run  # 무엇이 바뀌고 지워지는지 먼저 확인
php artisan module:update sirsoft-ecommerce --layout-strategy=keep --dry-run --json > preview.json  # 스크립트용 판정
php artisan module:update sirsoft-ecommerce --layout-strategy=keep --decisions=decisions.json --yes  # 화면별 결정대로 실행
```

#### 모듈 레이아웃 갱신

```bash
php artisan module:refresh-layout [identifier?] [--layout-strategy=keep|overwrite] [--dry-run [--json]] [--decisions=<파일>] [--layout-source=auto|json]
```

판 번호를 바꾸지 않고 모듈 파일의 레이아웃·확장 조각을 DB 에 다시 맞춥니다(파일만 교체한 뒤 등).

**인수**:
- `identifier` (선택): 특정 모듈만 갱신. 생략하면 모든 활성 모듈

**옵션**:
- `--layout-strategy` (7.2.0 기본 `keep`): `keep` 은 운영자가 고친 화면을 지키고 손대지 않은 부분에만 새 판을 병합, `overwrite` 는 파일 판으로 덮는다. 종전처럼 덮으려면 `--layout-strategy=overwrite` 를 준다
- `--dry-run`: 판정만 출력하고 DB 는 바꾸지 않음 — 식별자 필수. 운영자 수정과 겹친 화면이 있어도 종료 코드 0 (`--json` 이면 `has_conflicts` 로 낸다)
- `--json`: 결과를 JSON 한 줄로 출력
- `--decisions=<파일>`: 화면별 결정 JSON (`take-theirs`·`keep-ours`, `module:update` 와 같은 형식)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`)

**출력**: 갱신한 모듈마다 판정별 화면 수와 운영자 수정과 겹친 화면 목록. 식별자 없이 전체를 갱신해도 모듈마다 같은 꼴로 보이며, 실패한 모듈이 하나라도 있으면 종료 코드 1.

**예시**:
```bash
php artisan module:refresh-layout sirsoft-board --dry-run              # 판정 확인
php artisan module:refresh-layout sirsoft-board                        # 운영자 수정 유지(keep)
php artisan module:refresh-layout sirsoft-board --layout-strategy=overwrite  # 파일 판으로 덮기
```

> 플러그인은 `plugin:update`·`plugin:refresh-layout` 이 같은 옵션과 동작을 가진다. 플러그인 커맨드 목록: [plugin-development.md](plugin-development.md)

#### 모듈 캐시 초기화

```bash
php artisan module:cache-clear
```

**옵션**:
- `[identifier]`: 특정 모듈만 캐시 삭제

**예시**:
```bash
# 모든 모듈 캐시 삭제
php artisan module:cache-clear

# 특정 모듈 캐시만 삭제
php artisan module:cache-clear sirsoft-ecommerce
```

#### 모듈 프론트엔드 에셋 빌드

```bash
php artisan module:build [identifier]
```

> **기본 빌드 경로**: `_bundled` 디렉토리. 빌드 결과물은 빌드 경로 내에만 남음.
> 활성 디렉토리 반영은 `module:update` 커맨드로만 수행.

**옵션**:

- `--all`: 모든 모듈의 에셋 빌드 (_bundled 기준, --active 시 활성 기준)
- `--watch`: 파일 변경 감시 모드 (자동으로 활성 디렉토리 사용)
- `--production`: 프로덕션 최적화 빌드
- `--active`: 활성 디렉토리에서 빌드 (_bundled 대신)

**예시**:
```bash
# _bundled에서 빌드 (기본값)
php artisan module:build sirsoft-ecommerce

# 모든 _bundled 모듈 빌드
php artisan module:build --all

# 프로덕션 빌드 (_bundled)
php artisan module:build sirsoft-ecommerce --production

# 파일 감시 모드 (활성 디렉토리에서 자동 실행)
php artisan module:build sirsoft-ecommerce --watch

# 활성 디렉토리에서 빌드
php artisan module:build sirsoft-ecommerce --active

# 빌드 후 활성 디렉토리 반영
php artisan module:update sirsoft-ecommerce
```

**수행 작업**:

- 빌드 경로 결정: _bundled 우선 → 활성 폴백 (--active 시 활성만)
- npm 의존성 설치 (node_modules 없는 경우)
- Vite를 통한 IIFE 번들 빌드
- `dist/js/module.iife.js` 및 `dist/css/module.css` 생성
- 빌드 결과 파일 크기 출력
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**
  - 주의: `--watch` 모드에서는 캐시 버전이 증가하지 않음

**참고**: [module-assets.md](./module-assets.md) 문서에서 에셋 빌드 설정 상세 참조

#### 모듈 시더 실행

```bash
php artisan module:seed [identifier]
```

**옵션**:

- `--class=SeederClass`: 특정 시더 클래스만 실행
- `--sample`: 샘플 데이터 시더도 함께 실행 (기본: 설치 시더만)
- `--count=key=value`: 시더에 카운트 옵션 전달 (반복 가능)
- `--force`: 프로덕션 환경에서 강제 실행

**예시**:

```bash
# 단일 모듈 시더 실행 (설치 시더만)
php artisan module:seed sirsoft-ecommerce

# 설치 + 샘플 시더 실행
php artisan module:seed sirsoft-ecommerce --sample

# 모든 활성 모듈 시더 실행
php artisan module:seed

# 특정 시더 클래스 실행
php artisan module:seed sirsoft-ecommerce --class=Sample\\ProductSeeder

# 샘플 + 카운트 옵션
php artisan module:seed sirsoft-ecommerce --sample --count=products=1000

# 프로덕션 환경에서 강제 실행
php artisan module:seed sirsoft-ecommerce --force
```

**수행 작업**:

- 활성화된 모듈만 실행 가능 (비활성 모듈은 에러)
- `modules/{identifier}/database/seeders/DatabaseSeeder.php` 실행
- 기본 실행 시 설치 필수 시더만 실행, `--sample` 옵션 시 Sample/ 하위 시더도 실행
- `--class` 옵션 사용 시 해당 시더만 실행
- `--sample` 옵션은 `HasSampleSeeders` 트레이트를 통해 시더에 전파

**주의사항**:

- 활성화되지 않은 모듈은 시더 실행 불가
- 프로덕션 환경에서는 `--force` 옵션 필수
- 샘플 시더는 `database/seeders/Sample/` 하위 디렉토리에 위치

---

## 커맨드 구현 규칙

### 1. 다국어 메시지 필수

```php
// ✅ DO: 다국어 메시지 사용
$this->info(__('modules.commands.install.success', ['module' => $identifier]));

// ❌ DON'T: 하드코딩 메시지
$this->info('모듈 설치 성공');
```

### 2. 로그 기록 필수

```php
use Illuminate\Support\Facades\Log;

// 성공 로그
Log::info(__('modules.commands.install.success', ['module' => $identifier]));

// 에러 로그
Log::error('모듈 설치 실패', [
    'module' => $identifier,
    'error' => $e->getMessage(),
]);
```

### 3. 예외 처리

```php
public function handle(): int
{
    try {
        // 커맨드 로직

        $this->info('✅ ' . __('modules.commands.install.success', ['module' => $identifier]));
        return Command::SUCCESS;
    } catch (\Exception $e) {
        $this->error('❌ ' . $e->getMessage());
        Log::error('모듈 설치 실패', [
            'module' => $identifier,
            'error' => $e->getMessage(),
        ]);
        return Command::FAILURE;
    }
}
```

### 4. 상세 정보 출력

```php
// 모듈 정보 출력
$this->info('   - ' . __('modules.commands.install.version', ['version' => $module->version]));
$this->info('   - ' . __('modules.commands.install.permissions_created', ['count' => $permissionsCount]));
$this->info('   - ' . __('modules.commands.install.menus_created', ['count' => $menusCount]));
```

---

## 커맨드 테스트

### Feature 테스트 작성 필수

모든 커맨드에 대해 Feature 테스트를 작성해야 합니다.

#### 설치 테스트

```php
public function test_can_install_module_via_command(): void
{
    // Arrange
    $identifier = 'sirsoft-sample';

    // Act
    $this->artisan('module:install', ['identifier' => $identifier])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => $identifier,
        'status' => ExtensionStatus::Inactive->value,
    ]);
}
```

#### 삭제 확인 프롬프트 테스트

```php
public function test_uninstall_requires_confirmation(): void
{
    // Arrange
    $module = Module::factory()->create(['identifier' => 'test-module']);

    // Act & Assert
    $this->artisan('module:uninstall', ['identifier' => 'test-module'])
        ->expectsConfirmation(
            __('modules.commands.uninstall.confirm', ['module' => 'test-module']),
            'no'
        )
        ->assertSuccessful();

    // Module should still exist
    $this->assertDatabaseHas('modules', ['identifier' => 'test-module']);
}
```

#### 활성화/비활성화 테스트

```php
public function test_can_activate_module(): void
{
    // Arrange
    $module = Module::factory()->create([
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Inactive,
    ]);

    // Act
    $this->artisan('module:activate', ['identifier' => 'test-module'])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Active->value,
    ]);
}

public function test_can_deactivate_module(): void
{
    // Arrange
    $module = Module::factory()->create([
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Active,
    ]);

    // Act
    $this->artisan('module:deactivate', ['identifier' => 'test-module'])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('modules', [
        'identifier' => 'test-module',
        'status' => ExtensionStatus::Inactive->value,
    ]);
}
```

---

## 모듈 업데이트 (API + CLI)

모듈 업데이트는 **API 엔드포인트** 또는 **Artisan 커맨드**를 통해 수행됩니다.

### Artisan 커맨드

```bash
# 업데이트 확인
php artisan module:check-updates              # 전체
php artisan module:check-updates [identifier]  # 단일

# 업데이트 실행
php artisan module:update [identifier]

# 미리보기 (DB·파일 무변경)
php artisan module:update [identifier] --dry-run [--json]
```

### API 엔드포인트

```text
POST /api/admin/modules/check-updates
POST /api/admin/modules/{moduleName}/update
```

- 감지: GitHub·`_bundled` 중 높은 버전(같으면 GitHub, URL 없음이면 `_bundled`), `_pending` 미참여
- GitHub 조회 실패(7.2.0~): `check-updates` 는 실패 표 + 종료 코드 1 이고 그 모듈의 DB 업데이트 정보를 덮지 않는다. `update --source=github` 도 종료 코드 1 — [GitHub 조회 실패](extension-update-system.md#github-조회-실패-check_error-720)
- 상태 가드 확인 → 백업 → status: Updating → 파일 교체 → 마이그레이션 → 업그레이드 스텝 → 복원
- 에러 발생 시 자동 롤백 (백업에서 복원)

> 상세: [extension-update-system.md](extension-update-system.md)

---

## 관련 문서

- [모듈 개발 기초](module-basics.md) - AbstractModule, 디렉토리 구조
- [모듈 라우트 규칙](module-routing.md) - 라우트 네이밍, 자동 Prefix
- [모듈 레이아웃 시스템](module-layouts.md) - 레이아웃 등록, 오버라이드
- [권한 시스템](permissions.md) - 모듈 Role/Permission 자동 관리
- [확장 업데이트 시스템](extension-update-system.md) - 업데이트 감지/실행 전체 흐름
