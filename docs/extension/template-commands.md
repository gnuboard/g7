# 템플릿 Artisan 커맨드

> 템플릿 관련 모든 작업은 Artisan 커맨드를 통해 수행합니다.

---

## TL;DR (5초 요약)

```text
1. 목록: php artisan template:list
2. 설치: php artisan template:install [identifier]
3. 활성화: php artisan template:activate [identifier]
4. 비활성화: php artisan template:deactivate [identifier]
5. 빌드: php artisan template:build [identifier]
```

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [사용 가능한 커맨드](#사용-가능한-커맨드)
3. [커맨드 구현 규칙](#커맨드-구현-규칙)
4. [커맨드 테스트](#커맨드-테스트)
5. [문제 해결](#문제-해결)
6. [관련 문서](#관련-문서)

---

## 핵심 원칙

```
필수: 템플릿 관련 모든 작업은 Artisan 커맨드로 수행
✅ 규칙: 직접 DB 조작 또는 파일 시스템 변경 금지
```

### 종료 코드와 경고 표식 (7.1.3~)

설치·활성화·비활성화·제거·업데이트·레이아웃 갱신 명령은 작업을 마쳤어도 일부 단계가 실패했으면 완료 줄을 ✅ 대신 ⚠️ 로 찍고 「단계·대상·내용」 표를 출력한 뒤 **종료 코드 1** 과 `[g7-lifecycle-warnings] count=N` 한 줄로 끝난다. 배포 스크립트는 종료 코드 1 이면서 이 표식이 있으면 「경고와 함께 완료」, 없으면 실패로 가른다. 명령이 실패로 끝났으면 경고가 있어도 표식을 내지 않는다. 실패가 아닌 안내만 있으면 표만 보이고 종료 코드는 0 이다. 상세: [module-commands.md 「종료 코드와 경고 표식」](module-commands.md#종료-코드와-경고-표식-713)

---

## 사용 가능한 커맨드

> 검수 서버(`G7_RUNTIME_ROLE=stage`)에서는 `template:activate`·`template:deactivate`·`template:uninstall` 이 안내 후 종료 1 이다 — 같은 종류의 운영 활성 템플릿을 끄는 등 공유 DB 의 상태를 바꾼다. 설치·업데이트·레이아웃 갱신은 실행한다 ([runtime-role.md](../backend/runtime-role.md)).

### template:list

템플릿 목록을 조회합니다.

```bash
php artisan template:list
```

**기능**:
- 설치된 모든 템플릿 표시
- 활성화 상태, 타입, 버전 정보 포함

---

### template:install

템플릿을 설치합니다.

```bash
php artisan template:install [vendor-template] [--force] [--layout-source=auto|json] [--with-dependencies] [--with-language-packs=none|all|식별자,…]
```

**옵션**:
- `--force`: 이미 설치된 경우에도 `_bundled`/`_pending` 원본으로 활성 디렉토리를 덮어쓰고 재설치 (불완전 설치 복구)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`, 생략하면 설정 `LAYOUT_INSTALL_SOURCE` 를 따름)
- `--with-dependencies` (7.1.4): 템플릿이 요구하는 모듈·플러그인(의존의 의존 포함)을 먼저 설치·활성화한다. 이 옵션 없이 의존이 모자라면 무엇이 왜 모자란지와 이 옵션 안내를 출력하고 종료 코드 1. 템플릿 설치가 실패해도 앞서 설치·활성화한 의존 확장은 되돌리지 않고 그 사실을 알린다
- `--with-language-packs` (7.1.4): 함께 설치할 번들 언어팩 — `none`(기본) · `all` · 쉼표로 나눈 식별자. 후보는 관리자 설치 창과 같다. `all` 은 지금 설치할 수 없는 언어팩을 사유와 함께 건너뛴다. 후보에 없는 식별자를 주면 설치 전에 실패한다. 고른 언어팩이 하나라도 설치에 실패하면 템플릿 설치는 유지한 채 종료 코드 1

**기능**:
- `templates` 테이블에 템플릿 등록
- Vite 빌드 실행 (`npm run build`)
- 컴포넌트 레지스트리 캐시 생성
- 다국어 파일 검증

---

### template:activate

템플릿을 활성화합니다.

```bash
php artisan template:activate [vendor-template]
```

**기능**:
- `templates.is_active` 플래그 설정
- 동일 타입의 기존 활성 템플릿 비활성화
- 상태 캐시 무효화 (`ext.templates.active_identifiers*` / `ext.templates.installed_identifiers`)
- 이 템플릿을 끌 때 함께 꺼진 언어팩을 함께 켜고 개수와 언어를 출력 (`--without-language-packs` 를 붙이면 켜지 않고 목록만 출력)

**옵션**:
- `--force`: 필요한 의존 확장이 없거나 꺼져 있거나 버전이 모자라도 강제로 활성화. 조건을 맞출 때까지 그 템플릿의 화면이 503 안내가 된다(관리자 템플릿이면 관리자 화면도 — 이 명령으로 이전 관리자 템플릿을 다시 켜서 되돌린다)
- `--without-language-packs`: 함께 꺼진 언어팩을 다시 켜지 않음

---

### template:deactivate

템플릿을 비활성화합니다.

```bash
php artisan template:deactivate [vendor-template]
```

**옵션**:
- `--force`: 유형(관리자·사용자)의 마지막 활성 템플릿도 끈다. 끄면 그 화면이 그려지지 않으므로 복구 작업처럼 의도한 경우에만 쓴다 (7.2.0~)

**기능**:
- 유형의 마지막 활성 템플릿은 `--force` 없이 끄지 않고 사유와 `--force` 안내를 출력한 뒤 종료 코드 1 (관리자 화면과 같은 판정, 7.2.0~). 보통은 같은 유형의 다른 템플릿을 활성화해 바꾼다
- `--force` 로 마지막 활성 템플릿을 끄면 「활성 템플릿 없음」 경고를 낸다
- `templates.is_active` 플래그 해제
- 관련 캐시 무효화

---

### template:uninstall

템플릿을 삭제합니다.

```bash
php artisan template:uninstall [vendor-template]
```

**옵션**:
- `--force`: 확인 질문 없이 삭제
- `--yes`: 확인 질문만 생략 (7.2.0 — 자동화용)
- `--delete-data`: 「데이터도 삭제」를 선택한 것으로 제거 (활동 로그, 제거 구독 확장에 전달)

**기능**:
- 확인 질문 (`--force` 미사용 시). 확인에 답할 수 없는 실행(cron, CI, 원격 명령, `--no-interaction`)은 삭제하지 않은 사유와 `--yes`·`--force` 안내를 출력하고 종료 코드 1, 터미널에서 「아니오」를 답하면 종료 코드 0
- 유형(관리자·사용자)의 마지막 활성 템플릿은 지우지 않고 종료 코드 1 (`--force` 로도 건너뛰지 않음, 관리자 화면과 같은 판정). 같은 유형의 다른 템플릿을 먼저 활성화한다 (7.2.0~)
- 켜진 템플릿은 비활성화(직전·완료 훅 포함)를 먼저 거친 뒤 제거 (7.2.0~)
- 템플릿 데이터 삭제 (`templates`, `template_layouts`)
- 의존성 있는 템플릿 확인 후 경고
- 캐시 전체 무효화

---

### template:check-updates

템플릿 업데이트를 확인합니다.

```bash
php artisan template:check-updates [identifier?]
```

**인수**:
- `identifier` (선택): 특정 템플릿만 확인

**기능**:

- **전체 확인** (인수 없음): 설치된 모든 템플릿의 업데이트 가용 여부를 테이블로 출력
  - 감지: GitHub·`_bundled` 중 높은 버전(같으면 GitHub, 저장소 URL 이 없으면 `_bundled`), `_pending` 미참여
  - DB에 `update_available`, `latest_version`, `update_source` 갱신
- **단일 확인** (인수 있음): 특정 템플릿의 업데이트 가용 여부 확인
  - `checkTemplateUpdate()`는 DB 갱신 안 함 → 커맨드에서 `updateByIdentifier()` 직접 호출
- **GitHub 조회 실패** (7.2.0~): 연결 실패·요청 한도 초과(403)·5xx 등으로 조회하지 못한 템플릿이 있으면 실패 표(식별자·사유)를 출력하고 **종료 코드 1**. 그 템플릿의 DB 업데이트 정보(`update_available`·`latest_version`·`update_source`)는 덮지 않는다 — `_bundled` 에 더 새 판이 있어도 그 판은 출력에만 보인다. 릴리스가 아직 없는 저장소(404)는 실패가 아니라 「업데이트 없음」이다. 상세: [GitHub 조회 실패](extension-update-system.md#github-조회-실패-check_error-720)

**예시**:
```bash
# 모든 템플릿 업데이트 확인
php artisan template:check-updates

# 특정 템플릿만 확인
php artisan template:check-updates sirsoft-admin_basic
```

---

### template:update

템플릿을 업데이트합니다.

```bash
php artisan template:update [identifier] [--layout-strategy=overwrite|keep] [--force] [--yes] [--source=auto|bundled|github] [--zip=] [--signature=] [--allow-unsigned] [--layout-source=auto|json] [--dry-run [--json]] [--decisions=<파일>]
```

**인수**:
- `identifier` (필수): 업데이트할 템플릿 식별자

**옵션**:
- `--layout-strategy=overwrite`: 레이아웃 전략 (`overwrite` = 전체 덮어쓰기 | `keep` = 운영자 수정본에 새 버전 3자 병합 + 결과 표)
- `--force`: 강제 업데이트 — 확인 생략·같은 판 재적용·다운그레이드 허용·코어 호환 검사 생략·같은 판 업그레이드 스텝 재실행·멈춘 업데이트 정리 (파일 손상, 수동 수정 복원 시 사용)
- `--yes`(`-y`): 확인 질문만 생략 (`--force` 의 다른 효과 없음, 자동화용)
- `--source`: 업데이트 소스 강제 (`auto` 기본 = GitHub·`_bundled` 중 높은 버전(같으면 GitHub, URL 없음이면 `_bundled`, GitHub 조회에 실패하면 `_bundled` 기준으로 판정하고 경고 한 줄), `bundled` = `_bundled` 단독(GitHub 안 봄), `github` = GitHub 단독(`_bundled` 폴백 없음 — 조회에 실패하면 「업데이트 없음」 대신 사유를 출력하고 **종료 코드 1**, 7.2.0~))
- `--zip`: 외부 ZIP 파일을 소스로 사용 (GitHub·번들 우회, `--source` 와 함께 쓸 수 없음)
- `--signature` (7.2.0): `--zip` 의 서명 파일(`.sig.json`) 경로. 생략하면 ZIP 옆 `<zip>.sig.json` 을 자동으로 쓴다
- `--allow-unsigned` (7.2.0): 미서명(또는 이 서버에서 검증할 수 없는) 패키지를 확인 없이 계속. 답할 수 없는 실행은 이 옵션 없이 미서명이면 종료 코드 1, `--force` 는 대신하지 않음. 상세: [패키지 서명](package-signing.md#artisan-명령)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`, 생략하면 설정 `LAYOUT_INSTALL_SOURCE` 를 따름 — 문제 해결용)
- `--dry-run` (7.2.0): 업데이트하지 않고 관리자 [업데이트] 창과 같은 판정만 출력 — 화면별 레이아웃 판정(유지·새 판 반영·충돌 등)과 새 판에서 빠져 지워질 선언 항목(운영자 수정 여부 표시). DB·파일은 바꾸지 않는다. `--json` 을 함께 주면 JSON 한 줄(`dry_run: true`)로 출력
- `--decisions=<파일>` (7.2.0): 화면별 결정 JSON — `{"<화면 키>": "take-theirs|keep-ours"}`. 화면 키는 `--dry-run` 출력의 키 칸(JSON 은 `entries[].key`). `take-theirs` 는 그 화면만 새 판으로 덮고(덮기 직전 운영자 판은 버전 기록에 남음), `keep-ours` 는 운영자 판을 그대로 둔다. 값 상세: [extension-update-system.md 「화면별 결정 값」](extension-update-system.md#keep--조각-단위-3자-병합)

**수행 작업**:
- 레이아웃 전략 검증 (`overwrite` | `keep` 외 FAILURE)
- 업데이트 가용 여부 확인 (없으면 info + SUCCESS, `--force` 시 무시하고 진행)
- `overwrite` 전략 시 덮어쓸 운영자 수정 화면 목록 경고 (`hasModifiedLayouts()`)
- 확인 프롬프트 → 업데이트 실행 (`--force`·`--yes` 면 묻지 않음. 확인에 답할 수 없는 실행(cron, CI, 원격 명령, `--no-interaction`)은 시작하지 않은 사유와 `--yes` 안내를 출력하고 종료 코드 1, 터미널에서 「아니오」를 답하면 종료 코드 0)
- `keep` 전략이면 「레이아웃 처리 결과 (수정 유지)」 표 + 요약 한 줄
- 템플릿이 비활성이면 레이아웃 행은 두고 새 버전 원본만 기준으로 저장 → 활성화할 때 병합
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
# 기본 전략(overwrite)으로 업데이트
php artisan template:update sirsoft-admin_basic

# 운영자 수정본에 새 버전 병합 (수정 유지)
php artisan template:update sirsoft-admin_basic --layout-strategy=keep

# 강제 재설치
php artisan template:update sirsoft-admin_basic --force

# GitHub 장애/잘못된 태그 우회 — _bundled 단독 사용
php artisan template:update sirsoft-admin_basic --source=bundled

# 무엇이 바뀌고 지워지는지 먼저 확인 (DB·파일 무변경)
php artisan template:update sirsoft-admin_basic --layout-strategy=keep --dry-run

# 화면별 결정대로 실행
php artisan template:update sirsoft-admin_basic --layout-strategy=keep --decisions=decisions.json --yes
```

---

### template:refresh-layout

판 번호를 바꾸지 않고 템플릿 파일의 레이아웃을 DB 에 다시 맞춥니다(파일만 교체한 뒤 등).

```bash
php artisan template:refresh-layout [identifier?] [--layout-strategy=keep|overwrite] [--dry-run [--json]] [--decisions=<파일>] [--layout-source=auto|json]
```

**인수**:
- `identifier` (선택): 특정 템플릿만 갱신. 생략하면 모든 활성 템플릿

**옵션**:
- `--layout-strategy` (7.2.0 기본 `keep`): `keep` 은 운영자가 고친 화면을 지키고 손대지 않은 부분에만 새 판을 병합, `overwrite` 는 파일 판으로 덮는다. 종전처럼 덮으려면 `--layout-strategy=overwrite` 를 준다
- `--dry-run`: 판정만 출력하고 DB 는 바꾸지 않음 — 식별자 필수. 운영자 수정과 겹친 화면이 있어도 종료 코드 0 (`--json` 이면 `has_conflicts` 로 낸다)
- `--json`: 결과를 JSON 한 줄로 출력
- `--decisions=<파일>`: 화면별 결정 JSON (`take-theirs`·`keep-ours`, `template:update` 와 같은 형식)
- `--layout-source`: 레이아웃 설치 소스 (`auto`·`json`)

**출력**: 갱신한 템플릿마다 판정별 화면 수와 운영자 수정과 겹친 화면 목록. 식별자 없이 전체를 갱신해도 템플릿마다 같은 꼴로 보이며, 실패한 템플릿이 하나라도 있으면 종료 코드 1.

**예시**:
```bash
php artisan template:refresh-layout sirsoft-admin_basic --dry-run                       # 판정 확인
php artisan template:refresh-layout sirsoft-admin_basic                                 # 운영자 수정 유지(keep)
php artisan template:refresh-layout sirsoft-admin_basic --layout-strategy=overwrite     # 파일 판으로 덮기
```

---

### template:cache-clear

레이아웃 캐시를 초기화합니다.

```bash
php artisan template:cache-clear
```

**기능**:
- 모든 템플릿 관련 캐시 삭제
  - 활성 템플릿 캐시
  - 레이아웃 JSON 캐시
  - 컴포넌트 레지스트리 캐시

---

### template:build

템플릿의 프론트엔드 에셋을 빌드합니다 (기본: _bundled 디렉토리).

```bash
php artisan template:build [identifier]
```

> **기본 빌드 경로**: `_bundled` 디렉토리. 빌드 결과물은 빌드 경로 내에만 남음.
> 활성 디렉토리 반영은 `template:update` 커맨드로만 수행.

**옵션**:

- `--all`: 모든 템플릿의 에셋 빌드 (_bundled 기준, --active 시 활성 기준)
- `--watch`: 파일 변경 감시 모드 (자동으로 활성 디렉토리 사용)
- `--production`: 프로덕션 최적화 빌드
- `--active`: 활성 디렉토리에서 빌드 (_bundled 대신)

**예시**:

```bash
# _bundled에서 빌드 (기본값)
php artisan template:build sirsoft-admin_basic

# 모든 _bundled 템플릿 빌드
php artisan template:build --all

# 프로덕션 빌드 (_bundled)
php artisan template:build sirsoft-admin_basic --production

# 파일 감시 모드 (활성 디렉토리에서 자동 실행)
php artisan template:build sirsoft-admin_basic --watch

# 활성 디렉토리에서 빌드
php artisan template:build sirsoft-admin_basic --active

# 빌드 후 활성 디렉토리 반영
php artisan template:update sirsoft-admin_basic
```

**수행 작업**:

- 빌드 경로 결정: _bundled 우선 → 활성 폴백 (--active 시 활성만)
- npm 의존성 설치 (node_modules 없는 경우)
- Vite를 통한 컴포넌트 번들 빌드
- `dist/components.iife.js` 생성
- 빌드 결과 파일 크기 출력
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**
  - 주의: `--watch` 모드에서는 캐시 버전이 증가하지 않음

---

## 커맨드 구현 규칙

### 1. 다국어 메시지 필수

```php
// ✅ DO: 다국어 메시지 사용
$this->info(__('templates.commands.install.success', ['template' => $identifier]));

// ❌ DON'T: 하드코딩 메시지
$this->info('템플릿 설치 성공');
```

### 2. 로그 기록 필수

```php
use Illuminate\Support\Facades\Log;

// 성공 로그
Log::info(__('templates.commands.install.success', ['template' => $identifier]));

// 에러 로그
Log::error('템플릿 설치 실패', [
    'template' => $identifier,
    'error' => $e->getMessage(),
]);
```

### 3. 예외 처리 패턴

```php
public function handle(): int
{
    try {
        // 커맨드 로직

        $this->info('✅ ' . __('templates.commands.install.success', ['template' => $identifier]));
        return Command::SUCCESS;
    } catch (\Exception $e) {
        $this->error('❌ ' . $e->getMessage());
        Log::error('템플릿 설치 실패', [
            'template' => $identifier,
            'error' => $e->getMessage(),
        ]);
        return Command::FAILURE;
    }
}
```

### 4. 상세 정보 출력

```php
// 템플릿 정보 출력
$this->info('   - ' . __('templates.commands.install.type', ['type' => __('templates.types.' . $template->type)]));
$this->info('   - ' . __('templates.commands.install.version', ['version' => $template->version]));
$this->info('   - ' . __('templates.commands.install.layouts_created', ['count' => $template->layouts()->count()]));
```

---

## 커맨드 테스트

Feature 테스트 작성이 필수입니다.

```php
public function test_can_install_template_via_command(): void
{
    // Arrange
    $identifier = 'test-template';

    // Act
    $this->artisan('template:install', ['identifier' => $identifier])
        ->assertSuccessful();

    // Assert
    $this->assertDatabaseHas('templates', [
        'identifier' => $identifier,
        'is_active' => false,
    ]);
}
```

---

## 문제 해결

### 컴포넌트가 렌더링되지 않음

**원인**: ComponentRegistry에 미등록

**해결**:
1. `components.json`에 컴포넌트 추가
2. `npm run build` 실행
3. 캐시 초기화: `php artisan template:cache-clear`

---

### 레이아웃 JSON 저장 실패

**원인**: 검증 규칙 위반

**해결**:
1. 에러 메시지 확인 (FormRequest 검증)
2. 엔드포인트 화이트리스트 확인
3. 컴포넌트명 오타 확인
4. JSON 구조 검증 (최대 깊이 10)

---

### Vite 빌드 에러

**원인**: 의존성 누락 또는 TypeScript 오류

**해결**:
```bash
# 의존성 재설치
npm install

# TypeScript 체크
npm run type-check

# 빌드 로그 확인
npm run build -- --debug
```

---

## 관련 문서

- [템플릿 기초](template-basics.md) - 템플릿 타입, 메타데이터
- [템플릿 라우트](template-routing.md) - routes.json, 언어 파일
- [템플릿 보안](template-security.md) - API 서빙, 화이트리스트
- [템플릿 캐싱](template-caching.md) - 캐시 계층, 무효화
- [인덱스로 돌아가기](README.md)
