<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceTemplate;
use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\PrinterSetting;
use App\Services\BranchContextService;
use App\Services\BranchAddonService;
use Illuminate\Http\Request;

/**
 * Printer settings: shop details + which printer (network or local) a sale
 * receipt should actually print to. Any signed-in branch user can READ the
 * settings that apply to their own branch (or the global default), but only
 * master admin may create/update them — matching how BranchController gates
 * its own write actions.
 */
class PrinterConfigController extends Controller
{
    /**
     * GET /printer-config/templates — the predefined invoice template
     * catalog (label, description, paper width, which sections each one
     * shows). Any signed-in user can read this; it's just a static list,
     * not a setting.
     */
    public function templates()
    {
        return ApiResponse::success(['templates' => InvoiceTemplate::catalog()]);
    }

    /**
     * GET (and, for backwards compatibility with the existing app build,
     * POST) /printer-config — the settings the CURRENT user's branch
     * should print with: their branch's own row if one exists, otherwise
     * the global default row, otherwise sensible empty defaults.
     */
    public function show(Request $request, BranchContextService $branches, BranchAddonService $addons)
    {
        $branchId = $branches->effectiveBranchId($request);
        $setting = PrinterSetting::forBranch($branchId);
        $addonActive = $branchId
            ? $addons->isActive($branchId, BranchAddonService::BARCODE_LABELS)
            : false;
        $permissionGranted = $branches->isMasterAdmin($request->user())
            || $request->user()->can('print-barcode-labels');

        return ApiResponse::success(
            $this->present($setting, $addonActive, $permissionGranted)
        );
    }

    /**
     * GET /printer-config/all — every configured row, for the master-admin
     * settings screen (so they can see/manage per-branch overrides plus the
     * global default in one place).
     */
    public function index(Request $request, BranchContextService $branches, BranchAddonService $addons)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage printer settings.', 403);
        }

        $rows = PrinterSetting::with('branch:id,name')->orderBy('branch_id')->get();

        $branchIds = $rows->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->all();
        $addonMaps = $addons->activeMaps($branchIds);

        return ApiResponse::success([
            'settings' => $rows->map(fn (PrinterSetting $row) => $this->present(
                $row,
                $row->branch_id
                    ? (bool) ($addonMaps[(int) $row->branch_id][BranchAddonService::BARCODE_LABELS] ?? false)
                    : false,
                true
            ))->values(),
        ]);
    }

    /**
     * POST /printer-config/save — create or update the row for a branch
     * (branch_id = null means "the global default"). Master admin only.
     */
    public function save(Request $request, BranchContextService $branches, BranchAddonService $addons)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage printer settings.', 403);
        }

        $data = $request->validate([
            'branch_id'                   => 'nullable|integer|exists:branches,id',
            'shop_name'                   => 'nullable|string|max:255',
            'shop_address'                => 'nullable|string|max:255',
            'shop_phone'                  => 'nullable|string|max:50',
            'footer_lines'                => 'nullable|array|max:10',
            'footer_lines.*'              => 'string|max:100',
            'active_connection'           => 'required|in:network,local,none',
            'network_ip'                  => 'nullable|string|max:100',
            'network_port'                => 'nullable|integer|min:1|max:65535',
            'local_printer_name'          => 'nullable|string|max:255',
            'main_invoice_template'       => 'nullable|in:standard,compact,kitchen',
            'kitchen_print_enabled'       => 'boolean',
            'kitchen_network_ip'          => 'nullable|string|max:100',
            'kitchen_network_port'        => 'nullable|integer|min:1|max:65535',
            'kitchen_local_printer_name'  => 'nullable|string|max:255',
            'kitchen_invoice_template'    => 'nullable|in:standard,compact,kitchen',
            // New generic names. Legacy kitchen_* keys remain accepted below
            // so already-deployed clients continue working during upgrades.
            'secondary_print_enabled'      => 'nullable|boolean',
            'secondary_network_ip'          => 'nullable|string|max:100',
            'secondary_network_port'        => 'nullable|integer|min:1|max:65535',
            'secondary_local_printer_name'  => 'nullable|string|max:255',
            'secondary_invoice_template'    => 'nullable|in:standard,compact,kitchen',
            'barcode_print_enabled'         => 'nullable|boolean',
            'barcode_connection'            => 'nullable|in:dialog,local,network',
            'barcode_local_printer_name'     => 'nullable|string|max:255',
            'barcode_network_ip'             => 'nullable|string|max:100',
            'barcode_network_port'           => 'nullable|integer|min:1|max:65535',
            'barcode_printer_language'       => 'nullable|in:driver,zpl,tspl',
            'barcode_label_width_mm'         => 'nullable|numeric|min:15|max:200',
            'barcode_label_height_mm'        => 'nullable|numeric|min:10|max:200',
            'barcode_label_gap_mm'           => 'nullable|numeric|min:0|max:20',
            'barcode_dpi'                    => 'nullable|integer|in:203,300,600',
            'barcode_orientation'            => 'nullable|in:portrait,landscape',
            'barcode_currency'               => 'nullable|string|max:20',
            'barcode_show_name'              => 'nullable|boolean',
            'barcode_show_value'             => 'nullable|boolean',
            'barcode_show_price'             => 'nullable|boolean',
        ]);

        $barcodeKeys = [
            'barcode_print_enabled',
            'barcode_connection',
            'barcode_local_printer_name',
            'barcode_network_ip',
            'barcode_network_port',
            'barcode_printer_language',
            'barcode_label_width_mm',
            'barcode_label_height_mm',
            'barcode_label_gap_mm',
            'barcode_dpi',
            'barcode_orientation',
            'barcode_currency',
            'barcode_show_name',
            'barcode_show_value',
            'barcode_show_price',
        ];
        $hasBarcodePayload = collect($barcodeKeys)
            ->contains(fn ($key) => array_key_exists($key, $data));
        $targetBranchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $barcodeAddonActive = $targetBranchId
            ? $addons->isActive($targetBranchId, BranchAddonService::BARCODE_LABELS)
            : false;

        if ($hasBarcodePayload && !$barcodeAddonActive) {
            if (($data['barcode_print_enabled'] ?? false) === true) {
                return ApiResponse::error(
                    'Barcode Label Printing is not active for this branch.',
                    403,
                    ['code' => 'BARCODE_LABELS_ADDON_REQUIRED']
                );
            }

            foreach ($barcodeKeys as $key) {
                unset($data[$key]);
            }
        } elseif ($hasBarcodePayload) {
            $data['barcode_print_enabled'] = true;
        }

        if ($data['active_connection'] === 'network' && empty($data['network_ip'])) {
            return ApiResponse::error('Enter the printer\'s network address to use a network printer.', 422);
        }
        if ($data['active_connection'] === 'local' && empty($data['local_printer_name'])) {
            return ApiResponse::error('Select a local printer to use a local printer.', 422);
        }

        // Normalize the user-facing Secondary Printer keys into the existing
        // kitchen_* columns. This is intentionally backwards compatible and
        // avoids a risky production rename of already-populated columns.
        $secondaryMap = [
            'secondary_print_enabled' => 'kitchen_print_enabled',
            'secondary_network_ip' => 'kitchen_network_ip',
            'secondary_network_port' => 'kitchen_network_port',
            'secondary_local_printer_name' => 'kitchen_local_printer_name',
            'secondary_invoice_template' => 'kitchen_invoice_template',
        ];
        foreach ($secondaryMap as $newKey => $legacyKey) {
            if (array_key_exists($newKey, $data)) {
                $data[$legacyKey] = $data[$newKey];
            }
            unset($data[$newKey]);
        }

        // Do not inject barcode defaults into partial requests from an older
        // deployed client: database defaults cover new rows, while omitted
        // fields on existing rows must remain unchanged during rolling updates.
        foreach (['barcode_print_enabled', 'barcode_show_name', 'barcode_show_value', 'barcode_show_price'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = (bool) $data[$key];
            }
        }
        if (array_key_exists('barcode_currency', $data)) {
            $data['barcode_currency'] = trim((string) $data['barcode_currency']);
        }

        if (($data['barcode_print_enabled'] ?? false) === true) {
            $connection = $data['barcode_connection'] ?? 'dialog';
            if ($connection === 'local' && empty($data['barcode_local_printer_name'])) {
                return ApiResponse::error('Select an installed printer for barcode labels.', 422);
            }
            if ($connection === 'network') {
                if (empty($data['barcode_network_ip'])) {
                    return ApiResponse::error('Enter the barcode printer network address.', 422);
                }
                if (!in_array($data['barcode_printer_language'] ?? null, ['zpl', 'tspl'], true)) {
                    return ApiResponse::error('Direct network barcode printing requires ZPL or TSPL.', 422);
                }
            }
        }

        $data['updated_by'] = $request->user()->id;

        $setting = PrinterSetting::updateOrCreate(
            ['branch_id' => $data['branch_id'] ?? null],
            $data
        );

        return ApiResponse::success(
            $this->present($setting, $barcodeAddonActive, true),
            'Printer settings saved.'
        );
    }

    /**
     * POST /printer-config/test — send a short test ticket to the chosen
     * destination right now, without saving anything. Master admin only,
     * so a branch user can't probe arbitrary IPs from the settings screen.
     */
    public function test(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can test printer settings.', 403);
        }

        // The actual byte-level printing happens client-side (the backend
        // has no path to a printer on someone's desk); this endpoint just
        // validates the destination is well-formed before the app tries it.
        $data = $request->validate([
            'active_connection'  => 'required|in:network,local',
            'network_ip'         => 'required_if:active_connection,network|nullable|string|max:100',
            'network_port'       => 'nullable|integer|min:1|max:65535',
            'local_printer_name' => 'required_if:active_connection,local|nullable|string|max:255',
        ]);

        return ApiResponse::success($data, 'Destination looks valid; attempting test print on device.');
    }

    private function present(
        ?PrinterSetting $setting,
        bool $barcodeAddonActive = false,
        bool $barcodePermissionGranted = false
    ): array
    {
        $barcodeAccessGranted = $barcodeAddonActive && $barcodePermissionGranted;
        if (!$setting) {
            return [
                'branch_id'                  => null,
                'shop_name'                  => '',
                'shop_address'               => '',
                'shop_phone'                 => '',
                'footer_lines'               => [],
                'active_connection'          => 'none',
                'network_ip'                 => null,
                'network_port'               => 9100,
                'local_printer_name'         => null,
                'main_invoice_template'      => InvoiceTemplate::STANDARD->value,
                'kitchen_print_enabled'      => false,
                'kitchen_network_ip'         => null,
                'kitchen_network_port'       => 9100,
                'kitchen_local_printer_name' => null,
                'kitchen_invoice_template'   => InvoiceTemplate::KITCHEN->value,
                'secondary_print_enabled'      => false,
                'secondary_network_ip'          => null,
                'secondary_network_port'        => 9100,
                'secondary_local_printer_name'  => null,
                'secondary_invoice_template'    => InvoiceTemplate::KITCHEN->value,
                'barcode_print_enabled'         => false,
                'barcode_connection'            => 'dialog',
                'barcode_local_printer_name'     => null,
                'barcode_network_ip'             => null,
                'barcode_network_port'           => 9100,
                'barcode_printer_language'       => 'driver',
                'barcode_label_width_mm'         => 50.0,
                'barcode_label_height_mm'        => 30.0,
                'barcode_label_gap_mm'           => 2.0,
                'barcode_dpi'                    => 203,
                'barcode_orientation'            => 'portrait',
                'barcode_currency'               => 'KD',
                'barcode_show_name'              => true,
                'barcode_show_value'             => true,
                'barcode_show_price'             => true,
                'barcode_addon_active'            => $barcodeAddonActive,
                'barcode_permission_granted'      => $barcodePermissionGranted,
                'barcode_access_granted'          => $barcodeAccessGranted,
                // Legacy keys the existing app build already expects.
                'main_printer_name'          => null,
                'kitchen_printer_name'       => null,
                'secondary_printer_name'     => null,
            ];
        }

        $mainPrinterName = $setting->active_connection === 'local'
            ? $setting->local_printer_name
            : ($setting->active_connection === 'network' ? $setting->network_ip : null);

        return [
            'branch_id'                  => $setting->branch_id,
            'branch_name'                => $setting->relationLoaded('branch') ? $setting->branch?->name : null,
            'shop_name'                  => $setting->shop_name ?? '',
            'shop_address'               => $setting->shop_address ?? '',
            'shop_phone'                 => $setting->shop_phone ?? '',
            'footer_lines'               => $setting->footer_lines ?? [],
            'active_connection'          => $setting->active_connection,
            'network_ip'                 => $setting->network_ip,
            'network_port'               => $setting->network_port,
            'local_printer_name'         => $setting->local_printer_name,
            'main_invoice_template'      => $setting->main_invoice_template ?? InvoiceTemplate::STANDARD->value,
            'kitchen_print_enabled'      => (bool) $setting->kitchen_print_enabled,
            'kitchen_network_ip'         => $setting->kitchen_network_ip,
            'kitchen_network_port'       => $setting->kitchen_network_port,
            'kitchen_local_printer_name' => $setting->kitchen_local_printer_name,
            'kitchen_invoice_template'   => $setting->kitchen_invoice_template ?? InvoiceTemplate::KITCHEN->value,
            // Preferred generic names for new clients.
            'secondary_print_enabled'      => (bool) $setting->kitchen_print_enabled,
            'secondary_network_ip'          => $setting->kitchen_network_ip,
            'secondary_network_port'        => $setting->kitchen_network_port,
            'secondary_local_printer_name'  => $setting->kitchen_local_printer_name,
            'secondary_invoice_template'    => $setting->kitchen_invoice_template ?? InvoiceTemplate::KITCHEN->value,
            'barcode_print_enabled'         => $barcodeAccessGranted
                && (bool) ($setting->barcode_print_enabled ?? false),
            'barcode_connection'            => $setting->barcode_connection ?? 'dialog',
            'barcode_local_printer_name'     => $setting->barcode_local_printer_name,
            'barcode_network_ip'             => $setting->barcode_network_ip,
            'barcode_network_port'           => $setting->barcode_network_port ?? 9100,
            'barcode_printer_language'       => $setting->barcode_printer_language ?? 'driver',
            'barcode_label_width_mm'         => (float) ($setting->barcode_label_width_mm ?? 50),
            'barcode_label_height_mm'        => (float) ($setting->barcode_label_height_mm ?? 30),
            'barcode_label_gap_mm'           => (float) ($setting->barcode_label_gap_mm ?? 2),
            'barcode_dpi'                    => (int) ($setting->barcode_dpi ?? 203),
            'barcode_orientation'            => $setting->barcode_orientation ?? 'portrait',
            'barcode_currency'               => $setting->barcode_currency ?? 'KD',
            'barcode_show_name'              => (bool) ($setting->barcode_show_name ?? true),
            'barcode_show_value'             => (bool) ($setting->barcode_show_value ?? true),
            'barcode_show_price'             => (bool) ($setting->barcode_show_price ?? true),
            'barcode_addon_active'            => $barcodeAddonActive,
            'barcode_permission_granted'      => $barcodePermissionGranted,
            'barcode_access_granted'          => $barcodeAccessGranted,
            // Legacy keys for the existing PrinterConfig.fromJson() shape.
            'main_printer_name'          => $mainPrinterName,
            'kitchen_printer_name'       => $setting->kitchen_print_enabled
                ? ($setting->kitchen_local_printer_name ?: $setting->kitchen_network_ip)
                : null,
            'secondary_printer_name'     => $setting->kitchen_print_enabled
                ? ($setting->kitchen_local_printer_name ?: $setting->kitchen_network_ip)
                : null,
        ];
    }
}
