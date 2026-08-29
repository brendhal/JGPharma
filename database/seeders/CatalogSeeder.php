<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $medicines = Category::firstOrCreate(['name' => 'Medicines'], ['description' => 'Prescription and OTC drugs']);
        $essentials = Category::firstOrCreate(['name' => 'Essentials'], ['description' => 'General merchandise']);

        $categories = collect([
            'Antibiotics' => $medicines,
            'Analgesics' => $medicines,
            'Chronic Care' => $medicines,
            'Vitamins' => $medicines,
            'Respiratory' => $medicines,
            'Personal Care' => $essentials,
            'Medical Supplies' => $essentials,
        ])->mapWithKeys(fn (Category $parent, string $name) => [
            $name => Category::firstOrCreate(['name' => $name], ['parent_id' => $parent->id]),
        ]);

        $brands = collect([
            'Panadol', 'Amoxil', 'Lipitor', 'Glucophage', 'Ventolin',
            'Biogesic', 'Neozep', 'Enervon', 'Generic', 'ProCheck',
        ])->mapWithKeys(fn (string $name) => [$name => Brand::firstOrCreate(['name' => $name])]);

        collect([
            ['name' => 'PharmaDist Corp.', 'contact_person' => 'Ramon Dela Cruz', 'contact_number' => '+63 917 555 0101'],
            ['name' => 'Global Care Co.', 'contact_person' => 'Anna Lim', 'contact_number' => '+63 917 555 0202'],
            ['name' => 'MediSupply Ph', 'contact_person' => 'Jomar Aquino', 'contact_number' => '+63 917 555 0303'],
        ])->each(fn (array $s) => Supplier::firstOrCreate(['name' => $s['name']], $s));

        $catalog = [
            // [name, brand, category, unit, cost, price, min, generic, strength, form, rx, manufacturer]
            ['Amoxicillin 500mg Capsule', 'Amoxil', 'Antibiotics', 'Box (20 Cap)', 145.00, 189.00, 20, 'Amoxicillin', '500mg', 'Capsule', true, 'PharmaGenics Ltd.'],
            ['Azithromycin 500mg Tablet', 'Generic', 'Antibiotics', 'Strip (3 Tab)', 210.00, 275.00, 15, 'Azithromycin', '500mg', 'Tablet', true, 'PharmaGenics Ltd.'],
            ['Paracetamol 500mg Tablet', 'Biogesic', 'Analgesics', 'Box (20 Tab)', 42.00, 65.00, 40, 'Paracetamol', '500mg', 'Tablet', false, 'Unilab'],
            ['Ibuprofen 400mg Tablet', 'Generic', 'Analgesics', 'Strip (10 Tab)', 38.00, 58.00, 30, 'Ibuprofen', '400mg', 'Tablet', false, 'Unilab'],
            ['Mefenamic Acid 500mg', 'Generic', 'Analgesics', 'Strip (10 Cap)', 55.00, 82.00, 25, 'Mefenamic Acid', '500mg', 'Capsule', false, 'Unilab'],
            ['Atorvastatin 20mg Tablet', 'Lipitor', 'Chronic Care', 'Strip (10 Tab)', 380.00, 465.00, 12, 'Atorvastatin', '20mg', 'Tablet', true, 'Astra-Medicals'],
            ['Metformin 500mg Tablet', 'Glucophage', 'Chronic Care', 'Box (50 Tab)', 265.00, 340.00, 15, 'Metformin', '500mg', 'Tablet', true, 'Global Care Co.'],
            ['Losartan 50mg Tablet', 'Generic', 'Chronic Care', 'Strip (10 Tab)', 95.00, 135.00, 20, 'Losartan Potassium', '50mg', 'Tablet', true, 'Global Care Co.'],
            ['Metoprolol 50mg Tablet', 'Generic', 'Chronic Care', 'Strip (10 Tab)', 88.00, 125.00, 20, 'Metoprolol', '50mg', 'Tablet', true, 'Astra-Medicals'],
            ['Salbutamol Inhaler 100mcg', 'Ventolin', 'Respiratory', 'Inhaler', 385.00, 480.00, 8, 'Salbutamol', '100mcg', 'Inhaler', true, 'GlaxoWell'],
            ['Carbocisteine 500mg', 'Generic', 'Respiratory', 'Box (20 Cap)', 120.00, 168.00, 20, 'Carbocisteine', '500mg', 'Capsule', false, 'Unilab'],
            ['Neozep Forte Tablet', 'Neozep', 'Respiratory', 'Box (20 Tab)', 96.00, 140.00, 30, 'Phenylephrine + Paracetamol', '10mg/500mg', 'Tablet', false, 'Unilab'],
            ['Ascorbic Acid 500mg', 'Enervon', 'Vitamins', 'Bottle (100 Tab)', 320.00, 415.00, 15, 'Ascorbic Acid', '500mg', 'Tablet', false, 'Unilab'],
            ['Multivitamins + Iron', 'Enervon', 'Vitamins', 'Bottle (60 Tab)', 285.00, 375.00, 12, 'Multivitamins', null, 'Tablet', false, 'Unilab'],

            // General merchandise — no medicine record, no expiry tracking.
            ['Digital Thermometer ProCheck', 'ProCheck', 'Medical Supplies', 'Piece', 165.00, 245.00, 10, null, null, null, false, null],
            ['Alcohol 70% 500ml', 'Generic', 'Personal Care', 'Bottle', 78.00, 115.00, 24, null, null, null, false, null],
            ['Surgical Face Mask', 'Generic', 'Medical Supplies', 'Box (50 pcs)', 95.00, 150.00, 20, null, null, null, false, null],
            ['Cotton Balls 100g', 'Generic', 'Medical Supplies', 'Pack', 45.00, 72.00, 15, null, null, null, false, null],
            ['Bath Soap 90g', 'Generic', 'Personal Care', 'Piece', 28.00, 45.00, 30, null, null, null, false, null],
            ['Shampoo Sachet 12ml', 'Generic', 'Personal Care', 'Sachet', 6.50, 10.00, 100, null, null, null, false, null],
        ];

        foreach ($catalog as $index => $row) {
            [$name, $brand, $category, $unit, $cost, $price, $min, $generic, $strength, $form, $rx, $manufacturer] = $row;

            // "Box (20 Tab)" is how a pharmacist writes it; the catalog needs
            // the pack size as a number it can divide a price by.
            $pieceUnit = null;
            $piecesPerBox = 1;
            $blister = null;

            if (preg_match('/^\s*(.+?)\s*\(\s*(\d+)\s*([^)]+?)\s*\)\s*$/', $unit, $parts)) {
                [, $unit, $count, $pieceUnit] = $parts;
                $piecesPerBox = (int) $count;

                // Boxes of twenty and up normally arrive as blisters of ten.
                if ($piecesPerBox > 10 && $piecesPerBox % 10 === 0) {
                    $blister = 10;
                }
            }

            $product = Product::firstOrCreate(
                ['sku' => Str::upper(Str::slug(Str::limit($name, 18, ''), '-')).'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'category_id' => $categories[$category]->id,
                    'brand_id' => $brands[$brand]->id,
                    'barcode' => (string) (4800000000000 + $index),
                    'name' => $name,
                    'unit' => $unit,
                    'piece_unit' => $pieceUnit,
                    'pieces_per_box' => $piecesPerBox,
                    'cost_price' => $cost,
                    'selling_price' => $price,
                    // Reorder levels are counted in pieces like everything else.
                    'minimum_stock' => $min * $piecesPerBox,
                    'is_batch_tracked' => $generic !== null,
                    'is_active' => true,
                ]
            );

            // A blister is a tier between the box and the loose tablet, so it
            // is a row of its own — products may carry several.
            if ($blister !== null) {
                ProductPack::firstOrCreate(
                    ['product_id' => $product->id, 'pieces' => $blister],
                    ['label' => 'Blister']
                );
            }

            if ($generic) {
                Medicine::firstOrCreate(
                    ['product_id' => $product->id],
                    [
                        'generic_name' => $generic,
                        'strength' => $strength,
                        'dosage_form' => $form,
                        'requires_prescription' => $rx,
                        'manufacturer' => $manufacturer,
                        'registration_number' => 'DR-XY'.random_int(10000, 99999),
                    ]
                );
            }
        }
    }
}
