<?php

namespace App\Enums;

/**
 * The five non-sales cash movements, each carrying its own:
 *   - direction (cash in / cash out)
 *   - contra GL account code (the non-cash leg of the double entry)
 *   - whether a party makes accounting sense on the contra leg
 *
 * The cash leg (1000 Cash / 1010 Bank) is resolved separately from the
 * payment method, so a Qameti installment can be paid by bank as easily
 * as by cash without changing any of this mapping.
 */
enum CashLedgerCategory: string
{
    case QAMETI_PAYMENT    = 'QAMETI_PAYMENT';
    case QAMETI_COLLECTION = 'QAMETI_COLLECTION';
    case LOAN_GIVEN        = 'LOAN_GIVEN';
    case LOAN_RECOVERED    = 'LOAN_RECOVERED';
    case OTHER_EXPENSE     = 'OTHER_EXPENSE';

    /** 'in' = cash received, 'out' = cash paid. */
    public function direction(): string
    {
        return match ($this) {
            self::QAMETI_COLLECTION, self::LOAN_RECOVERED => 'in',
            default => 'out',
        };
    }

    public function isInflow(): bool
    {
        return $this->direction() === 'in';
    }

    /**
     * The contra (non-cash) GL account code for this category.
     * OTHER_EXPENSE has a sensible default but callers may override it
     * with any EXPENSE-type account_code.
     */
    public function contraAccountCode(): string
    {
        return match ($this) {
            self::QAMETI_PAYMENT, self::QAMETI_COLLECTION => '1310', // Qameti / Committee Control (Asset)
            self::LOAN_GIVEN, self::LOAN_RECOVERED        => '1300', // Loans Receivable (Asset)
            self::OTHER_EXPENSE                           => '5300', // Other / Sundry Expense
        };
    }

    /**
     * Whether binding a party to the contra leg is meaningful. For loans it is
     * effectively required (you track who owes you); for Qameti/expenses it is
     * optional context.
     */
    public function partyIsMeaningful(): bool
    {
        return match ($this) {
            self::LOAN_GIVEN, self::LOAN_RECOVERED => true,
            default => false,
        };
    }

    /** Human label for reports / UI. */
    public function label(): string
    {
        return match ($this) {
            self::QAMETI_PAYMENT    => 'Qameti Payment',
            self::QAMETI_COLLECTION => 'Qameti Collection',
            self::LOAN_GIVEN        => 'Loan Given',
            self::LOAN_RECOVERED    => 'Loan Recovered',
            self::OTHER_EXPENSE     => 'Other Expense',
        };
    }

    /** The bucket this category rolls up into on the unified cash-flow summary. */
    public function summaryBucket(): string
    {
        return match ($this) {
            self::QAMETI_COLLECTION => 'qameti_collections',
            self::LOAN_RECOVERED    => 'loan_recoveries',
            self::QAMETI_PAYMENT    => 'qameti_payments',
            self::LOAN_GIVEN        => 'loans_given',
            self::OTHER_EXPENSE     => 'business_expenses',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
