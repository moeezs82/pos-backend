<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop if exists
        DB::statement('DROP VIEW IF EXISTS customers_ar_view');

        // Create
        DB::statement(<<<'SQL'
CREATE VIEW customers_ar_view AS
SELECT
  c.id AS customer_id,
  CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,

  COALESCE(s.tot_sales, 0.0)      AS total_sales,
  COALESCE(rc.tot_receipts, 0.0)  AS total_receipts,
  COALESCE(s.tot_sales, 0.0) - COALESCE(rc.tot_receipts, 0.0) AS balance,

  -- MariaDB-safe "later of" between last sale and last receipt
  CASE
    WHEN COALESCE(s.last_sale_date,    '1970-01-01') >= COALESCE(rc.last_receipt_date, '1970-01-01')
      THEN COALESCE(s.last_sale_date,  '1970-01-01')
    ELSE COALESCE(rc.last_receipt_date,'1970-01-01')
  END AS last_activity_at

FROM customers c
LEFT JOIN (
  SELECT
    customer_id,
    SUM(total)                     AS tot_sales,
    MAX(invoice_date)              AS last_sale_date
  FROM sales
  GROUP BY customer_id
) s  ON s.customer_id = c.id
LEFT JOIN (
  -- Sum receipts allocated to a customer's sales; take last received_at
  SELECT
    s.customer_id,
    SUM(ra.amount)                 AS tot_receipts,
    MAX(r.received_at)                 AS last_receipt_date
  FROM receipt_allocations ra
  JOIN receipts r ON r.id = ra.receipt_id
  JOIN sales    s ON s.id = ra.sale_id
  GROUP BY s.customer_id
) rc ON rc.customer_id = c.id;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS customers_ar_view');
    }
};
