# 플러그인 개발 가이드

> **관련 문서**: [index.md](index.md) | [extension-manager.md](extension-manager.md) | [hooks.md](hooks.md)

---

## TL;DR (5초 요약)

```text
1. 디렉토리: plugins/vendor-plugin (예: sirsoft-payment)
2. 네임스페이스: Plugins\Vendor\Plugin\
3. AbstractPlugin 상속 권장 (PluginInterface 직접 구현 가능)
4. 필수: plugin.json (메타데이터 SSoT), plugin.php, composer.json
5. getName()/getVersion()/getDescription()은 plugin.json에서 자동 파싱 (하드코딩 불필요)
```

---

## 목차

1. [플러그인 네이밍 규칙](#플러그인-네이밍-규칙)
2. [PluginInterface 구현](#plugininterface-구현)
3. [composer.json 작성법](#composerjson-작성법)
4. [플러그인 디렉토리 구조](#플러그인-디렉토리-구조)
5. [플러그인 설정 시스템](#플러그인-설정-시스템)
6. [설정 레이아웃 파일 시스템](#설정-레이아웃-파일-시스템)
7. [defaults.json과 frontend_schema](#defaultsjson을-통한-설정-기본값-및-프론트엔드-노출-제어)
8. [플러그인 원칙](#플러그인-원칙)

---

## 플러그인 네이밍 규칙

### 디렉토리명

`vendor-plugin` 형식 (GitHub 스타일)

- 소문자 사용
- 하이픈(-): vendor와 plugin 구분
- 언더스코어(_): 플러그인명 내 단어 구분 (선택적)
- 예: `sirsoft-payment`, `sirsoft-daum_postcode`, `johndoe-analytics`

### 네임스페이스

`Plugins\Vendor\Plugin\` 형식

- PascalCase 사용
- 백슬래시(\) 구분
- 예: `Plugins\Sirsoft\Payment\`, `Plugins\Sirsoft\DaumPostcode\`

### 디렉토리명 → 네임스페이스 변환 규칙

```text
필수: 변환 규칙 이해

하이픈(-) → 네임스페이스 구분자(\)
언더스코어(_) → PascalCase 결합

예시:
  sirsoft-payment      → Sirsoft\Payment
  sirsoft-daum_postcode → Sirsoft\DaumPostcode
  vendor-my_plugin_name → Vendor\MyPluginName
```

### Composer 패키지명

`plugins/vendor-plugin`

- 예: `plugins/sirsoft-payment`, `plugins/sirsoft-daum_postcode`

### 네이밍 규칙 요약

| 항목 | 형식 | 예시 |
|------|------|------|
| 디렉토리명 | `vendor-plugin` | `sirsoft-payment`, `sirsoft-daum_postcode` |
| 네임스페이스 | `Plugins\Vendor\Plugin\` | `Plugins\Sirsoft\Payment\`, `Plugins\Sirsoft\DaumPostcode\` |
| Composer | `plugins/vendor-plugin` | `plugins/sirsoft-payment`, `plugins/sirsoft-daum_postcode` |

### 식별자 검증 규칙

모듈/플러그인/템플릿 공통 식별자 검증 규칙은 [extension-manager.md](./extension-manager.md#식별자-검증-규칙-validextensionidentifier)를 참조하세요.

---

## PluginInterface 구현

### 기본 구조

**중요**: plugin.php는 **루트 레벨**에 위치 (src/ 내부 아님)

```php
<?php

namespace Plugins\Sirsoft\Payment;

use App\Contracts\Extension\PluginInterface;

class Plugin implements PluginInterface
{
    /**
     * 플러그인 고유 식별자 반환 (vendor-plugin 형식)
     */
    public function getIdentifier(): string
    {
        return 'sirsoft-payment';
    }

    /**
     * 벤더/개발자명 반환
     */
    public function getVendor(): string
    {
        return 'sirsoft';
    }

    /**
     * 플러그인명 반환 (표시용)
     */
    public function getName(): string
    {
        return 'Payment';
    }

    /**
     * 플러그인 버전 반환
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * 플러그인 설명 반환
     */
    public function getDescription(): string
    {
        return '결제 게이트웨이 플러그인';
    }

    /**
     * 플러그인 설치
     */
    public function install(): bool
    {
        // 설치 로직
        return true;
    }

    /**
     * 플러그인 제거
     */
    public function uninstall(): bool
    {
        // 제거 로직
        return true;
    }

    /**
     * 플러그인 활성화
     */
    public function activate(): bool
    {
        return true;
    }

    /**
     * 플러그인 비활성화
     */
    public function deactivate(): bool
    {
        return true;
    }

    /**
     * 플러그인이 의존하는 모듈/플러그인
     */
    public function getDependencies(): array
    {
        return [
            'sirsoft-ecommerce' => '>=1.0.0',  // vendor-module 형식
        ];
    }

    /**
     * 훅 리스너 목록
     */
    public function getHookListeners(): array
    {
        return [
            \Plugins\Sirsoft\Payment\Listeners\PaymentProcessListener::class,
        ];
    }
}
```

### manifest 자동 파싱 메서드 (오버라이드 불필요)

`plugin.json`에서 자동으로 값을 읽어오는 메서드입니다. 하드코딩 오버라이드가 불필요합니다.

| 메서드 | 반환 타입 | 소스 (plugin.json) | 기본값 |
|--------|----------|-------------------|--------|
| `getName()` | `string\|array` | `name` | identifier |
| `getVersion()` | `string` | `version` | `'0.0.0'` |
| `getDescription()` | `string\|array` | `description` | `''` |
| `getRequiredCoreVersion()` | `?string` | `g7_version` | `null` |
| `getLicense()` | `?string` | `license` | `null` |
| `getGithubUrl()` | `?string` | `github_url` | `null` |
| `getAssets()` | `array` | `assets` | `[]` |
| `getAssetLoadingConfig()` | `array` | `loading` | strategy: global, priority: 100 |

> **`license` 필드**: `plugin.json`에 `"license": "MIT"` 등의 라이선스 정보를 포함합니다. `getLicense()` 메서드로 값을 읽으며, API 리소스의 `license` 필드로 노출됩니다. 또한 각 플러그인 루트에 `LICENSE` 파일을 포함하여 라이선스 전문을 제공해야 합니다.

### 자동 추론 메서드 (final - 오버라이드 불가)

| 메서드 | 반환 타입 | 설명 |
|--------|----------|------|
| `getIdentifier()` | `string` | 디렉토리명에서 자동 추론 (예: `sirsoft-payment`) |
| `getVendor()` | `string` | 디렉토리명에서 자동 추론 (예: `sirsoft`) |

### 기본값 제공 메서드 (필요시 오버라이드)

| 메서드 | 기본값 | 설명 |
|--------|--------|------|
| `install()` | `true` | 설치 로직 |
| `uninstall()` | `true` | 제거 로직 |
| `activate()` | `true` | 활성화 로직 |
| `deactivate()` | `true` | 비활성화 로직 |
| `getDynamicTables()` | `[]` | 런타임 동적 생성 테이블 목록 (언인스톨 시 Manager가 삭제) |
| `getDependencies()` | `[]` | 의존하는 모듈/플러그인 목록 |
| `getHookListeners()` | `[]` | 훅 리스너 클래스 목록 |
| `upgrades()` | `[]` | 업그레이드 스텝 (`upgrades/` 디렉토리 자동 발견) |

---

## composer.json 작성법

**중요**: autoload에 `["src/", "./"]` 포함 (루트의 plugin.php 로딩을 위해)

```json
{
    "name": "plugins/sirsoft-payment",
    "description": "Payment gateway plugin for Gnuboard7 platform by sirsoft",
    "type": "library",
    "authors": [
        {
            "name": "sirsoft",
            "email": "contact@sirsoft.com"
        }
    ],
    "require": {
        "php": "^8.2"
    },
    "autoload": {
        "psr-4": {
            "Plugins\\Sirsoft\\Payment\\": ["src/", "./"]
        }
    }
}
```

### autoload 설정 상세

```json
"autoload": {
    "psr-4": {
        "Plugins\\Sirsoft\\Payment\\": ["src/", "./"]
    }
}
```

- `"src/"`: src 디렉토리 내 클래스 로딩
- `"./"`: 루트의 plugin.php 로딩

---

## 플러그인 디렉토리 구조

```text
plugins/_bundled/sirsoft-payment/
├── plugin.json                  # 메타데이터 (이름, 버전, 설명 등 SSoT)
├── plugin.php                    # PluginInterface 구현 (루트)
├── LICENSE                      # 라이선스 전문 (MIT)
├── composer.json                 # PSR-4 오토로딩 + 외부 패키지 의존성
├── vendor/                      # Composer 의존성 (자동 생성, gitignore 대상)
├── upgrades/                    # 버전 업그레이드 스텝 (UpgradeStepInterface 구현)
│   └── Upgrade_1_1_0.php        # 1.1.0 버전 업그레이드 로직
├── config/                      # 플러그인 설정
│   ├── payment.php
│   └── settings/
│       └── defaults.json        # 기본값 + frontend_schema (선택)
├── database/
│   ├── migrations/              # 마이그레이션
│   └── seeders/                 # 시더
│       └── Sample/              # 샘플(개발용) 시더
├── resources/
│   ├── lang/                    # 다국어 파일
│   │   ├── ko.json
│   │   └── en.json
│   └── layouts/                 # 레이아웃 파일
│       └── admin/               # 관리자 레이아웃 (admin 템플릿 등록)
│           └── plugin_settings.json  # 설정 페이지 레이아웃
├── src/
│   ├── Listeners/              # 이벤트(훅)를 처리하는 리스너
│   └── Providers/              # 서비스 컨테이너에 기능을 등록하는 프로바이더
└── tests/                       # 테스트 파일
    ├── Feature/                # 기능 테스트
    └── Unit/                   # 단위 테스트
```

### 디렉토리 설명

| 디렉토리 | 설명 |
|----------|------|
| `plugin.json` | 메타데이터 SSoT (이름, 버전, 설명, 의존성, 라이선스 등) |
| `plugin.php` | PluginInterface 구현 파일 (루트에 위치) |
| `LICENSE` | 라이선스 전문 (MIT 등) — API 엔드포인트 `GET /api/admin/plugins/{identifier}/license`로 제공 |
| `composer.json` | PSR-4 오토로딩 + 외부 패키지 의존성 설정 |
| `vendor/` | Composer 의존성 디렉토리 (자동 생성, gitignore 대상) |
| `config/` | 플러그인 전용 설정 파일 |
| `config/settings/defaults.json` | 기본값 + frontend_schema 정의 (선택) |
| `database/migrations/` | 플러그인 마이그레이션 파일 |
| `database/seeders/` | 플러그인 시더 파일 |
| `resources/lang/` | 다국어 JSON 파일 (ko.json, en.json) |
| `resources/layouts/admin/` | 관리자 레이아웃 JSON 파일 (plugin_settings.json - 설정 페이지 전용) |
| `src/Listeners/` | 훅 이벤트 리스너 클래스 |
| `src/Providers/` | 서비스 프로바이더 클래스 |
| `tests/` | 테스트 파일 (Feature/Unit) |

---

## 플러그인 설정 시스템

플러그인이 관리자 설정 UI를 제공하려면 `AbstractPlugin`을 상속하고 설정 관련 메서드를 구현합니다.

### AbstractPlugin 상속 (권장)

> **참고**: `getName()`, `getVersion()`, `getDescription()`은 `plugin.json`에서 자동 파싱되므로 오버라이드가 불필요합니다.

```php
<?php

namespace Plugins\Sirsoft\DaumPostcode;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    // getName(), getVersion(), getDescription()은 plugin.json에서 자동 파싱
    // 별도 오버라이드 불필요

    /**
     * 플러그인 설정 스키마 반환
     *
     * 관리자 설정 페이지 UI를 동적으로 생성하는 데 사용됩니다.
     */
    public function getSettingsSchema(): array
    {
        return [
            'display_mode' => [
                'type' => 'enum',
                'options' => ['popup', 'layer'],
                'default' => 'layer',
                'label' => [
                    'ko' => '표시 방식',
                    'en' => 'Display Mode',
                ],
                'hint' => [
                    'ko' => '주소 검색 창을 표시하는 방식을 선택합니다.',
                    'en' => 'Select how to display the address search window.',
                ],
                'required' => false,
            ],
            'popup_width' => [
                'type' => 'integer',
                'default' => 500,
                'label' => [
                    'ko' => '팝업 너비 (px)',
                    'en' => 'Popup Width (px)',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 플러그인 설정 기본값 반환
     */
    public function getConfigValues(): array
    {
        return [
            'display_mode' => 'layer',
            'popup_width' => 500,
        ];
    }
}
```

### 설정 스키마 필드 타입

| 타입 | 설명 | 추가 속성 |
|------|------|----------|
| `string` | 텍스트 입력 | `sensitive`: 민감 정보 마스킹 |
| `integer` | 숫자 입력 | `min`, `max` |
| `boolean` | 체크박스 | - |
| `enum` | 선택 박스 | `options`: 선택지 배열 |

### 설정 스키마 공통 속성

| 속성 | 타입 | 설명 |
|------|------|------|
| `type` | `string` | 필드 타입 (필수) |
| `default` | `mixed` | 기본값 |
| `label` | `array` | 다국어 레이블 (`ko`, `en`) |
| `hint` | `array` | 다국어 힌트 텍스트 |
| `required` | `bool` | 필수 여부 (기본: false) |
| `sensitive` | `bool` | 민감 정보 여부 (마스킹, 암호화) |
| `readonly` | `bool` | 읽기 전용 여부 |

### 설정 API 엔드포인트

플러그인 설정은 다음 API를 통해 관리됩니다:

| 메서드 | 엔드포인트 | 설명 |
|--------|-----------|------|
| GET | `/api/admin/plugins/{identifier}/settings` | 설정 조회 |
| PUT | `/api/admin/plugins/{identifier}/settings` | 설정 저장 |
| GET | `/api/admin/plugins/{identifier}/settings/layout` | 설정 UI 레이아웃 |

### defaults.json을 통한 설정 기본값 및 프론트엔드 노출 제어

플러그인도 모듈과 동일하게 `config/settings/defaults.json`을 통해 기본값을 정의하고, `frontend_schema`로 `window.G7Config.plugins`에 노출할 설정을 제어할 수 있습니다.

**파일 경로**: `plugins/{identifier}/config/settings/defaults.json`

```json
{
  "_meta": {
    "version": "1.0.0",
    "description": "플러그인 환경설정"
  },
  "defaults": {
    "display_mode": "layer",
    "popup_width": 500,
    "popup_height": 600,
    "api_secret": "sk_xxx"
  },
  "frontend_schema": {
    "display_mode": { "expose": true },
    "popup_width": { "expose": true },
    "popup_height": { "expose": true },
    "api_secret": { "expose": false }
  }
}
```

#### frontend_schema 동작 규칙

| 조건                                              | 동작                                                  |
|---------------------------------------------------|-------------------------------------------------------|
| `defaults.json`에 `frontend_schema` 있음          | 스키마 기반 필터링 (`expose: true`만 G7Config에 노출) |
| `defaults.json` 없거나 `frontend_schema` 비어있음 | 기존 동작 유지 (`sensitive: true`만 제외)             |

#### 카테고리 구조 설정에서의 필터링

카테고리 구조 설정을 사용하는 플러그인은 카테고리/필드 레벨로 제어할 수 있습니다:

```json
{
  "frontend_schema": {
    "general": {
      "expose": true,
      "fields": {
        "site_name": { "expose": true },
        "secret_key": { "expose": false }
      }
    },
    "internal": { "expose": false }
  }
}
```

- `expose: false`인 카테고리 → 해당 카테고리 전체 미노출
- `expose: true` + `fields` 정의 → 필드별 제어
- `expose: true` + `fields` 없음 → 카테고리 전체 노출

#### 설정 초기화 우선순위

플러그인 설치 시 `initializePluginSettings()`에서 다음 순서로 기본값을 결정합니다:

1. `defaults.json`의 `defaults` 섹션 (1순위)
2. `getConfigValues()` 반환값 (하위 호환성)
3. 빈 값이면 초기화 스킵

```text
하위 호환성: defaults.json이 없는 기존 플러그인은 기존 동작을 유지합니다.
✅ 권장: 새 플러그인은 defaults.json + frontend_schema를 사용하세요.
```

### 플러그인이 제공하는 훅 정의

외부 연동 플러그인은 다른 모듈/플러그인이 구독할 수 있는 훅을 정의할 수 있습니다:

```php
/**
 * 플러그인이 제공하는 훅 정보 반환
 */
public function getHooks(): array
{
    return [
        [
            'name' => 'sirsoft-daum_postcode.address.selected',
            'type' => 'action',
            'description' => [
                'ko' => '주소 선택 완료 시 실행되는 액션 훅',
                'en' => 'Action hook executed when address selection is complete',
            ],
            'parameters' => [
                'zonecode' => 'string - 우편번호',
                'address' => 'string - 기본 주소',
                'roadAddress' => 'string - 도로명 주소',
            ],
        ],
    ];
}
```

---

## 설정 레이아웃 파일 시스템

플러그인 설정 UI는 JSON 레이아웃 파일을 통해 정의합니다. `AbstractPlugin`의 `getSettingsLayout()` 메서드가 레이아웃 파일 경로를 반환하며, 파일이 존재하면 자동으로 설정 UI를 제공합니다.

### 핵심 원칙

```text
필수: 설정 UI는 resources/layouts/settings.json으로 정의 (PHP 코드에 하드코딩 금지)
필수: resources/layouts/admin/plugin_settings.json 파일로 레이아웃 정의
✅ 필수: 템플릿 오버라이드 우선순위 준수
```

### 레이아웃 파일 경로

기본 경로: `plugins/{identifier}/resources/layouts/admin/plugin_settings.json`

```php
// AbstractPlugin 기본 구현
public function getSettingsLayout(): ?string
{
    $path = $this->getBasePath() . '/resources/layouts/admin/plugin_settings.json';
    return file_exists($path) ? $path : null;
}

// hasSettings()는 레이아웃 파일 존재 여부로 판단
public function hasSettings(): bool
{
    return $this->getSettingsLayout() !== null;
}
```

### 템플릿 오버라이드 우선순위

설정 레이아웃은 다음 순서로 로드됩니다:

| 우선순위 | 경로 | 설명 |
|----------|------|------|
| 1순위 | `templates/{template}/layouts/plugins/{identifier}/admin/plugin_settings.json` | 템플릿 오버라이드 |
| 2순위 | `plugins/{identifier}/resources/layouts/admin/plugin_settings.json` | 플러그인 기본 |

### 레이아웃 JSON 구조 (간소화 버전)

`schema`만 정의하면 설정 UI가 자동 생성됩니다:

```json
{
  "version": "1.0.0",
  "meta": {
    "title": "$t:settings.page_title",
    "description": "$t:settings.page_description"
  },
  "pageConfig": {
    "notice": "$t:settings.notice",
    "guide": {
      "title": "$t:settings.guide_title",
      "items": [
        "$t:settings.guide_item_1",
        "$t:settings.guide_item_2"
      ]
    }
  },
  "schema": {
    "display_mode": {
      "type": "enum",
      "options": ["popup", "layer"],
      "default": "layer",
      "label": "$t:settings.display_mode.label",
      "hint": "$t:settings.display_mode.hint"
    },
    "popup_width": {
      "type": "integer",
      "default": 500,
      "label": "$t:settings.popup_width.label",
      "hint": "$t:settings.popup_width.hint"
    }
  }
}
```

### 레이아웃 필드 설명

| 필드 | 타입 | 설명 |
|------|------|------|
| `version` | `string` | 레이아웃 버전 |
| `meta.title` | `string` | 페이지 제목 (다국어 바인딩 지원) |
| `meta.description` | `string` | 페이지 설명 |
| `pageConfig.notice` | `string` | 상단 알림 메시지 |
| `pageConfig.guide` | `object` | 가이드 박스 설정 |
| `schema` | `object` | 설정 필드 스키마 (getSettingsSchema()와 동일 구조) |

### 다국어 바인딩

레이아웃에서 `$t:` 접두사를 사용하여 플러그인 다국어 파일을 참조합니다:

```json
{
  "label": "$t:settings.display_mode.label"
}
```

다국어 파일 (`resources/lang/ko.json`):

```json
{
  "settings": {
    "page_title": "Daum 우편번호 설정",
    "display_mode": {
      "label": "표시 방식",
      "hint": "주소 검색 창을 표시하는 방식을 선택합니다."
    }
  }
}
```

### 커스텀 레이아웃 (slots 사용)

기본 UI 대신 직접 UI를 구성하려면 `slots.content`를 정의합니다:

```text
주의: slots 사용 시 Form 자동 바인딩 규칙
필수: dataKey와 trackChanges를 컨테이너에 설정
필수: Input/Select에 name prop 추가 (자동 바인딩 활성화)
주의: 수동 value 바인딩과 onChange 액션은 자동 바인딩 시 불필요
```

**자동 바인딩 패턴 (권장)**:

```json
{
  "slots": {
    "content": [
      {
        "id": "settings_container",
        "type": "basic",
        "name": "Div",
        "dataKey": "form",
        "trackChanges": true,
        "children": [
          {
            "type": "basic",
            "name": "Input",
            "props": {
              "name": "popup_width",
              "type": "number"
            }
          },
          {
            "type": "basic",
            "name": "Select",
            "props": {
              "name": "display_mode"
            },
            "children": [
              { "type": "basic", "name": "Option", "props": { "value": "popup" }, "text": "popup" },
              { "type": "basic", "name": "Option", "props": { "value": "layer" }, "text": "layer" }
            ]
          }
        ]
      }
    ]
  }
}
```

**자동 바인딩 동작 원리**:

1. `dataKey: "form"` → 모든 입력이 `_local.form.{name}`에 저장
2. `trackChanges: true` → 값 변경 시 `_local.hasChanges = true` 자동 설정
3. `name: "popup_width"` → `_local.form.popup_width`에 자동 바인딩
4. 저장 버튼의 `disabled: "{{!_local.hasChanges}}"` → 변경 시 활성화

**수동 바인딩 패턴 (사용 금지)**:

```json
{
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "number",
    "value": "{{_local.form?.popup_width ?? 500}}"
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "local",
        "form": "{{Object.assign({}, _local.form, { popup_width: parseInt($event.target.value) })}}"
      }
    }
  ]
}
```

**수동 바인딩의 문제점**:

- `trackChanges`가 작동하지 않음 (저장 버튼이 활성화되지 않음)
- 코드가 장황하고 유지보수 어려움
- 실수로 hasChanges 업데이트를 빠뜨릴 수 있음

### 전체 예시: Daum 우편번호 플러그인

**파일 구조:**

```text
plugins/sirsoft-daum_postcode/
├── plugin.php
├── composer.json
└── resources/
    ├── lang/
    │   ├── ko.json
    │   └── en.json
    └── layouts/
        └── admin/
            └── plugin_settings.json
```

**plugin_settings.json:**

```json
{
  "version": "1.0.0",
  "meta": {
    "title": "$t:settings.page_title",
    "description": "$t:settings.page_description"
  },
  "pageConfig": {
    "notice": "$t:settings.notice",
    "guide": {
      "title": "$t:settings.guide_title",
      "items": [
        "$t:settings.guide_item_1",
        "$t:settings.guide_item_2",
        "$t:settings.guide_item_3"
      ]
    }
  },
  "schema": {
    "display_mode": {
      "type": "enum",
      "options": ["popup", "layer"],
      "default": "layer",
      "label": "$t:settings.display_mode.label",
      "hint": "$t:settings.display_mode.hint",
      "required": false
    },
    "popup_width": {
      "type": "integer",
      "default": 500,
      "label": "$t:settings.popup_width.label",
      "hint": "$t:settings.popup_width.hint",
      "required": false
    },
    "popup_height": {
      "type": "integer",
      "default": 600,
      "label": "$t:settings.popup_height.label",
      "hint": "$t:settings.popup_height.hint",
      "required": false
    },
    "theme_color": {
      "type": "string",
      "default": "#1976D2",
      "label": "$t:settings.theme_color.label",
      "hint": "$t:settings.theme_color.hint",
      "required": false
    }
  }
}
```

**ko.json:**

```json
{
  "name": "Daum 우편번호",
  "description": "Daum 우편번호 서비스를 통해 주소 검색 기능을 제공합니다.",
  "settings": {
    "page_title": "Daum 우편번호 설정",
    "page_description": "주소 검색 팝업 표시 방식을 설정합니다.",
    "notice": "Daum 우편번호 서비스는 API 키 없이 무료로 사용할 수 있습니다.",
    "guide_title": "사용 안내",
    "guide_item_1": "표시 방식: 팝업 또는 레이어 중 선택할 수 있습니다.",
    "guide_item_2": "팝업 크기: 팝업 방식 선택 시 너비와 높이를 지정합니다.",
    "guide_item_3": "테마 색상: 우편번호 검색 UI의 강조 색상을 지정합니다.",
    "display_mode": {
      "label": "표시 방식",
      "hint": "주소 검색 창을 표시하는 방식을 선택합니다."
    },
    "popup_width": {
      "label": "팝업 너비 (px)",
      "hint": "팝업 창의 너비를 픽셀 단위로 지정합니다."
    },
    "popup_height": {
      "label": "팝업 높이 (px)",
      "hint": "팝업 창의 높이를 픽셀 단위로 지정합니다."
    },
    "theme_color": {
      "label": "테마 색상",
      "hint": "우편번호 검색 UI의 강조 색상을 HEX 코드로 지정합니다."
    }
  }
}
```

---

## 플러그인 권한 구조

### 모듈 vs 플러그인 권한 구조 비교

플러그인도 모듈과 동일한 **3레벨 계층적 권한 구조**(`플러그인 → 카테고리 → 개별 권한`)를 사용합니다.

| 항목 | 모듈 | 플러그인 |
| ---- | ---- | ---- |
| 정의 구조 | `categories` → `permissions` | `categories` → `permissions` (동일) |
| DB 저장 구조 | 모듈 노드 → 카테고리 → 개별 권한 | 플러그인 노드 → 카테고리 → 개별 권한 |
| identifier 패턴 | `vendor-module.category.action` | `vendor-plugin.category.action` |
| 등록 메서드 | `getPermissions()` | `getPermissions()` |

### 플러그인 권한 정의 방법

`AbstractPlugin::getPermissions()`를 오버라이드합니다. 모듈과 동일한 `categories` 구조를 사용합니다:

```php
public function getPermissions(): array
{
    return [
        'name' => ['ko' => '본인인증', 'en' => 'Identity Verification'],
        'description' => ['ko' => '본인인증 권한', 'en' => 'Identity verification permissions'],
        'categories' => [
            [
                'identifier' => 'settings',
                'name' => ['ko' => '설정 관리', 'en' => 'Settings Management'],
                'description' => ['ko' => '본인인증 설정 관리', 'en' => 'Verification settings'],
                'permissions' => [
                    [
                        'action' => 'view',
                        'name' => ['ko' => '본인인증 설정 조회', 'en' => 'View Settings'],
                        'description' => ['ko' => '설정 조회 권한', 'en' => 'View settings'],
                        'type' => 'admin',       // admin 또는 user (기본값: admin)
                        'roles' => ['admin'],    // 기본 할당 역할
                    ],
                    [
                        'action' => 'update',
                        'name' => ['ko' => '본인인증 설정 수정', 'en' => 'Update Settings'],
                        'description' => ['ko' => '설정 수정 권한', 'en' => 'Update settings'],
                        'roles' => ['admin'],
                    ],
                ],
            ],
        ],
    ];
}
```

### 계층 구조 동작

`PluginManager`는 설치 시 3레벨 계층 구조를 자동 생성합니다:

1. **1레벨 (플러그인 노드)**: 플러그인 식별자로 루트 노드 생성, 이름은 `name` 필드 또는 `plugin.json`의 `name` 사용
2. **2레벨 (카테고리 노드)**: `categories` 각 항목을 플러그인 노드의 자식으로 생성
3. **3레벨 (개별 권한)**: 각 카테고리의 `permissions` 항목을 카테고리 노드의 자식으로 생성
4. **리프 노드만 역할에 할당 가능** (그룹/카테고리 노드는 UI 그룹화 전용)
5. `categories`가 없는 플러그인은 권한 노드를 생성하지 않음

권한 설정 UI에서 모듈과 동일하게 "플러그인명 → 카테고리명 → 개별 권한" 구조로 표시됩니다.

> **참고**: 모듈 권한 구조 상세는 [permissions.md](permissions.md)를 참조하세요.

---

## 플러그인 원칙

### 플러그인 격리 원칙

```text
필수: 플러그인은 모듈에만 의존 (플러그인 간 직접 의존 금지)
✅ 필수: 다른 플러그인과는 API로만 상호작용
```

### 5대 원칙

#### 1. 격리성 (Isolation)

플러그인 간 직접 의존 금지

```php
// ❌ DON'T: 다른 플러그인 직접 참조
use Plugins\Other\Analytics\Service\AnalyticsService;

// ✅ DO: API를 통한 통신
$response = Http::get('/api/analytics/data');
```

#### 2. 의존성 (Dependency)

모듈에만 의존 가능

```php
public function getDependencies(): array
{
    return [
        // ✅ 모듈 의존성만 허용
        'sirsoft-ecommerce' => '>=1.0.0',

        // ❌ 다른 플러그인 의존성 금지
        // 'other-payment' => '>=1.0.0',
    ];
}
```

#### 3. API 통신 (API Communication)

다른 플러그인과는 API로만 상호작용

```php
// 플러그인 간 통신은 HTTP API 사용
$response = Http::withToken($token)
    ->get('/api/plugins/analytics/report');
```

#### 4. Lazy Loading

필요 시에만 로딩

```php
// PluginManager가 플러그인을 필요할 때만 로딩
// 활성화된 플러그인만 메모리에 로드
```

#### 5. 캐싱 (Caching)

플러그인 목록 캐싱으로 성능 최적화

```php
// PluginManager가 플러그인 목록을 캐싱
// 변경 시에만 캐시 갱신
```

#### 6. 레이아웃 제한 (Layout Restriction)

플러그인은 모듈과 달리 완전한 레이아웃을 등록할 수 없습니다.

```text
필수: 페이지 레이아웃은 모듈만 등록 가능 (플러그인은 layout_extensions만 사용)
허용: admin/plugin_settings.json (환경설정 UI) — 유일한 완전 레이아웃
✅ 필수: 설정 외 UI 확장은 layout_extensions(확장 지점/Overlay)로만 가능
```

| 방식 | 모듈 | 플러그인 |
|------|------|---------|
| 임의 페이지 레이아웃 (admin/user) | 가능 | 금지 |
| admin/plugin_settings.json (환경설정) | 가능 | 가능 |
| layout_extensions (확장 지점) | 가능 | 가능 |

---

## 플러그인 Artisan 커맨드

플러그인 관리는 모듈과 동일한 패턴의 Artisan 커맨드로 수행합니다.

### 주요 커맨드

| 커맨드 | 설명 |
|--------|------|
| `php artisan plugin:list` | 플러그인 목록 조회 |
| `php artisan plugin:install [identifier]` | 플러그인 설치 (Composer 의존성 자동 설치 포함) |
| `php artisan plugin:activate [identifier]` | 플러그인 활성화 |
| `php artisan plugin:deactivate [identifier]` | 플러그인 비활성화 |
| `php artisan plugin:uninstall [identifier]` | 플러그인 삭제 (--delete-data 시 vendor/ 삭제 포함) |
| `php artisan plugin:composer-install [identifier]` | 플러그인 Composer 의존성 설치 |
| `php artisan plugin:build [identifier]` | 플러그인 에셋 빌드 |
| `php artisan plugin:refresh-layout [identifier?]` | 플러그인 레이아웃 갱신 |
| `php artisan plugin:cache-clear [identifier?]` | 플러그인 캐시 삭제 |
| `php artisan plugin:seed [identifier]` | 플러그인 시더 실행 |

### 플러그인 에셋 빌드

```bash
php artisan plugin:build [identifier]
```

**옵션**:

- `--all`: 모든 플러그인의 에셋 빌드
- `--watch`: 파일 변경 감시 모드 (개발용)
- `--production`: 프로덕션 최적화 빌드

**수행 작업**:

- npm 의존성 설치 (node_modules 없는 경우)
- Vite를 통한 IIFE 번들 빌드
- `dist/js/plugin.iife.js` 및 `dist/css/plugin.css` 생성
- 빌드 결과 파일 크기 출력
- **extension_cache_version 증가 (프론트엔드 캐시 무효화)**
  - 주의: `--watch` 모드에서는 캐시 버전이 증가하지 않음

### 플러그인 시더 실행

```bash
php artisan plugin:seed [identifier]
```

**옵션**:

- `--class=SeederClass`: 특정 시더 클래스만 실행
- `--sample`: 샘플 데이터 시더도 함께 실행 (기본: 설치 시더만)
- `--count=key=value`: 시더에 카운트 옵션 전달 (반복 가능)
- `--force`: 프로덕션 환경에서 강제 실행

**예시**:

```bash
# 단일 플러그인 시더 실행 (설치 시더만)
php artisan plugin:seed sirsoft-payment

# 설치 + 샘플 시더 실행
php artisan plugin:seed sirsoft-payment --sample

# 모든 활성 플러그인 시더 실행
php artisan plugin:seed

# 특정 시더 클래스 실행
php artisan plugin:seed sirsoft-payment --class=PaymentMethodSeeder

# 프로덕션 환경에서 강제 실행
php artisan plugin:seed sirsoft-payment --force
```

**수행 작업**:

- 활성화된 플러그인만 실행 가능 (비활성 플러그인은 에러)
- `plugins/{identifier}/database/seeders/DatabaseSeeder.php` 실행
- 기본 실행 시 설치 필수 시더만 실행, `--sample` 옵션 시 Sample/ 하위 시더도 실행
- `--class` 옵션 사용 시 해당 시더만 실행

**주의사항**:

- 활성화되지 않은 플러그인은 시더 실행 불가
- 프로덕션 환경에서는 `--force` 옵션 필수
- 샘플 시더는 `database/seeders/Sample/` 하위 디렉토리에 위치

### 캐시 무효화

플러그인 활성화/비활성화/삭제 시 다음 캐시가 자동으로 무효화됩니다:

```text
✅ 템플릿 언어 캐시 (template.language.*)
✅ 템플릿 routes 캐시 (template.routes.*)
✅ extension_cache_version 증가 (프론트엔드 캐시 무효화)
✅ 플러그인 상태 캐시 무효화
```

이를 통해 플러그인 상태 변경 시 프론트엔드가 즉시 새로운 데이터를 받아올 수 있습니다.

---

## 번들 디렉토리 작업 규칙

```text
필수: 플러그인 수정/개발은 _bundled 디렉토리에서만 작업 (활성 디렉토리 직접 수정 금지)
필수: _bundled 작업 완료 후 반영/검증은 업데이트 프로세스 사용
```

### 개발 워크플로우

```text
1. plugins/_bundled/{identifier}/ 에서 코드 수정
2. plugin.json 버전 올리기
3. php artisan plugin:update {identifier} 로 활성 디렉토리에 반영
4. 테스트 실행으로 검증
```

### 왜 활성 디렉토리 직접 수정이 금지되는가?

- 활성 디렉토리는 `.gitignore` 대상 → Git에 변경 기록 불가
- 다음 업데이트 시 `_bundled` 소스로 덮어쓰기 → 직접 수정 사항 유실
- 업데이트 프로세스 미수행 시 마이그레이션/레이아웃 갱신 누락

### 예외: 초기 개발 (아직 _bundled에 미등록)

```text
✅ 허용: 신규 플러그인 초기 개발 시 활성 디렉토리에서 직접 작업
전환점: _bundled에 최초 반영한 이후부터는 반드시 _bundled에서만 작업
```

> 상세: [extension-update-system.md](./extension-update-system.md) "번들 디렉토리 개발 워크플로우" 참조

---

## 코드 변경 시 버전/업그레이드 필수

```text
필수: 플러그인 코드를 변경한 경우 버전을 올리고 필요 시 업그레이드 스텝을 작성해야 합니다.
버전 변경 없이 _bundled에 반영하면, 이미 설치된 환경에서 업데이트가 감지되지 않습니다.
```

### 필수 작업

1. **`plugin.json` 버전 올리기**: `version` 필드를 Semantic Versioning에 따라 증가
2. **`_bundled` 동기화**: `plugins/_bundled/{identifier}/` 디렉토리에 변경 사항 반영
3. **업그레이드 스텝 작성** (조건부): DB 스키마/환경설정 구조/데이터 마이그레이션이 필요한 경우

### 업그레이드 스텝 작성 기준

| 변경 유형 | 업그레이드 스텝 필요 | 비고 |
|----------|-------------------|------|
| DB 스키마 변경 | ✅ (+ 마이그레이션) | 컬럼/테이블 구조 변경 |
| 환경설정 구조 변경 | ✅ (SettingsMigrator) | 설정 키 이름/구조 변경 |
| 기존 데이터 변환 | ✅ | 데이터 형식 변환, 기본값 |
| 레이아웃 JSON 변경 | ❌ (자동 갱신) | refresh-layout에서 처리 |
| PHP 코드만 변경 | ❌ | 버전만 올리면 됨 |

> 상세: [extension-update-system.md](extension-update-system.md) "개발자 버전 업데이트 가이드" 참조

---

## 관련 문서

- [index.md](index.md) - 확장 시스템 전체 개요
- [extension-manager.md](extension-manager.md) - Composer 오토로드 관리
- [hooks.md](hooks.md) - 훅 시스템 및 리스너 구현
- [활동 로그 시스템](../backend/activity-log.md) - 활동 로그 Listener, Per-Item Bulk 로깅 규칙
- [module-basics.md](module-basics.md) - 모듈 개발 기초 (비교용)
- [extension-update-system.md](extension-update-system.md) - 확장 업데이트 시스템
