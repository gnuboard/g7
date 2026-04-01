# 다국어 키 추출 (extract-i18n-keys)

레이아웃 JSON 파일에서 사용된 다국어 키를 추출하고 언어 파일과 비교하여 누락된 키를 보고합니다.

## 0단계: 추출 대상 판별

```text
⚠️ CRITICAL: 추출 대상 경로 확인
```

### 대상 경로 유형

| 경로 패턴 | 유형 | 언어 파일 위치 |
| --------- | ---- | -------------- |
| `modules/[vendor-module]/resources/layouts/**/*.json` | 모듈 레이아웃 | `modules/[vendor-module]/resources/lang/*.json` |
| `modules/_bundled/[vendor-module]/resources/layouts/**/*.json` | _bundled 모듈 레이아웃 | `modules/_bundled/[vendor-module]/resources/lang/*.json` |
| `templates/[vendor-template]/layouts/**/*.json` | 템플릿 레이아웃 | `templates/[vendor-template]/lang/*.json` |
| `templates/_bundled/[vendor-template]/layouts/**/*.json` | _bundled 템플릿 레이아웃 | `templates/_bundled/[vendor-template]/lang/*.json` |
| `resources/layouts/**/*.json` | 코어 레이아웃 | `lang/*.json` |

### $ARGUMENTS 처리

- 경로가 지정된 경우: 해당 경로의 JSON 파일만 대상
- 경로가 지정되지 않은 경우: 사용자에게 대상 경로 문의

## 1단계: 레이아웃 파일 수집

### 1.1 대상 파일 탐색

```bash
# 모듈 레이아웃 예시
find modules/sirsoft-ecommerce/resources/layouts -name "*.json" -type f

# 템플릿 레이아웃 예시
find templates/sirsoft-admin_basic/layouts -name "*.json" -type f
```

### 1.2 파일 목록 생성

수집된 파일 목록을 정리:
- 메인 레이아웃 파일
- partials 디렉토리 내 파일
- 중첩된 모든 JSON 파일

## 2단계: 다국어 키 추출

### 2.1 추출 패턴

다음 패턴의 다국어 키를 추출합니다:

| 패턴 | 설명 | 예시 |
| ---- | ---- | ---- |
| `$t:namespace.key.path` | 즉시 번역 | `$t:sirsoft-ecommerce.admin.product.name` |
| `$t:defer:namespace.key.path` | 지연 번역 | `$t:defer:common.save` |

### 2.2 추출 정규식

```javascript
// $t: 패턴 추출 (defer 포함)
const regex = /\$t:(?:defer:)?([a-zA-Z][a-zA-Z0-9_.-]*(?:\.[a-zA-Z0-9_-]+)+)/g;
```

### 2.3 키 분류

추출된 키를 네임스페이스별로 분류:

| 키 패턴 | 분류 | 저장 위치 |
| ------- | ---- | --------- |
| `sirsoft-ecommerce.*` | 모듈 전용 | `modules/sirsoft-ecommerce/resources/lang/*.json` |
| `common.*` | 공통 키 | 코어 또는 모듈 lang 파일 |
| `messages.*` | 메시지 | 코어 또는 모듈 lang 파일 |
| `currency.*` | 통화 | 코어 또는 모듈 lang 파일 |
| `validation.*` | 검증 | 코어 또는 모듈 lang 파일 |

### 2.4 모듈 네임스페이스 검증 (CRITICAL)

모듈 레이아웃에서 사용된 다국어 키가 올바른 네임스페이스를 사용하는지 검증합니다.

```text
⚠️ CRITICAL: 모듈 레이아웃에서 다국어 키 사용 시 반드시 모듈 식별자 접두사 포함
```

| ❌ 금지 | ✅ 올바른 사용 |
| -------- | --------------- |
| `$t:common.save` (모듈에서 코어 키 직접 참조) | `$t:sirsoft-ecommerce.common.save` (모듈 식별자 접두사) |
| `$t:admin.product.name` (네임스페이스 누락) | `$t:sirsoft-ecommerce.admin.product.name` |

**검증 항목**:

- 모듈 레이아웃 내 `$t:` 키가 해당 모듈 식별자로 시작하는지 확인
- `$t:common.*` 형태로 코어 키를 직접 참조하면 경고 (모듈 lang 파일이 아닌 코어 lang에서 찾게 됨)
- 예외: `$t:common.*`이 코어 키를 의도적으로 참조하는 경우 (명시적 확인 필요)

## 3단계: 언어 파일 분석

### 3.1 언어 파일 읽기

```bash
# 모듈 언어 파일
modules/[vendor-module]/resources/lang/ko.json
modules/[vendor-module]/resources/lang/en.json

# 코어 언어 파일
lang/ko.json
lang/en.json
```

### 3.2 기존 키 추출

JSON 파일에서 모든 키 경로를 추출:

```javascript
// 중첩된 객체에서 키 경로 추출
function extractKeys(obj, prefix = '') {
  const keys = [];
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'object' && value !== null) {
      keys.push(...extractKeys(value, path));
    } else {
      keys.push(path);
    }
  }
  return keys;
}
```

## 4단계: 비교 및 분석

### 4.1 누락 키 식별

레이아웃에서 사용된 키 vs 언어 파일에 존재하는 키 비교:

```text
사용된 키 - 존재하는 키 = 누락된 키
```

### 4.2 미사용 키 식별 (선택)

언어 파일에 존재하지만 레이아웃에서 사용되지 않는 키:

```text
존재하는 키 - 사용된 키 = 미사용 키
```

## 5단계: 결과 보고

### 5.1 보고서 형식

```text
## 다국어 키 추출 결과

### 추출 대상
- 레이아웃 경로: [경로]
- 검사 파일 수: X개
- 언어 파일: ko.json, en.json

### 추출된 키 요약
- 총 사용된 키: XXX개
- 모듈 전용 키 (sirsoft-ecommerce.*): XXX개
- 공통 키 (common.*): XX개
- 메시지 키 (messages.*): XX개
- 기타 키: XX개

### 언어 파일 현황
- ko.json 존재 키: XXX개
- en.json 존재 키: XXX개

### 누락된 키 (CRITICAL)
- 총 누락: XX개 (XX.X% 누락률)

#### 모듈 전용 키 누락 (sirsoft-ecommerce.*)
| 키 | 사용 위치 |
|----|----------|
| sirsoft-ecommerce.admin.product.xxx | file.json:line |
| ... | ... |

#### 공통 키 누락 (common.*)
| 키 | 사용 위치 |
|----|----------|
| common.xxx | file.json:line |
| ... | ... |

### 미사용 키 (참고)
- 총 미사용: XX개
| 키 | 위치 |
|----|------|
| sirsoft-ecommerce.xxx.unused | ko.json |
```

### 5.2 키 생성 템플릿 제공

누락된 키에 대한 JSON 템플릿 생성:

```json
// ko.json에 추가할 키
{
  "admin": {
    "product": {
      "missing_key_1": "[한국어 번역 필요]",
      "missing_key_2": "[한국어 번역 필요]"
    }
  }
}

// en.json에 추가할 키
{
  "admin": {
    "product": {
      "missing_key_1": "[English translation needed]",
      "missing_key_2": "[English translation needed]"
    }
  }
}
```

## 6단계: 추출 스크립트 (참고)

### Node.js 추출 스크립트 예시

```javascript
const fs = require('fs');
const path = require('path');

// 디렉토리 내 모든 JSON 파일 찾기
function findJsonFiles(dir, files = []) {
  const items = fs.readdirSync(dir);
  for (const item of items) {
    const fullPath = path.join(dir, item);
    if (fs.statSync(fullPath).isDirectory()) {
      findJsonFiles(fullPath, files);
    } else if (item.endsWith('.json')) {
      files.push(fullPath);
    }
  }
  return files;
}

// $t: 키 추출
function extractI18nKeys(content) {
  const regex = /\$t:(?:defer:)?([a-zA-Z][a-zA-Z0-9_.-]*(?:\.[a-zA-Z0-9_-]+)+)/g;
  const keys = new Set();
  let match;
  while ((match = regex.exec(content)) !== null) {
    keys.add(match[1]);
  }
  return keys;
}

// JSON에서 키 경로 추출
function extractExistingKeys(obj, prefix = '', namespace = '') {
  const keys = new Set();
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    const fullPath = namespace ? `${namespace}.${path}` : path;
    if (typeof value === 'object' && value !== null) {
      const childKeys = extractExistingKeys(value, path, namespace);
      childKeys.forEach(k => keys.add(k));
    } else {
      keys.add(fullPath);
    }
  }
  return keys;
}

// 메인 실행
const layoutDir = process.argv[2] || 'modules/sirsoft-ecommerce/resources/layouts';
const langDir = process.argv[3] || 'modules/sirsoft-ecommerce/resources/lang';
const namespace = process.argv[4] || 'sirsoft-ecommerce';

// 레이아웃에서 사용된 키 추출
const layoutFiles = findJsonFiles(layoutDir);
const usedKeys = new Set();
for (const file of layoutFiles) {
  const content = fs.readFileSync(file, 'utf-8');
  const keys = extractI18nKeys(content);
  keys.forEach(k => usedKeys.add(k));
}

// 언어 파일에서 존재하는 키 추출
const koJson = JSON.parse(fs.readFileSync(path.join(langDir, 'ko.json'), 'utf-8'));
const existingKeys = extractExistingKeys(koJson, '', namespace);

// 비교
const missingKeys = [...usedKeys].filter(k => !existingKeys.has(k));
const unusedKeys = [...existingKeys].filter(k => !usedKeys.has(k));

console.log('=== 다국어 키 추출 결과 ===');
console.log(`사용된 키: ${usedKeys.size}개`);
console.log(`존재하는 키: ${existingKeys.size}개`);
console.log(`누락된 키: ${missingKeys.length}개`);
console.log(`미사용 키: ${unusedKeys.length}개`);

if (missingKeys.length > 0) {
  console.log('\n=== 누락된 키 목록 ===');
  missingKeys.sort().forEach(k => console.log(k));
}
```

---

## 핵심 원칙

```text
⚠️ CRITICAL:
- 모든 $t: 패턴 추출 (defer 포함)
- 모듈 네임스페이스와 공통 키 모두 추출
- 모듈 레이아웃: $t:키에 모듈 식별자 접두사 필수 ($t:sirsoft-ecommerce.xxx)
- _bundled 디렉토리 레이아웃도 추출 대상에 포함
- 정확한 키 경로 비교 (중첩 객체 고려)
- 누락 키에 대한 실용적인 템플릿 제공
- ko.json과 en.json 모두 검사
```

---

## 사용 예시

```bash
# 모듈 레이아웃 키 추출
/extract-i18n-keys modules/sirsoft-ecommerce/resources/layouts

# 특정 레이아웃 파일만 추출
/extract-i18n-keys modules/sirsoft-ecommerce/resources/layouts/admin/admin_ecommerce_product_form.json

# 템플릿 레이아웃 키 추출
/extract-i18n-keys templates/sirsoft-admin_basic/layouts
```
