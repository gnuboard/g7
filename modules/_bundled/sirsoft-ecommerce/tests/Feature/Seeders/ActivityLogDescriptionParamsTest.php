<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use Modules\Sirsoft\Ecommerce\Listeners\ActivityLogDescriptionResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 활동 로그 description 치환자 정합성 테스트.
 *
 * 템플릿의 `:option_id` 같은 치환자는 (기록 지점이 넘긴 description_params) +
 * (필터 훅 `core.activity_log.filter_description_params` 이 보강한 값) 으로 전부 채워져야 한다.
 * 하나라도 비면 화면에 치환자 원문이 그대로 찍힌다 — 오류 없이 조용히 잘못 보인다.
 *
 * 대상은 하드코딩하지 않고 시더 소스에서 (키, 파라미터명) 쌍을 **도출**한다.
 * 시더에 새 로그 유형이 추가되면 이 테스트가 자동으로 그것까지 검사한다.
 */
class ActivityLogDescriptionParamsTest extends ModuleTestCase
{
    /**
     * 시더 소스에서 (lang 키, description_params 키 목록) 쌍을 도출합니다.
     *
     * @return array<int, array{key: string, params: array<int, string>, line: int}>
     */
    private function declaredLogShapes(): array
    {
        $file = dirname(__DIR__, 3).'/database/seeders/ActivityLogSampleSeeder.php';

        $this->assertFileExists($file, '이커머스 활동 로그 샘플 시더가 있어야 합니다.');

        $source = file_get_contents($file);
        $shapes = [];

        // ['action' => '...', 'key' => 'xxx', ... 'params' => fn (...) => ['a' => ..., 'b' => ...]
        preg_match_all("/'key'\s*=>\s*'([a-z0-9_]+)'/i", $source, $keys, PREG_OFFSET_CAPTURE);

        foreach ($keys[1] as $match) {
            [$key, $offset] = $match;

            $paramsPos = strpos($source, "'params'", $offset);

            if ($paramsPos === false) {
                continue;
            }

            // 다음 'key' 선언보다 뒤에 있으면 이 항목의 params 가 아니다 (params 미선언 항목)
            $nextKey = strpos($source, "'key'", $offset + 1);

            if ($nextKey !== false && $paramsPos > $nextKey) {
                continue;
            }

            $open = strpos($source, '[', $paramsPos);

            if ($open === false) {
                continue;
            }

            $depth = 0;
            $end = null;

            for ($p = $open; $p < strlen($source); $p++) {
                if ($source[$p] === '[') {
                    $depth++;
                } elseif ($source[$p] === ']') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $p;
                        break;
                    }
                }
            }

            if ($end === null) {
                continue;
            }

            preg_match_all("/'([a-z0-9_]+)'\s*=>/i", substr($source, $open, $end - $open), $names);

            $shapes[] = [
                'key' => $key,
                'params' => $names[1],
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            ];
        }

        return $shapes;
    }

    /**
     * 시더가 만드는 모든 로그의 설명이 치환자를 남기지 않는지 검증합니다.
     *
     * 렌더 경로는 모델 접근자와 동일하게 필터 훅을 태운다 — 훅이 ID → 이름으로
     * 보강해 주는 키(coupon_id → coupon_name 등)를 결함으로 오판하지 않기 위해서다.
     */
    #[Test]
    public function seeded_activity_log_descriptions_leave_no_placeholder(): void
    {
        $shapes = $this->declaredLogShapes();

        $this->assertNotEmpty($shapes, '시더에서 로그 형태를 도출하지 못했습니다(파서 회귀).');

        $unresolved = [];

        foreach ($shapes as $shape) {
            $langKey = 'sirsoft-ecommerce::activity_log.description.'.$shape['key'];
            $template = __($langKey);

            // 시더 배열의 'key' 가 description 키가 아닌 경우(다른 용도)는 건너뛴다
            if ($template === $langKey) {
                continue;
            }

            if (preg_match('/:[a-zA-Z_]/', $template) !== 1) {
                continue;
            }

            $params = array_fill_keys($shape['params'], '1');

            // 훅 체인 전체(applyFilters)를 태우면 다른 모듈의 리스너까지 해석되어
            // 이 모듈 테스트 컨텍스트에서 바인딩이 없는 의존성으로 터진다.
            // 검증 대상은 "이커머스 시더 + 이커머스 리졸버" 조합이므로 그 리스너만 직접 호출한다.
            $params = app(ActivityLogDescriptionResolver::class)
                ->resolveDescriptionParams($params, $langKey, []);

            $rendered = __($langKey, $params);

            if (preg_match('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $rendered, $m) === 1) {
                $unresolved[] = sprintf(
                    '%s (시더 %d행) → "%s" — 치환자 :%s 미충족 (전달: %s)',
                    $shape['key'],
                    $shape['line'],
                    $rendered,
                    $m[1],
                    implode(', ', $shape['params']) ?: '없음'
                );
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "샘플 활동 로그 설명에 치환자가 그대로 남습니다:\n  ".implode("\n  ", $unresolved)
        );
    }
}
