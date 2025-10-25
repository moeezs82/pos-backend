<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');

        // Handle both alias ('vendor') and FQCN ('App\Models\Vendor')
        $vendorFqcn    = \App\Models\Vendor::class;                  // App\Models\Vendor
        $vendorTypeSql = str_replace('\\', '\\\\', $vendorFqcn);     // App\\Models\\Vendor (escaped for SQL)

        $sql = <<<SQL
CREATE VIEW vendors_ap_view AS
SELECT
  v.id AS vendor_id,
  CONCAT_WS(' ', v.first_name, v.last_name) AS vendor_name,

  -- AP perspective without account filtering:
  -- credit increases liability (purchases/bills), debit decreases (payments/credit notes)
  COALESCE(ap.tot_purchases, 0.0) AS total_purchases,
  COALESCE(ap.tot_payments, 0.0)  AS total_payments,
  COALESCE(ap.balance, 0.0)       AS balance,                -- SUM(credit - debit); positive => we owe vendor
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
  WHERE jp.party_type IN ('vendor', '{$vendorTypeSql}')
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
