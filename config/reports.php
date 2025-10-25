<?php

return [
    // Map to your seeded account CODES (strings). You can put multiple codes in an array.
    'accounts' => [
        'sales_revenue' => ['4000'],              // INCOME → Sales Revenue
        'sales_discounts' => [],                  // if you have a contra-income account for discounts, e.g. ['4010']
        'sales_returns'   => [],                  // if you have a returns contra-income account, e.g. ['4020']
        'output_tax'      => ['2205'],            // LIABILITY → Output VAT (Sales Tax Payable)
        'cash'            => ['1000'],            // ASSET → Cash in Hand
        'bank'            => ['1010'],            // ASSET → Bank (add more codes if you have multiple banks)
    ],

    // Optional: if codes are empty, try these fuzzy fallbacks
    'fallbacks' => [
        'sales_revenue'  => ['type' => 'INCOME',   'name_like' => 'Sales Revenue'],
        'sales_discounts'=> ['type' => 'INCOME',   'name_like' => 'Discount'],
        'sales_returns'  => ['type' => 'INCOME',   'name_like' => 'Return'],
        'output_tax'     => ['type' => 'LIABILITY','name_like' => 'Output VAT'],
        'cash'           => ['type' => 'ASSET',    'name_like' => 'Cash'],
        'bank'           => ['type' => 'ASSET',    'name_like' => 'Bank'],
    ],
];
