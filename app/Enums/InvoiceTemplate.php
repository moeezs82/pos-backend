<?php

namespace App\Enums;

/**
 * The predefined invoice/receipt layouts a printer destination can use.
 * Each one is just a named bundle of (a) which sections are visible and
 * (b) paper width — there is no free-form template builder by design, so
 * every receipt anywhere in the app stays predictable and easy to reason
 * about. The frontend renders both the PDF preview and the real ESC/POS
 * ticket from this exact same section list, so "preview" never drifts
 * from what actually prints.
 */
enum InvoiceTemplate: string
{
    case STANDARD = 'standard';
    case COMPACT  = 'compact';
    case KITCHEN  = 'kitchen';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Standard Receipt',
            self::COMPACT  => 'Compact Receipt',
            self::KITCHEN  => 'Kitchen Ticket',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::STANDARD => 'Full customer receipt: logo, shop details, customer info, itemised totals, and a thank-you footer.',
            self::COMPACT  => 'Narrow 58mm layout for small printers: shop name and items only, no logo, no customer details.',
            self::KITCHEN  => 'Item list for the kitchen: who it is for and what to make, no prices, no shop header.',
        };
    }

    /** 'mm58' | 'mm80' */
    public function paperWidth(): string
    {
        return $this === self::COMPACT ? 'mm58' : 'mm80';
    }

    /**
     * Which sections this template shows by default. The frontend treats
     * this as the starting point; nothing here is currently overridable
     * per-printer beyond picking a different template altogether.
     *
     * @return array{logo:bool,header:bool,customer:bool,totals_breakdown:bool,footer:bool}
     */
    public function sections(): array
    {
        return match ($this) {
            self::STANDARD => [
                'logo'             => true,
                'header'           => true,
                'customer'         => true,
                'totals_breakdown' => true,
                'footer'           => true,
            ],
            self::COMPACT => [
                'logo'             => false,
                'header'           => true,
                'customer'         => false,
                'totals_breakdown' => true,
                'footer'           => false,
            ],
            self::KITCHEN => [
                'logo'             => false,
                'header'           => false,
                'customer'         => true,
                'totals_breakdown' => false,
                'footer'           => false,
            ],
        };
    }

    /** @return array<int, array{value:string,label:string,description:string,paper_width:string,sections:array}> */
    public static function catalog(): array
    {
        return array_map(
            fn (self $t) => [
                'value'       => $t->value,
                'label'       => $t->label(),
                'description' => $t->description(),
                'paper_width' => $t->paperWidth(),
                'sections'    => $t->sections(),
            ],
            self::cases()
        );
    }
}
