<?php

namespace App\Console\Commands\Settings;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Exceptions\SettingsConsoleException;
use App\Services\SettingsConsoleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * 설정 값 하나를 서버 터미널에서 지정한다 — 관리자 화면 「저장」과 같은 검증·같은 저장 경로.
 *
 * 키는 설정 파일과 같은 `범주.항목`(코어·모듈) 또는 플러그인 설정 키다. 민감 값(비밀번호·비밀 키·토큰)은
 * 명령줄 인자로 받지 않는다(프로세스 목록·셸 기록에 남는다) — 환경변수 G7_SETTING_VALUE, --value-file, 숨김 입력만.
 * 문자열 값은 현재 값의 형태(참/거짓·정수·실수)로 바꿔 검증에 넘긴다. 배열·객체는 --json-value 로 통째로 준다.
 */
class SettingsSetCommand extends Command
{
    use LocalizesDescription;

    /**
     * 명령 시그니처.
     *
     * @var string
     */
    protected $signature = 'settings:set
        {key : 설정 키 (예: mail.host, security.max_login_attempts)}
        {value? : 새 값 (민감 값은 받지 않음 — G7_SETTING_VALUE·--value-file·숨김 입력)}
        {--plugin= : 플러그인 설정 (플러그인 식별자)}
        {--module= : 모듈 설정 (모듈 식별자)}
        {--value-file= : 값을 담은 파일 (끝 개행 하나만 제거)}
        {--json-value : 값을 JSON 으로 해석 (배열·객체 — 통째로 교체)}';

    /**
     * 값을 정하고 저장한다.
     *
     * @param  SettingsConsoleService  $service  터미널 설정 조회·저장
     * @return int 종료 코드 (성공 0, 실패 1)
     */
    public function handle(SettingsConsoleService $service): int
    {
        $key = (string) $this->argument('key');
        $plugin = $this->option('plugin') ?: null;
        $module = $this->option('module') ?: null;
        $sensitive = $service->isSensitive($key, $plugin, $module);

        try {
            $raw = $this->resolveRawValue($key, $sensitive);
            if ($raw === null) {
                return self::FAILURE;
            }

            $value = $this->option('json-value')
                ? $this->decodeJson($raw)
                : $this->castLike($raw, $service->currentValue($key, $plugin, $module));

            $result = $service->set($key, $value, $plugin, $module);
        } catch (SettingsConsoleException $e) {
            $this->error('❌ '.$e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error('❌ '.__('settings.console.cli.validation_failed'));
            foreach ($e->errors() as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $this->line("   - {$field}: {$message}");
                }
            }

            return self::FAILURE;
        }

        $this->info('✓ '.__('settings.console.cli.saved', ['key' => $key]));
        $this->line(__('settings.console.cli.changed', [
            'before' => $this->present($result['before'], $sensitive),
            'after' => $this->present($result['after'], $sensitive),
        ]));
        if (is_array($value)) {
            $this->line('   '.__('settings.console.cli.array_replaced'));
        }
        // 설정 캐시 파일은 코어 저장만 다시 만든다 — 플러그인·모듈 저장은 설정 미러를 갱신하고 설정 버전을 올린다(화면 저장과 같다).
        if ($plugin === null && $module === null) {
            $this->line('   '.__('settings.console.cli.cache_rebuilt'));
        }

        return self::SUCCESS;
    }

    /**
     * 값 원문 — 환경변수 → --value-file → 위치 인자(민감 값이면 거부) → 숨김 입력(민감 값·대화형).
     *
     * @param  string  $key  설정 키
     * @param  bool  $sensitive  민감 값 여부
     * @return string|null 값 원문 (얻지 못하면 null — 사유 출력)
     */
    private function resolveRawValue(string $key, bool $sensitive): ?string
    {
        $fromEnv = getenv('G7_SETTING_VALUE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $file = $this->option('value-file');
        if (is_string($file) && $file !== '') {
            $content = @file_get_contents($file);
            if (! is_string($content)) {
                $this->error('❌ '.__('settings.console.cli.value_file_unreadable', ['path' => $file]));

                return null;
            }

            return (string) preg_replace('/\r?\n\z/', '', $content);
        }

        $positional = $this->argument('value');
        if ($positional !== null) {
            if ($sensitive) {
                $this->error('❌ '.__('settings.console.cli.sensitive_positional', ['key' => $key]));

                return null;
            }

            return (string) $positional;
        }

        if ($sensitive && $this->input->isInteractive()) {
            return (string) $this->secret(__('settings.console.cli.ask_secret', ['key' => $key]));
        }

        $this->error('❌ '.__('settings.console.cli.value_missing'));

        return null;
    }

    /**
     * JSON 값 해석.
     *
     * @param  string  $raw  JSON 원문
     * @return mixed 해석한 값
     *
     * @throws SettingsConsoleException JSON 이 아닌 경우
     */
    private function decodeJson(string $raw): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SettingsConsoleException(__('settings.console.cli.invalid_json', ['error' => $e->getMessage()]));
        }
    }

    /**
     * 문자열 값을 현재 값의 형태로 바꾼다 — 바꿀 수 없으면 원문 그대로 둬 검증이 거부하게 한다.
     *
     * @param  string  $raw  값 원문
     * @param  mixed  $current  현재 값
     * @return mixed 변환한 값
     */
    private function castLike(string $raw, mixed $current): mixed
    {
        if (is_bool($current)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $raw;
        }

        if (is_int($current) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if (is_float($current) && is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * 출력용 값 표현 (민감 값은 가림).
     *
     * @param  mixed  $value  값
     * @param  bool  $sensitive  민감 값 여부
     * @return string 표현
     */
    private function present(mixed $value, bool $sensitive): string
    {
        if ($sensitive && $value !== null && $value !== '') {
            return SettingsConsoleService::MASK;
        }

        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
