<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve literals BEFORE composing the SQL
        // $arId = (int) env('AR_ACCOUNT_ID', 1200); // set in .env or swap to config(...)
        $customerType = "App\Models\Customer"; // e.g. "App\Models\Customer"
        // dd($customerType);

        DB::statement('DROP VIEW IF EXISTS customers_ar_view');

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
  WHERE jp.party_type = '{$customerType}'
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
