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

---

## 사용 가능한 커맨드

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
php artisan template:install [vendor-template]
```

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
- 캐시 무효화 (`templates.active.{type}`)

---

### template:deactivate

템플릿을 비활성화합니다.

```bash
php artisan template:deactivate [vendor-template]
```

**기능**:
- `templates.is_active` 플래그 해제
- 관련 캐시 무효화

---

### template:uninstall

템플릿을 삭제합니다.

```bash
php artisan template:uninstall [vendor-template]
```

**기능**:
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
  - 감지 우선순위: GitHub > `_bundled` (2단계, `_pending` 미참여)
  - DB에 `update_available`, `latest_version`, `update_source` 갱신
- **단일 확인** (인수 있음): 특정 템플릿의 업데이트 가용 여부 확인
  - `checkTemplateUpdate()`는 DB 갱신 안 함 → 커맨드에서 `updateByIdentifier()` 직접 호출

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
php artisan template:update [identifier] [--layout-strategy=overwrite] [--force] [--source=auto|bundled|github]
```

**인수**:
- `identifier` (필수): 업데이트할 템플릿 식별자

**옵션**:
- `--layout-strategy=overwrite`: 레이아웃 전략 (`overwrite` | `keep`)
- `--force`: 버전 비교 없이 강제 업데이트 (파일 손상, 수동 수정 복원 시 사용)
- `--source`: 업데이트 소스 강제 (`auto` 기본 = GitHub > `_bundled` 우선순위, `bundled` = `_bundled` 단독, `github` = GitHub 단독)

**수행 작업**:
- 레이아웃 전략 검증 (`overwrite` | `keep` 외 FAILURE)
- 업데이트 가용 여부 확인 (없으면 info + SUCCESS, `--force` 시 무시하고 진행)
- `overwrite` 전략 시 수정된 레이아웃 경고 (`hasModifiedLayouts()`)
- 확인 프롬프트 → 업데이트 실행
- 실패 시 백업 복원 안내

**예시**:
```bash
# 기본 전략(overwrite)으로 업데이트
php artisan template:update sirsoft-admin_basic

# 레이아웃 보존 전략
php artisan template:update sirsoft-admin_basic --layout-strategy=keep

# 강제 재설치
php artisan template:update sirsoft-admin_basic --force

# GitHub 장애/잘못된 태그 우회 — _bundled 단독 사용
php artisan template:update sirsoft-admin_basic --source=bundled
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
- [인덱스로 돌아가기](index.md)