<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Product::class;
    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        return [
            'sku'             => strtoupper(Str::random(8)),
            'barcode'         => $this->faker->unique()->ean13(),
            'name'            => ucfirst($name),
            'description'     => $this->faker->sentence(),
            'category_id'     => null, // set in seeder if you want real categories
            'vendor_id'       => null,
            'brand_id'        => null,
            'price'           => $this->faker->randomFloat(2, 5, 500),
            'cost_price'      => $this->faker->randomFloat(2, 1, 400),
            'wholesale_price' => $this->faker->optional()->randomFloat(2, 1, 450),
            // 'stock'           => 0,
            // 'tax_rate'        => $this->faker->randomFloat(2, 0, 20),
            // 'tax_inclusive'   => $this->faker->boolean(30),
            'discount'        => $this->faker->optional()->randomFloat(2, 0, 50),
            'is_active'       => true,
        ];
    }
}
