<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;

/**
 * 공통정보 시더
 *
 * 이커머스에서 사용되는 다양한 배송, 반품, 교환, 환불, A/S, 상품별 안내 정보를 생성합니다.
 */
class ProductCommonInfoSeeder extends Seeder
{
    /**
     * 공통정보 데이터 배열
     *
     * @var array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}, content_mode?: string, is_default?: bool, is_active?: bool}>
     */
    private array $commonInfos = [];

    public function __construct()
    {
        $this->commonInfos = array_merge(
            $this->getShippingPolicies(),
            $this->getReturnPolicies(),
            $this->getExchangePolicies(),
            $this->getRefundPolicies(),
            $this->getWarrantyPolicies(),
            $this->getProductSpecificNotices()
        );
    }

    /**
     * 시더를 실행합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $this->command->info('공통정보 시더 시작...');

        $this->deleteExisting();
        $this->createCommonInfos();

        $count = ProductCommonInfo::count();
        $this->command->info("공통정보 시더 완료: {$count}개 생성됨");
    }

    /**
     * 기존 공통정보를 삭제합니다.
     *
     * @return void
     */
    private function deleteExisting(): void
    {
        $count = ProductCommonInfo::count();
        if ($count > 0) {
            ProductCommonInfo::query()->delete();
            $this->command->line("  - 기존 공통정보 {$count}개 삭제됨");
        }
    }

    /**
     * 공통정보를 생성합니다.
     *
     * @return void
     */
    private function createCommonInfos(): void
    {
        foreach ($this->commonInfos as $index => $data) {
            ProductCommonInfo::create([
                'name' => $data['name'],
                'content' => $data['content'],
                'content_mode' => $data['content_mode'] ?? 'text',
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * 배송 정책 (20개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}, content_mode?: string, is_default?: bool}>
     */
    private function getShippingPolicies(): array
    {
        return [
            [
                'name' => ['ko' => '일반 배송 안내', 'en' => 'Standard Shipping'],
                'content' => [
                    'ko' => "• 배송 기간: 결제 완료 후 1~3일 이내 출고 (영업일 기준)\n• 배송 업체: CJ대한통운, 한진택배, 롯데택배\n• 배송 지역: 전국 배송 가능 (일부 도서산간 지역 제외)\n• 배송 조회: 마이페이지 > 주문내역에서 실시간 조회 가능\n• 배송비: 3만원 이상 구매 시 무료, 미만 시 3,000원",
                    'en' => "• Delivery Time: Ships within 1-3 business days after payment\n• Carriers: CJ Logistics, Hanjin, Lotte\n• Coverage: Nationwide (some remote areas excluded)\n• Tracking: Available in My Page > Order History\n• Shipping Fee: Free for orders over 30,000 KRW, otherwise 3,000 KRW",
                ],
                'is_default' => true,
            ],
            [
                'name' => ['ko' => '당일 배송 안내', 'en' => 'Same-Day Delivery'],
                'content' => [
                    'ko' => "• 주문 마감: 오전 11시까지 결제 완료 시 당일 출고\n• 배송 지역: 서울, 경기, 인천 일부 지역\n• 배송비: 7만원 이상 무료, 미만 시 4,000원\n• 수령 시간: 오후 6시~9시 사이 도착\n• 주의: 재고 상황에 따라 익일 배송으로 변경될 수 있습니다",
                    'en' => "• Order Deadline: Complete payment by 11 AM for same-day shipping\n• Coverage: Parts of Seoul, Gyeonggi, Incheon\n• Fee: Free for orders over 70,000 KRW, otherwise 4,000 KRW\n• Arrival: Between 6 PM - 9 PM\n• Note: May change to next-day delivery based on stock availability",
                ],
            ],
            [
                'name' => ['ko' => '새벽 배송 안내', 'en' => 'Dawn Delivery'],
                'content' => [
                    'ko' => "• 주문 마감: 오후 11시까지 결제 완료 시 익일 새벽 7시 이전 도착\n• 배송 지역: 서울, 경기 일부 지역\n• 배송비: 5만원 이상 무료, 미만 시 5,000원\n• 부재 시: 문 앞 안전 배송 (Fresh Box 사용)\n• 휴무일: 일요일, 법정 공휴일은 새벽배송 불가",
                    'en' => "• Order Deadline: Complete payment by 11 PM for delivery before 7 AM next day\n• Coverage: Seoul and parts of Gyeonggi\n• Fee: Free for orders over 50,000 KRW, otherwise 5,000 KRW\n• If Absent: Safe delivery at door (Fresh Box)\n• Holidays: No dawn delivery on Sundays and public holidays",
                ],
            ],
            [
                'name' => ['ko' => '해외 배송 안내', 'en' => 'International Shipping'],
                'content' => [
                    'ko' => "• 배송 기간: 국가별 7~21일 소요\n• 배송 업체: DHL, FedEx, EMS\n• 관부가세: 수입국 기준에 따라 고객 부담\n• 배송비: 무게 및 국가별 상이 (결제 시 자동 계산)\n• 배송 불가: 일부 제한 품목 및 국가 존재",
                    'en' => "• Delivery Time: 7-21 days depending on country\n• Carriers: DHL, FedEx, EMS\n• Customs/Tax: Customer responsibility based on destination country\n• Fee: Varies by weight and country (calculated at checkout)\n• Restrictions: Some items and countries not available",
                ],
            ],
            [
                'name' => ['ko' => '도서산간 배송 안내', 'en' => 'Remote Area Shipping'],
                'content' => [
                    'ko' => "• 배송 기간: 출고 후 3~7일 소요\n• 추가 배송비: 3,000원~5,000원 추가 발생\n• 해당 지역: 제주도, 울릉도, 도서 지역\n• 일부 상품: 배송 불가 상품 존재\n• 조회 방법: 결제 전 배송비 자동 계산",
                    'en' => "• Delivery Time: 3-7 days after shipping\n• Additional Fee: 3,000-5,000 KRW extra\n• Areas: Jeju, Ulleungdo, island regions\n• Some Products: May not be available for delivery\n• Check: Shipping fee calculated automatically before payment",
                ],
            ],
            [
                'name' => ['ko' => '대형 상품 배송', 'en' => 'Large Item Delivery'],
                'content' => [
                    'ko' => "• 배송 기간: 주문 후 3~7일 소요 (설치 일정 협의)\n• 배송 방법: 전문 배송 업체 방문 설치\n• 배송비: 상품별 상이 (상품 상세 참조)\n• 설치비: 기본 설치 무료, 추가 작업 시 별도 비용\n• 사전 연락: 배송 전 전화 연락 후 방문",
                    'en' => "• Delivery Time: 3-7 days after order (installation date to be arranged)\n• Method: Professional delivery with installation\n• Fee: Varies by product (see product details)\n• Installation: Basic installation free, additional work charged separately\n• Prior Contact: Phone call before delivery visit",
                ],
            ],
            [
                'name' => ['ko' => '설치 배송 안내', 'en' => 'Installation Delivery'],
                'content' => [
                    'ko' => "• 설치 범위: 기본 설치 (포장재 수거 포함)\n• 추가 비용: 벽걸이 설치, 철거, 특수 작업 시 별도\n• 설치 시간: 평일 오전 9시~오후 6시\n• 예약 변경: 설치 예정일 2일 전까지 가능\n• 설치 불가: 안전상 설치가 어려운 환경",
                    'en' => "• Scope: Basic installation (includes packaging disposal)\n• Extra Cost: Wall mounting, removal, special work charged separately\n• Hours: Weekdays 9 AM - 6 PM\n• Reschedule: Up to 2 days before scheduled date\n• Cannot Install: Unsafe installation environments",
                ],
            ],
            [
                'name' => ['ko' => '냉장/냉동 배송', 'en' => 'Refrigerated/Frozen Delivery'],
                'content' => [
                    'ko' => "• 배송 방법: 전용 냉장/냉동 차량으로 신선하게 배송\n• 배송 시간: 오전 출고, 당일 또는 익일 도착\n• 부재 시: 경비실 또는 지정 장소에 보관\n• 주의사항: 수령 후 즉시 냉장/냉동 보관 필수\n• 여름철: 아이스팩 동봉, 신선도 유지",
                    'en' => "• Method: Dedicated refrigerated/frozen vehicles for fresh delivery\n• Timing: Ships in morning, arrives same day or next day\n• If Absent: Left with security or at designated location\n• Important: Must refrigerate/freeze immediately upon receipt\n• Summer: Ice packs included for freshness",
                ],
            ],
            [
                'name' => ['ko' => '무료 배송 조건', 'en' => 'Free Shipping Conditions'],
                'content' => [
                    'ko' => "• 일반 배송: 3만원 이상 구매 시 무료\n• 새벽 배송: 5만원 이상 구매 시 무료\n• 당일 배송: 7만원 이상 구매 시 무료\n• 도서산간: 무료 배송 대상에서 제외\n• 프로모션: 이벤트 기간 조건 변경 가능",
                    'en' => "• Standard: Free for orders over 30,000 KRW\n• Dawn Delivery: Free for orders over 50,000 KRW\n• Same-Day: Free for orders over 70,000 KRW\n• Remote Areas: Excluded from free shipping\n• Promotions: Conditions may change during events",
                ],
            ],
            [
                'name' => ['ko' => '배송 추적 안내', 'en' => 'Shipment Tracking'],
                'content' => [
                    'ko' => "• 조회 방법: 마이페이지 > 주문내역 > 배송조회\n• 알림 서비스: 카카오톡/문자로 배송 상태 안내\n• 배송 단계: 상품준비 → 출고 → 배송중 → 배송완료\n• 문의: 배송 지연 시 고객센터 1588-0000\n• 송장번호: 출고 완료 후 문자로 발송",
                    'en' => "• How to Track: My Page > Order History > Track Shipment\n• Notifications: KakaoTalk/SMS delivery status updates\n• Stages: Preparing → Shipped → In Transit → Delivered\n• Inquiries: Call 1588-0000 for delivery delays\n• Tracking Number: Sent via SMS after shipping",
                ],
            ],
            [
                'name' => ['ko' => '부분 배송 안내', 'en' => 'Partial Shipment Notice'],
                'content' => [
                    'ko' => "• 적용 조건: 주문 상품의 재고 상황이 다른 경우\n• 배송비: 부분 배송 시에도 추가 배송비 없음\n• 알림: 부분 출고 시 별도 안내 문자 발송\n• 잔여 상품: 입고 즉시 순차 발송\n• 취소: 미출고 상품 개별 취소 가능",
                    'en' => "• When Applied: Items have different stock availability\n• Shipping Fee: No additional fee for partial shipment\n• Notification: Separate SMS for partial shipments\n• Remaining Items: Shipped immediately upon restocking\n• Cancellation: Unshipped items can be cancelled individually",
                ],
            ],
            [
                'name' => ['ko' => '예약 배송 안내', 'en' => 'Scheduled Delivery'],
                'content' => [
                    'ko' => "• 예약 방법: 결제 시 희망 배송일 선택\n• 예약 가능: 결제일로부터 최대 30일 이내\n• 배송 시간: 오전/오후/저녁 중 선택\n• 변경: 배송 예정일 3일 전까지 가능\n• 주의: 일부 상품 예약 배송 불가",
                    'en' => "• How to Schedule: Select preferred date during checkout\n• Available: Up to 30 days from payment date\n• Time Slots: Choose morning/afternoon/evening\n• Changes: Up to 3 days before scheduled date\n• Note: Some products not available for scheduled delivery",
                ],
            ],
            [
                'name' => ['ko' => '퀵서비스 배송', 'en' => 'Quick Service Delivery'],
                'content' => [
                    'ko' => "• 배송 시간: 주문 후 2~4시간 이내 도착\n• 가능 지역: 서울 전 지역\n• 배송비: 10,000원~15,000원 (거리에 따라 상이)\n• 결제 방법: 선불 결제만 가능\n• 이용 시간: 오전 9시~오후 9시",
                    'en' => "• Delivery Time: Arrives within 2-4 hours of order\n• Coverage: All of Seoul\n• Fee: 10,000-15,000 KRW (varies by distance)\n• Payment: Prepaid only\n• Hours: 9 AM - 9 PM",
                ],
            ],
            [
                'name' => ['ko' => '편의점 픽업', 'en' => 'Convenience Store Pickup'],
                'content' => [
                    'ko' => "• 이용 방법: 결제 시 편의점 픽업 선택\n• 제휴 편의점: GS25, CU, 세븐일레븐\n• 보관 기간: 도착일로부터 3일\n• 알림: 도착 시 문자/카카오톡 안내\n• 수령: 신분증 지참, 인증번호 필요",
                    'en' => "• How to Use: Select convenience store pickup at checkout\n• Partner Stores: GS25, CU, 7-Eleven\n• Storage Period: 3 days from arrival\n• Notification: SMS/KakaoTalk upon arrival\n• Pickup: Bring ID and verification code",
                ],
            ],
            [
                'name' => ['ko' => '매장 픽업', 'en' => 'Store Pickup'],
                'content' => [
                    'ko' => "• 이용 방법: 결제 시 매장 픽업 선택 후 매장 지정\n• 준비 시간: 주문 후 1~2시간 내 준비 완료\n• 알림: 픽업 준비 완료 시 문자 발송\n• 수령: 주문번호 또는 QR코드 제시\n• 운영 시간: 각 매장 영업시간 내",
                    'en' => "• How to Use: Select store pickup at checkout and choose store\n• Preparation: Ready within 1-2 hours of order\n• Notification: SMS when ready for pickup\n• Pickup: Show order number or QR code\n• Hours: During store operating hours",
                ],
            ],
            [
                'name' => ['ko' => '안심 배송 서비스', 'en' => 'Safe Delivery Service'],
                'content' => [
                    'ko' => "• 배송 사진: 배송 완료 시 사진 촬영하여 전송\n• 부재 시: 문 앞 안전 배송 또는 경비실 보관\n• 분실 보상: 배송 완료 후 분실 시 100% 보상\n• 도난 방지: 봉인 테이프 사용, 훼손 확인 가능\n• 알림: 배송 완료 즉시 문자/앱 알림",
                    'en' => "• Photo Proof: Photo taken and sent upon delivery\n• If Absent: Safe delivery at door or left with security\n• Loss Compensation: 100% compensation if lost after delivery\n• Theft Prevention: Sealed tape, can verify tampering\n• Notification: Instant SMS/app alert upon delivery",
                ],
            ],
            [
                'name' => ['ko' => '친환경 포장 배송', 'en' => 'Eco-Friendly Packaging'],
                'content' => [
                    'ko' => "• 포장재: 재활용 가능한 친환경 소재 사용\n• 테이프: 종이 테이프 사용, 분리 배출 용이\n• 완충재: 종이 완충재 또는 옥수수 전분 완충재\n• 반납: 배송 박스 반납 시 포인트 적립\n• 목표: 탄소 발자국 최소화",
                    'en' => "• Materials: Recyclable eco-friendly packaging\n• Tape: Paper tape for easy recycling\n• Cushioning: Paper or corn starch cushioning\n• Return: Points for returning delivery boxes\n• Goal: Minimize carbon footprint",
                ],
            ],
            [
                'name' => ['ko' => '선물 포장 배송', 'en' => 'Gift Wrapping Delivery'],
                'content' => [
                    'ko' => "• 포장 옵션: 일반 선물 포장, 프리미엄 포장 선택 가능\n• 추가 비용: 일반 2,000원, 프리미엄 5,000원\n• 메시지 카드: 무료 메시지 카드 동봉 가능\n• 가격표 제거: 선물 포장 시 가격 정보 제거\n• 직접 전달: 수령인 주소로 직접 배송 가능",
                    'en' => "• Options: Standard gift wrap or premium wrapping\n• Extra Cost: Standard 2,000 KRW, Premium 5,000 KRW\n• Message Card: Free message card included\n• Price Removal: Price information removed for gifts\n• Direct Delivery: Can ship directly to recipient",
                ],
            ],
            [
                'name' => ['ko' => '군부대 배송 안내', 'en' => 'Military Base Delivery'],
                'content' => [
                    'ko' => "• 배송 가능: 전국 군부대 배송 가능\n• 배송 기간: 일반 배송보다 2~3일 추가 소요\n• 주소 입력: 부대명, 보급대 주소 정확히 입력\n• 수령인: 군번 또는 사번 필수 기재\n• 제한 품목: 부대별 반입 금지 품목 확인 필요",
                    'en' => "• Available: Delivery to all military bases nationwide\n• Delivery Time: 2-3 additional days vs standard\n• Address: Enter base name and supply depot address accurately\n• Recipient: Military/service number required\n• Restrictions: Check prohibited items per base",
                ],
            ],
            [
                'name' => ['ko' => '해외 직구 배송', 'en' => 'Direct Import Shipping'],
                'content' => [
                    'ko' => "• 배송 기간: 국가별 10~30일 소요\n• 관세: 물품가 $150 초과 시 관부가세 발생\n• 추적: 국제 배송 추적번호 제공\n• 배송 업체: 현지 물류사 + 국내 배송사\n• 통관: 개인통관고유부호 필수 입력",
                    'en' => "• Delivery Time: 10-30 days depending on country\n• Customs: Tax applies for items over $150\n• Tracking: International tracking number provided\n• Carriers: Local logistics + domestic delivery\n• Clearance: Personal customs code required",
                ],
            ],
        ];
    }

    /**
     * 반품 정책 (18개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}}>
     */
    private function getReturnPolicies(): array
    {
        return [
            [
                'name' => ['ko' => '일반 반품 안내', 'en' => 'Return Policy'],
                'content' => [
                    'ko' => "• 반품 기간: 상품 수령 후 7일 이내\n• 반품 조건: 상품 및 포장 상태가 훼손되지 않은 경우\n• 반품 불가: 고객 변심으로 인한 훼손, 사용 흔적이 있는 경우\n• 반품 배송비: 고객 변심 시 왕복 6,000원, 제품 하자 시 무료\n• 반품 방법: 마이페이지에서 반품 신청 후 택배 수거",
                    'en' => "• Return Period: Within 7 days of receipt\n• Condition: Product and packaging must be undamaged\n• Non-returnable: Damaged by customer, signs of use\n• Shipping Cost: 6,000 KRW round-trip for change of mind, free for defects\n• How to Return: Request via My Page, courier pickup arranged",
                ],
            ],
            [
                'name' => ['ko' => '반품 불가 안내', 'en' => 'Non-Returnable Items'],
                'content' => [
                    'ko' => "• 개봉 상품: 포장을 개봉하여 상품 가치가 손상된 경우\n• 사용 흔적: 착용, 사용, 조립한 흔적이 있는 경우\n• 소비성 상품: 식품, 화장품 등 일부 소비 시\n• 맞춤 제작: 주문 제작 상품, 개인화 상품\n• 복제 가능: 소프트웨어, 디지털 콘텐츠",
                    'en' => "• Opened: Packaging opened, product value damaged\n• Used: Signs of wear, use, or assembly\n• Consumables: Food, cosmetics partially consumed\n• Custom Made: Made-to-order, personalized items\n• Reproducible: Software, digital content",
                ],
            ],
            [
                'name' => ['ko' => '식품 반품 정책', 'en' => 'Food Return Policy'],
                'content' => [
                    'ko' => "• 반품 가능: 상품 하자, 오배송, 유통기한 임박 상품\n• 반품 불가: 단순 변심, 개봉 후 반품, 냉장/냉동 식품 수령 후\n• 신선식품: 수령 당일 이상 발견 시 사진 첨부하여 고객센터 문의\n• 환불: 반품 확인 후 2~3일 내 처리\n• 주의: 식품 특성상 반품이 제한될 수 있습니다",
                    'en' => "• Returnable: Defective, wrong delivery, near-expiry products\n• Non-returnable: Change of mind, opened items, refrigerated/frozen after receipt\n• Fresh Food: Contact customer service with photos on delivery day\n• Refund: Processed within 2-3 days after return confirmation\n• Note: Returns may be limited due to food product nature",
                ],
            ],
            [
                'name' => ['ko' => '화장품 반품 정책', 'en' => 'Cosmetics Return Policy'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 14일 이내 (미개봉에 한함)\n• 개봉 상품: 위생상 반품/교환 불가\n• 피부 트러블: 사용 후 트러블 발생 시 사진 첨부 문의\n• 샘플/증정품: 반품 시 함께 반송 필수\n• 정품 확인: 정품 인증 상품만 반품 가능",
                    'en' => "• Return Period: Within 14 days (unopened only)\n• Opened: Cannot return/exchange for hygiene reasons\n• Skin Issues: Contact with photos if issues occur after use\n• Samples/Gifts: Must return together\n• Authenticity: Only authentic products can be returned",
                ],
            ],
            [
                'name' => ['ko' => '의류 반품 정책', 'en' => 'Clothing Return Policy'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 14일 이내\n• 반품 조건: 택 제거하지 않음, 세탁하지 않음, 착용 흔적 없음\n• 반품 불가: 속옷, 수영복, 맞춤 제작 의류\n• 교환: 동일 상품 다른 사이즈/색상으로 1회 무료 교환\n• 반품 배송비: 고객 변심 5,000원, 사이즈 오차/하자 무료",
                    'en' => "• Return Period: Within 14 days of receipt\n• Condition: Tags attached, unwashed, no signs of wear\n• Non-returnable: Underwear, swimwear, custom-made clothing\n• Exchange: One free exchange for different size/color of same item\n• Shipping Cost: 5,000 KRW for change of mind, free for size discrepancy/defects",
                ],
            ],
            [
                'name' => ['ko' => '전자제품 반품 정책', 'en' => 'Electronics Return Policy'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 7일 이내\n• 반품 조건: 미개봉, 미사용 상태, 모든 구성품 포함\n• 개봉 상품: 초기 불량에 한해 교환/반품 가능\n• 시리얼 확인: 시리얼 번호 일치 여부 확인\n• 데이터 삭제: 개인 정보 삭제 후 반품 권장",
                    'en' => "• Return Period: Within 7 days of receipt\n• Condition: Unopened, unused, all components included\n• Opened: Exchange/return only for initial defects\n• Serial Check: Serial number verification required\n• Data: Recommend deleting personal data before return",
                ],
            ],
            [
                'name' => ['ko' => '가구 반품 정책', 'en' => 'Furniture Return Policy'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 7일 이내\n• 반품 조건: 조립 전, 미사용 상태\n• 조립 상품: 조립 완료 시 반품 불가 (하자 제외)\n• 반품 배송비: 대형 상품 특성상 별도 비용 발생\n• 설치 상품: 설치 완료 시 반품 제한",
                    'en' => "• Return Period: Within 7 days of receipt\n• Condition: Before assembly, unused\n• Assembled: Cannot return after assembly (except defects)\n• Shipping Cost: Additional fee for large items\n• Installed: Limited returns after installation",
                ],
            ],
            [
                'name' => ['ko' => '맞춤 제작 반품 불가', 'en' => 'Custom-Made Non-Returnable'],
                'content' => [
                    'ko' => "• 적용 상품: 주문 제작, 맞춤 사이즈, 각인 상품\n• 반품 불가 사유: 고객 요청에 따른 개별 제작\n• 하자 발생 시: 동일 사양으로 재제작 또는 환불\n• 제작 취소: 제작 시작 전까지만 취소 가능\n• 확인 필수: 주문 전 사양 꼼꼼히 확인",
                    'en' => "• Applies to: Made-to-order, custom size, engraved items\n• Non-returnable: Custom made per customer request\n• If Defective: Remake with same specs or refund\n• Cancellation: Only before production starts\n• Important: Verify specifications before ordering",
                ],
            ],
            [
                'name' => ['ko' => '위생용품 반품 불가', 'en' => 'Hygiene Products Non-Returnable'],
                'content' => [
                    'ko' => "• 적용 상품: 속옷, 수영복, 칫솔, 면도기, 마스크 등\n• 반품 불가 사유: 위생상 재판매 불가\n• 하자 발생 시: 동일 상품 교환 또는 환불\n• 포장 훼손: 포장 개봉만으로도 반품 불가\n• 예외: 미개봉 상태의 명백한 하자",
                    'en' => "• Applies to: Underwear, swimwear, toothbrushes, razors, masks, etc.\n• Non-returnable: Cannot resell for hygiene reasons\n• If Defective: Exchange for same item or refund\n• Package Damage: Even opening package makes return impossible\n• Exception: Clear defects in unopened state",
                ],
            ],
            [
                'name' => ['ko' => '소프트웨어 반품 정책', 'en' => 'Software Return Policy'],
                'content' => [
                    'ko' => "• 반품 불가: 시리얼 등록 또는 인증 완료 시\n• 미등록 상품: 포장 미개봉 시 7일 이내 반품 가능\n• 다운로드: 다운로드 시작 시 반품 불가\n• 구독형: 이용 시작 후 일할 계산 환불\n• 기업용: 볼륨 라이선스 별도 정책 적용",
                    'en' => "• Non-returnable: After serial registration or activation\n• Unregistered: Returnable within 7 days if sealed\n• Downloads: No returns after download starts\n• Subscription: Prorated refund after use begins\n• Enterprise: Volume licenses have separate policies",
                ],
            ],
            [
                'name' => ['ko' => '다운로드 상품 반품', 'en' => 'Digital Download Returns'],
                'content' => [
                    'ko' => "• 반품 불가: 다운로드 완료 즉시 반품 불가\n• 다운로드 전: 결제 후 다운로드 전 취소 가능\n• 스트리밍: 재생 시작 시 반품 불가\n• 기술 문제: 다운로드 오류 시 재다운로드 제공\n• 환불 기준: 전자상거래법에 따른 환불 제한",
                    'en' => "• Non-returnable: Immediately after download complete\n• Before Download: Can cancel after payment, before download\n• Streaming: No returns after playback starts\n• Technical Issues: Re-download provided for errors\n• Refund Standard: Limited refunds per e-commerce law",
                ],
            ],
            [
                'name' => ['ko' => '반품 배송비 안내', 'en' => 'Return Shipping Costs'],
                'content' => [
                    'ko' => "• 고객 변심: 왕복 배송비 고객 부담 (5,000~6,000원)\n• 상품 하자: 왕복 배송비 무료\n• 오배송: 배송비 전액 무료\n• 도서산간: 추가 배송비 발생 가능\n• 결제: 환불 금액에서 차감 또는 착불",
                    'en' => "• Change of Mind: Customer pays round-trip (5,000-6,000 KRW)\n• Product Defect: Free round-trip shipping\n• Wrong Delivery: Completely free shipping\n• Remote Areas: Additional shipping may apply\n• Payment: Deducted from refund or cash on delivery",
                ],
            ],
            [
                'name' => ['ko' => '무료 반품 서비스', 'en' => 'Free Return Service'],
                'content' => [
                    'ko' => "• 대상 상품: 무료 반품 아이콘 표시 상품\n• 반품 사유: 어떤 사유든 무료 반품 가능\n• 반품 횟수: 월 3회까지 무료 (초과 시 유료)\n• 반품 방법: 마이페이지에서 반품 접수 후 수거\n• 적용 기간: 수령 후 14일 이내",
                    'en' => "• Eligible: Products with free return icon\n• Reason: Free return for any reason\n• Limit: Up to 3 free returns per month (charged after)\n• Method: Submit return request in My Page, pickup arranged\n• Period: Within 14 days of receipt",
                ],
            ],
            [
                'name' => ['ko' => '반품 기간 안내 (7일)', 'en' => 'Return Period (7 Days)'],
                'content' => [
                    'ko' => "• 반품 기간: 상품 수령일로부터 7일 이내\n• 기간 산정: 수령일 다음날부터 7일\n• 휴일 포함: 토/일/공휴일 포함 계산\n• 반품 접수: 기간 내 반품 신청 완료 필요\n• 기간 초과: 반품 접수 불가",
                    'en' => "• Return Period: Within 7 days from receipt date\n• Calculation: 7 days from day after receipt\n• Holidays: Includes Sat/Sun/holidays\n• Submission: Must complete return request within period\n• After Period: Cannot accept returns",
                ],
            ],
            [
                'name' => ['ko' => '반품 기간 안내 (14일)', 'en' => 'Return Period (14 Days)'],
                'content' => [
                    'ko' => "• 반품 기간: 상품 수령일로부터 14일 이내\n• 적용 상품: 의류, 신발, 잡화 등 패션 카테고리\n• 기간 산정: 수령일 다음날부터 14일\n• 반품 접수: 기간 내 반품 신청 완료 필요\n• 연장 불가: 특별한 사유 없이 기간 연장 불가",
                    'en' => "• Return Period: Within 14 days from receipt date\n• Applies to: Fashion categories - clothing, shoes, accessories\n• Calculation: 14 days from day after receipt\n• Submission: Must complete return request within period\n• No Extension: Cannot extend without special reason",
                ],
            ],
            [
                'name' => ['ko' => '반품 기간 안내 (30일)', 'en' => 'Return Period (30 Days)'],
                'content' => [
                    'ko' => "• 반품 기간: 상품 수령일로부터 30일 이내\n• 적용 상품: 프리미엄 멤버십 회원 전용\n• 기간 산정: 수령일 다음날부터 30일\n• 반품 조건: 일반 반품 조건과 동일\n• 멤버십 혜택: 무료 반품 배송비 포함",
                    'en' => "• Return Period: Within 30 days from receipt date\n• Applies to: Premium membership members only\n• Calculation: 30 days from day after receipt\n• Conditions: Same as standard return conditions\n• Membership Benefit: Includes free return shipping",
                ],
            ],
            [
                'name' => ['ko' => '해외 상품 반품 정책', 'en' => 'International Product Returns'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 14일 이내\n• 반품 불가: 관세/부가세 납부 완료 상품\n• 배송비: 국제 반품 배송비 고객 부담\n• 환불: 관세/부가세 환급 별도 진행\n• 주의: 국가별 반품 정책 상이",
                    'en' => "• Return Period: Within 14 days of receipt\n• Non-returnable: After customs/VAT paid\n• Shipping: International return shipping at customer's expense\n• Refund: Customs/VAT refund processed separately\n• Note: Return policies vary by country",
                ],
            ],
            [
                'name' => ['ko' => '아울렛 상품 반품', 'en' => 'Outlet Product Returns'],
                'content' => [
                    'ko' => "• 반품 기간: 수령 후 7일 이내\n• 반품 조건: 미사용, 택/라벨 부착 상태\n• 할인 상품: 반품 가능 (일부 최종 할인 상품 제외)\n• B급 상품: 명시된 하자 외 사유로 반품 불가\n• 반품 배송비: 고객 변심 시 유료",
                    'en' => "• Return Period: Within 7 days of receipt\n• Condition: Unused, tags/labels attached\n• Discounted: Returnable (except some final sale items)\n• B-grade: No returns except for issues beyond stated defects\n• Shipping: Charged for change of mind",
                ],
            ],
        ];
    }

    /**
     * 교환 정책 (15개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}}>
     */
    private function getExchangePolicies(): array
    {
        return [
            [
                'name' => ['ko' => '일반 교환 안내', 'en' => 'Exchange Policy'],
                'content' => [
                    'ko' => "• 교환 기간: 상품 수령 후 7일 이내\n• 교환 조건: 상품 및 포장이 훼손되지 않은 경우\n• 교환 방법: 마이페이지 > 교환 신청 > 택배 수거\n• 교환 배송비: 고객 변심 시 6,000원, 상품 하자 시 무료\n• 재배송: 교환 상품 입고 확인 후 1~2일 내 발송",
                    'en' => "• Exchange Period: Within 7 days of receipt\n• Condition: Product and packaging undamaged\n• How to Exchange: My Page > Exchange Request > Courier pickup\n• Shipping Cost: 6,000 KRW for change of mind, free for defects\n• Re-delivery: Ships within 1-2 days after exchange item received",
                ],
            ],
            [
                'name' => ['ko' => '사이즈 교환 안내', 'en' => 'Size Exchange'],
                'content' => [
                    'ko' => "• 교환 가능: 동일 상품의 다른 사이즈로 1회 무료 교환\n• 교환 조건: 미착용, 택 부착, 세탁하지 않은 상태\n• 재고 없음: 원하는 사이즈 품절 시 환불 처리\n• 교환 기간: 수령 후 14일 이내\n• 신청 방법: 마이페이지에서 희망 사이즈 선택 후 신청",
                    'en' => "• Available: One free exchange for different size of same item\n• Condition: Unworn, tags attached, unwashed\n• Out of Stock: Refund if desired size unavailable\n• Period: Within 14 days of receipt\n• How to Apply: Select desired size in My Page and submit request",
                ],
            ],
            [
                'name' => ['ko' => '색상 교환 안내', 'en' => 'Color Exchange'],
                'content' => [
                    'ko' => "• 교환 가능: 동일 상품의 다른 색상으로 교환\n• 교환 조건: 미착용, 택 부착, 세탁하지 않은 상태\n• 재고 없음: 원하는 색상 품절 시 환불 또는 대기\n• 교환 배송비: 고객 변심 시 유료 (왕복 5,000원)\n• 제한: 한정판/특별 색상은 교환 불가할 수 있음",
                    'en' => "• Available: Exchange for different color of same item\n• Condition: Unworn, tags attached, unwashed\n• Out of Stock: Refund or wait if desired color unavailable\n• Shipping: Charged for change of mind (5,000 KRW round-trip)\n• Limit: Limited edition/special colors may not be exchangeable",
                ],
            ],
            [
                'name' => ['ko' => '불량 교환 안내', 'en' => 'Defective Item Exchange'],
                'content' => [
                    'ko' => "• 교환 기간: 수령 후 30일 이내\n• 접수 방법: 불량 부분 사진 촬영 후 고객센터 문의\n• 배송비: 전액 무료 (왕복 배송비 부담 없음)\n• 교환 방법: 동일 상품 교환 또는 환불 선택 가능\n• 재고 없음: 품절 시 전액 환불 처리",
                    'en' => "• Period: Within 30 days of receipt\n• How to Submit: Take photos of defect and contact customer service\n• Shipping: Completely free (no round-trip cost)\n• Options: Exchange for same item or choose refund\n• Out of Stock: Full refund if sold out",
                ],
            ],
            [
                'name' => ['ko' => '무상 교환 조건', 'en' => 'Free Exchange Conditions'],
                'content' => [
                    'ko' => "• 제조 불량: 봉제 불량, 색상 차이, 오염 등\n• 오배송: 주문과 다른 상품/사이즈/색상 배송 시\n• 파손: 배송 중 파손된 경우 (사진 증빙 필요)\n• 누락: 구성품 누락 시\n• 유통기한: 유통기한 임박/경과 상품",
                    'en' => "• Manufacturing Defect: Sewing issues, color difference, stains\n• Wrong Delivery: Different item/size/color than ordered\n• Damage: Damaged during shipping (photo evidence required)\n• Missing Parts: Components missing\n• Expiration: Near or past expiration date",
                ],
            ],
            [
                'name' => ['ko' => '유상 교환 조건', 'en' => 'Paid Exchange Conditions'],
                'content' => [
                    'ko' => "• 단순 변심: 마음에 들지 않아 교환 시\n• 사이즈 변경: 다른 사이즈로 교환 시 (1회 초과)\n• 색상 변경: 다른 색상으로 교환 시\n• 배송비: 왕복 배송비 고객 부담\n• 차액: 상품 가격 차이 발생 시 정산",
                    'en' => "• Change of Mind: Not satisfied with product\n• Size Change: Exchange for different size (after 1st free)\n• Color Change: Exchange for different color\n• Shipping: Customer pays round-trip shipping\n• Price Difference: Settled if product prices differ",
                ],
            ],
            [
                'name' => ['ko' => '교환 불가 안내', 'en' => 'Non-Exchangeable Items'],
                'content' => [
                    'ko' => "• 사용/착용: 사용 또는 착용 흔적이 있는 경우\n• 세탁/수선: 세탁 또는 수선한 경우\n• 택/라벨 제거: 상품 택이나 라벨을 제거한 경우\n• 향수 등 냄새: 향수, 화장품 등 냄새가 배인 경우\n• 파손/훼손: 고객 부주의로 파손/훼손된 경우",
                    'en' => "• Used/Worn: Signs of use or wear\n• Washed/Altered: Has been washed or altered\n• Tags Removed: Product tags or labels removed\n• Odors: Perfume, cosmetics, or other scents absorbed\n• Damage: Damaged due to customer negligence",
                ],
            ],
            [
                'name' => ['ko' => '신선식품 교환 정책', 'en' => 'Fresh Food Exchange'],
                'content' => [
                    'ko' => "• 교환 가능: 상품 하자, 변질, 파손 시\n• 접수 시한: 수령 당일 사진 촬영 후 고객센터 접수\n• 교환 방법: 동일 상품으로 재배송\n• 재고 없음: 품절 시 전액 환불\n• 배송비: 하자 시 무료, 단순 변심 불가",
                    'en' => "• Exchangeable: Product defects, spoilage, damage\n• Deadline: Photo and customer service contact on delivery day\n• Method: Re-delivery of same product\n• Out of Stock: Full refund if sold out\n• Shipping: Free for defects, no exchange for change of mind",
                ],
            ],
            [
                'name' => ['ko' => '가전제품 교환 정책', 'en' => 'Electronics Exchange'],
                'content' => [
                    'ko' => "• 초기 불량: 수령 후 14일 이내 교환/환불\n• 교환 조건: 모든 구성품 및 포장재 보관 필수\n• 설치 상품: 설치 기사 방문 시 즉시 확인 필요\n• 시리얼: 정품 시리얼 번호 일치 확인\n• A/S: 14일 경과 시 A/S 접수 안내",
                    'en' => "• Initial Defect: Exchange/refund within 14 days of receipt\n• Condition: Must keep all components and packaging\n• Installed Products: Must verify immediately with installer\n• Serial: Authentic serial number verification\n• Service: After 14 days, directed to A/S",
                ],
            ],
            [
                'name' => ['ko' => '의류/신발 교환', 'en' => 'Clothing/Shoes Exchange'],
                'content' => [
                    'ko' => "• 사이즈 교환: 1회 무료 (동일 상품 한정)\n• 교환 조건: 택 부착, 미착용, 미세탁\n• 신발: 실내에서 시착만 가능, 외출 착용 시 교환 불가\n• 교환 기간: 수령 후 14일 이내\n• 박스 보관: 신발 박스 훼손 시 교환 제한",
                    'en' => "• Size Exchange: One free (same item only)\n• Condition: Tags attached, unworn, unwashed\n• Shoes: Indoor try-on only, no exchange after outdoor wear\n• Period: Within 14 days of receipt\n• Box: Exchange limited if shoe box damaged",
                ],
            ],
            [
                'name' => ['ko' => '주얼리 교환 정책', 'en' => 'Jewelry Exchange'],
                'content' => [
                    'ko' => "• 교환 기간: 수령 후 7일 이내\n• 교환 조건: 미착용, 포장 미개봉 상태\n• 귀걸이: 위생상 착용 시 교환 불가\n• 각인 상품: 맞춤 각인 상품 교환 불가\n• 보증서: 보증서/품질보증서 동봉 필수",
                    'en' => "• Period: Within 7 days of receipt\n• Condition: Unworn, packaging unopened\n• Earrings: Cannot exchange if worn for hygiene\n• Engraved: Custom engraved items non-exchangeable\n• Certificate: Must include warranty/authenticity certificate",
                ],
            ],
            [
                'name' => ['ko' => '안경/렌즈 교환', 'en' => 'Glasses/Lens Exchange'],
                'content' => [
                    'ko' => "• 도수 렌즈: 맞춤 제작으로 교환 불가\n• 프레임: 미착용 시 7일 이내 교환 가능\n• 콘택트렌즈: 미개봉 시에만 교환 가능\n• 도수 오류: 처방전과 다를 경우 무료 교환\n• 파손: 배송 중 파손 시 무료 교환",
                    'en' => "• Prescription Lenses: Custom-made, non-exchangeable\n• Frames: Exchangeable within 7 days if unworn\n• Contact Lenses: Only exchangeable if unopened\n• Prescription Error: Free exchange if different from prescription\n• Damage: Free exchange if damaged during delivery",
                ],
            ],
            [
                'name' => ['ko' => '교환 배송비 안내', 'en' => 'Exchange Shipping Costs'],
                'content' => [
                    'ko' => "• 무료 교환: 초기 불량, 오배송, 상품 하자 시\n• 유료 교환: 단순 변심, 사이즈/색상 변경 시\n• 교환 배송비: 왕복 5,000~6,000원\n• 결제 방법: 착불 또는 환불 금액에서 차감\n• 도서산간: 추가 배송비 발생",
                    'en' => "• Free: Initial defect, wrong delivery, product issues\n• Paid: Change of mind, size/color change\n• Cost: 5,000-6,000 KRW round-trip\n• Payment: Cash on delivery or deducted from refund\n• Remote Areas: Additional shipping applies",
                ],
            ],
            [
                'name' => ['ko' => '동일 상품 교환만 가능', 'en' => 'Same Item Exchange Only'],
                'content' => [
                    'ko' => "• 교환 범위: 동일 상품의 다른 옵션으로만 교환 가능\n• 다른 상품: 다른 상품으로 교환 불가 (반품 후 재구매)\n• 사이즈/색상: 동일 상품 내 사이즈/색상 변경 가능\n• 가격 차이: 옵션별 가격 차이 발생 시 정산\n• 품절: 원하는 옵션 품절 시 환불 처리",
                    'en' => "• Scope: Only exchange for different option of same item\n• Different Items: Cannot exchange (return and repurchase)\n• Size/Color: Can change within same item\n• Price: Difference settled if option prices vary\n• Sold Out: Refund if desired option unavailable",
                ],
            ],
            [
                'name' => ['ko' => '해외 상품 교환 정책', 'en' => 'International Product Exchange'],
                'content' => [
                    'ko' => "• 교환 기간: 수령 후 7일 이내\n• 교환 불가: 관세 통관 완료 상품은 교환 제한\n• 배송비: 국제 배송비 고객 부담\n• 소요 기간: 교환 완료까지 2~4주 소요\n• 하자 시: 무료 교환 또는 환불 처리",
                    'en' => "• Period: Within 7 days of receipt\n• Non-exchangeable: Limited after customs clearance\n• Shipping: International shipping at customer's expense\n• Duration: 2-4 weeks to complete exchange\n• Defects: Free exchange or refund",
                ],
            ],
        ];
    }

    /**
     * 환불 정책 (15개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}}>
     */
    private function getRefundPolicies(): array
    {
        return [
            [
                'name' => ['ko' => '일반 환불 안내', 'en' => 'Refund Policy'],
                'content' => [
                    'ko' => "• 환불 처리: 반품 상품 입고 확인 후 2~3 영업일 내\n• 카드 결제: 카드사에 따라 3~7일 후 취소 확인\n• 현금/계좌이체: 등록된 환불 계좌로 입금\n• 부분 환불: 묶음 배송 상품 중 일부 반품 시 가능\n• 환불 불가: 사용 흔적, 훼손, 분실한 상품",
                    'en' => "• Processing: Within 2-3 business days after return item received\n• Card Payment: Cancellation confirmed in 3-7 days depending on card company\n• Cash/Bank Transfer: Deposited to registered refund account\n• Partial Refund: Available for partial returns from bundled orders\n• Non-refundable: Used, damaged, or lost items",
                ],
            ],
            [
                'name' => ['ko' => '카드 결제 환불', 'en' => 'Card Payment Refund'],
                'content' => [
                    'ko' => "• 취소 요청: 반품 완료 후 자동 취소 처리\n• 취소 시점: 카드사별 영업일 기준 3~7일 소요\n• 할부 결제: 할부 수수료 포함 전액 취소\n• 부분 취소: 할부 결제 시 잔여 금액 재청구 가능\n• 확인 방법: 카드사 앱 또는 고객센터에서 확인",
                    'en' => "• Cancellation: Automatically processed after return complete\n• Timing: 3-7 business days depending on card company\n• Installment: Full cancellation including installment fees\n• Partial: Remaining amount may be re-billed for installment payments\n• Verification: Check via card company app or customer service",
                ],
            ],
            [
                'name' => ['ko' => '현금 결제 환불', 'en' => 'Cash Payment Refund'],
                'content' => [
                    'ko' => "• 환불 방법: 등록된 환불 계좌로 입금\n• 소요 기간: 반품 확인 후 2~3 영업일 내\n• 계좌 등록: 마이페이지에서 환불 계좌 등록\n• 본인 명의: 주문자 본인 명의 계좌만 가능\n• 무통장: 가상계좌 결제도 동일하게 적용",
                    'en' => "• Method: Deposited to registered refund account\n• Duration: Within 2-3 business days after return confirmed\n• Registration: Register refund account in My Page\n• Account Name: Must be account in orderer's name\n• Virtual Account: Same process for virtual account payments",
                ],
            ],
            [
                'name' => ['ko' => '포인트 환불 안내', 'en' => 'Points Refund'],
                'content' => [
                    'ko' => "• 포인트 환불: 반품 완료 시 사용 포인트 자동 복원\n• 유효기간: 복원된 포인트는 기존 유효기간 유지\n• 적립 포인트: 취소 시 적립 포인트도 함께 차감\n• 일부 반품: 결제 비율에 따라 포인트 복원\n• 즉시 복원: 반품 확인 즉시 포인트 복원",
                    'en' => "• Points Refund: Used points automatically restored upon return\n• Validity: Restored points keep original expiration\n• Earned Points: Earned points also deducted on cancellation\n• Partial Return: Points restored based on payment ratio\n• Instant: Points restored immediately upon return confirmation",
                ],
            ],
            [
                'name' => ['ko' => '부분 환불 안내', 'en' => 'Partial Refund'],
                'content' => [
                    'ko' => "• 적용 조건: 묶음 주문 중 일부 상품만 반품 시\n• 배송비 정산: 무료 배송 기준 미달 시 배송비 차감\n• 할인 정산: 묶음 할인 적용 상품은 할인액 재계산\n• 쿠폰 처리: 사용 쿠폰은 복원 불가\n• 환불 금액: 반품 상품 금액에서 정산액 차감",
                    'en' => "• Condition: When returning only some items from bundled order\n• Shipping: Shipping fee deducted if below free shipping threshold\n• Discounts: Bundle discounts recalculated\n• Coupons: Used coupons cannot be restored\n• Amount: Deductions subtracted from return item price",
                ],
            ],
            [
                'name' => ['ko' => '환불 소요 기간', 'en' => 'Refund Processing Time'],
                'content' => [
                    'ko' => "• 카드 결제: 영업일 기준 3~7일\n• 실시간 계좌이체: 영업일 기준 2~3일\n• 가상계좌: 영업일 기준 2~3일\n• 휴대폰 결제: 익월 요금에서 차감 또는 환급\n• 포인트/적립금: 반품 확인 즉시",
                    'en' => "• Card: 3-7 business days\n• Bank Transfer: 2-3 business days\n• Virtual Account: 2-3 business days\n• Mobile Payment: Deducted from next month or refunded\n• Points: Immediately upon return confirmation",
                ],
            ],
            [
                'name' => ['ko' => '환불 계좌 등록', 'en' => 'Refund Account Registration'],
                'content' => [
                    'ko' => "• 등록 방법: 마이페이지 > 환불 계좌 관리\n• 필요 정보: 은행명, 계좌번호, 예금주\n• 본인 확인: 주문자와 예금주 동일해야 함\n• 변경: 환불 진행 전까지 변경 가능\n• 해외 계좌: 국내 계좌만 등록 가능",
                    'en' => "• How to Register: My Page > Refund Account Management\n• Information: Bank name, account number, account holder\n• Verification: Orderer and account holder must match\n• Changes: Can modify before refund processing\n• International: Only domestic accounts accepted",
                ],
            ],
            [
                'name' => ['ko' => '해외 결제 환불', 'en' => 'International Payment Refund'],
                'content' => [
                    'ko' => "• 환불 통화: 결제 시 통화로 환불\n• 환율 차이: 결제일과 환불일 환율 차이 발생 가능\n• 소요 기간: 영업일 기준 7~14일\n• 카드 수수료: 해외 결제 수수료 환불 불가\n• PayPal: PayPal 정책에 따라 처리",
                    'en' => "• Currency: Refunded in payment currency\n• Exchange Rate: May differ between payment and refund dates\n• Duration: 7-14 business days\n• Card Fees: International transaction fees not refundable\n• PayPal: Processed according to PayPal policies",
                ],
            ],
            [
                'name' => ['ko' => '할부 결제 환불', 'en' => 'Installment Payment Refund'],
                'content' => [
                    'ko' => "• 전액 취소: 할부 수수료 포함 전액 취소\n• 부분 취소: 잔여 할부금 재청구 또는 일시불 전환\n• 무이자 할부: 무이자 혜택 소멸 가능\n• 카드사별 상이: 카드사 정책에 따라 처리\n• 확인 필요: 카드사 고객센터에서 상세 확인",
                    'en' => "• Full Cancel: Full cancellation including installment fees\n• Partial Cancel: Remaining installments re-billed or converted to lump sum\n• Interest-Free: Interest-free benefit may be lost\n• Varies: Processed according to card company policies\n• Verification: Check details with card company customer service",
                ],
            ],
            [
                'name' => ['ko' => '복합 결제 환불', 'en' => 'Mixed Payment Refund'],
                'content' => [
                    'ko' => "• 적립금 + 카드: 적립금 먼저 복원, 카드 취소\n• 포인트 + 카드: 포인트 먼저 복원, 카드 취소\n• 상품권 + 카드: 상품권 재발급 또는 계좌 환불\n• 환불 순서: 적립금/포인트 > 상품권 > 카드\n• 부분 환불: 결제 비율에 따라 각각 환불",
                    'en' => "• Store Credit + Card: Credit restored first, then card canceled\n• Points + Card: Points restored first, then card canceled\n• Gift Card + Card: Gift card reissued or account refund\n• Order: Credits/Points > Gift Cards > Card\n• Partial: Refunded by payment ratio",
                ],
            ],
            [
                'name' => ['ko' => '쿠폰 사용 환불', 'en' => 'Coupon Payment Refund'],
                'content' => [
                    'ko' => "• 전액 취소: 사용 쿠폰 복원 (유효기간 내)\n• 부분 취소: 쿠폰 할인액 재계산 후 환불\n• 유효기간 만료: 기간 만료 쿠폰은 복원 불가\n• 1회성 쿠폰: 사용 후 복원 불가한 쿠폰 존재\n• 프로모션 쿠폰: 이벤트 종료 시 복원 불가",
                    'en' => "• Full Cancel: Coupon restored (if still valid)\n• Partial Cancel: Coupon discount recalculated then refunded\n• Expired: Expired coupons cannot be restored\n• One-Time: Some coupons non-restorable after use\n• Promotional: Cannot restore after event ends",
                ],
            ],
            [
                'name' => ['ko' => '적립금 환불 정책', 'en' => 'Store Credit Refund'],
                'content' => [
                    'ko' => "• 적립금 사용: 반품 시 사용 적립금 자동 복원\n• 구매 적립: 취소 시 적립 예정 적립금 차감\n• 복원 시점: 반품 완료 확인 후 즉시\n• 유효기간: 기존 유효기간 유지\n• 현금 전환: 적립금의 현금 전환 불가",
                    'en' => "• Credits Used: Automatically restored upon return\n• Purchase Credits: Pending credits deducted on cancellation\n• Restoration: Immediately upon return confirmation\n• Validity: Original expiration maintained\n• Cash Conversion: Credits cannot be converted to cash",
                ],
            ],
            [
                'name' => ['ko' => '취소 수수료 안내', 'en' => 'Cancellation Fees'],
                'content' => [
                    'ko' => "• 주문 취소: 결제 완료 후 출고 전 무료 취소\n• 출고 후 취소: 반품 배송비 발생 가능\n• 예약 상품: 제작 시작 후 취소 시 수수료 발생\n• 맞춤 상품: 취소 불가 또는 수수료 발생\n• 프로모션: 한정 프로모션 취소 시 혜택 소멸",
                    'en' => "• Order Cancel: Free before shipping after payment\n• After Shipping: Return shipping fees may apply\n• Pre-order: Fees if cancelled after production starts\n• Custom: Non-cancellable or fees apply\n• Promotions: Benefits lost when limited promotions cancelled",
                ],
            ],
            [
                'name' => ['ko' => '예약 상품 환불', 'en' => 'Pre-Order Refund'],
                'content' => [
                    'ko' => "• 취소 가능: 예약 마감일 전까지 전액 환불\n• 마감 후 취소: 취소 수수료 발생 가능\n• 제작 시작 후: 원칙적으로 취소 불가\n• 배송 지연 시: 전액 환불 또는 계속 대기 선택\n• 환불 처리: 일반 환불과 동일",
                    'en' => "• Cancellable: Full refund before reservation deadline\n• After Deadline: Cancellation fees may apply\n• After Production: Generally non-cancellable\n• Delivery Delay: Choose full refund or continue waiting\n• Processing: Same as standard refunds",
                ],
            ],
            [
                'name' => ['ko' => '환불 불가 조건', 'en' => 'Non-Refundable Conditions'],
                'content' => [
                    'ko' => "• 사용/착용: 상품 사용 또는 착용 흔적이 있는 경우\n• 훼손/분실: 고객 부주의로 훼손 또는 분실\n• 기간 초과: 반품 가능 기간 초과\n• 맞춤 제작: 주문 제작 완료된 상품\n• 디지털 상품: 다운로드/등록 완료된 상품",
                    'en' => "• Used/Worn: Signs of use or wear\n• Damage/Loss: Damaged or lost due to customer negligence\n• Period Exceeded: Return period exceeded\n• Custom Made: Completed made-to-order items\n• Digital: Downloaded/registered products",
                ],
            ],
        ];
    }

    /**
     * A/S 및 보증 정책 (15개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}}>
     */
    private function getWarrantyPolicies(): array
    {
        return [
            [
                'name' => ['ko' => '제조사 A/S 안내', 'en' => 'Manufacturer Warranty Service'],
                'content' => [
                    'ko' => "• A/S 접수: 제조사 고객센터 또는 공식 서비스센터\n• 보증 기간: 제품별 상이 (보증서 참조)\n• 무상 수리: 보증 기간 내 제조상 결함\n• 유상 수리: 사용자 과실, 보증 기간 만료 후\n• 필요 서류: 구매 영수증, 보증서",
                    'en' => "• Service Request: Manufacturer customer center or official service center\n• Warranty Period: Varies by product (see warranty card)\n• Free Repair: Manufacturing defects within warranty period\n• Paid Repair: User negligence, after warranty expiration\n• Required Documents: Purchase receipt, warranty card",
                ],
            ],
            [
                'name' => ['ko' => '자체 A/S 안내', 'en' => 'In-House Service'],
                'content' => [
                    'ko' => "• A/S 접수: 고객센터 1588-0000 또는 마이페이지\n• 서비스센터: 전국 직영 서비스센터 운영\n• 방문 수리: 제품에 따라 방문 서비스 가능\n• 택배 수리: 고객 발송 → 수리 → 고객 반송\n• 수리 기간: 접수 후 7~14일 소요",
                    'en' => "• Request: Customer center 1588-0000 or My Page\n• Centers: Nationwide authorized service centers\n• On-Site: Home service available for some products\n• Courier: Customer sends → Repair → Return to customer\n• Duration: 7-14 days after receipt",
                ],
            ],
            [
                'name' => ['ko' => '무상 A/S 기간', 'en' => 'Free Service Period'],
                'content' => [
                    'ko' => "• 기본 기간: 구매일로부터 1년\n• 적용 범위: 정상적인 사용 중 발생한 고장\n• 연장 등록: 제품 등록 시 3개월 연장\n• 제외 항목: 소모품, 외관 손상, 사용자 과실\n• 증빙 필요: 구매 영수증 또는 주문 내역",
                    'en' => "• Standard: 1 year from purchase date\n• Coverage: Failures during normal use\n• Extension: 3 months extended upon product registration\n• Exclusions: Consumables, cosmetic damage, user negligence\n• Proof Required: Purchase receipt or order history",
                ],
            ],
            [
                'name' => ['ko' => '유상 A/S 안내', 'en' => 'Paid Service'],
                'content' => [
                    'ko' => "• 적용 조건: 보증 기간 만료, 사용자 과실\n• 수리 비용: 부품비 + 공임비\n• 견적 안내: 수리 전 예상 비용 안내\n• 결제 방법: 카드, 현금, 계좌이체\n• 수리 취소: 견적 확인 후 수리 취소 가능",
                    'en' => "• Conditions: Warranty expired, user negligence\n• Cost: Parts + labor\n• Quote: Estimated cost provided before repair\n• Payment: Card, cash, bank transfer\n• Cancel: Can cancel after reviewing quote",
                ],
            ],
            [
                'name' => ['ko' => 'A/S 접수 방법', 'en' => 'Service Request Methods'],
                'content' => [
                    'ko' => "• 온라인: 마이페이지 > A/S 신청\n• 전화: 고객센터 1588-0000 (평일 09~18시)\n• 방문: 전국 서비스센터 직접 방문\n• 카카오톡: 플러스친구 상담 후 접수\n• 준비물: 제품, 보증서, 구매 증빙",
                    'en' => "• Online: My Page > Service Request\n• Phone: 1588-0000 (Weekdays 9AM-6PM)\n• Visit: Direct visit to nationwide service centers\n• KakaoTalk: Consult via Plus Friend then submit\n• Bring: Product, warranty card, proof of purchase",
                ],
            ],
            [
                'name' => ['ko' => '출장 A/S 서비스', 'en' => 'On-Site Service'],
                'content' => [
                    'ko' => "• 적용 제품: 대형 가전, 가구 등\n• 출장비: 무상 기간 내 무료, 이후 유료\n• 예약 방법: 고객센터 통해 일정 예약\n• 방문 시간: 평일 09~18시 (주말/공휴일 제외)\n• 사전 연락: 방문 전 기사 전화 연락",
                    'en' => "• Products: Large appliances, furniture, etc.\n• Fee: Free during warranty, charged after\n• Booking: Schedule through customer center\n• Hours: Weekdays 9AM-6PM (no weekends/holidays)\n• Notice: Technician calls before visit",
                ],
            ],
            [
                'name' => ['ko' => '택배 A/S 서비스', 'en' => 'Courier Service'],
                'content' => [
                    'ko' => "• 이용 방법: 접수 후 택배로 제품 발송\n• 배송비: 무상 수리 시 왕복 무료\n• 포장: 안전 포장 후 발송 권장\n• 수리 후: 수리 완료 후 고객에게 반송\n• 조회: 마이페이지에서 진행 상황 확인",
                    'en' => "• Method: Ship product via courier after request\n• Shipping: Free round-trip for warranty repairs\n• Packaging: Safe packaging recommended\n• After Repair: Returned to customer after repair\n• Tracking: Check progress in My Page",
                ],
            ],
            [
                'name' => ['ko' => '소모품 교체 안내', 'en' => 'Consumables Replacement'],
                'content' => [
                    'ko' => "• 소모품 예시: 배터리, 필터, 브러시, 램프 등\n• 교체 주기: 제품 사용 설명서 참조\n• 구매 방법: 공식몰 또는 서비스센터\n• 보증 제외: 소모품은 품질보증 대상 아님\n• 자가 교체: 사용자가 직접 교체 가능한 부품",
                    'en' => "• Examples: Batteries, filters, brushes, lamps, etc.\n• Cycle: Refer to product manual\n• Purchase: Official store or service center\n• Warranty: Consumables not covered\n• Self-Replace: Parts user can replace directly",
                ],
            ],
            [
                'name' => ['ko' => '보증서 안내', 'en' => 'Warranty Card Information'],
                'content' => [
                    'ko' => "• 보관: 구매 시 동봉된 보증서 반드시 보관\n• 필수 정보: 구매일, 판매처, 제품 정보\n• 분실 시: 구매 영수증으로 대체 가능\n• 등록: 온라인 제품 등록으로 보증 관리\n• 양도: 중고 거래 시 보증서 함께 양도",
                    'en' => "• Keep: Must keep warranty card included with purchase\n• Required: Purchase date, seller, product info\n• If Lost: Can substitute with purchase receipt\n• Register: Manage warranty via online product registration\n• Transfer: Transfer warranty card with secondhand sale",
                ],
            ],
            [
                'name' => ['ko' => '1년 품질보증', 'en' => '1-Year Quality Warranty'],
                'content' => [
                    'ko' => "• 보증 기간: 구매일로부터 1년\n• 보증 범위: 제조상 결함, 정상 사용 중 고장\n• 보증 제외: 소모품, 외관 손상, 사용자 과실\n• 보증 방법: 구매 영수증 지참 후 고객센터 접수\n• 연장 보증: 추가 비용으로 보증 기간 연장 가능",
                    'en' => "• Period: 1 year from purchase date\n• Coverage: Manufacturing defects, failure during normal use\n• Exclusions: Consumables, cosmetic damage, user negligence\n• How to Claim: Contact customer center with purchase receipt\n• Extended Warranty: Available for additional cost",
                ],
            ],
            [
                'name' => ['ko' => '2년 품질보증', 'en' => '2-Year Quality Warranty'],
                'content' => [
                    'ko' => "• 보증 기간: 구매일로부터 2년\n• 적용 제품: 프리미엄 가전, 고가 전자제품\n• 보증 범위: 제조상 결함, 부품 고장\n• 보증 방법: 정품 등록 필수\n• 혜택: 2년간 무상 수리 및 부품 교체",
                    'en' => "• Period: 2 years from purchase date\n• Products: Premium appliances, high-end electronics\n• Coverage: Manufacturing defects, part failures\n• Claim Method: Product registration required\n• Benefits: Free repair and parts for 2 years",
                ],
            ],
            [
                'name' => ['ko' => '평생 품질보증', 'en' => 'Lifetime Warranty'],
                'content' => [
                    'ko' => "• 적용 제품: 프리미엄 라인 일부 제품\n• 보증 범위: 제조상 결함에 한해 평생 보증\n• 보증 제외: 소모품, 사용자 과실, 외관\n• 수리비: 부품비는 무료, 공임비 별도\n• 조건: 정품 등록 및 정상 사용",
                    'en' => "• Products: Select premium line products\n• Coverage: Lifetime warranty for manufacturing defects\n• Exclusions: Consumables, user negligence, cosmetic\n• Cost: Parts free, labor charged separately\n• Conditions: Product registration and normal use",
                ],
            ],
            [
                'name' => ['ko' => '파손 보험 안내', 'en' => 'Damage Insurance'],
                'content' => [
                    'ko' => "• 가입 방법: 구매 시 선택 가입\n• 보장 범위: 낙하, 파손, 침수 등 사고\n• 보장 기간: 구매일로부터 1~2년\n• 자기부담금: 수리비의 10~20%\n• 청구 방법: 고객센터 통해 보험 청구",
                    'en' => "• Enrollment: Optional at purchase\n• Coverage: Drops, breaks, water damage, accidents\n• Period: 1-2 years from purchase\n• Deductible: 10-20% of repair cost\n• Claims: Submit through customer center",
                ],
            ],
            [
                'name' => ['ko' => '연장 보증 서비스', 'en' => 'Extended Warranty'],
                'content' => [
                    'ko' => "• 가입 시점: 제품 구매 시 또는 기본 보증 내\n• 연장 기간: 1년, 2년 옵션 선택\n• 비용: 제품 가격의 5~15%\n• 보장 범위: 기본 보증과 동일\n• 혜택: 보증 기간 연장 + 우선 서비스",
                    'en' => "• Enrollment: At purchase or within standard warranty\n• Extension: 1 or 2 year options\n• Cost: 5-15% of product price\n• Coverage: Same as standard warranty\n• Benefits: Extended period + priority service",
                ],
            ],
            [
                'name' => ['ko' => 'A/S 센터 안내', 'en' => 'Service Center Information'],
                'content' => [
                    'ko' => "• 센터 위치: 전국 주요 도시 운영\n• 운영 시간: 평일 09~18시 (점심 12~13시)\n• 예약: 사전 예약 후 방문 권장\n• 주차: 센터별 주차 가능 여부 상이\n• 조회: 홈페이지에서 가까운 센터 검색",
                    'en' => "• Locations: Major cities nationwide\n• Hours: Weekdays 9AM-6PM (lunch 12-1PM)\n• Reservations: Advance booking recommended\n• Parking: Varies by center\n• Search: Find nearest center on website",
                ],
            ],
        ];
    }

    /**
     * 상품별 특수 안내 (20개)
     *
     * @return array<int, array{name: array{ko: string, en: string}, content: array{ko: string, en: string}}>
     */
    private function getProductSpecificNotices(): array
    {
        return [
            [
                'name' => ['ko' => '식품 유통기한 안내', 'en' => 'Food Expiration Notice'],
                'content' => [
                    'ko' => "• 유통기한: 상품 포장에 표기된 날짜 확인\n• 보관 방법: 직사광선을 피해 서늘한 곳에 보관\n• 냉장 식품: 0~10°C 냉장 보관 필수\n• 냉동 식품: -18°C 이하 냉동 보관 필수\n• 개봉 후: 가급적 빠른 시일 내 섭취 권장",
                    'en' => "• Expiration: Check date printed on product packaging\n• Storage: Keep in cool place away from direct sunlight\n• Refrigerated: Must be stored at 0-10°C\n• Frozen: Must be stored at -18°C or below\n• After Opening: Consume as soon as possible",
                ],
            ],
            [
                'name' => ['ko' => '건강기능식품 안내', 'en' => 'Health Supplement Notice'],
                'content' => [
                    'ko' => "• 섭취 방법: 제품에 표기된 섭취량 준수\n• 주의사항: 질병 치료 목적이 아님\n• 상담 권장: 임산부, 어린이, 질환자는 전문가 상담\n• 보관: 직사광선 피하고 서늘한 곳에 보관\n• 부작용: 이상 증상 발생 시 섭취 중단",
                    'en' => "• Intake: Follow dosage on product label\n• Caution: Not intended to treat diseases\n• Consult: Pregnant, children, patients should consult experts\n• Storage: Keep away from sunlight in cool place\n• Side Effects: Stop if unusual symptoms occur",
                ],
            ],
            [
                'name' => ['ko' => '주류 판매 안내', 'en' => 'Alcohol Sales Notice'],
                'content' => [
                    'ko' => "• 구매 자격: 만 19세 이상 성인만 구매 가능\n• 본인 인증: 주문 시 성인 인증 필수\n• 수령 시: 신분증 확인 후 전달\n• 음주 경고: 지나친 음주는 건강에 해롭습니다\n• 법적 고지: 청소년 보호법에 따라 미성년자 판매 금지",
                    'en' => "• Eligibility: Must be 19 years or older\n• Verification: Adult verification required at checkout\n• Upon Receipt: ID check before delivery\n• Warning: Excessive drinking is harmful to health\n• Legal Notice: Sale to minors prohibited by Youth Protection Act",
                ],
            ],
            [
                'name' => ['ko' => '담배 판매 안내', 'en' => 'Tobacco Sales Notice'],
                'content' => [
                    'ko' => "• 구매 자격: 만 19세 이상 성인만 구매 가능\n• 경고: 흡연은 폐암 등 각종 질병의 원인\n• 수령 시: 신분증 확인 필수\n• 반품 불가: 담배류 반품/교환 불가\n• 법적 고지: 청소년 판매 금지",
                    'en' => "• Eligibility: Must be 19 years or older\n• Warning: Smoking causes lung cancer and other diseases\n• Upon Receipt: ID verification required\n• No Returns: Tobacco products non-returnable/exchangeable\n• Legal: Sale to minors prohibited",
                ],
            ],
            [
                'name' => ['ko' => '의약품 판매 안내', 'en' => 'Medication Sales Notice'],
                'content' => [
                    'ko' => "• 판매 범위: 일반의약품(안전상비의약품)에 한함\n• 구매 수량: 1회 구매 수량 제한 있음\n• 복약 안내: 제품 설명서 반드시 확인\n• 부작용: 이상 증상 시 즉시 의사/약사 상담\n• 반품 불가: 의약품 특성상 반품 불가",
                    'en' => "• Scope: General medicines (safety medicines) only\n• Quantity: Purchase quantity limits apply\n• Directions: Must check product instructions\n• Side Effects: Consult doctor/pharmacist if issues occur\n• No Returns: Non-returnable due to medication nature",
                ],
            ],
            [
                'name' => ['ko' => '의료기기 안내', 'en' => 'Medical Device Notice'],
                'content' => [
                    'ko' => "• 사용 전: 사용 설명서 반드시 숙지\n• 용도: 의료 목적으로만 사용\n• 주의: 전문가 지도 하에 사용 권장\n• A/S: 제조사 또는 판매처 문의\n• 인증: 식약처 인증 제품만 취급",
                    'en' => "• Before Use: Must read instruction manual\n• Purpose: Use only for medical purposes\n• Caution: Professional guidance recommended\n• Service: Contact manufacturer or seller\n• Certification: Only MFDS certified products",
                ],
            ],
            [
                'name' => ['ko' => '화장품 성분 안내', 'en' => 'Cosmetics Ingredients Notice'],
                'content' => [
                    'ko' => "• 전성분: 제품 패키지 및 상세페이지 확인\n• 알레르기: 특정 성분 알레르기 시 사용 전 확인\n• 테스트: 사용 전 팔 안쪽 등에 테스트 권장\n• 유통기한: 개봉 후 6~12개월 이내 사용\n• 보관: 직사광선 피하고 서늘한 곳에 보관",
                    'en' => "• Ingredients: Check product package and details page\n• Allergies: Check before use if allergic to specific ingredients\n• Patch Test: Test on inner arm before use recommended\n• Expiry: Use within 6-12 months after opening\n• Storage: Keep away from sunlight in cool place",
                ],
            ],
            [
                'name' => ['ko' => '화장품 사용 주의', 'en' => 'Cosmetics Usage Precautions'],
                'content' => [
                    'ko' => "• 상처 부위: 상처가 있는 부위에 사용 금지\n• 이상 반응: 붉은기, 가려움 발생 시 사용 중단\n• 눈 접촉: 눈에 들어갔을 시 즉시 세척\n• 어린이: 어린이 손에 닿지 않는 곳에 보관\n• 용도 외 사용: 지정된 부위 외 사용 금지",
                    'en' => "• Wounds: Do not use on wounded areas\n• Reactions: Stop if redness or itching occurs\n• Eye Contact: Rinse immediately if contact with eyes\n• Children: Keep out of reach of children\n• Off-Label: Do not use on non-designated areas",
                ],
            ],
            [
                'name' => ['ko' => '전자제품 안전 주의', 'en' => 'Electronics Safety Notice'],
                'content' => [
                    'ko' => "• 전원: 정격 전압에서만 사용, 멀티탭 과부하 주의\n• 습기: 물기 있는 손으로 조작 금지, 욕실 사용 주의\n• 분해: 임의 분해 시 A/S 불가, 감전 위험\n• 청소: 전원 분리 후 마른 천으로 닦기\n• 폐기: 전자제품 전용 수거함에 분리 배출",
                    'en' => "• Power: Use only at rated voltage, avoid overloading power strips\n• Moisture: Do not operate with wet hands, caution in bathrooms\n• Disassembly: Warranty void if opened, risk of electric shock\n• Cleaning: Disconnect power, wipe with dry cloth\n• Disposal: Dispose at designated electronics recycling points",
                ],
            ],
            [
                'name' => ['ko' => '리튬배터리 주의', 'en' => 'Lithium Battery Notice'],
                'content' => [
                    'ko' => "• 충전: 정품 충전기만 사용\n• 고온 주의: 직사광선, 고온 환경에 노출 금지\n• 충격 금지: 떨어뜨리거나 충격 주지 않기\n• 폐기: 분리 수거, 일반 쓰레기 투기 금지\n• 부풀음: 배터리 부풀음 발견 시 즉시 사용 중단",
                    'en' => "• Charging: Use only genuine chargers\n• Heat: Avoid direct sunlight and high temperatures\n• Impact: Do not drop or subject to shock\n• Disposal: Separate disposal, do not throw in regular trash\n• Swelling: Stop use immediately if battery swells",
                ],
            ],
            [
                'name' => ['ko' => '어린이 안전 경고', 'en' => 'Child Safety Warning'],
                'content' => [
                    'ko' => "• 질식 위험: 작은 부품 삼킴 주의\n• 연령 제한: 권장 연령 확인 후 사용\n• 보호자 동반: 어린이 사용 시 보호자 감독 필수\n• 날카로운 부분: 모서리, 뾰족한 부분 주의\n• 사용 설명: 사용 전 안전 수칙 확인",
                    'en' => "• Choking Hazard: Beware of small parts\n• Age Limit: Check recommended age before use\n• Supervision: Adult supervision required for children\n• Sharp Parts: Beware of edges and pointed parts\n• Instructions: Check safety rules before use",
                ],
            ],
            [
                'name' => ['ko' => '가구 조립 안내', 'en' => 'Furniture Assembly Guide'],
                'content' => [
                    'ko' => "• 설명서: 조립 전 설명서 숙지\n• 부품 확인: 조립 전 모든 부품 확인\n• 2인 이상: 대형 가구 조립 시 2인 이상 권장\n• 바닥 보호: 조립 시 바닥 보호 매트 사용\n• 전문 조립: 조립 서비스 별도 신청 가능",
                    'en' => "• Manual: Read assembly instructions first\n• Parts Check: Verify all parts before assembly\n• Two People: Large furniture requires 2+ people\n• Floor Protection: Use floor mats during assembly\n• Professional: Assembly service available separately",
                ],
            ],
            [
                'name' => ['ko' => '침구류 관리 안내', 'en' => 'Bedding Care Guide'],
                'content' => [
                    'ko' => "• 세탁: 세탁 라벨 확인 후 세탁\n• 건조: 그늘에서 건조, 직사광선 피하기\n• 보관: 통풍이 잘 되는 곳에 보관\n• 이불 털기: 정기적으로 햇볕에 널어 소독\n• 교체 주기: 베개 1~2년, 이불 5~7년 권장",
                    'en' => "• Washing: Check care label before washing\n• Drying: Dry in shade, avoid direct sunlight\n• Storage: Store in well-ventilated place\n• Airing: Regularly air out in sunlight\n• Replacement: Pillows 1-2 years, comforters 5-7 years",
                ],
            ],
            [
                'name' => ['ko' => '의류 세탁 안내', 'en' => 'Clothing Care Guide'],
                'content' => [
                    'ko' => "• 세탁 표시: 제품 라벨의 세탁 기호 확인\n• 색상 분리: 밝은색과 어두운색 분리 세탁\n• 울/캐시미어: 드라이클리닝 또는 손세탁\n• 건조: 형태 변형 방지를 위해 뉘어서 건조\n• 다림질: 소재별 적정 온도로 다림질",
                    'en' => "• Care Labels: Check washing symbols on product label\n• Color Sort: Wash lights and darks separately\n• Wool/Cashmere: Dry clean or hand wash\n• Drying: Lay flat to prevent shape distortion\n• Ironing: Iron at appropriate temperature for material",
                ],
            ],
            [
                'name' => ['ko' => '신발 관리 안내', 'en' => 'Shoe Care Guide'],
                'content' => [
                    'ko' => "• 보관: 통풍이 잘 되는 곳에 보관\n• 청소: 소재별 전용 세척제 사용\n• 건조: 직사광선 피해 그늘에서 건조\n• 형태 유지: 슈트리 또는 신문지로 형태 유지\n• 방수 처리: 가죽/스웨이드 방수 스프레이 권장",
                    'en' => "• Storage: Store in well-ventilated place\n• Cleaning: Use cleaner appropriate for material\n• Drying: Dry in shade away from direct sunlight\n• Shape: Use shoe trees or newspaper to maintain shape\n• Waterproof: Waterproof spray recommended for leather/suede",
                ],
            ],
            [
                'name' => ['ko' => '보석/귀금속 안내', 'en' => 'Jewelry Care Notice'],
                'content' => [
                    'ko' => "• 보관: 개별 보관, 다른 보석과 접촉 피하기\n• 착용 순서: 화장/향수 후 마지막에 착용\n• 세척: 부드러운 천으로 닦기, 초음파 세척 주의\n• 수영/운동: 착용 금지 (변색/손상 위험)\n• 정기 점검: 1년에 1회 전문점 점검 권장",
                    'en' => "• Storage: Store separately, avoid contact with other jewelry\n• Wearing Order: Put on last after makeup/perfume\n• Cleaning: Wipe with soft cloth, careful with ultrasonic\n• Swimming/Exercise: Do not wear (risk of damage/discoloration)\n• Check-up: Annual professional inspection recommended",
                ],
            ],
            [
                'name' => ['ko' => '악기 관리 안내', 'en' => 'Instrument Care Guide'],
                'content' => [
                    'ko' => "• 보관: 온습도 일정한 곳에 케이스 보관\n• 청소: 사용 후 마른 천으로 닦기\n• 현악기: 연주 후 현 이완, 송진 제거\n• 관악기: 연주 후 수분 제거 필수\n• 정기 점검: 6개월~1년 주기 전문가 점검",
                    'en' => "• Storage: Store in case with consistent temperature/humidity\n• Cleaning: Wipe with dry cloth after use\n• Strings: Loosen strings after playing, remove rosin\n• Wind: Must remove moisture after playing\n• Check-up: Professional inspection every 6-12 months",
                ],
            ],
            [
                'name' => ['ko' => '캠핑용품 안전', 'en' => 'Camping Gear Safety'],
                'content' => [
                    'ko' => "• 텐트: 화기 근처 설치 금지, 환기 필수\n• 버너: 환기되는 곳에서만 사용\n• 랜턴: 가연물 근처 사용 금지\n• 보관: 사용 후 건조 후 보관\n• 점검: 시즌 시작 전 장비 상태 점검",
                    'en' => "• Tent: No fire nearby, ventilation required\n• Burner: Use only in ventilated areas\n• Lantern: Keep away from flammable materials\n• Storage: Dry before storing after use\n• Check: Inspect equipment before each season",
                ],
            ],
            [
                'name' => ['ko' => '스포츠용품 안전', 'en' => 'Sports Equipment Safety'],
                'content' => [
                    'ko' => "• 사용 전: 장비 상태 점검 필수\n• 보호 장비: 적절한 보호 장비 착용\n• 사용 환경: 지정된 장소에서만 사용\n• 보관: 직사광선 피해 건조한 곳에 보관\n• 교체: 마모/손상 시 즉시 교체",
                    'en' => "• Before Use: Equipment check required\n• Protection: Wear appropriate protective gear\n• Environment: Use only in designated areas\n• Storage: Store in dry place away from sunlight\n• Replace: Replace immediately if worn/damaged",
                ],
            ],
            [
                'name' => ['ko' => '반려동물 용품 안내', 'en' => 'Pet Supplies Notice'],
                'content' => [
                    'ko' => "• 사료: 급여량 및 급여 방법 확인\n• 장난감: 반려동물 체구에 맞는 크기 선택\n• 위생용품: 반려동물 전용 제품 사용\n• 의약품: 수의사 상담 후 사용\n• 알레르기: 처음 사용 시 반응 확인",
                    'en' => "• Food: Check feeding amount and method\n• Toys: Choose size appropriate for pet\n• Hygiene: Use pet-specific products\n• Medicine: Use after veterinarian consultation\n• Allergies: Monitor reaction on first use",
                ],
            ],
        ];
    }
}
