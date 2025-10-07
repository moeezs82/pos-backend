<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop view if exists (separate statement)
        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');

        // Create view (single statement)
        DB::statement(
            <<<'SQL'
CREATE VIEW vendors_ap_view AS
SELECT
  v.id AS vendor_id,
  CONCAT_WS(' ', v.first_name, v.last_name) AS vendor_name,
  COALESCE(p.tot_purchases, 0.0) AS total_purchases,
  COALESCE(vp.tot_payments, 0.0) AS total_payments,
  COALESCE(p.tot_purchases, 0.0) - COALESCE(vp.tot_payments, 0.0) AS balance,
  -- Use IF() to get the later date in a MariaDB-compatible way
  CASE
    WHEN COALESCE(p.last_purchase_date, '1970-01-01') >= COALESCE(vp.last_payment_date, '1970-01-01')
      THEN COALESCE(p.last_purchase_date, '1970-01-01')
    ELSE COALESCE(vp.last_payment_date, '1970-01-01')
  END AS last_activity_at
FROM vendors v
LEFT JOIN (
  SELECT vendor_id, SUM(total) AS tot_purchases, MAX(invoice_date) AS last_purchase_date
  FROM purchases
  GROUP BY vendor_id
) p ON p.vendor_id = v.id
LEFT JOIN (
  SELECT vendor_id, SUM(amount) AS tot_payments, MAX(paid_at) AS last_payment_date
  FROM vendor_payments
  GROUP BY vendor_id
) vp ON vp.vendor_id = v.id;
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS vendors_ap_view');
    }
};
