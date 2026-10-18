# 콘솔 yes/no 프롬프트 (ConsoleConfirm)

> **관련 문서**: [README.md](README.md) | [docs/extension/upgrade-step-guide.md](../extension/upgrade-step-guide.md)

---

## TL;DR (5초 요약)

```text
1. 콘솔 커맨드의 yes/no 프롬프트는 $this->unifiedConfirm() 사용 — Laravel $this->confirm() 직접 호출 금지
2. trait: App\Console\Commands\Traits\HasUnifiedConfirm
3. 입력 규칙: empty=default, yes/y=true, no/n=false, 그 외=재질문 루프
4. --no-interaction 또는 콘솔 없는 STDIN: default 즉시 반환 (파괴적 확인의 default 는 false 여야 한다)
5. upgrade step / Symfony 외부 환경: \App\Console\Helpers\ConsoleConfirm::ask() FQN 직접 호출
```

---

## 목차

1. [왜 통일 헬퍼가 필요한가](#왜-통일-헬퍼가-필요한가)
2. [HasUnifiedConfirm trait (Laravel Command)](#hasunifiedconfirm-trait-laravel-command)
3. [ConsoleConfirm helper (Symfony 비의존)](#consoleconfirm-helper-symfony-비의존)
4. [입력 처리 규칙](#입력-처리-규칙)
5. [테스트 작성 방법](#테스트-작성-방법)
6. [안티 패턴 vs 모범 사례](#안티-패턴-vs-모범-사례)
7. [체크리스트](#체크리스트)

---

## 왜 통일 헬퍼가 필요한가

Laravel `Command::confirm()` (Symfony `ConfirmationQuestion`) 의 기본 동작은 입력 검증이 느슨하다:

- `/^y/i` 만 true 로 처리 → `yell_no`, `yummy` 도 true 로 인식
- empty 입력만 default 사용, 잘못된 입력은 묵묵히 false
- 사용자 피드백 없음 (재질문 없이 false 처리)

본 헬퍼는 다음을 표준화한다:

- 입력을 trim + 소문자 정규화
- `yes`/`y` 만 true, `no`/`n` 만 false
- empty → default
- 그 외 입력 → "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문 루프
- `--no-interaction` 또는 비TTY 환경 → default 즉시 반환

---

## HasUnifiedConfirm trait (Laravel Command)

### 사용법

```php
use App\Console\Commands\Traits\HasUnifiedConfirm;
use Illuminate\Console\Command;

class UninstallFooCommand extends Command
{
    use HasUnifiedConfirm;

    public function handle(): int
    {
        if (! $this->unifiedConfirm('Foo 를 삭제하시겠습니까?', false)) {
            $this->info('취소되었습니다.');

            return self::SUCCESS;
        }

        // 삭제 로직
        return self::SUCCESS;
    }
}
```

### 시그니처

```php
protected function unifiedConfirm(string $question, bool $default = false): bool
```

| 파라미터 | 설명 |
|---------|------|
| `$question` | 질문 메시지 (말미 `(yes/no) [yes\|no]:` 자동 부여) |
| `$default` | 기본값 — true=`[yes]` 표시 / false=`[no]` 표시 |

### 동작

| 환경 | 동작 |
|------|------|
| `--no-interaction` 옵션 | `$default` 즉시 반환 |
| 콘솔 없는 STDIN (CI·스케줄러·에이전트) | `$default` 즉시 반환 |
| 대화형 + 유효 입력 | 정규화 후 즉시 반환 |
| 대화형 + 잘못된 입력 | "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문 |
| 대화형 + empty 입력 | `$default` 반환 |

#### 비TTY 판정을 Symfony 에 맡기지 않는 이유

Symfony 는 `posix_isatty()` 로 비대화 실행을 감지해 `isInteractive()` 를 false 로 내린다.
그런데 **Windows PHP 에는 posix 확장이 없어 그 감지 분기 자체가 실행되지 않는다.** 결과적으로
`--no-interaction` 없이 콘솔 없는 곳에서 부르면 `isInteractive()` 가 true 로 남고, 프롬프트는
버퍼에 갇힌 채 `ask()` 가 오지 않을 응답을 무한히 기다린다. 예외도 로그도 없어 겉으로는
**"그 커맨드가 느리다"** 로만 보인다 — 실제로는 영영 끝나지 않는다.

그래서 `unifiedConfirm()` 은 `canPromptForAnswer()` 로 한 번 더 판정한다. `stream_isatty()` 는
posix 확장 없이 Windows 를 포함해 동작하므로 이 판정의 근거로 쓴다. 판정 재료가 없으면
`true`(질문함)로 떨어진다 — 물어볼 수 있는데 안 묻는 쪽이 더 위험하기 때문이다.

이 가드가 의미를 가지려면 **파괴적 확인의 `$default` 는 반드시 `false`(중단)** 여야 한다.
콘솔이 없으면 그 기본값이 곧 결정이 되기 때문이다.

---

## ConsoleConfirm helper (Symfony 비의존)

`HasUnifiedConfirm` 은 Symfony QuestionHelper 를 거치므로 Laravel Command 외부 (예: upgrade step, 단순 PHP CLI 스크립트) 에서는 사용할 수 없다. 그런 경우 `ConsoleConfirm::ask()` 를 직접 호출한다.

### 사용법

```php
$confirmed = \App\Console\Helpers\ConsoleConfirm::ask(
    '진행하시겠습니까?',
    true, // default = yes
);
```

### 시그니처

```php
public static function ask(
    string $question,
    bool $default = false,
    $stdin = null,        // 테스트용 STDIN 주입
    ?callable $writer = null, // 테스트용 출력 콜백
): bool
```

### upgrade step 에서 사용 시 주의

`upgrades/Upgrade_X_Y_Z.php` 는 **이전 버전 PHP 메모리에서 실행**된다. 신규 클래스 호출은 PHP autoloader 가 lazy load 하므로 안전하지만, **FQN 직접 호출** 권장 (use 문 의존 회피).

```php
$confirmed = \App\Console\Helpers\ConsoleConfirm::ask(
    '번들 일괄 업데이트를 진행하시겠습니까?',
    true,
);
```

> **상세**: [docs/extension/upgrade-step-guide.md](../extension/upgrade-step-guide.md) "사용자 입력" 섹션

---

## 입력 처리 규칙

### 정규화 단계

1. `trim()` — 좌우 공백/개행 제거
2. `strtolower()` — 대소문자 무시

### 결정 매트릭스

| 정규화된 입력 | default 무관 | 결과 |
|--------------|-------------|------|
| `''` (empty) | true | true |
| `''` (empty) | false | false |
| `yes`, `y` | - | true |
| `no`, `n` | - | false |
| `yell_no`, `nope`, `1`, `true`, `예`, `네` 등 | - | **재질문** |

### EOF / 비TTY

| 환경 | 결과 |
|------|------|
| `STDIN` 닫힘 (EOF) | `default` |
| TTY 미연결 (CI, spawn) | `default` |
| `--no-interaction` 옵션 | `default` |

---

## 테스트 작성 방법

### Unit — 입력 정규화 (`ConsoleConfirm::parse`)

```php
use App\Console\Helpers\ConsoleConfirm;

public function test_parse(): void
{
    $this->assertTrue(ConsoleConfirm::parse('YES', false));
    $this->assertFalse(ConsoleConfirm::parse('  no  ', true));
    $this->assertNull(ConsoleConfirm::parse('yell_no', false)); // 재질문
    $this->assertTrue(ConsoleConfirm::parse('', true));         // empty → default
}
```

### Unit — STDIN 루프 (`ConsoleConfirm::ask`)

테스트용 stream 주입:

```php
$stream = fopen('php://memory', 'r+');
fwrite($stream, "abc\ny\n");
rewind($stream);

$result = ConsoleConfirm::ask('Q?', false, $stream, fn (string $t) => null);
$this->assertTrue($result);
```

### Feature — Laravel Artisan (`unifiedConfirm`)

`expectsQuestion()` 사용 — 질문 텍스트는 `'{question} (yes/no) [no]'` 형식:

```php
$this->artisan('foo:bar')
    ->expectsQuestion('진행하시겠습니까? (yes/no) [no]', 'yes')
    ->assertExitCode(0);
```

`--no-interaction` 또는 `--force` 시 default 동작 회귀:

```php
$this->artisan('foo:bar --no-interaction')->assertExitCode(0);
```

### 주의

- `expectsConfirmation()` (Laravel 헬퍼) 는 Symfony `ConfirmationQuestion` 전용이므로 신규 trait 와 호환되지 않는다 → **`expectsQuestion()` 사용**

---

## 안티 패턴 vs 모범 사례

### ❌ 안티 패턴

```php
// 1. Laravel 기본 confirm 직접 호출 (입력 검증 느슨)
if (! $this->confirm('정말 삭제하시겠습니까?')) { ... }

// 2. fgets(STDIN) 직접 호출 (TTY 가드 없음, 재질문 없음)
echo "진행할까요? (y/n): ";
$answer = trim(fgets(STDIN));
if (strtolower($answer) === 'y') { ... }

// 3. ask() 로 yes/no 받기 (자유 텍스트 입력에 적합, 정규화 없음)
$answer = $this->ask('진행할까요? (y/n)');
```

### ✅ 모범 사례

```php
// 1. Laravel Command — trait 사용
class FooCommand extends Command
{
    use HasUnifiedConfirm;

    public function handle(): int
    {
        if (! $this->unifiedConfirm('정말 삭제하시겠습니까?', false)) {
            return self::SUCCESS;
        }
    }
}

// 2. upgrade step 또는 외부 환경 — FQN 직접 호출
$confirmed = \App\Console\Helpers\ConsoleConfirm::ask('진행할까요?', true);
```

---

## 체크리스트

```text
□ Laravel Command 에서 yes/no 입력이 필요한가?
   → use HasUnifiedConfirm + $this->unifiedConfirm($question, $default)

□ upgrade step 또는 단순 PHP CLI 에서 yes/no 입력이 필요한가?
   → \App\Console\Helpers\ConsoleConfirm::ask($question, $default) FQN 호출

□ 자유 텍스트 입력이 필요한가?
   → $this->ask() 그대로 사용 (yes/no 정규화 불필요)

□ 다중 옵션 선택이 필요한가?
   → $this->choice() 사용 (yes/no 정규화 불필요)

□ 테스트는 expectsQuestion('{question} (yes/no) [default]', 'yes/no') 형식으로 작성했는가?
   → expectsConfirmation() 은 신규 trait 와 호환되지 않음
```

---

## 참고 파일

- **ConsoleConfirm**: `app/Console/Helpers/ConsoleConfirm.php`
- **HasUnifiedConfirm**: `app/Console/Commands/Traits/HasUnifiedConfirm.php`
- **단위 테스트**: `tests/Unit/Console/Helpers/ConsoleConfirmTest.php`, `tests/Unit/Console/Commands/Traits/HasUnifiedConfirmTest.php`
- **회귀 테스트**: `tests/Feature/Console/UnifiedConfirmRegressionTest.php`
