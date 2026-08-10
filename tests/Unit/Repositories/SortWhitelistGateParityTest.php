<?php

namespace Tests\Unit\Repositories;

use App\Http\Requests\ActivityLog\ActivityLogIndexRequest;
use App\Http\Requests\Admin\Identity\AdminIdentityMessageDefinitionIndexRequest;
use App\Http\Requests\Menu\MenuListRequest;
use App\Http\Requests\NotificationDefinition\NotificationDefinitionIndexRequest;
use App\Http\Requests\NotificationLog\NotificationLogIndexRequest;
use App\Http\Requests\Schedule\ScheduleListRequest;
use App\Http\Requests\User\UserListRequest;
use App\Repositories\ActivityLogRepository;
use App\Repositories\IdentityMessageDefinitionRepository;
use App\Repositories\MenuRepository;
use App\Repositories\NotificationDefinitionRepository;
use App\Repositories\NotificationLogRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * 정렬 허용 컬럼의 포함관계 회귀 가드.
 *
 * 목록 조회는 두 곳에서 정렬 컬럼을 제한한다.
 *
 *  - 게이트: FormRequest 의 `sort_by` `in:` 규칙 (요청을 실제로 차단하는 곳)
 *  - 저장소: Repository 의 `SORTABLE_COLUMNS` 상수 (ResolvesSortSpec 이 해석하는 닫힌 집합)
 *
 * 불변식은 **게이트 ⊆ 저장소** 다 (docs/backend/service-repository.md "정렬 컬럼 화이트리스트").
 *
 *  - 저장소 ⊊ 게이트 → 검증을 통과한 정렬이 조용히 기본 정렬로 되돌아간다. 422 도 로그도
 *    남지 않아 "정렬이 안 먹는다" 로만 관측된다 → 결함.
 *  - 저장소 ⊋ 게이트 → 의도된 안전 여유다. 게이트가 `HookManager::applyFilters` 로 확장에
 *    열려 있어 확장이 정렬 컬럼을 늘릴 수 있고, 그때 저장소가 이미 허용하고 있어야 한다.
 *
 * 다만 **화면이 실제로 제공하는 정렬 옵션이 게이트에서 422 가 되는 것**은 별개의 결함이다
 * (#492 실측: 알림 발송 이력 화면의 "수신자명순"·"제목순"). 그 검증은 화면 실측이 담당한다.
 *
 * 대상 쌍을 손으로 나열하지 않고 파일에서 도출한다. 새 목록이 추가돼도 자동으로 검사된다.
 */
class SortWhitelistGateParityTest extends TestCase
{
    /**
     * Repository 상수 ↔ FormRequest 규칙 쌍 목록.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function surfaceProvider(): array
    {
        return [
            '활동 로그' => [
                ActivityLogRepository::class,
                ActivityLogIndexRequest::class,
            ],
            '알림 발송 이력' => [
                NotificationLogRepository::class,
                NotificationLogIndexRequest::class,
            ],
            '사용자' => [
                UserRepository::class,
                UserListRequest::class,
            ],
            '메뉴' => [
                MenuRepository::class,
                MenuListRequest::class,
            ],
            '스케줄' => [
                ScheduleRepository::class,
                ScheduleListRequest::class,
            ],
            '알림 정의' => [
                NotificationDefinitionRepository::class,
                NotificationDefinitionIndexRequest::class,
            ],
            '본인인증 메시지 정의' => [
                IdentityMessageDefinitionRepository::class,
                AdminIdentityMessageDefinitionIndexRequest::class,
            ],
        ];
    }

    /**
     * 게이트가 허용하는 정렬 컬럼은 모두 저장소에서도 허용돼야 한다 (게이트 ⊆ 저장소).
     */
    #[DataProvider('surfaceProvider')]
    public function test_게이트가_허용한_정렬은_저장소도_허용한다(string $repositoryClass, string $requestClass): void
    {
        $repoColumns = $this->readSortableConstant($repositoryClass);
        $gateColumns = $this->readGateSortColumns($requestClass);

        $this->assertNotEmpty($repoColumns, "{$repositoryClass} 에서 SORTABLE 상수를 찾지 못했습니다.");
        $this->assertNotEmpty($gateColumns, "{$requestClass} 에서 sort_by 허용 목록을 찾지 못했습니다.");

        $missing = array_values(array_diff($gateColumns, $repoColumns));

        $this->assertSame(
            [],
            $missing,
            sprintf(
                '게이트가 통과시키는 정렬 컬럼을 저장소가 허용하지 않습니다 — 그 정렬은 조용히 '
                ."기본 정렬로 되돌아갑니다.\n  누락: %s\n  저장소(%s): %s\n  게이트(%s): %s",
                implode(',', $missing),
                class_basename($repositoryClass),
                implode(',', $repoColumns),
                class_basename($requestClass),
                implode(',', $gateColumns)
            )
        );
    }

    /**
     * Repository 소스에서 SORTABLE 상수 값을 읽습니다.
     *
     * @return array<int, string> 정렬 허용 컬럼 목록
     */
    private function readSortableConstant(string $class): array
    {
        foreach ((new ReflectionClass($class))->getReflectionConstants() as $const) {
            if (str_contains(strtoupper($const->getName()), 'SORT')) {
                $value = $const->getValue();

                if (is_array($value)) {
                    return array_values(array_filter($value, 'is_string'));
                }
            }
        }

        return [];
    }

    /**
     * FormRequest 소스에서 sort_by 의 in: / Rule::in 허용 목록을 읽습니다.
     *
     * rules() 를 실행하지 않는 이유: 훅 필터와 라우트 바인딩에 의존하는 규칙이 있어
     * 단위 테스트 환경에서 부작용이 생긴다. 선언 자체를 읽는 편이 안정적이다.
     *
     * @return array<int, string> 정렬 허용 컬럼 목록
     */
    private function readGateSortColumns(string $class): array
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        // 형태 1: 'sort_by' => 'nullable|string|in:a,b,c'
        if (preg_match("/'sort_by'\s*=>\s*'[^']*\bin:([^'|]+)/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        // 형태 2: 'sort_by' => ['nullable', 'string', 'in:a,b,c']
        if (preg_match("/'sort_by'\s*=>\s*\[[^\]]*'in:([^']+)'/", $source, $m)) {
            return $this->splitColumns($m[1]);
        }

        // 형태 3: 'sort_by' => ['nullable', 'string', Rule::in(['a', 'b'])]
        if (preg_match("/'sort_by'\s*=>\s*\[.*?Rule::in\(\s*\[(.*?)\]/s", $source, $m)) {
            return $this->splitColumns(str_replace("'", '', $m[1]));
        }

        return [];
    }

    /**
     * 쉼표 구분 컬럼 문자열을 배열로 변환합니다.
     *
     * @return array<int, string> 정리된 컬럼 목록
     */
    private function splitColumns(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
