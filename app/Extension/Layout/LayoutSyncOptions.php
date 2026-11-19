<?php

namespace App\Extension\Layout;

use Closure;
use InvalidArgumentException;

/**
 * 레이아웃 동기화 한 번의 실행 옵션 — 미리보기(dry-run)와 화면별 결정.
 *
 * 동기화는 매니저 → 서비스 → 동기화기로 여러 층을 지나므로 인자로 끝까지 넘기지 않고 실행 범위({@see run()})로 건넨다.
 * 범위 밖에서는 기본값(쓰기 허용·결정 없음)이다.
 *
 * - `dryRun`: 판정만 계산하고 DB·캐시·파일에 쓰지 않는다. 쓰기 지점마다 {@see writes()} 로 가드한다(트랜잭션 롤백 방식은
 *   모델 이벤트의 캐시 무효화 같은 부수효과를 되돌리지 못해 쓰지 않는다).
 * - `decisions`: 화면별 결정. 키는 레이아웃 `layout:{템플릿}:{이름}`, 조각 `extension:{템플릿}:{출처 식별자}:{조각 키}`,
 *   값은 `take-theirs`(그 화면만 새 판으로 덮기) 또는 `keep-ours`(운영자 판 유지).
 * - `sourceRoot`: 레이아웃 파일을 읽을 확장 루트 (업데이트 미리보기가 새 판 디렉토리를 넘긴다, null 이면 활성 디렉토리).
 */
final class LayoutSyncOptions
{
    /** 결정 값: 그 화면만 새 판으로 덮는다 */
    public const TAKE_THEIRS = 'take-theirs';

    /** 결정 값: 운영자 판을 유지한다 */
    public const KEEP_OURS = 'keep-ours';

    /** 지금 실행 범위의 옵션 */
    private static ?self $current = null;

    /**
     * @param  bool  $dryRun  미리보기 여부 (true 면 쓰지 않는다)
     * @param  array<string, string>  $decisions  화면별 결정 (키 => take-theirs|keep-ours)
     * @param  string|null  $sourceRoot  레이아웃 파일을 읽을 확장 루트 (null 이면 활성 디렉토리)
     * @param  string  $origin  이 동기화의 출처 — 버전 이력 요약에 적힌다 (`extension_update`·`refresh`)
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly array $decisions = [],
        public readonly ?string $sourceRoot = null,
        public readonly string $origin = 'extension_update',
    ) {
        foreach ($decisions as $key => $value) {
            if (! is_string($key) || ! in_array($value, [self::TAKE_THEIRS, self::KEEP_OURS], true)) {
                throw new InvalidArgumentException(__('extensions.layout_sync.errors.invalid_decision', ['key' => (string) $key]));
            }
        }
    }

    /**
     * 지금 실행 범위의 옵션을 돌려줍니다 (범위 밖이면 기본값).
     *
     * @return self 옵션
     */
    public static function current(): self
    {
        return self::$current ?? new self;
    }

    /**
     * 옵션을 범위로 걸고 작업을 실행합니다 — 끝나면(예외 포함) 이전 범위로 되돌립니다.
     *
     * @param  self  $options  옵션
     * @param  Closure  $callback  작업
     * @return mixed 작업 반환값
     */
    public static function run(self $options, Closure $callback): mixed
    {
        $previous = self::$current;
        self::$current = $options;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * 지금 범위에서 쓰기를 해도 되는지 (미리보기가 아니면 true).
     *
     * @return bool 쓰기 허용 여부
     */
    public static function writes(): bool
    {
        return ! self::current()->dryRun;
    }

    /**
     * 화면 하나의 결정을 읽습니다.
     *
     * @param  string  $key  결정 키
     * @return string|null `take-theirs`·`keep-ours`, 없으면 null
     */
    public function decisionFor(string $key): ?string
    {
        return $this->decisions[$key] ?? null;
    }

    /**
     * 레이아웃 결정 키를 만듭니다.
     *
     * @param  string  $template  템플릿 식별자
     * @param  string  $name  레이아웃 이름
     * @return string 결정 키
     */
    public static function layoutKey(string $template, string $name): string
    {
        return "layout:{$template}:{$name}";
    }

    /**
     * 확장 조각 결정 키를 만듭니다.
     *
     * @param  string  $template  템플릿 식별자
     * @param  string|null  $sourceIdentifier  조각 출처 식별자
     * @param  string|null  $fragmentKey  조각 식별자
     * @return string 결정 키
     */
    public static function extensionKey(string $template, ?string $sourceIdentifier, ?string $fragmentKey): string
    {
        return 'extension:'.$template.':'.(string) $sourceIdentifier.':'.(string) $fragmentKey;
    }

    /**
     * 결정 JSON 을 읽습니다 — `{"키": "take-theirs"}` 또는 `{"decisions": {...}}` 모양.
     *
     * @param  mixed  $decoded  JSON 디코드 결과
     * @return array<string, string> 결정
     *
     * @throws InvalidArgumentException 모양이 맞지 않을 때
     */
    public static function parseDecisions(mixed $decoded): array
    {
        if (is_array($decoded) && isset($decoded['decisions']) && is_array($decoded['decisions'])) {
            $decoded = $decoded['decisions'];
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(__('extensions.layout_sync.errors.invalid_decisions_file'));
        }

        // 생성자가 값을 검증한다.
        return (new self(false, $decoded))->decisions;
    }

    /**
     * 결정 파일(JSON)을 읽습니다.
     *
     * @param  string  $path  파일 경로
     * @return array<string, string> 결정
     *
     * @throws InvalidArgumentException 파일이 없거나 JSON 이 아닐 때
     */
    public static function decisionsFromFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException(__('extensions.layout_sync.errors.decisions_file_not_found', ['path' => $path]));
        }

        // layout-assoc-decode-ok: 화면별 결정 파일(키 => 값 표) — 레이아웃 본문이 아니다
        $decoded = json_decode((string) file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(__('extensions.layout_sync.errors.invalid_decisions_file'));
        }

        return self::parseDecisions($decoded);
    }
}
