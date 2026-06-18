<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Response\ApiResponse;
use App\Models\PrinterSetting;
use App\Services\BranchContextService;
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
     * GET (and, for backwards compatibility with the existing app build,
     * POST) /printer-config — the settings the CURRENT user's branch
     * should print with: their branch's own row if one exists, otherwise
     * the global default row, otherwise sensible empty defaults.
     */
    public function show(Request $request, BranchContextService $branches)
    {
        $branchId = $branches->effectiveBranchId($request);
        $setting = PrinterSetting::forBranch($branchId);

        return ApiResponse::success($this->present($setting));
    }

    /**
     * GET /printer-config/all — every configured row, for the master-admin
     * settings screen (so they can see/manage per-branch overrides plus the
     * global default in one place).
     */
    public function index(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage printer settings.', 403);
        }

        $rows = PrinterSetting::with('branch:id,name')->orderBy('branch_id')->get();

        return ApiResponse::success([
            'settings' => $rows->map(fn (PrinterSetting $row) => $this->present($row))->values(),
        ]);
    }

    /**
     * POST /printer-config/save — create or update the row for a branch
     * (branch_id = null means "the global default"). Master admin only.
     */
    public function save(Request $request, BranchContextService $branches)
    {
        if (!$branches->isMasterAdmin($request->user())) {
            return ApiResponse::error('Only master admin can manage printer settings.', 403);
        }

        $data = $request->validate([
            'branch_id'                   => 'nullable|integer|exists:branches,id',
            'shop_name'                   => 'nullable|string|max:255',
            'shop_address'                => 'nullable|string|max:255',
            'shop_phone'                  => 'nullable|string|max:50',
            'active_connection'           => 'required|in:network,local,none',
            'network_ip'                  => 'nullable|string|max:100',
            'network_port'                => 'nullable|integer|min:1|max:65535',
            'local_printer_name'          => 'nullable|string|max:255',
            'kitchen_print_enabled'       => 'boolean',
            'kitchen_network_ip'          => 'nullable|string|max:100',
            'kitchen_network_port'        => 'nullable|integer|min:1|max:65535',
            'kitchen_local_printer_name'  => 'nullable|string|max:255',
        ]);

        if ($data['active_connection'] === 'network' && empty($data['network_ip'])) {
            return ApiResponse::error('Enter the printer\'s network address to use a network printer.', 422);
        }
        if ($data['active_connection'] === 'local' && empty($data['local_printer_name'])) {
            return ApiResponse::error('Select a local printer to use a local printer.', 422);
        }

        $data['updated_by'] = $request->user()->id;

        $setting = PrinterSetting::updateOrCreate(
            ['branch_id' => $data['branch_id'] ?? null],
            $data
        );

        return ApiResponse::success($this->present($setting), 'Printer settings saved.');
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

    private function present(?PrinterSetting $setting): array
    {
        if (!$setting) {
            return [
                'branch_id'                  => null,
                'shop_name'                  => '',
                'shop_address'               => '',
                'shop_phone'                 => '',
                'active_connection'          => 'none',
                'network_ip'                 => null,
                'network_port'               => 9100,
                'local_printer_name'         => null,
                'kitchen_print_enabled'      => false,
                'kitchen_network_ip'         => null,
                'kitchen_network_port'       => 9100,
                'kitchen_local_printer_name' => null,
                // Legacy keys the existing app build already expects —
                // kept so older clients don't break while they update.
                'main_printer_name'          => null,
                'kitchen_printer_name'       => null,
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
            'active_connection'          => $setting->active_connection,
            'network_ip'                 => $setting->network_ip,
            'network_port'               => $setting->network_port,
            'local_printer_name'         => $setting->local_printer_name,
            'kitchen_print_enabled'      => (bool) $setting->kitchen_print_enabled,
            'kitchen_network_ip'         => $setting->kitchen_network_ip,
            'kitchen_network_port'       => $setting->kitchen_network_port,
            'kitchen_local_printer_name' => $setting->kitchen_local_printer_name,
            // Legacy keys for the existing PrinterConfig.fromJson() shape.
            'main_printer_name'          => $mainPrinterName,
            'kitchen_printer_name'       => $setting->kitchen_print_enabled
                ? ($setting->active_connection === 'local' ? $setting->kitchen_local_printer_name : $setting->kitchen_network_ip)
                : null,
        ];
    }
}
