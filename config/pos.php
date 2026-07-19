<?php

return [
    'shift_variance_approval_limit' => (float) env('SHIFT_VARIANCE_APPROVAL_LIMIT', 5),
    'shift_cash_out_approval_limit' => (float) env('SHIFT_CASH_OUT_APPROVAL_LIMIT', 500),

    // EXPENSE-type accounts that are system/inventory-driven and must NOT be
    // manually selectable as an "Other Expense" posting target:
    //   5100 = Cost of Goods Sold (posted automatically by sales)
    //   5205 = Purchase Price Variance / stock-adjustment variance
    // Single source of truth for both the operational expense-options endpoint
    // and the Cash Ledger submission guard.
    'system_expense_codes' => ['5100', '5205'],
];
