<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS customers_ar_view');

        // If you store aliases like 'customer', keep it in the IN() below.
        // If you store FQCNs, escape backslashes for SQL:
        $customerFqcn      = \App\Models\Customer::class;               // App\Models\Customer
        $customerTypeSql   = str_replace('\\', '\\\\', $customerFqcn);  // App\\Models\\Customer (for SQL literal)

        // OPTIONAL: filter only AR control accounts. If you have an accounts table flag, use it.
        // Example with explicit IDs:
        // $arIdsSql = '1200,1201'; // comma-separated list
        // and then add: AND jp.account_id IN ('.$arIdsSql.')
        //
        // Or if you have accounts.is_ar = 1, join accounts a ... AND a.is_ar = 1

        $sql = <<<SQL
CREATE VIEW customers_ar_view AS
SELECT
  c.id AS customer_id,
  CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,

  COALESCE(ar.tot_sales, 0.0)     AS total_sales,
  COALESCE(ar.tot_receipts, 0.0)  AS total_receipts,
  COALESCE(ar.balance, 0.0)       AS balance,
  COALESCE(ar.last_activity_at, '1970-01-01') AS last_activity_at

FROM customers c
LEFT JOIN (
  SELECT
    jp.party_id AS customer_id,
    SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS tot_sales,
    SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_receipts,
    SUM(jp.debit - jp.credit)                              AS balance,
    MAX(jp.created_at)                                   AS last_activity_at
  FROM journal_postings jp
  WHERE jp.party_type IN ('customer', '{$customerTypeSql}')
  GROUP BY jp.party_id
) ar ON ar.customer_id = c.id
SQL;

        DB::statement($sql);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS customers_ar_view');
    }
};
