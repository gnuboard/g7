# 모듈 레이아웃 시스템

> 이 문서는 그누보드7 모듈의 레이아웃 시스템에 대해 설명합니다. 레이아웃 등록, 오버라이드, 상속 처리를 다룹니다.

---

## TL;DR (5초 요약)

```text
1. 위치: modules/_bundled/vendor-module/resources/layouts/admin/*.json 또는 user/*.json
2. 네이밍: vendor-module.layout-name 형식 (DOT 구분자)
3. admin/ 하위 → Admin 템플릿, user/ 하위 → User 템플릿에 등록
4. 활성화 시 자동 등록, 비활성화 시 자동 삭제
5. 오버라이드: 템플릿 layouts/overrides/vendor-module/ + version_constraint 지원
```

---

## 목차

1. [개요](#개요)
2. [핵심 원칙](#핵심-원칙)
3. [레이아웃 파일 위치](#레이아웃-파일-위치)
4. [레이아웃 네이밍 규칙](#레이아웃-네이밍-규칙)
5. [레이아웃 등록 메커니즘](#레이아웃-등록-메커니즘)
6. [모듈 활성화/비활성화 흐름](#모듈-활성화비활성화-흐름)
7. [레이아웃 오버라이드 시스템](#레이아웃-오버라이드-시스템)
8. [레이아웃 상속 처리](#레이아웃-상속-처리)
9. [DB 저장 규칙](#db-저장-규칙)
10. [캐시 전략](#캐시-전략)
11. [개발 가이드](#모듈-레이아웃-개발-가이드)
12. [문제 해결](#문제-해결)

---

## 개요

모듈은 자체 레이아웃 파일을 제공하여 관리자/사용자 페이지 UI를 구성할 수 있습니다. 모듈 레이아웃은 디렉토리 위치에 따라 활성화된 Admin 또는 User 템플릿에 자동으로 등록되며, 템플릿 오버라이드 시스템을 통해 커스터마이징이 가능합니다.

---

## 핵심 원칙

```text
필수: ModuleManager와 TemplateManager의 일관성 유지
필수: admin/ 하위 레이아웃 → Admin 템플릿에만 등록
필수: user/ 하위 레이아웃 → User 템플릿에만 등록
경고: layouts/ 루트에 직접 배치된 파일은 스킵됨 (admin/ 또는 user/ 하위 필수)
✅ 레이아웃 content는 layoutData 전체를 저장
✅ 모듈 비활성화 시 레이아웃 soft delete
✅ 모듈 재활성화 시 레이아웃 복원
```

---

## 레이아웃 파일 위치

모듈 레이아웃은 `resources/layouts/` 디렉토리에 위치합니다. 디렉토리 위치에 따라 등록 대상 템플릿이 결정됩니다.

```
modules/
└── _bundled/
    └── vendor-module/
        └── resources/
            └── layouts/
            ├── admin/              ← Admin 템플릿에 등록
            │   ├── index.json
            │   └── edit.json
            └── user/               ← User 템플릿에 등록
                ├── main.json
                └── detail.json
```

> **주의**: `layouts/` 루트에 직접 배치된 파일(예: `layouts/settings.json`)은 등록되지 않고 경고 로그가 출력됩니다. 반드시 `admin/` 또는 `user/` 하위에 배치하세요.

### 등록 대상 템플릿 결정

| 파일 위치                | 등록 대상            | 예시                 |
| ----------------------- | ------------------- | ------------------- |
| `layouts/admin/*.json`  | 활성 Admin 템플릿     | sirsoft-admin_basic  |
| `layouts/user/*.json`   | 활성 User 템플릿      | sirsoft-user_theme1  |
| `layouts/*.json` (루트)  | 등록 안됨 (스킵)      | 경고 로그 출력         |

---

## 레이아웃 네이밍 규칙

### 자동 생성 패턴

```
{module-identifier}.{layout_path}
```

모듈 레이아웃은 DB에 등록될 때 `moduleIdentifier`가 점(`.`) 구분자로 접두사에 추가됩니다.

### 예시

```
파일: modules/_bundled/sirsoft-sample/resources/layouts/admin/index.json
파일 내 layout_name: admin_sample_index
DB 등록명: sirsoft-sample.admin_sample_index

파일: modules/_bundled/sirsoft-sample/resources/layouts/admin/edit.json
파일 내 layout_name: admin_sample_edit
DB 등록명: sirsoft-sample.admin_sample_edit

파일: modules/_bundled/sirsoft-sample/resources/layouts/user/main.json
파일 내 layout_name: user_sample_main
DB 등록명: sirsoft-sample.user_sample_main
```

### 중요 사항

- 레이아웃 파일 내에서는 `layout_name`을 **접두사 없이** 작성합니다
- 시스템이 자동으로 `{moduleIdentifier}.{layout_name}` 형식으로 변환합니다
- 점(`.`) 구분자는 moduleIdentifier와 layoutName의 경계를 명확히 구분합니다

### 파일 내 layout_name 지정 예시

```json
{
  "layout_name": "admin_sample_index",
  "version": "1.0.0",
  "extends": "_admin_base",
  "meta": {
    "title": "$t:sirsoft-sample.admin.index.title"
  },
  "slots": {
    "content": [...]
  }
}
```

이 레이아웃은 DB에 `sirsoft-sample.admin_sample_index`로 등록됩니다.

---

## 레이아웃 등록 메커니즘

### ModuleManager.registerLayoutToTemplate()

```php
protected function registerLayoutToTemplate(
    Template $template,
    string $layoutName,
    array $layoutData,
    string $moduleIdentifier
): void
{
    // TemplateManager와 동일하게 layoutData 전체 저장
    $content = $layoutData;

    TemplateLayout::updateOrCreate(
        [
            'template_id' => $template->id,
            'name' => $layoutName,
        ],
        [
            'content' => $content,  // extends, layout_name 포함
            'extends' => $layoutData['extends'] ?? null,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]
    );
}
```

**중요**: `content`에는 `layoutData` 전체가 저장되므로 `extends`, `layout_name`, `version` 등 모든 필드가 포함됩니다.

---

## 모듈 활성화/비활성화 흐름

### 1. 모듈 활성화 (activateModule)

```php
public function activateModule(string $moduleName): array
{
    // 1. 모듈 activate() 실행
    $result = $module->activate();

    if ($result) {
        // 2. 모듈 상태를 Active로 변경
        Module::where('identifier', $module->getIdentifier())
            ->update(['status' => ExtensionStatus::Active->value]);

        // 3. soft deleted된 레이아웃 복원 (재활성화 시)
        $this->restoreModuleLayouts($module->getIdentifier());

        // 4. 레이아웃 등록 (새로운 레이아웃 또는 업데이트)
        $layoutsRegistered = $this->registerModuleLayouts($moduleName);

        // 5. 캐시 무효화
        $this->invalidateLayoutCache($module->getIdentifier());
    }

    return ['success' => $result, 'layouts_registered' => $layoutsRegistered];
}
```

### 2. 모듈 비활성화 (deactivateModule)

```php
public function deactivateModule(string $moduleName): array
{
    // 1. 모듈 deactivate() 실행
    $result = $module->deactivate();

    if ($result) {
        // 2. 모듈 상태를 Inactive로 변경
        Module::where('identifier', $module->getIdentifier())
            ->update(['status' => ExtensionStatus::Inactive->value]);

        // 3. 모듈 레이아웃 soft delete
        $layoutsDeleted = $this->softDeleteModuleLayouts($module->getIdentifier());

        // 4. 캐시 무효화
        $this->invalidateLayoutCache($module->getIdentifier());
    }

    return ['success' => $result, 'layouts_deleted' => $layoutsDeleted];
}
```

### 3. 템플릿 활성화 시 레이아웃 등록 (activateTemplate)

새 템플릿이 활성화되면 `TemplateManager::activateTemplate()`이 기존 활성 모듈/플러그인의 레이아웃을 새 템플릿에 자동으로 등록합니다.

```php
// TemplateManager::activateTemplate() 내부 (트랜잭션)
DB::transaction(function () use ($templateName, $template, $templateRecord) {
    // 1. 같은 타입의 기존 활성 템플릿 비활성화
    $this->deactivateTemplatesByType($template['type']);

    // 2. 상태 업데이트
    $this->templateRepository->updateByIdentifier($templateName, [...]);

    // 3. 레이아웃 확장(layout_extensions) 등록
    $this->layoutExtensionService->registerAllActiveExtensionsToTemplate($templateRecord->id);

    // 4. 활성 모듈/플러그인의 레이아웃을 새 템플릿에 등록
    app(ModuleManager::class)->registerLayoutsForAllActiveModules();
    app(PluginManager::class)->registerLayoutsForAllActivePlugins();

    // 5. 캐시 워밍
    $this->warmTemplateCache($templateName);
});
```

**중요**: `registerModuleLayouts()`는 `updateOrCreate` 패턴을 사용하므로 중복 호출에 안전합니다.

### 4. Soft Delete/Restore 이유

| 작업 | 설명 |
|------|------|
| **Soft Delete** | 모듈 재활성화 시 레이아웃 복원을 위해 |
| **Hard Delete** | 모듈 제거(uninstall) 시에만 사용 |

**장점**: 모듈 설정 및 오버라이드 데이터 보존

---

## 레이아웃 오버라이드 시스템

### 우선순위 (LayoutResolverService)

```
1. 템플릿 오버라이드 (source_type = 'template')
2. 모듈 기본 레이아웃 (source_type = 'module')
```

### 오버라이드 생성 예시

```php
// 템플릿에서 모듈 레이아웃 오버라이드
TemplateLayout::create([
    'template_id' => $templateId,
    'name' => 'sirsoft-sample.admin_sample_index',  // 모듈 레이아웃명과 동일
    'content' => $customizedLayoutData,             // 커스터마이징된 데이터
    'extends' => '_admin_base',
    'source_type' => LayoutSourceType::Template,    // 오버라이드 표시
    'source_identifier' => 'sirsoft-admin_basic',
    'created_by' => Auth::id(),
]);
```

### 오버라이드 해석 로직

```php
// LayoutService.resolveLayout()
if ($this->isModuleLayoutName($layoutName)) {
    // LayoutResolverService를 통해 우선순위 적용
    $resolved = $this->layoutResolverService->resolve($layoutName, $templateId);

    // 오버라이드가 있으면 오버라이드 반환, 없으면 모듈 기본 반환
    return $resolved;
}
```

### 오버라이드 버전 호환성 (version_constraint)

템플릿에서 모듈/플러그인의 레이아웃을 오버라이드할 때, 대상 모듈/플러그인의 버전이 변경되면 오버라이드가 호환되지 않을 수 있습니다. `version_constraint` 필드를 사용하여 오버라이드가 적용될 버전 범위를 지정할 수 있습니다.

#### 사용 목적

- 모듈/플러그인 메이저 버전 변경 시 오버라이드 호환성 문제 방지
- 비호환 오버라이드 자동 스킵 및 관리자 경고 표시
- 템플릿 개발자가 지원하는 버전 범위 명시

#### JSON 스키마

```json
{
  "layout_name": "admin_sample_index",
  "version_constraint": "^1.0",
  "injections": [...]
}
```

#### 지원 문법 (Composer Semver)

| 문법 | 의미 | 예시 |
| ---- | ---- | ---- |
| `^1.0` | 1.0.0 이상, 2.0.0 미만 | 1.0.0, 1.5.0, 1.9.9 ✅ / 2.0.0 ❌ |
| `~1.5` | 1.5.0 이상, 2.0.0 미만 | 1.5.0, 1.9.0 ✅ / 2.0.0 ❌ |
| `~1.5.0` | 1.5.0 이상, 1.6.0 미만 | 1.5.0, 1.5.9 ✅ / 1.6.0 ❌ |
| `>=1.0 <2.0` | 명시적 범위 | 1.0.0, 1.9.9 ✅ / 0.9.0, 2.0.0 ❌ |
| `1.0.0 - 1.9.9` | 하이픈 범위 | 1.0.0 ~ 1.9.9 ✅ |
| `1.0.0 \|\| 2.0.0` | OR 조건 | 1.0.0, 2.0.0 ✅ / 1.5.0 ❌ |

#### 동작 방식

1. **version_constraint 없음**: 항상 오버라이드 적용 (하위 호환성 유지)
2. **버전 제약 만족**: 오버라이드 정상 적용
3. **버전 제약 불만족**: 오버라이드 스킵 + 관리자 경고 표시
4. **대상 버전 정보 없음**: 오버라이드 적용 (경고 로그)

#### 관리자 경고 표시

비호환 오버라이드가 감지되면 레이아웃 상단에 Alert 컴포넌트가 자동 주입됩니다:

```
호환성 경고
오버라이드 "sirsoft-admin_basic"가 모듈 버전과 호환되지 않습니다 (요구: ^1.0, 현재: 2.0.0)
```

#### 사용 예시

```json
{
  "target_layout": "sirsoft-board.admin_board_settings",
  "version_constraint": "^1.0",
  "injections": [
    {
      "target_id": "notification_section",
      "position": "after",
      "components": [
        {
          "id": "custom_notification",
          "name": "Card",
          "type": "composite",
          "props": {
            "title": "$t:template.custom_notification.title"
          }
        }
      ]
    }
  ]
}
```

위 예시에서 `sirsoft-board` 모듈이 2.0.0으로 업데이트되면:

- 오버라이드가 자동으로 스킵됨
- 관리자에게 호환성 경고 표시
- 템플릿 개발자가 오버라이드를 업데이트할 때까지 기본 레이아웃 사용

#### 구현 위치

- **LayoutResolverService**: `checkOverrideVersionCompatibility()` 메서드 (레이아웃 오버라이드)
- **LayoutExtensionService**: `checkVersionCompatibility()` 메서드 (확장 오버라이드)
- **ModuleManager**: `getModuleVersion()` 헬퍼
- **PluginManager**: `getPluginVersion()` 헬퍼

#### 레이아웃 오버라이드에서의 version_constraint

템플릿이 모듈 레이아웃을 오버라이드할 때도 `version_constraint`를 사용할 수 있습니다.

오버라이드 JSON 예시:

```json
{
  "layout_name": "admin_sample_index",
  "version_constraint": "^1.0",
  "extends": "_admin_base",
  "slots": {
    "content": [...]
  }
}
```

동작:

1. `version_constraint` 없음 → 항상 오버라이드 적용
2. 버전 호환 → 오버라이드 적용
3. 버전 비호환 → 오버라이드 스킵, 모듈 기본 레이아웃으로 폴백 + `Log::warning`

---

## 레이아웃 상속 처리

### extends 필드 사용

```json
{
  "layout_name": "admin_sample_index",
  "extends": "_admin_base",
  "slots": {
    "content": [...]
  }
}
```

### 병합 로직 (LayoutService.mergeLayouts)

```php
// content에 extends가 있어야 병합 수행
if (isset($layoutData['extends'])) {
    $parentLayoutName = $layoutData['extends'];
    $parentLayout = $this->loadAndMergeLayout($templateId, $parentLayoutName);
    $mergedLayout = $this->mergeLayouts($parentLayout, $layoutData);
} else {
    $mergedLayout = $layoutData;
}
```

### 병합 결과

```php
$result = [
    'version' => $childLayout['version'] ?? $parentLayout['version'] ?? '1.0.0',
    'layout_name' => $childLayout['layout_name'] ?? $parentLayout['layout_name'] ?? '',
    'meta' => $mergedMeta,
    'data_sources' => $mergedDataSources,
    'components' => $mergedComponents,
];
```

---

## DB 저장 규칙

### 템플릿 레이아웃과 일관성 유지

| 구분 | TemplateManager | ModuleManager |
|------|----------------|---------------|
| content 저장 | `$layoutData` 전체 | `$layoutData` 전체 |
| extends 포함 | ✅ Yes | ✅ Yes |
| layout_name 포함 | ✅ Yes | ✅ Yes |
| version 포함 | ✅ Yes | ✅ Yes |

### 잘못된 구현 (❌ DON'T)

```php
// 개별 필드만 추출 - 병합 실패 원인
$content = [];
if (isset($layoutData['slots'])) {
    $content['slots'] = $layoutData['slots'];
}
if (isset($layoutData['meta'])) {
    $content['meta'] = $layoutData['meta'];
}
// extends, layout_name 누락!
```

### 올바른 구현 (✅ DO)

```php
// layoutData 전체 저장 - TemplateManager와 일관성
$content = $layoutData;
```

---

## 캐시 전략

### 무효화 시점

1. 모듈 활성화 후
2. 모듈 비활성화 후
3. 레이아웃 파일 변경 시

### 무효화 메서드

```php
use App\Contracts\Extension\CacheInterface;

protected function invalidateLayoutCache(string $moduleIdentifier): void
{
    $cache = app(CacheInterface::class); // 드라이버가 `g7:core:` 접두사 자동 적용

    // 1. 태그 기반 캐시 삭제 (CacheInterface::flushTags)
    $cache->flushTags(['layouts', $moduleIdentifier]);

    // 2. 개별 레이아웃 캐시 삭제
    $moduleLayouts = TemplateLayout::where('source_type', LayoutSourceType::Module)
        ->where('source_identifier', $moduleIdentifier)
        ->get();

    foreach ($moduleLayouts as $layout) {
        $cache->forget("template.{$layout->template_id}.layout.{$layout->name}");
    }
}
```

### 캐시 무효화 원칙

```text
중요: 모듈/플러그인 활성화/비활성화 시 관련 캐시 자동 무효화 필수
✅ 필수: Manager 레벨에서 캐시 무효화 구현 (Service 아님)
✅ 권장: 공통 캐시 무효화 로직은 트레이트로 분리
```

---

## 모듈 레이아웃 개발 가이드

### 1. 레이아웃 파일 생성

```bash
# 디렉토리 생성
mkdir -p modules/_bundled/sirsoft-sample/resources/layouts/admin

# 레이아웃 파일 생성
touch modules/_bundled/sirsoft-sample/resources/layouts/admin/index.json
```

### 2. 레이아웃 JSON 작성

```json
{
  "layout_name": "admin_sample_index",
  "version": "1.0.0",
  "extends": "_admin_base",
  "meta": {
    "title": "$t:sirsoft-sample.admin.index.title",
    "description": "$t:sirsoft-sample.admin.index.description",
    "auth_required": true
  },
  "slots": {
    "content": [
      {
        "id": "page_header",
        "name": "PageHeader",
        "type": "composite",
        "props": {
          "title": "$t:sirsoft-sample.admin.index.title"
        }
      }
    ]
  },
  "data_sources": [
    {
      "id": "items",
      "type": "api",
      "method": "GET",
      "endpoint": "/api/modules/sirsoft-sample/admin/items",
      "auto_fetch": true,
      "auth_required": true
    }
  ]
}
```

### 3. 라우트 등록 (routes.json)

```json
{
  "routes": [
    {
      "path": "/admin/sirsoft-sample",
      "layout_name": "sirsoft-sample.admin_sample_index",
      "permissions": ["sirsoft-sample.admin.view"]
    }
  ]
}
```

### 4. 모듈 활성화

```bash
php artisan module:activate sirsoft-sample
```

---

## 문제 해결

### 레이아웃 병합 안 됨

**원인**: `content`에 `extends` 필드 누락

**해결**:
1. `ModuleManager.registerLayoutToTemplate()`에서 `$layoutData` 전체 저장 확인
2. 모듈 재활성화로 DB 업데이트:
   ```bash
   php artisan module:deactivate sirsoft-sample
   php artisan module:activate sirsoft-sample
   ```

### layout_name 누락 오류

**원인**: `content`에 `layout_name` 필드 누락

**해결**: 위와 동일 (ModuleManager 수정 후 재활성화)

### 오버라이드가 적용 안 됨

**원인**:
1. 레이아웃 이름 불일치
2. `source_type`이 'template'이 아님
3. 캐시 문제

**해결**:
```bash
# 캐시 초기화
php artisan cache:clear

# 레이아웃 이름 확인 (DB 조회)
SELECT name, source_type FROM template_layouts
WHERE name = 'sirsoft-sample.admin_sample_index';
```

### user 레이아웃이 등록되지 않음

**원인**: 레이아웃 파일이 `layouts/user/` 하위에 위치하지 않음

**해결**: `resources/layouts/user/` 디렉토리로 파일 이동

### layouts/ 루트에 배치한 레이아웃이 무시됨

**원인**: `admin/` 또는 `user/` 하위 배치 필수

**해결**: 적절한 하위 디렉토리로 파일 이동. 경고 로그 확인:

```text
"레이아웃 파일이 admin/ 또는 user/ 하위에 위치하지 않아 스킵됩니다: {file}"
```

### 모듈 재활성화 후에도 레이아웃 안 보임

**확인 사항**:
1. 레이아웃 파일 경로가 올바른지 확인
2. JSON 파일 문법 오류 확인
3. `layout_name` 필드가 JSON에 있는지 확인

---

## 관련 문서

- [module-basics.md](module-basics.md) - 모듈 개발 기초
- [module-routing.md](module-routing.md) - 모듈 라우트 규칙
- [module-commands.md](module-commands.md) - 모듈 Artisan 커맨드
- [module-i18n.md](module-i18n.md) - 모듈 다국어
- [template-caching.md](template-caching.md) - 템플릿 캐싱 전략
- [index.md](index.md) - 확장 시스템 인덱스
