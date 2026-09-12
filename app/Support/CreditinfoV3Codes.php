<?php

namespace App\Support;

/**
 * Vendor-confirmed Creditinfo Namibia CBS code tables -- received directly
 * from Creditinfo as written clarification, superseding the earlier
 * guess-nothing/blank-until-confirmed posture in CreditinfoSetting and
 * CreditinfoBureauClient. These are fixed vendor facts, not internal
 * business configuration -- DesertLedger's own choice of WHICH inquiry
 * reason to use for a given business scenario stays a
 * CreditinfoSetting-driven, admin-editable value (creditinfo_inquiry_reason_search/
 * _report); only the code table itself (what each number means) lives here.
 */
class CreditinfoV3Codes
{
    /** gender, as sent on search/smart/individual. Vendor-confirmed. */
    public const GENDER_MALE = 1;
    public const GENDER_FEMALE = 2;

    public const GENDER_CODES = [
        'Male' => self::GENDER_MALE,
        'Female' => self::GENDER_FEMALE,
    ];

    /**
     * inquiryReason, as sent on BOTH search/smart/individual and
     * reports/custom -- Creditinfo confirmed this is one shared integer
     * enum, not the string enum the manual originally implied for search.
     */
    public const INQUIRY_REASON_NOT_SPECIFIED = 0;
    public const INQUIRY_REASON_APPLICATION_FOR_CREDIT = 1;
    public const INQUIRY_REASON_ANOTHER_REASON = 9;
    public const INQUIRY_REASON_CREDIT_RENEWAL = 17;
    public const INQUIRY_REASON_CUSTOMER_INQUIRY = 36;
    public const INQUIRY_REASON_INSURANCE_APPLICATION = 41;

    public const INQUIRY_REASONS = [
        self::INQUIRY_REASON_NOT_SPECIFIED => 'NotSpecified',
        self::INQUIRY_REASON_APPLICATION_FOR_CREDIT => 'ApplicationForCreditOrAmendmentOfCreditTerms',
        self::INQUIRY_REASON_ANOTHER_REASON => 'AnotherReason',
        self::INQUIRY_REASON_CREDIT_RENEWAL => 'CreditRenewal',
        self::INQUIRY_REASON_CUSTOMER_INQUIRY => 'CustomerInquiry',
        self::INQUIRY_REASON_INSURANCE_APPLICATION => 'InsuranceApplication',
    ];

    /**
     * DesertLedger's own business default for a normal new loan application /
     * credit affordability and eligibility assessment -- a business choice
     * among the vendor-confirmed codes above, not itself a vendor fact.
     * Still only a default: CreditinfoSetting::get('creditinfo_inquiry_reason_search', ...)
     * remains the actual, admin-editable source of truth.
     */
    public const DEFAULT_NEW_CREDIT_INQUIRY_REASON = self::INQUIRY_REASON_APPLICATION_FOR_CREDIT;
}
