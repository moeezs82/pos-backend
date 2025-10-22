<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $apId = (int) env('AP_ACCOUNT_ID', 2000); // set in .env or swap to config(...)
        $vendorType = addslashes(\App\Models\Vendor::class);

        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');

        $sql = <<<SQL
CREATE VIEW vendors_ap_view AS
SELECT
  v.id AS vendor_id,
  CONCAT_WS(' ', v.first_name, v.last_name) AS vendor_name,

  COALESCE(ap.tot_purchases, 0.0) AS total_purchases,
  COALESCE(ap.tot_payments, 0.0)  AS total_payments,
  COALESCE(ap.balance, 0.0)       AS balance,
  COALESCE(ap.last_activity_at, '1970-01-01') AS last_activity_at

FROM vendors v
LEFT JOIN (
  SELECT
    jp.party_id AS vendor_id,
    SUM(CASE WHEN jp.credit > 0 THEN jp.credit ELSE 0 END) AS tot_purchases,
    SUM(CASE WHEN jp.debit  > 0 THEN jp.debit  ELSE 0 END) AS tot_payments,
    SUM(jp.credit - jp.debit)                              AS balance,
    MAX(jp.created_at)                                   AS last_activity_at
  FROM journal_postings jp
  WHERE jp.party_type = '{$vendorType}'
    AND jp.account_id = {$apId}
  GROUP BY jp.party_id
) ap ON ap.vendor_id = v.id
SQL;

        DB::statement($sql);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');
    }
};
