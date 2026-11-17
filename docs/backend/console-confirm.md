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
6. 기본값 「아니오」가 곧 작업 취소인 확인은 canAnswerConfirmation() 으로 먼저 가르고, 답할 수 없으면 refuseUnanswerableConfirmation() 으로 사유 출력 + 종료 코드 1 (취소를 0 으로 끝내지 않는다)
7. 트레이트를 쓰는 명령은 --yes(-y) 를 자동으로 받는다 — 묻지 않고 진행. 명령별 설명이 필요하면 시그니처에 {--y|yes : 설명} 으로 선언한다(트레이트는 그때 붙이지 않는다)
8. 확인을 거절해 끝낼 때는 return $this->confirmAbortExitCode() — 묻지 못해 거절된 실행은 경고 + 종료코드 1
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
- 그 외 입력 → "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문 루프 (안내 문구는 사이트 언어를 따른다 — Laravel 이 부팅되지 않은 단순 PHP 스크립트에서는 이 원문)
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
protected function unifiedConfirm(string $question, bool $default = false, bool $optional = false): bool
```

| 파라미터 | 설명 |
|---------|------|
| `$question` | 질문 메시지 (말미 `(yes/no) [yes\|no]:` 자동 부여) |
| `$default` | 기본값 — true=`[yes]` 표시 / false=`[no]` 표시 |
| `$optional` | 진행 여부가 아니라 **부가 선택**을 묻는 질문이면 true. `--yes`·비대화 실행에서 기본값을 그대로 쓰고, 경고·실패 처리도 하지 않는다 (예: 「검색 색인도 다시 만들까요?」) |

### 동작

| 환경 | 동작 |
|------|------|
| `--yes`(`-y`) 옵션 | 묻지 않고 `true` (부가 선택 질문은 `$default`) |
| `--no-interaction` 옵션 | `$default` 즉시 반환. `$default` 가 false 이고 부가 선택이 아니면 「`--yes` 를 붙이세요」 경고 |
| 콘솔 없는 STDIN (CI·스케줄러·에이전트) | 위와 같음 |
| 대화형 + 유효 입력 | 정규화 후 즉시 반환 |
| 대화형 + 잘못된 입력 | "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문 |
| 대화형 + empty 입력 | `$default` 반환 |

### `--yes` 옵션과 거절 종료코드 (7.1.6+)

트레이트가 Symfony `configure()` 로 `--yes`(`-y`) 옵션을 더하므로, 트레이트를 쓰는 명령은 시그니처에 적지 않아도
이 옵션을 받는다. 명령마다 도움말 설명을 달고 싶으면 시그니처에 `{--y|yes : 설명}` 으로 선언한다 — 트레이트는 시그니처에
`--yes` 선언이 있으면 옵션을 더하지 않으므로 「이미 있는 옵션」 오류가 나지 않는다(7.2.0. 7.1.6 은 선언하면 이 오류가 났다).
명령이 `configure()` 를 직접 재정의해야 하면 `parent::configure()` 가 아니라 트레이트 메서드를 별칭으로 불러야 한다.

터미널 없는 실행(스케줄러·배포 스크립트·`-n`)에서 확인 질문은 기본값 「아니오」로 떨어진다. 종전에는 이때 명령이
**아무 일도 하지 않고 성공(0)** 으로 끝나 실패를 알 수 없었다. 지금은:

- 트레이트가 「확인이 필요한 작업이라 실행하지 않았습니다 — `--yes` 를 붙이세요」 경고를 낸다.
- 명령은 거절 갈래에서 `SUCCESS` 를 직접 돌려주지 않고 `confirmAbortExitCode()` 를 돌려준다 — 묻지 못해 거절됐으면
  `FAILURE`(1), 사람이 직접 「아니오」라고 답했으면 `SUCCESS`(0).

```php
if (! $this->unifiedConfirm('Foo 를 삭제하시겠습니까?', false)) {
    $this->info('취소되었습니다.');

    return $this->confirmAbortExitCode();
}
```

`--yes` 는 「진행하시겠습니까?」 류의 확인에만 「예」로 답한다. 부가 선택 질문(`$optional = true`)과 선택·입력 질문은
기본값으로 진행한다 — `--yes` 가 「검색 색인도 다시 만들까요? [no]」 까지 「예」로 바꾸면 운영 중인 사이트에서
원하지 않은 무거운 작업이 따라 돈다.

### 선택·입력 질문 — `unifiedChoice()` / `unifiedAsk()`

`$this->choice()`·`$this->ask()` 를 직접 부르면 콘솔 없는 실행에서 오지 않을 답을 기다린다(위 「비TTY 판정」과 같은 이유).
확인 질문과 섞어 쓰는 선택·입력은 트레이트의 두 메서드로 부른다 — 물을 수 없거나 `--yes` 면 기본값을 곧바로 돌려준다.

```php
$strategy = $this->unifiedChoice('레이아웃 전략', ['overwrite' => '덮어쓰기', 'keep' => '유지'], 'overwrite');
$targets = $this->unifiedAsk('대상(쉼표로 구분)', '');
```

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

### 답할 수 없는 실행의 작업 취소는 실패로 끝낸다

기본값 「아니오」가 곧 작업 취소인 확인(업데이트, 제거, 삭제, 덮어쓰기)을 `unifiedConfirm()` 하나로만 받으면,
cron, CI, 원격 명령, `--no-interaction` 실행은 묻지 않고 취소되어 「취소되었습니다」와 종료 코드 0 으로 끝난다.
자동화는 그 0 을 작업이 끝났다는 뜻으로 읽는다. 그래서 확인 앞에서 먼저 가른다.

```php
if (! $this->option('force')) {
    if (! $this->canAnswerConfirmation()) {
        return $this->refuseUnanswerableConfirmation(
            __('foo.commands.delete.confirm_unavailable'),       // 작업하지 않은 사유
            __('foo.commands.delete.confirm_unavailable_hint'),  // 확인 생략 옵션, 없으면 터미널에서 실행하라는 안내
        );
    }

    if (! $this->unifiedConfirm(__('foo.commands.delete.confirm_question'), false)) {
        $this->info(__('foo.commands.delete.aborted'));

        return self::SUCCESS; // 대화형에서 운영자가 직접 답한 「아니오」는 의도한 취소라 0
    }
}
```

| 메서드 | 동작 |
|--------|------|
| `canAnswerConfirmation(): bool` | `--yes` 로 미리 답했거나, `isInteractive()` 이고 `canPromptForAnswer()` 이면 true. 답할 수 없는 실행 판정의 단일 지점이며 `unifiedConfirm()` 도 이 판정을 쓴다 |
| `confirmationPreApproved(): bool` | `--yes` 가 주어졌으면 true(트레이트가 붙인 옵션이든 시그니처 선언이든) — `unifiedConfirm()` 은 묻지 않고 true(7.2.0) |
| `canShowPrompt(): bool` | `isInteractive()` 이고 `canPromptForAnswer()` 이면 true — `--yes` 는 보지 않는다. 확인 외의 질문과 선택 질문은 이 판정으로 가른다(7.2.0) |
| `optionalConfirm(string $question, bool $default = false): bool` | 선택 질문용 yes/no — `--yes` 가 「예」로 답하지 않고, 질문을 띄울 수 없으면 `$default`(7.2.0) |
| `refuseUnanswerableConfirmation(string $reason, string $hint): int` | 사유를 오류로, 안내를 한 줄로 출력하고 `Command::FAILURE` 를 돌려준다 |

확인 외의 질문(`choice()`·`ask()`·`secret()`)은 `canShowPrompt()` 로 먼저 가른다 — 콘솔 없는 실행에서 그 질문을 띄우면 위와 같은 이유로 영영 끝나지 않는다.
`canAnswerConfirmation()` 은 `--yes` 를 준 실행도 「답할 수 있음」으로 보므로 이 갈래에 쓰지 않는다. 미서명 패키지 확인(`HandlesPackageIntegrity::runWithIntegrityConsent()`)도 `--yes` 가 답하지 않는 질문이라 `canShowPrompt()`·`optionalConfirm()` 으로 묻는다 — 동의는 `--allow-unsigned` 하나로만 한다.
답할 수 없으면 기본값을 쓰거나(일괄 업데이트의 레이아웃 전략은 overwrite, 확장별 덮어쓰기·검색 인덱스 재생성은 묻지 않음) 거절한다
(`user:reset-password`·`settings:set` 의 비밀값 입력은 출처 옵션 안내와 함께 실패).

`--yes`(`-y`) 는 **확인 질문에만** 「예」로 답한다(`module|plugin|template:update`·`:uninstall`·`language-pack:update`·`language-pack:uninstall`·`core:update`·`core:execute-upgrade-steps`·`stage:apply-to-live`·`stage-layouts:reset-from-live`·`settings:migrate-to-json`·`search:index`·`analytics:anonymize`·`analytics:purge`·`analytics:purge-extension`·`hotfix:rollback-stale-files`). 7.1.6 에서 `--yes` 를 받던 명령은 7.2.0 에서도 모두 받는다 — 확장 명령을 포함해 7.1.6 스크립트가 「옵션 없음」으로 실패하지 않게 한다. 시그니처에 선언할 때는 `{--y|yes : …}` 로 `-y` 축약을 함께 둔다 — `{--yes}` 만 두면 `-y` 가 「옵션 없음」으로 실패한다. 확인 질문이 없는 `language-pack:update` 도 다른 `{type}:update` 와 같은 형태로 부를 수 있게 받는다(효과 없음). 업데이트의 `--force` 는
확인 생략에 더해 ① 같은 판 재적용 ② 다운그레이드 허용 ③ 코어 호환 검사 생략 ④ 같은 판 업그레이드 스텝 재실행 ⑤ 멈춘 업데이트 정리를 함께 켜므로,
비대화형 자동화가 확인만 건너뛰려면 `--yes` 를 쓴다.

단위 테스트에서 `canPromptForAnswer()` 는 늘 true 다(입력을 주입하므로). 「콘솔 없는 실행」 갈래는 `App\Console\Helpers\PromptCapability::fake(false)` 로
고정해 재고, `tearDown()` 에서 `PromptCapability::reset()` 으로 되돌린다.

안내 문구는 사실대로 쓴다. 확인 생략 옵션이 있는 명령은 그 옵션(`--yes`, 같은 판 재적용이 필요할 때만 `--force`)을 안내하고,
없는 명령은 터미널에서 실행해 질문에 답하라고 안내한다. 되돌릴 수 없는 삭제(`analytics:purge` 등)의 필수 동의
표식(`--confirm`)은 비대화형 동의가 아니다 — 비대화형 동의는 `--yes` 하나다. 이 규칙을 따르는 명령: `core:update`, `module:update`, `plugin:update`,
`template:update`, `module:uninstall`, `plugin:uninstall`, `template:uninstall`, `language-pack:uninstall`,
`user:reset-password`(이메일을 생략해 최고관리자를 대상으로 할 때 — 확인 생략 옵션 대신 이메일 지정을 안내),
`analytics:anonymize`, `analytics:purge`, `analytics:purge-extension`, `hotfix:rollback-stale-files --prune`,
`settings:migrate-to-json`.

선택 질문(기본값이 문서화된 진행 방식이고 거절이 작업 취소가 아닌 것, 예: 확장별 덮어쓰기 변경 여부, 검색 인덱스 재생성 여부)은
`optionalConfirm()` 으로 묻는다 — `--yes` 가 「예」로 답하지 않고, 질문을 띄울 수 없으면 기본값을 그대로 쓴다.

---

## ConsoleConfirm helper (Symfony 비의존)

`HasUnifiedConfirm` 은 Symfony QuestionHelper 를 거치므로 Laravel Command 외부 (예: upgrade step, 단순 PHP CLI 스크립트) 에서는 사용할 수 없다. 그런 경우 `ConsoleConfirm::ask()` 를 직접 호출한다.

터미널 설치(`php g7-install`, 7.1.4)의 예/아니오 질문(`g7_cli_confirm()`)도 이 헬퍼를 쓴다 — 설치기는 vendor 없이 도는 순수 PHP 라 파일을 경로로 직접 `require_once` 한다(오토로더 없음). 설치기에 별도 예/아니오 해석을 두지 않는다.

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

`--no-interaction` 시 default 동작 회귀(선택 질문):

```php
$this->artisan('foo:bar --no-interaction')->assertExitCode(0);
```

작업 취소 확인은 비대화형이 실패(1)로 끝나는지 본다. 테스트 실행에서는 `canPromptForAnswer()` 가 늘 true 이므로
「터미널 없음」은 그 메서드만 false 로 바꾼 익명 하위 클래스를 `Kernel::registerCommand()` 로 등록해 흉내 낸다
(`tests/Feature/Console/ExtensionLifecycleNonInteractiveConfirmTest.php` 참조).

```php
$this->artisan('foo:delete --no-interaction')
    ->expectsOutputToContain(__('foo.commands.delete.confirm_unavailable'))
    ->assertExitCode(1);
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

□ 「아니오」가 곧 작업 취소인 확인인가?
   → 확인 앞에서 canAnswerConfirmation() 으로 가르고, 답할 수 없으면 refuseUnanswerableConfirmation() 으로 종료 코드 1

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
