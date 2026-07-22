<?php

namespace App\Support;

final class PermissionCatalog
{
    private const ITEMS = [
        'view-sales' => ['Sales', 'View Sales', 'View sale invoices and details.', 'normal', []],
        'create-sales' => ['Sales', 'Create Sales', 'Create and complete sale invoices.', 'financial', ['view-sales']],
        'manage-sales' => ['Sales', 'Edit Sales & Payments', 'Modify sales, items, and payments.', 'financial', ['view-sales']],
        'refund-sale' => ['Sales', 'Process Returns & Refunds', 'Create negative sale items and issue refunds.', 'high', ['view-sales']],
        'view-products' => ['Products & Inventory', 'View Products', 'View the product catalog and prices.', 'normal', []],
        'manage-products' => ['Products & Inventory', 'Manage Products', 'Create, edit, delete, import, and export products.', 'normal', ['view-products']],
        'view-stock' => ['Products & Inventory', 'View Stock', 'View branch stock balances.', 'normal', ['view-products']],
        'adjust-stock' => ['Products & Inventory', 'Adjust & Transfer Stock', 'Change stock quantities or transfer stock.', 'high', ['view-stock', 'view-products']],
        'view-categories' => ['Products & Inventory', 'View Categories', 'View product categories.', 'normal', []],
        'manage-categories' => ['Products & Inventory', 'Manage Categories', 'Create, edit, and delete categories.', 'normal', ['view-categories']],
        'view-brands' => ['Products & Inventory', 'View Brands', 'View product brands.', 'normal', []],
        'manage-brands' => ['Products & Inventory', 'Manage Brands', 'Create, edit, and delete brands.', 'normal', ['view-brands']],
        'view-customers' => ['Customers', 'View Customers', 'View customer profiles and ledgers.', 'normal', []],
        'manage-customers' => ['Customers', 'Manage Customers', 'Create, edit, and delete customers.', 'normal', ['view-customers']],
        'manage-receipts' => ['Party Payments', 'Receive Customer Payments', 'Record money received from customers.', 'financial', ['view-customers']],
        'view-vendors' => ['Vendors & Purchases', 'View Vendors', 'View vendor profiles and ledgers.', 'normal', []],
        'manage-vendors' => ['Vendors & Purchases', 'Manage Vendors', 'Create, edit, and delete vendors.', 'normal', ['view-vendors']],
        'view-purchases' => ['Vendors & Purchases', 'View Purchases', 'View purchase invoices.', 'normal', []],
        'manage-purchases' => ['Vendors & Purchases', 'Manage Purchases & Claims', 'Create and modify purchases and supplier claims.', 'financial', ['view-purchases', 'view-vendors']],
        'manage-payments' => ['Party Payments', 'Pay Vendors', 'Record payments made to vendors.', 'financial', ['view-vendors']],
        'reverse-party-payments' => ['Party Payments', 'Reverse Party Payments', 'Reverse an incorrect customer receipt or vendor payment.', 'high', []],
        'view-cashbook' => ['Cash & Accounting', 'View Cash Ledger & Day Book', 'View cash and bank movements.', 'financial', []],
        'manage-cashbook' => ['Cash & Accounting', 'Record & Void Cash Entries', 'Create or void manual cash ledger entries.', 'high', ['view-cashbook']],
        'view-reports' => ['Reports', 'View Reports', 'View operational and financial reports.', 'financial', []],
        'view-register-shifts' => ['Register & Shifts', 'View Register Shifts', 'View register and shift activity.', 'normal', []],
        'open-register-shift' => ['Register & Shifts', 'Open Register Shift', 'Open a register shift.', 'financial', ['view-register-shifts']],
        'close-own-register-shift' => ['Register & Shifts', 'Close Own Register Shift', 'Close the signed-in user\'s shift.', 'financial', ['view-register-shifts']],
        'record-shift-cash-movement' => ['Register & Shifts', 'Record Shift Cash In/Out', 'Record drawer cash movements.', 'high', ['view-register-shifts']],
        'manage-register-shifts' => ['Register & Shifts', 'Manage Registers & All Shifts', 'Manage registers and other users\' shifts.', 'high', ['view-register-shifts']],
        'approve-shift-variance' => ['Register & Shifts', 'Approve Closing Variance', 'Approve a counted-versus-expected variance.', 'high', ['view-register-shifts']],
        'approve-shift-cash-movement' => ['Register & Shifts', 'Approve Cash Movement', 'Approve controlled drawer movements.', 'high', ['view-register-shifts']],
        'view-delivery' => ['Delivery', 'View Delivery Operations', 'View riders, orders, and balances.', 'financial', []],
        'manage-delivery' => ['Delivery', 'Manage Delivery Assignments', 'Assign and update delivery riders.', 'financial', ['view-delivery']],
        'receive-delivery-cash' => ['Delivery', 'Receive Delivery Cash', 'Settle cash held by a delivery rider.', 'high', ['view-delivery']],
        'view-users' => ['Users & Access', 'View Users', 'View branch users.', 'normal', []],
        'manage-users' => ['Users & Access', 'Manage Users', 'Create, edit, disable, and assign roles to users.', 'high', ['view-users']],
        'view-roles' => ['Users & Access', 'View Roles', 'View branch roles and permissions.', 'normal', []],
        'manage-roles' => ['Users & Access', 'Manage Roles & Permissions', 'Create roles and change their permissions.', 'high', ['view-roles']],
    ];

    public static function metadata(string $key): array
    {
        $item = self::ITEMS[$key] ?? ['Other', ucwords(str_replace(['-', '_'], ' ', $key)), '', 'normal', []];
        return ['key' => $key, 'name' => $key, 'group' => $item[0], 'label' => $item[1], 'description' => $item[2], 'risk' => $item[3], 'depends_on' => $item[4]];
    }

    public static function normalize(array $permissions): array
    {
        $selected = array_values(array_unique($permissions));
        do {
            $before = count($selected);
            foreach ($selected as $key) {
                foreach ((self::ITEMS[$key][4] ?? []) as $dependency) $selected[] = $dependency;
            }
            $selected = array_values(array_unique($selected));
        } while (count($selected) !== $before);
        return $selected;
    }
}
