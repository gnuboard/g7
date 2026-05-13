<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시글/댓글 샘플 시더
 *
 * 각 게시판에 테스트용 게시글과 댓글을 생성합니다.
 * 게시판 설정(secret_mode, use_comment, use_reply 등)에 따라 데이터를 생성합니다.
 *
 * 개선사항:
 * - 답변글 다단계 depth (max_reply_depth 활용)
 * - 대댓글 다단계 depth (max_comment_depth 활용)
 * - 관리자/스텝 역할 사용자 활용
 * - 게시판별 try-catch 및 진행 로그
 */
class PostSampleSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 게시판별 콘텐츠 템플릿
     *
     * 각 게시판의 특성에 맞는 제목과 내용을 정의합니다.
     */
    private const BOARD_CONTENT_TEMPLATES = [
        'notice' => [
            ['title' => '서비스 점검 안내', 'content' => '<p>안녕하세요. 서비스 품질 향상을 위한 정기 점검이 예정되어 있습니다.</p><p><strong>점검 일시:</strong> 매주 화요일 02:00 ~ 06:00</p><p>점검 시간 동안 서비스 이용이 제한될 수 있습니다.</p><p>양해 부탁드립니다.</p>'],
            ['title' => '이용약관 개정 공지', 'content' => '<p>이용약관이 아래와 같이 개정됨을 알려드립니다.</p><ul><li>개인정보 처리방침 변경</li><li>서비스 이용 조건 명확화</li><li>책임 제한 조항 수정</li></ul><p>변경된 약관은 공지일로부터 7일 후 적용됩니다.</p>'],
            ['title' => '신규 기능 출시 안내', 'content' => '<p>새로운 기능이 추가되었습니다.</p><h3>주요 기능</h3><ul><li>다크 모드 지원</li><li>알림 설정 개선</li><li>검색 성능 향상</li></ul><p>많은 이용 부탁드립니다.</p>'],
            ['title' => '회원 등급 제도 안내', 'content' => '<p>회원 등급 제도가 도입되었습니다.</p><p>활동에 따라 등급이 부여되며, 등급별 혜택이 제공됩니다.</p><table><tr><th>등급</th><th>조건</th><th>혜택</th></tr><tr><td>골드</td><td>게시글 100개 이상</td><td>포인트 2배 적립</td></tr></table>'],
            ['title' => '커뮤니티 가이드라인', 'content' => '<p>건전한 커뮤니티 운영을 위해 다음 사항을 준수해주세요.</p><ul><li>타인을 존중하는 언어 사용</li><li>개인정보 보호</li><li>허위 정보 유포 금지</li><li>저작권 준수</li></ul>'],
            ['title' => '보안 업데이트 완료', 'content' => '<p>서비스 보안이 강화되었습니다.</p><p>더욱 안전한 서비스 이용을 위해 비밀번호 변경을 권장드립니다.</p>'],
            ['title' => '연말 이벤트 안내', 'content' => '<p>연말을 맞아 특별 이벤트를 진행합니다.</p><p>참여 방법과 경품 안내는 이벤트 페이지를 확인해주세요.</p>'],
            ['title' => '앱 업데이트 안내', 'content' => '<p>앱 최신 버전이 출시되었습니다. 업데이트 후 새로운 기능을 이용해보세요.</p>'],
            ['title' => '서버 이전 공지', 'content' => '<p>서비스 안정성 향상을 위해 서버 이전 작업이 진행됩니다.</p><p>작업 중 일시적으로 접속이 불안정할 수 있습니다.</p>'],
            ['title' => '개인정보 처리방침 변경 안내', 'content' => '<p>개인정보 처리방침이 일부 변경되었습니다. 주요 변경 내용을 확인해주세요.</p>'],
        ],
        // 입력 제한 커스텀 적용 (min_title:5, max_title:100, min_content:0, max_content:2000)
        // 짧은 제목(5자 이상)과 내용 없는 게시글(링크/이미지만) 케이스 포함
        'free' => [
            ['title' => '오늘 날씨 좋다', 'content' => ''],  // min_content_length:0 검증 — 내용 없는 게시글
            ['title' => '링크 공유', 'content' => 'https://example.com'],  // 짧은 내용
            ['title' => '한 달 사용 후기 공유합니다', 'content' => '<p>서비스를 한 달간 사용해본 솔직한 후기입니다.</p><p><strong>장점:</strong> 직관적인 UI, 빠른 응답 속도</p><p><strong>아쉬운 점:</strong> 모바일 앱 기능 부족</p><p>전반적으로 만족스럽습니다.</p>'],
            ['title' => '맛집 추천해요', 'content' => '<p>최근에 다녀온 맛집을 공유합니다.</p><p>위치: 강남역 근처</p><p>메뉴: 파스타가 맛있어요</p>'],
            ['title' => '주말 계획 있으세요?', 'content' => '<p>이번 주말에 뭐 하실 계획인가요?</p><p>저는 영화 보러 갈 예정이에요.</p>'],
            ['title' => '운동 시작했어요', 'content' => '<p>오늘부터 헬스장 다니기 시작했습니다.</p><p>작심삼일 되지 않도록 열심히 해볼게요!</p>'],
            ['title' => '요즘 읽고 있는 책', 'content' => '<p>요즘 자기계발서를 읽고 있는데 추천드립니다.</p>'],
            ['title' => '새로 산 물건 자랑', 'content' => '<p>고민하다가 드디어 샀어요!</p>'],
            ['title' => '오랜만에 친구 만났어요', 'content' => '<p>오랜만에 친구들이랑 만나서 수다 떨었습니다.</p>'],
            ['title' => '요즘 빠져있는 취미', 'content' => '<p>요즘 사진 찍는 취미에 빠졌어요.</p><p>주말마다 출사 다니고 있습니다.</p>'],
            ['title' => '직장인의 소소한 일상', 'content' => '<p>출근길 커피 한 잔의 여유가 좋습니다.</p>'],
            ['title' => '반려동물 자랑합니다', 'content' => '<p>우리 집 강아지 사진이에요. 너무 귀엽지 않나요?</p>'],
        ],
        'gallery' => [
            ['title' => '주말 나들이 사진', 'content' => '<p>주말에 다녀온 나들이 사진입니다.</p><p>날씨가 좋아서 사진이 잘 나왔어요.</p>'],
            ['title' => '작업물 공유합니다', 'content' => '<p>최근에 작업한 결과물입니다.</p><p>피드백 환영합니다!</p>'],
            ['title' => '일상 스냅 모음', 'content' => '<p>오늘의 일상 사진들입니다.</p>'],
            ['title' => '여행 사진 공유', 'content' => '<p>지난 여행에서 찍은 사진들이에요.</p><p>정말 아름다웠습니다.</p>'],
            ['title' => '풍경 사진 모음', 'content' => '<p>출퇴근길에 찍은 풍경 사진들입니다.</p>'],
            ['title' => '음식 사진', 'content' => '<p>오늘 먹은 맛있는 음식 사진이에요.</p>'],
            ['title' => '야경 촬영', 'content' => '<p>밤에 찍은 야경 사진입니다.</p><p>삼각대 없이 찍어서 좀 흔들렸네요.</p>'],
            ['title' => '반려동물 사진', 'content' => '<p>우리 집 고양이 사진입니다.</p><p>자는 모습이 너무 귀여워요.</p>'],
            ['title' => '꽃 사진 공유', 'content' => '<p>봄에 찍어둔 꽃 사진들이에요.</p>'],
            ['title' => '카페 인테리어', 'content' => '<p>분위기 좋은 카페 사진입니다.</p>'],
        ],
        // card 타입 이벤트 게시판 — 카드 형식에 맞는 짧고 임팩트 있는 내용
        'event' => [
            ['title' => '신규 가입 환영 이벤트', 'content' => '<p>새로 가입하신 모든 분께 웰컴 쿠폰을 드립니다.</p><p><strong>혜택:</strong> 10% 할인 쿠폰 즉시 지급</p><p><strong>기간:</strong> 상시</p>'],
            ['title' => '친구 초대 이벤트', 'content' => '<p>친구를 초대하고 함께 혜택을 누리세요.</p><p><strong>혜택:</strong> 초대자·피초대자 모두 포인트 적립</p><p><strong>기간:</strong> 이번 달 말까지</p>'],
            ['title' => '리뷰 작성 이벤트', 'content' => '<p>서비스 이용 후기를 남겨주세요.</p><p><strong>혜택:</strong> 리뷰 1건당 100포인트 적립</p><p><strong>기간:</strong> 매월 진행</p>'],
            ['title' => '출석 체크 이벤트', 'content' => '<p>매일 출석 체크하고 포인트를 받아가세요.</p><p><strong>혜택:</strong> 연속 7일 출석 시 보너스 포인트</p><p><strong>기간:</strong> 상시</p>'],
            ['title' => '사진 공모전', 'content' => '<p>일상의 아름다운 순간을 담아 제출해주세요.</p><p><strong>상금:</strong> 1등 50만원 / 2등 30만원 / 3등 10만원</p><p><strong>마감:</strong> 이번 달 말</p>'],
            ['title' => '설문조사 참여 이벤트', 'content' => '<p>서비스 개선을 위한 설문에 참여해주세요.</p><p><strong>혜택:</strong> 참여자 전원 포인트 지급</p><p><strong>소요 시간:</strong> 약 3분</p>'],
            ['title' => '시즌 특가 이벤트', 'content' => '<p>시즌 한정 특별 할인을 놓치지 마세요.</p><p><strong>할인율:</strong> 최대 50% 할인</p><p><strong>기간:</strong> 한정 수량 소진 시 종료</p>'],
            ['title' => '커뮤니티 활동왕 이벤트', 'content' => '<p>이번 달 가장 활발하게 활동한 회원을 선정합니다.</p><p><strong>선정 기준:</strong> 게시글 + 댓글 수</p><p><strong>혜택:</strong> 1위 상품권 10만원</p>'],
        ],
        'qna' => [
            ['title' => '결제 오류가 발생합니다', 'content' => '<p>결제 시 오류가 발생하는데요.</p><p>오류 메시지: "결제 처리 중 오류가 발생했습니다"</p><p>해결 방법을 알려주세요.</p>'],
            ['title' => '기능 사용법 질문', 'content' => '<p>이 기능은 어떻게 사용하나요?</p><p>메뉴에서 찾을 수가 없어요.</p>'],
            ['title' => 'API 연동 문의', 'content' => '<p>API 연동 방법을 알려주세요.</p><p>문서를 봤는데 잘 이해가 안 됩니다.</p>'],
            ['title' => '회원가입이 안 돼요', 'content' => '<p>회원가입 시 이메일 인증이 안 됩니다.</p><p>인증 메일이 오지 않아요.</p>'],
            ['title' => '비밀번호 변경 방법', 'content' => '<p>비밀번호를 변경하고 싶은데 어디서 하나요?</p>'],
            ['title' => '알림 설정 질문', 'content' => '<p>알림을 끄고 싶은데 설정을 못 찾겠어요.</p>'],
            ['title' => '파일 업로드 오류', 'content' => '<p>파일 업로드가 안 됩니다.</p><p>용량 제한이 있나요?</p>'],
            ['title' => '모바일에서 접속 문제', 'content' => '<p>모바일에서 접속이 잘 안 됩니다.</p><p>PC에서는 정상입니다.</p>'],
            ['title' => '포인트 사용 방법', 'content' => '<p>적립된 포인트는 어떻게 사용하나요?</p>'],
            ['title' => '탈퇴 후 재가입 가능한가요?', 'content' => '<p>탈퇴 후 동일한 이메일로 재가입이 가능한지 궁금합니다.</p>'],
            ['title' => '데이터 백업 방법', 'content' => '<p>내 데이터를 백업하는 방법이 있나요?</p>'],
            ['title' => '언어 설정 변경', 'content' => '<p>언어 설정은 어디서 변경하나요?</p>'],
        ],
        // secret_mode:always + use_comment:true 조합 — 비밀게시판 내 댓글 동작 확인
        'members' => [
            ['title' => '회원 전용 이벤트 안내', 'content' => '<p>회원 여러분을 위한 특별 이벤트입니다.</p><p>참여 방법은 아래를 확인해주세요.</p>'],
            ['title' => '내부 자료 공유', 'content' => '<p>회원 전용 자료입니다.</p><p>외부 유출을 삼가해주세요.</p>'],
            ['title' => '회원 모임 공지', 'content' => '<p>다음 달 정기 모임 일정입니다.</p><p>많은 참여 부탁드립니다.</p>'],
            ['title' => '회원 혜택 안내', 'content' => '<p>이번 달 회원 혜택을 안내드립니다.</p>'],
            ['title' => '회원 전용 할인 쿠폰', 'content' => '<p>회원님께 드리는 특별 할인 쿠폰입니다.</p>'],
            ['title' => '커뮤니티 규칙 공유', 'content' => '<p>회원 커뮤니티 이용 규칙입니다.</p>'],
            ['title' => '신규 회원 환영합니다', 'content' => '<p>새로 가입하신 회원분들 환영합니다!</p>'],
            ['title' => '회원 설문조사', 'content' => '<p>서비스 개선을 위한 설문조사입니다.</p><p>참여 부탁드립니다.</p>'],
            ['title' => '회원 등급 업그레이드 안내', 'content' => '<p>회원 등급 업그레이드 조건이 변경되었습니다.</p>'],
            ['title' => '비공개 정보 공유', 'content' => '<p>회원에게만 공개되는 정보입니다.</p>'],
        ],
        'inquiry' => [
            ['title' => '배송 관련 문의드립니다', 'content' => '<p>주문한 상품 배송이 지연되고 있습니다.</p><p>현재 배송 상태 확인 부탁드립니다.</p>'],
            ['title' => '결제 오류 문의', 'content' => '<p>결제가 이중으로 된 것 같습니다.</p><p>확인 후 조치 부탁드립니다.</p>'],
            ['title' => '환불 요청합니다', 'content' => '<p>구매한 상품 환불을 요청합니다.</p><p>자세한 사유는 아래와 같습니다.</p>'],
            ['title' => '계정 로그인 문제', 'content' => '<p>계정에 문제가 있어서 문의합니다.</p><p>로그인이 안 되는 상황입니다.</p>'],
            ['title' => '서비스 이용 문의', 'content' => '<p>서비스 이용 중 궁금한 점이 있어 문의드립니다.</p>'],
            ['title' => '회원정보 변경 요청', 'content' => '<p>회원정보 변경을 요청드립니다.</p><p>연락처와 주소를 변경하고 싶습니다.</p>'],
            ['title' => '제휴 및 협력 문의', 'content' => '<p>제휴 및 협력 관련하여 문의드립니다.</p><p>담당자 연락처 부탁드립니다.</p>'],
            ['title' => '기타 문의사항', 'content' => '<p>기타 문의사항이 있어 연락드립니다.</p>'],
            ['title' => '불편사항 접수', 'content' => '<p>서비스 이용 중 불편한 점이 있어 접수합니다.</p>'],
            ['title' => '개인정보 관련 문의', 'content' => '<p>개인정보 처리 관련하여 문의드립니다.</p>'],
        ],
        // is_active:false 비활성 게시판 — 과거 아카이브 성격의 내용
        // order_direction:ASC이므로 오래된 글이 상단에 노출되는 구조
        'archive' => [
            ['title' => '2022년 서비스 오픈 안내', 'content' => '<p>서비스가 정식 오픈되었습니다.</p><p>많은 관심과 이용 부탁드립니다.</p>'],
            ['title' => '2022년 주요 업데이트 내역', 'content' => '<p>2022년 한 해 동안 진행된 주요 업데이트를 정리했습니다.</p><ul><li>1분기: 회원 시스템 개편</li><li>2분기: 모바일 앱 출시</li><li>3분기: 알림 시스템 도입</li><li>4분기: 다국어 지원 추가</li></ul>'],
            ['title' => '2023년 서비스 운영 회고', 'content' => '<p>2023년 서비스 운영 결과를 공유합니다.</p><p>총 방문자 수, 게시글 수, 주요 이슈 등을 정리했습니다.</p>'],
            ['title' => '구버전 기능 안내', 'content' => '<p>현재는 사용되지 않는 구버전 기능 안내입니다.</p><p>새로운 기능으로 마이그레이션 방법을 안내합니다.</p>'],
            ['title' => '이전 이벤트 당첨자 발표', 'content' => '<p>지난 이벤트 당첨자를 발표합니다.</p><p>당첨되신 분들은 이메일을 확인해주세요.</p>'],
            ['title' => '과거 공지사항 모음', 'content' => '<p>2022~2023년 주요 공지사항을 아카이브합니다.</p>'],
            ['title' => '서비스 초기 이용 가이드', 'content' => '<p>서비스 초기 버전 이용 가이드입니다.</p><p>현재 UI와 다를 수 있습니다.</p>'],
            ['title' => '구버전 FAQ', 'content' => '<p>자주 묻는 질문 구버전입니다.</p><p>최신 FAQ는 고객센터를 이용해주세요.</p>'],
        ],
    ];

    /**
     * 일반 콘텐츠 템플릿 (기본 폴백용)
     */
    private const DEFAULT_CONTENT_TEMPLATES = [
        ['title' => '테스트 게시글입니다', 'content' => '<p>테스트용 게시글 내용입니다.</p>'],
        ['title' => '샘플 데이터', 'content' => '<p>샘플 데이터입니다.</p>'],
        ['title' => '안녕하세요', 'content' => '<p>안녕하세요. 반갑습니다.</p>'],
    ];

    /**
     * 댓글 샘플 템플릿
     */
    private const COMMENT_TEMPLATES = [
        '좋은 글 감사합니다!',
        '유익한 정보네요.',
        '저도 같은 생각입니다.',
        '질문이 있는데요, 자세히 설명해주실 수 있나요?',
        '공유해주셔서 감사합니다.',
        '도움이 많이 되었습니다.',
        '저도 비슷한 경험이 있어요.',
        '추가 정보 있으면 공유 부탁드립니다.',
        '궁금한 점이 해결됐습니다. 감사합니다!',
        '이 부분은 조금 다르게 생각하는데요.',
        '참고할만한 자료가 있을까요?',
        '정말 유용한 팁이네요!',
        '저는 다른 방법을 사용했는데 이 방법도 좋네요.',
        '이해가 잘 안되는 부분이 있어요.',
        '계속해서 좋은 글 부탁드립니다.',
    ];

    /**
     * 대댓글 샘플 템플릿 (depth별)
     */
    private const NESTED_REPLY_TEMPLATES = [
        1 => [
            '감사합니다!',
            '네, 맞습니다.',
            '추가 설명드리자면...',
            '궁금하신 점 해결되셨으면 좋겠습니다.',
            '또 궁금하신 점 있으면 언제든지 물어보세요.',
        ],
        2 => [
            '아, 그렇군요! 이해했습니다.',
            '추가 질문이 있는데요.',
            '덕분에 잘 해결했습니다.',
            '조금 더 자세히 설명해주실 수 있나요?',
        ],
        3 => [
            '완전히 이해됐습니다. 감사해요!',
            '다른 분들도 참고하시면 좋겠네요.',
            '마지막으로 한 가지만 더 여쭤볼게요.',
        ],
    ];

    /**
     * 답변글 템플릿
     */
    private const ANSWER_TEMPLATES = [
        '문의하신 내용에 대해 답변드립니다.',
        '안녕하세요. 답변드립니다.',
        '해당 문의에 대한 답변입니다.',
        '확인 후 답변드립니다.',
    ];

    /**
     * 다단계 답변글 템플릿 (depth 2+)
     */
    private const NESTED_ANSWER_TEMPLATES = [
        2 => [
            '추가 답변드립니다.',
            '보충 설명드리겠습니다.',
            '말씀하신 부분에 대해 추가로 안내드립니다.',
        ],
        3 => [
            '최종 답변드립니다.',
            '추가 확인 후 답변드립니다.',
            '관련 부서 확인 결과 안내드립니다.',
        ],
    ];

    /**
     * 샘플 이미지 파일 개수
     */
    private const SAMPLE_IMAGE_COUNT = 5;

    /**
     * 게시판별 게시글 수
     *
     * - notice  : 15건 — 관리자 전용, 공지 특성상 적은 수
     * - free    : 30건 — 입력 제한 커스텀 검증, 비회원 포함 다양한 작성자
     * - gallery : 20건 — 이미지 첨부 위주, 조회수 정렬 분포 확인
     * - event   : 10건 — card 타입, 관리자만 등록
     * - qna     : 30건 — 카테고리 분포 + 답글 다단계 확인
     * - members : 25건 — secret_mode:always 비밀글 전량 생성
     * - inquiry : 20건 — 비밀글 필수, 답글 depth:1 응답률 확인
     * - archive : 10건 — 비활성 게시판, ASC 정렬 확인용
     */
    private const POSTS_COUNT = [
        'notice'  => 15,
        'free'    => 30,
        'gallery' => 20,
        'event'   => 10,
        'qna'     => 30,
        'members' => 25,
        'inquiry' => 20,
        'archive' => 10,
    ];

    /**
     * 생성된 샘플 이미지 파일 정보
     *
     * @var array<int, array{filename: string, path: string, size: int, width: int, height: int}>
     */
    private array $sampleImages = [];

    /**
     * 비회원 비밀번호 캐시 (bcrypt 반복 호출 방지)
     */
    private ?string $guestPasswordHash = null;

    /**
     * 관리자 사용자
     *
     * @var User|null
     */
    private ?User $adminUser = null;

    /**
     * 스텝 사용자
     *
     * @var User|null
     */
    private ?User $stepUser = null;

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('게시글/댓글 샘플 데이터 생성 중...');

        // 쿼리 로그 비활성화 (메모리 절약 — 수백~수천 건 INSERT 시 필수)
        DB::disableQueryLog();
        DB::connection()->unsetEventDispatcher();

        // 테스트 사용자 조회 (더 많은 사용자 확보)
        $users = User::limit(20)->get();

        if ($users->isEmpty()) {
            $this->command->error('사용자가 없습니다. DummyUserSeeder를 먼저 실행하세요.');

            return;
        }

        // 관리자/스텝 사용자 조회
        $this->resolveRoleUsers($users);

        $this->command->info("  - {$users->count()}명의 사용자로 게시글/댓글 생성");
        if ($this->adminUser) {
            $this->command->info("  - 관리자: {$this->adminUser->name} (ID: {$this->adminUser->id})");
        }
        if ($this->stepUser) {
            $this->command->info("  - 스텝: {$this->stepUser->name} (ID: {$this->stepUser->id})");
        }

        // 스케일링 비율 계산
        $baseTotal = (int) array_sum(self::POSTS_COUNT);
        $requestedTotal = $this->getSeederCount('posts', $baseTotal);
        $ratio = $requestedTotal / $baseTotal;

        if ($ratio !== 1.0) {
            $this->command->info("  - 게시글 수 스케일링: 기본 {$baseTotal}개 → 요청 {$requestedTotal}개 (비율: {$ratio})");
        }

        // 샘플 이미지 파일 미리 생성 (5개)
        $this->createSampleImageFiles();

        // 모든 게시판에 게시글 생성
        $boards = Board::all();
        $totalStats = ['posts' => 0, 'replies' => 0, 'comments' => 0];

        foreach ($boards as $board) {
            try {
                $this->command->warn("  >>> {$board->slug} 게시판 처리 시작");
                $stats = $this->createPostsForBoard($board, $users, $ratio);
                $totalStats['posts'] += $stats['posts'];
                $totalStats['replies'] += $stats['replies'];
                $totalStats['comments'] += $stats['comments'];
                $this->command->warn("  <<< {$board->slug} 게시판 처리 완료 (게시글 {$stats['posts']}, 답변글 {$stats['replies']}, 댓글 {$stats['comments']})");
            } catch (\Exception $e) {
                $this->command->error("  !!! {$board->slug} 게시판 처리 중 오류: {$e->getMessage()}");
                $this->command->error("      {$e->getFile()}:{$e->getLine()}");
            }

            // 게시판별 메모리 해제
            gc_collect_cycles();
        }

        $this->command->info('');
        $this->command->info("게시글/댓글 샘플 데이터 생성 완료 — 총 게시글 {$totalStats['posts']}개, 답변글 {$totalStats['replies']}개, 댓글 {$totalStats['comments']}개");
    }

    /**
     * 관리자/스텝 역할 사용자를 조회합니다.
     *
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     */
    private function resolveRoleUsers($users): void
    {
        // admin 역할 사용자
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->adminUser = $adminRole->users()->first();
        }

        // step 역할 사용자 (없으면 두 번째 사용자를 사용)
        $stepRole = Role::where('identifier', 'step')->first();
        if ($stepRole) {
            $this->stepUser = $stepRole->users()->first();
        }

        // 폴백: 사용자 목록에서 할당
        if (! $this->adminUser && $users->count() > 0) {
            $this->adminUser = $users->first();
        }
        if (! $this->stepUser && $users->count() > 1) {
            $this->stepUser = $users[1];
        }
    }

    /**
     * 샘플 이미지 파일을 미리 생성합니다.
     *
     * 5개의 샘플 이미지를 storage/app/modules/sirsoft-board/attachments/samples/ 에 저장합니다.
     */
    private function createSampleImageFiles(): void
    {
        $this->command->info('  - 샘플 이미지 파일 생성 중...');

        $datePath = date('Y/m/d');

        for ($i = 0; $i < self::SAMPLE_IMAGE_COUNT; $i++) {
            $filename = Str::uuid().'.jpg';
            $path = "samples/{$datePath}/{$filename}";

            // 이미지 크기 랜덤
            $width = rand(800, 1920);
            $height = rand(600, 1080);

            // 실제 샘플 이미지 생성
            $imageContent = $this->createSampleImage($width, $height);
            $fileSize = strlen($imageContent);

            // 스토리지에 파일 저장
            $storagePath = "sirsoft-board/attachments/{$path}";
            Storage::disk('modules')->put($storagePath, $imageContent);

            // 파일 정보 저장
            $this->sampleImages[] = [
                'filename' => $filename,
                'path' => $path,
                'size' => $fileSize,
                'width' => $width,
                'height' => $height,
            ];

            $this->command->info("    - 샘플 이미지 #{$i}: {$storagePath}");
        }
    }

    /**
     * 특정 게시판에 게시글과 댓글을 생성합니다.
     *
     * @param  Board  $board  게시판
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @param  float  $ratio  게시글 수 스케일링 비율
     * @return array{posts: int, replies: int, comments: int} 생성 통계
     */
    private function createPostsForBoard(Board $board, $users, float $ratio = 1.0): array
    {
        // 이미 데이터가 있으면 스킵
        $existingCount = DB::table('board_posts')->where('board_id', $board->id)->count();
        if ($existingCount > 0) {
            $this->command->warn("  - {$board->slug} 게시판에 이미 게시글 {$existingCount}개가 있습니다. 스킵합니다.");

            return ['posts' => 0, 'replies' => 0, 'comments' => 0];
        }

        // card/gallery 타입 게시판은 배지 케이스 보장 게시글 먼저 생성
        $badgeStats = ['posts' => 0, 'replies' => 0, 'comments' => 0];
        if (in_array($board->type, ['card', 'gallery'])) {
            $badgeStats = $this->createBadgeCasePosts($board, $users);
        }

        // 권한 확인
        $allowGuestPost = $this->boardAllowsGuestWrite($board);
        $allowGuestComment = $this->boardAllowsGuestComment($board);

        $postCount = $badgeStats['posts'];
        $replyPostCount = $badgeStats['replies'];
        $commentCount = $badgeStats['comments'];

        // 생성할 게시글 개수 (비율 스케일링 적용)
        $defaultCount = self::POSTS_COUNT[$board->slug] ?? 20;
        $totalPosts = max(1, (int) round($defaultCount * $ratio));

        // 게시판별 콘텐츠 템플릿 가져오기
        $contentTemplates = self::BOARD_CONTENT_TEMPLATES[$board->slug] ?? self::DEFAULT_CONTENT_TEMPLATES;
        $templateCount = count($contentTemplates);

        // 날짜 분포 계산: 오늘(10%), 이번주(30%), 이번달(40%), 이전(20%)
        $todayCount = (int) ($totalPosts * 0.10);
        $weekCount = (int) ($totalPosts * 0.30);
        $monthCount = (int) ($totalPosts * 0.40);

        for ($i = 0; $i < $totalPosts; $i++) {
            $templateIndex = $i % $templateCount;
            $template = $contentTemplates[$templateIndex];

            // 비회원 게시글 - 권한 확인 후 30% 확률로 생성
            $isGuest = $allowGuestPost && rand(1, 10) <= 3;

            // 작성자 결정: notice는 관리자만, 나머지는 역할 혼합
            $user = $this->pickAuthor($board, $users, $i);

            // 카테고리 설정 (있는 경우)
            $category = null;
            if (! empty($board->categories) && is_array($board->categories)) {
                $category = $board->categories[array_rand($board->categories)];
            }

            // 비밀글 처리 - secret_mode 기반
            $isSecret = $this->determineIsSecret($board);

            // 상태 랜덤 설정 (published: 70%, blinded: 20%, deleted: 10%)
            $statusRand = rand(1, 100);
            $status = 'published';
            $deletedAt = null;
            $actionLogs = null;

            if ($statusRand <= 10) {
                // 10% 삭제
                $status = 'deleted';
                $deletedAt = now()->subDays(rand(1, 5));
                $actionLogs = [[
                    'action' => 'delete',
                    'reason' => '부적절한 내용으로 인한 삭제',
                    'admin_id' => $this->adminUser?->id ?? $user->id,
                    'admin_name' => $this->adminUser?->name ?? $user->name,
                    'ip_address' => '127.0.0.1',
                    'created_at' => $deletedAt,
                ]];
            } elseif ($statusRand <= 30) {
                // 20% 블라인드
                $status = 'blinded';
                $actionLogs = [[
                    'action' => 'blind',
                    'reason' => '커뮤니티 가이드 위반',
                    'admin_id' => $this->adminUser?->id ?? $user->id,
                    'admin_name' => $this->adminUser?->name ?? $user->name,
                    'ip_address' => '127.0.0.1',
                    'created_at' => now()->subDays(rand(1, 5)),
                ]];
            }

            // 공지글 처리 - notice 게시판 제외, 다른 게시판은 5% 확률
            $isNotice = ($board->slug !== 'notice') && (rand(1, 20) === 1);

            // 날짜 분포에 따른 created_at 설정
            $createdAt = $this->generateCreatedAt($i, $todayCount, $weekCount, $monthCount);

            // 조회수: 최근 게시글일수록 적게 (현실적인 분포)
            $viewCount = match (true) {
                $i < $todayCount => rand(0, 50),
                $i < $todayCount + $weekCount => rand(20, 200),
                $i < $todayCount + $weekCount + $monthCount => rand(50, 400),
                default => rand(100, 500),
            };

            // 게시글 생성
            $postData = [
                'category' => $category,
                'title' => ($isNotice && $board->slug !== 'notice' ? '[공지] ' : '').$template['title'],
                'content' => $template['content'],
                'content_mode' => 'html',
                'user_id' => $isGuest ? null : $user->id,
                'author_name' => $isGuest ? '익명'.rand(1, 100) : null,
                'password' => $isGuest ? $this->getGuestPassword() : null,
                'ip_address' => '127.0.0.'.rand(1, 255),
                'is_notice' => $isNotice,
                'is_secret' => $isSecret,
                'status' => $status,
                'trigger_type' => 'admin',
                'action_logs' => $actionLogs ? json_encode($actionLogs) : null,
                'view_count' => $viewCount,
                'parent_id' => null,
                'depth' => 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'deleted_at' => $deletedAt,
            ];

            $postData['board_id'] = $board->id;
            $postId = DB::table('board_posts')->insertGetId($postData);
            $postCount++;

            // 첨부파일 생성 - use_file_upload 설정 확인
            if ($board->use_file_upload) {
                // gallery는 85% (일부는 이미지 없음), 나머지는 50% 확률
                $attachmentChance = ($board->type === 'gallery') ? 85 : 50;
                if (rand(1, 100) <= $attachmentChance) {
                    $this->createAttachmentsForPost($board->id, $postId, $user->id ?? null);
                }
            }

            // 답변글 생성 - use_reply 설정 확인, 다단계 depth 지원
            if ($board->use_reply && ! $isNotice) {
                $replyPostCount += $this->createRepliesForPost(
                    $board, $postId, $category, $template, $isSecret, $createdAt, $users
                );
            }

            // 댓글 생성 - use_comment 설정 확인, 다단계 depth 지원
            if ($board->use_comment) {
                $commentCount += $this->createCommentsForPost(
                    $board, $postId, $createdAt, $users, $i,
                    $todayCount, $weekCount, $monthCount,
                    $allowGuestComment
                );
            }
        }

        $this->command->info("  - {$board->slug}: 게시글 {$postCount}개, 답변글 {$replyPostCount}개, 댓글 {$commentCount}개 생성");

        return ['posts' => $postCount, 'replies' => $replyPostCount, 'comments' => $commentCount];
    }

    /**
     * card/gallery 타입 게시판에 배지 케이스별 보장 게시글을 생성합니다.
     *
     * 다음 케이스를 각각 1개 이상 보장합니다:
     * - 공지글 (is_notice)
     * - 신규글 (created_at = 지금, is_new = true)
     * - 블라인드 + 이미지 있음
     * - 삭제됨 + 이미지 있음
     * - 비밀글 (is_secret)
     * - 답글 RE (parent_id 있음)
     * - 이미지 없는 일반글
     * - 카테고리 있는 글
     * - 일반글 + 이미지 있음
     *
     * @param  Board  $board  게시판
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @return array{posts: int, replies: int, comments: int} 생성 통계
     */
    private function createBadgeCasePosts(Board $board, $users): array
    {
        $this->command->info("  - {$board->slug}: 배지 케이스 보장 게시글 생성 중...");

        $postCount = 0;
        $replyCount = 0;
        $user = $users->first();
        $adminUser = $this->adminUser ?? $user;

        // 카테고리 (있으면 첫 번째, 없으면 null)
        $firstCategory = (! empty($board->categories) && is_array($board->categories))
            ? $board->categories[0]
            : null;

        // 공통 게시글 INSERT 헬퍼
        $insertPost = function (array $data) use ($board): int {
            $defaults = [
                'board_id' => $board->id,
                'category' => null,
                'content_mode' => 'html',
                'ip_address' => '127.0.0.1',
                'is_notice' => false,
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'action_logs' => null,
                'view_count' => rand(10, 200),
                'parent_id' => null,
                'depth' => 0,
                'deleted_at' => null,
            ];

            $now = now()->subMinutes(rand(1, 30));
            $defaults['created_at'] = $now;
            $defaults['updated_at'] = $now;

            return DB::table('board_posts')->insertGetId(array_merge($defaults, $data));
        };

        // ① 공지글 + 이미지 있음
        $postId = $insertPost([
            'title' => '[공지] 게시판 이용 안내',
            'content' => '<p>게시판 이용 규칙을 안내드립니다. 건전한 커뮤니티 문화를 함께 만들어주세요.</p>',
            'user_id' => $adminUser->id,
            'is_notice' => true,
        ]);
        if ($board->use_file_upload) {
            $this->createAttachmentsForPost($board->id, $postId, $adminUser->id);
        }
        $postCount++;

        // ② 신규글 (created_at = 바로 지금 → is_new = true) + 이미지 있음
        $nowTime = now()->subMinutes(1);
        $postId = DB::table('board_posts')->insertGetId([
            'board_id' => $board->id,
            'category' => $firstCategory,
            'title' => '방금 올라온 신규 게시글',
            'content' => '<p>방금 등록된 최신 게시글입니다. N 배지가 표시됩니다.</p>',
            'content_mode' => 'html',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 1,
            'parent_id' => null,
            'depth' => 0,
            'deleted_at' => null,
            'created_at' => $nowTime,
            'updated_at' => $nowTime,
        ]);
        if ($board->use_file_upload) {
            $this->createAttachmentsForPost($board->id, $postId, $user->id);
        }
        $postCount++;

        // ③ 블라인드 + 이미지 있음
        $blindedAt = now()->subDays(1);
        $postId = $insertPost([
            'title' => '블라인드 처리된 게시글',
            'content' => '<p>이 게시글은 블라인드 처리되었습니다.</p>',
            'user_id' => $user->id,
            'status' => 'blinded',
            'action_logs' => json_encode([[
                'action' => 'blind',
                'reason' => '커뮤니티 가이드 위반',
                'admin_id' => $adminUser->id,
                'admin_name' => $adminUser->name,
                'ip_address' => '127.0.0.1',
                'created_at' => $blindedAt,
            ]]),
        ]);
        if ($board->use_file_upload) {
            $this->createAttachmentsForPost($board->id, $postId, $user->id);
        }
        $postCount++;

        // ④ 삭제됨 + 이미지 있음
        $deletedAt = now()->subDays(2);
        $postId = $insertPost([
            'title' => '삭제된 게시글',
            'content' => '<p>이 게시글은 삭제되었습니다.</p>',
            'user_id' => $user->id,
            'status' => 'deleted',
            'deleted_at' => $deletedAt,
            'action_logs' => json_encode([[
                'action' => 'delete',
                'reason' => '부적절한 내용',
                'admin_id' => $adminUser->id,
                'admin_name' => $adminUser->name,
                'ip_address' => '127.0.0.1',
                'created_at' => $deletedAt,
            ]]),
        ]);
        if ($board->use_file_upload) {
            $this->createAttachmentsForPost($board->id, $postId, $user->id);
        }
        $postCount++;

        // ⑤ 비밀글 (secret_mode가 disabled가 아닌 경우만)
        if ($board->secret_mode->value !== 'disabled') {
            $postId = $insertPost([
                'title' => '비밀글입니다',
                'content' => '<p>비밀글 내용입니다. 작성자 본인과 관리자만 볼 수 있습니다.</p>',
                'user_id' => $user->id,
                'is_secret' => true,
                'category' => $firstCategory,
            ]);
            if ($board->use_file_upload) {
                $this->createAttachmentsForPost($board->id, $postId, $user->id);
            }
            $postCount++;
        }

        // ⑥ 원본 게시글 (답글의 부모)
        $parentPostId = $insertPost([
            'title' => '답글이 달린 게시글',
            'content' => '<p>이 게시글에는 답글이 있습니다.</p>',
            'user_id' => $user->id,
            'category' => $firstCategory,
        ]);
        if ($board->use_file_upload && rand(1, 2) === 1) {
            $this->createAttachmentsForPost($board->id, $parentPostId, $user->id);
        }
        $postCount++;

        // ⑥-1 답글 RE (use_reply가 true인 경우)
        if ($board->use_reply) {
            $replyCreatedAt = now()->subHours(1);
            $replyId = DB::table('board_posts')->insertGetId([
                'board_id' => $board->id,
                'category' => $firstCategory,
                'title' => 'Re: 답글이 달린 게시글',
                'content' => '<p>답글입니다. RE 배지가 표시됩니다.</p>',
                'content_mode' => 'html',
                'user_id' => $adminUser->id,
                'ip_address' => '127.0.0.1',
                'is_notice' => false,
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'view_count' => rand(5, 50),
                'parent_id' => $parentPostId,
                'depth' => 1,
                'deleted_at' => null,
                'created_at' => $replyCreatedAt,
                'updated_at' => $replyCreatedAt,
            ]);
            $replyCount++;
        }

        // ⑦ 이미지 없는 일반글
        $postId = $insertPost([
            'title' => '이미지 없는 게시글',
            'content' => '<p>첨부 이미지가 없는 게시글입니다. 플레이스홀더가 표시됩니다.</p>',
            'user_id' => $user->id,
            'category' => $firstCategory,
        ]);
        // 첨부파일 미생성 (의도적)
        $postCount++;

        // ⑧ 카테고리 있는 글 (카테고리가 있는 게시판만)
        if ($firstCategory) {
            $postId = $insertPost([
                'title' => "[$firstCategory] 카테고리가 있는 게시글",
                'content' => "<p>{$firstCategory} 카테고리에 속한 게시글입니다.</p>",
                'user_id' => $user->id,
                'category' => $firstCategory,
            ]);
            if ($board->use_file_upload) {
                $this->createAttachmentsForPost($board->id, $postId, $user->id);
            }
            $postCount++;
        }

        // ⑨ 일반글 + 이미지 있음 (댓글도 포함)
        $postId = $insertPost([
            'title' => '일반 게시글 (이미지 있음)',
            'content' => '<p>이미지가 첨부된 일반 게시글입니다. 정상적으로 표시됩니다.</p>',
            'user_id' => $user->id,
            'view_count' => rand(50, 300),
        ]);
        if ($board->use_file_upload) {
            $this->createAttachmentsForPost($board->id, $postId, $user->id);
        }
        if ($board->use_comment) {
            $commentCreatedAt = now()->subHours(2);
            DB::table('board_comments')->insert([
                'board_id' => $board->id,
                'post_id' => $postId,
                'user_id' => $adminUser->id,
                'parent_id' => null,
                'author_name' => null,
                'password' => null,
                'content' => '좋은 게시글 감사합니다!',
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'depth' => 0,
                'ip_address' => '127.0.0.1',
                'created_at' => $commentCreatedAt,
                'updated_at' => $commentCreatedAt,
            ]);
            $commentCreatedAt2 = now()->subHours(1);
            DB::table('board_comments')->insert([
                'board_id' => $board->id,
                'post_id' => $postId,
                'user_id' => $user->id,
                'parent_id' => null,
                'author_name' => null,
                'password' => null,
                'content' => '저도 좋아요!',
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'depth' => 0,
                'ip_address' => '127.0.0.1',
                'created_at' => $commentCreatedAt2,
                'updated_at' => $commentCreatedAt2,
            ]);
        }
        $postCount++;

        $this->command->info("  - {$board->slug}: 배지 케이스 보장 게시글 {$postCount}개, 답글 {$replyCount}개 생성 완료");

        return ['posts' => $postCount, 'replies' => $replyCount, 'comments' => ($board->use_comment ? 2 : 0)];
    }

    /**
     * 게시글 작성자를 결정합니다.
     *
     * notice는 관리자만, 나머지는 일반 사용자 + 관리자/스텝 혼합
     *
     * @param  Board  $board  게시판
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @param  int  $index  현재 인덱스
     * @return User 작성자
     */
    private function pickAuthor(Board $board, $users, int $index): User
    {
        // notice 게시판은 관리자만 작성
        if ($board->slug === 'notice') {
            return $this->adminUser ?? $users[0];
        }

        // 10% 확률로 관리자, 5% 확률로 스텝
        $roleRand = rand(1, 100);
        if ($roleRand <= 10 && $this->adminUser) {
            return $this->adminUser;
        }
        if ($roleRand <= 15 && $this->stepUser) {
            return $this->stepUser;
        }

        return $users[$index % count($users)];
    }

    /**
     * 게시글에 대한 답변글을 다단계로 생성합니다.
     *
     * @param  Board  $board  게시판
     * @param  int  $postId  원본 게시글 ID
     * @param  string|null  $category  카테고리
     * @param  array  $template  콘텐츠 템플릿
     * @param  bool  $isSecret  비밀글 여부
     * @param  \Carbon\Carbon  $createdAt  원본 게시글 생성일
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @return int 생성된 답변글 수
     */
    private function createRepliesForPost(
        Board $board,
        int $postId,
        ?string $category,
        array $template,
        bool $isSecret,
        \Carbon\Carbon $createdAt,
        $users
    ): int {
        // inquiry 게시판은 95% 확률, 나머지는 30% 확률
        $replyChance = ($board->slug === 'inquiry') ? 95 : 30;
        if (rand(1, 100) > $replyChance) {
            return 0;
        }

        $maxDepth = $board->max_reply_depth ?? 1;
        $replyCount = 0;
        $currentParentId = $postId;

        // depth 1 답변글 (관리자 또는 스텝이 답변)
        $replyUser = ($board->slug === 'inquiry')
            ? ($this->stepUser ?? $this->adminUser ?? $users[count($users) - 1])
            : ($this->adminUser ?? $users[count($users) - 1]);
        $replyCreatedAt = $createdAt->copy()->addHours(rand(1, 72));
        $answerTemplate = self::ANSWER_TEMPLATES[array_rand(self::ANSWER_TEMPLATES)];

        $replyId = DB::table('board_posts')->insertGetId([
            'board_id' => $board->id,
            'category' => $category,
            'title' => 'Re: '.$template['title'],
            'content' => '<p>'.$answerTemplate.'</p><p>추가 문의사항이 있으시면 말씀해주세요.</p>',
            'content_mode' => 'html',
            'user_id' => $replyUser->id,
            'author_name' => null,
            'password' => null,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => $isSecret,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => rand(0, 50),
            'parent_id' => $currentParentId,
            'depth' => 1,
            'created_at' => $replyCreatedAt,
            'updated_at' => $replyCreatedAt,
        ]);
        $replyCount++;
        $currentParentId = $replyId;

        // depth 2+ 답변글 체인 생성 (40% 확률로 이어짐, max_reply_depth까지)
        for ($depth = 2; $depth <= $maxDepth; $depth++) {
            if (rand(1, 100) > 40) {
                break;
            }

            // 짝수 depth는 원글 작성자(재문의), 홀수 depth는 관리자/스텝(재답변)
            $isUserReply = ($depth % 2 === 0);
            $depthUser = $isUserReply
                ? $users[rand(0, count($users) - 1)]
                : ($this->stepUser ?? $this->adminUser ?? $users[count($users) - 1]);

            $depthCreatedAt = $replyCreatedAt->copy()->addHours(rand(1, 48));

            $nestedTemplates = self::NESTED_ANSWER_TEMPLATES[$depth] ?? self::NESTED_ANSWER_TEMPLATES[3] ?? self::ANSWER_TEMPLATES;
            $nestedContent = $nestedTemplates[array_rand($nestedTemplates)];

            $prefix = $isUserReply ? 'Re: ' : 'Re: [답변] ';

            $newReplyId = DB::table('board_posts')->insertGetId([
                'board_id' => $board->id,
                'category' => $category,
                'title' => $prefix.$template['title'],
                'content' => '<p>'.$nestedContent.'</p>',
                'content_mode' => 'html',
                'user_id' => $depthUser->id,
                'author_name' => null,
                'password' => null,
                'ip_address' => '127.0.0.1',
                'is_notice' => false,
                'is_secret' => $isSecret,
                'status' => 'published',
                'trigger_type' => 'admin',
                'view_count' => rand(0, 30),
                'parent_id' => $currentParentId,
                'depth' => $depth,
                'created_at' => $depthCreatedAt,
                'updated_at' => $depthCreatedAt,
            ]);

            $replyCount++;
            $currentParentId = $newReplyId;
            $replyCreatedAt = $depthCreatedAt;
        }

        return $replyCount;
    }

    /**
     * 게시글에 대한 댓글을 다단계로 생성합니다.
     *
     * @param  Board  $board  게시판
     * @param  int  $postId  게시글 ID
     * @param  \Carbon\Carbon  $createdAt  게시글 생성일
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @param  int  $postIndex  게시글 인덱스 (날짜 분포 결정용)
     * @param  int  $todayCount  오늘 게시글 수
     * @param  int  $weekCount  이번주 게시글 수
     * @param  int  $monthCount  이번달 게시글 수
     * @param  bool  $allowGuestComment  비회원 댓글 허용 여부
     * @return int 생성된 댓글 수
     */
    private function createCommentsForPost(
        Board $board,
        int $postId,
        \Carbon\Carbon $createdAt,
        $users,
        int $postIndex,
        int $todayCount,
        int $weekCount,
        int $monthCount,
        bool $allowGuestComment
    ): int {
        $commentCount = 0;
        $maxCommentDepth = $board->max_comment_depth ?? 1;

        // 댓글 수: 축소된 범위
        $commentCountForPost = match (true) {
            $postIndex < $todayCount => rand(0, 3),
            $postIndex < $todayCount + $weekCount => rand(1, 5),
            $postIndex < $todayCount + $weekCount + $monthCount => rand(2, 8),
            default => rand(3, 10),
        };

        for ($j = 0; $j < $commentCountForPost; $j++) {
            // 비회원 댓글 - 권한 확인 후 30% 확률
            $isGuestComment = $allowGuestComment && rand(1, 10) <= 3;

            // 작성자: 일부는 관리자/스텝
            $commentUser = $this->pickCommentAuthor($users);

            // 댓글은 게시글보다 0~7일 후 작성
            $commentCreatedAt = $createdAt->copy()->addHours(rand(1, 168));

            $commentId = DB::table('board_comments')->insertGetId([
                'board_id' => $board->id,
                'post_id' => $postId,
                'user_id' => $isGuestComment ? null : $commentUser->id,
                'parent_id' => null,
                'author_name' => $isGuestComment ? '익명'.rand(1, 100) : null,
                'password' => $isGuestComment ? $this->getGuestPassword() : null,
                'content' => self::COMMENT_TEMPLATES[array_rand(self::COMMENT_TEMPLATES)],
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'depth' => 0,
                'ip_address' => '127.0.0.1',
                'created_at' => $commentCreatedAt,
                'updated_at' => $commentCreatedAt,
            ]);

            $commentCount++;

            // 다단계 대댓글 체인 생성 (50% 확률로 시작, depth별 감소)
            if (rand(0, 1) === 1 && $maxCommentDepth > 0) {
                $commentCount += $this->createNestedComments(
                    $board, $postId, $commentId, $commentCreatedAt, $users, $maxCommentDepth
                );
            }
        }

        return $commentCount;
    }

    /**
     * 다단계 대댓글 체인을 생성합니다.
     *
     * @param  Board  $board  게시판
     * @param  int  $postId  게시글 ID
     * @param  int  $parentCommentId  부모 댓글 ID
     * @param  \Carbon\Carbon  $parentCreatedAt  부모 댓글 생성일
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @param  int  $maxDepth  최대 depth
     * @return int 생성된 대댓글 수
     */
    private function createNestedComments(
        Board $board,
        int $postId,
        int $parentCommentId,
        \Carbon\Carbon $parentCreatedAt,
        $users,
        int $maxDepth
    ): int {
        $count = 0;
        $currentParentId = $parentCommentId;
        $currentCreatedAt = $parentCreatedAt;

        // depth 1부터 maxDepth까지 체인 생성 (depth가 깊어질수록 확률 감소)
        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            // depth별 생성 확률: depth 1=60%, depth 2=40%, depth 3+=25%
            $chance = match (true) {
                $depth === 1 => 60,
                $depth === 2 => 40,
                default => 25,
            };

            if (rand(1, 100) > $chance) {
                break;
            }

            $replyUser = $this->pickCommentAuthor($users);
            $replyCreatedAt = $currentCreatedAt->copy()->addHours(rand(1, 48));

            // depth별 템플릿 선택
            $templates = self::NESTED_REPLY_TEMPLATES[$depth] ?? self::NESTED_REPLY_TEMPLATES[3] ?? self::NESTED_REPLY_TEMPLATES[1];
            $content = $templates[array_rand($templates)];

            $newCommentId = DB::table('board_comments')->insertGetId([
                'board_id' => $board->id,
                'post_id' => $postId,
                'user_id' => $replyUser->id,
                'parent_id' => $currentParentId,
                'author_name' => null,
                'password' => null,
                'content' => $content,
                'is_secret' => false,
                'status' => 'published',
                'trigger_type' => 'admin',
                'depth' => $depth,
                'ip_address' => '127.0.0.1',
                'created_at' => $replyCreatedAt,
                'updated_at' => $replyCreatedAt,
            ]);

            $count++;
            $currentParentId = $newCommentId;
            $currentCreatedAt = $replyCreatedAt;
        }

        return $count;
    }

    /**
     * 댓글 작성자를 결정합니다.
     *
     * 8% 관리자, 5% 스텝, 나머지 일반 사용자
     *
     * @param  \Illuminate\Support\Collection  $users  사용자 목록
     * @return User 작성자
     */
    private function pickCommentAuthor($users): User
    {
        $roleRand = rand(1, 100);
        if ($roleRand <= 8 && $this->adminUser) {
            return $this->adminUser;
        }
        if ($roleRand <= 13 && $this->stepUser) {
            return $this->stepUser;
        }

        return $users[rand(0, count($users) - 1)];
    }

    /**
     * 게시판의 secret_mode에 따라 비밀글 여부를 결정합니다.
     *
     * @param  Board  $board  게시판
     * @return bool 비밀글 여부
     */
    private function determineIsSecret(Board $board): bool
    {
        return match ($board->secret_mode->value) {
            'always' => true,              // 항상 비밀글 (inquiry)
            'disabled' => false,           // 절대 비밀글 아님 (notice, gallery, members)
            'enabled' => rand(1, 5) === 1, // 20% 확률 (free, qna)
            default => false,
        };
    }

    /**
     * 날짜 분포에 따른 created_at을 생성합니다.
     *
     * 날짜 포맷 표시 구간을 모두 커버하도록 분포:
     * - 방금 전 (30초 이내)
     * - 1~9분 전 (정확한 분 표시)
     * - 10~50분 전 (10분 단위 내림 표시)
     * - 1~23시간 전
     * - 1~29일 전 (표준형: MM-DD, 유동형: N일 전)
     * - 1~11개월 전 (유동형: N개월 전)
     * - 1~3년 전 (표준형: YY-MM-DD, 유동형: N년 전)
     *
     * @param  int  $index  현재 인덱스
     * @param  int  $todayCount  오늘 게시글 수 (방금~24시간 전)
     * @param  int  $weekCount  이번주 게시글 수 (1~7일 전)
     * @param  int  $monthCount  이번달 게시글 수 (8~30일 전)
     * @return \Carbon\Carbon 생성일
     */
    private function generateCreatedAt(int $index, int $todayCount, int $weekCount, int $monthCount): \Carbon\Carbon
    {
        if ($index < $todayCount) {
            // 오늘: 방금 전 ~ 23시간 전 (모든 분/시간 구간 커버)
            $segment = $index % 6;

            return match ($segment) {
                0 => now()->subSeconds(rand(0, 30)),          // 방금 전 (30초 이내)
                1 => now()->subMinutes(rand(1, 9)),           // 1~9분 전
                2 => now()->subMinutes(rand(10, 19)),         // 10~19분 → 10분 전 표시
                3 => now()->subMinutes(rand(20, 59)),         // 20~59분 → 20~50분 전 표시
                4 => now()->subHours(rand(1, 11)),            // 1~11시간 전
                default => now()->subHours(rand(12, 23)),     // 12~23시간 전
            };
        } elseif ($index < $todayCount + $weekCount) {
            // 이번주: 1~7일 전
            return now()->subDays(rand(1, 7))->subHours(rand(0, 23));
        } elseif ($index < $todayCount + $weekCount + $monthCount) {
            // 이번달: 8~30일 전 (유동형 N일 전, 표준형 MM-DD)
            return now()->subDays(rand(8, 30));
        } else {
            // 오래된 글: 1개월~3년 전 (유동형 N개월/N년, 표준형 YY-MM-DD)
            $segment = $index % 3;

            return match ($segment) {
                0 => now()->subMonths(rand(1, 5)),            // 1~5개월 전
                1 => now()->subMonths(rand(6, 11)),           // 6~11개월 전
                default => now()->subYears(rand(1, 3)),       // 1~3년 전
            };
        }
    }

    /**
     * 게시판이 비회원 글쓰기를 허용하는지 확인합니다.
     *
     * @param  Board  $board  게시판
     * @return bool 비회원 글쓰기 허용 여부
     */
    private function boardAllowsGuestWrite(Board $board): bool
    {
        $permission = Permission::where(
            'identifier',
            "sirsoft-board.{$board->slug}.posts.write"
        )->first();

        if (! $permission) {
            return false;
        }

        // 역할이 없으면 전체 허용
        if ($permission->roles()->count() === 0) {
            return true;
        }

        return $permission->roles()->where('identifier', 'guest')->exists();
    }

    /**
     * 게시판이 비회원 댓글을 허용하는지 확인합니다.
     *
     * @param  Board  $board  게시판
     * @return bool 비회원 댓글 허용 여부
     */
    private function boardAllowsGuestComment(Board $board): bool
    {
        if (! $board->use_comment) {
            return false;
        }

        $permission = Permission::where(
            'identifier',
            "sirsoft-board.{$board->slug}.comments.write"
        )->first();

        if (! $permission) {
            return false;
        }

        // 역할이 없으면 전체 허용
        if ($permission->roles()->count() === 0) {
            return true;
        }

        return $permission->roles()->where('identifier', 'guest')->exists();
    }

    /**
     * 비회원 비밀번호 해시를 반환합니다 (캐싱).
     *
     * bcrypt 반복 호출로 인한 메모리 소모를 방지합니다.
     *
     * @return string bcrypt 해시
     */
    private function getGuestPassword(): string
    {
        if ($this->guestPasswordHash === null) {
            $this->guestPasswordHash = bcrypt('1234');
        }

        return $this->guestPasswordHash;
    }

    /**
     * 첨부파일 인덱스 (샘플 이미지를 순환하며 사용)
     */
    private int $attachmentIndex = 0;

    /**
     * 게시글에 첨부파일을 생성합니다.
     *
     * 미리 생성한 5개의 샘플 이미지 파일을 돌려가면서 사용합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  int  $postId  게시글 ID
     * @param  int|null  $userId  사용자 ID
     */
    private function createAttachmentsForPost(int $boardId, int $postId, ?int $userId): void
    {
        // 첨부파일 개수 랜덤 (1~3개)
        $attachmentCount = rand(1, 3);

        for ($i = 0; $i < $attachmentCount; $i++) {
            try {
                // 미리 생성된 샘플 이미지 중 하나 선택 (순환)
                $sampleImage = $this->sampleImages[$this->attachmentIndex % self::SAMPLE_IMAGE_COUNT];
                $this->attachmentIndex++;

                $meta = json_encode([
                    'width' => $sampleImage['width'],
                    'height' => $sampleImage['height'],
                ]);

                // DB에 첨부파일 레코드 생성 (샘플 파일 경로 사용)
                DB::table('board_attachments')->insert([
                    'board_id' => $boardId,
                    'post_id' => $postId,
                    'hash' => Str::random(12),
                    'original_filename' => 'sample-image-'.rand(1, 1000).'.jpg',
                    'stored_filename' => $sampleImage['filename'],
                    'disk' => 'modules',
                    'path' => $sampleImage['path'],
                    'mime_type' => 'image/jpeg',
                    'size' => $sampleImage['size'],
                    'collection' => 'attachments',
                    'order' => $i + 1,
                    'meta' => $meta,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Exception $e) {
                // 오류 발생 시 스킵하고 계속 진행
                $this->command->warn("    - 첨부파일 레코드 생성 실패 (Post {$postId}): {$e->getMessage()}");

                continue;
            }
        }
    }

    /**
     * 샘플 이미지 생성 (GD 라이브러리 사용)
     *
     * @param  int  $width  이미지 너비
     * @param  int  $height  이미지 높이
     * @return string JPEG 이미지 바이너리
     */
    private function createSampleImage(int $width, int $height): string
    {
        // GD 라이브러리로 간단한 그라데이션 이미지 생성
        $image = imagecreatetruecolor($width, $height);

        // 랜덤 색상으로 그라데이션 배경
        $startColor = [rand(50, 200), rand(50, 200), rand(50, 200)];
        $endColor = [rand(50, 200), rand(50, 200), rand(50, 200)];

        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / $height;
            $r = (int) ($startColor[0] + ($endColor[0] - $startColor[0]) * $ratio);
            $g = (int) ($startColor[1] + ($endColor[1] - $startColor[1]) * $ratio);
            $b = (int) ($startColor[2] + ($endColor[2] - $startColor[2]) * $ratio);
            $color = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $y, $width, $y, $color);
        }

        // 텍스트 추가
        $textColor = imagecolorallocate($image, 255, 255, 255);
        $text = 'Sample Image';
        $fontSize = 5;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        imagestring($image, $fontSize, (int) $x, (int) $y, $text, $textColor);

        // JPEG로 출력
        ob_start();
        imagejpeg($image, null, 85);
        $imageContent = ob_get_clean();
        imagedestroy($image);

        return $imageContent;
    }
}
