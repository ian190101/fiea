<?php
declare(strict_types=1);

namespace App\Services\Finance;

use InvalidArgumentException;

final class BudgetCatalog
{
    /** @return array<int, array{catalog_key:string,description_en:string,description_es:?string,section:string,bucket:string,arranged_eligible:bool}> */
    public function forType(string $type): array
    {
        $rows = match ($type) {
            'IC' => [
                ['ic.bus_quito', 'Bus Rental - Quito Tour all day', 'Transportation', 'dr', true],
                ['ic.airport_pickup', 'Airport Pickup', 'Transportation', 'dr', true],
                ['ic.car_community', 'Car Rental All Day - Community', 'Transportation', 'dr', true],
                ['ic.fuel', 'Fuel', 'Transportation', 'dr', false],
                ['ic.community_transport', 'Transportation In & Out of the Community Fee', 'Transportation', 'dr', true],
                ['ic.toll', 'Toll', 'Transportation', 'dr', false],
                ['ic.nissan', 'EIA Vehicle Rent - Nissan', 'Transportation', 'dr', false],
                ['ic.driver', 'Driver', 'Services', 'dr', true],
                ['ic.translator', 'Translator', 'Services', 'dr', true],
                ['ic.mason', 'Mason', 'Services', 'dr', true],
                ['ic.hotel', 'Hotel', 'Lodging', 'dr', false],
                ['ic.hotel_staff', 'Hotel - EIA Staff', 'Lodging', 'dr', false],
                ['ic.hotel_quito', 'Hotel in Quito', 'Lodging', 'dr', false],
                ['ic.food_3', 'On Site Food 3/3', 'Food', 'dr', false],
                ['ic.food_2', 'On Site Food 2/3', 'Food', 'dr', false],
                ['ic.food_1', 'On Site Food 1/3', 'Food', 'dr', false],
                ['ic.staff_perdiem', 'Perdiem for EIA Staff', 'Food', 'dr', false],
                ['ic.topography', 'Topographic Service', 'Miscellaneous', 'dr', true],
                ['ic.water_lab', 'Laboratory water testing analysis', 'Miscellaneous', 'dr', true],
                ['ic.emergency_car', 'EIA Rental Car - Emergency', 'EIA Services', 'own', false],
                ['ic.engineering', 'Engineering and Community Development Services', 'EIA Services', 'own', false],
                ['ic.staff_driver', 'Driver - FIEA Staff', 'EIA Services', 'own', false],
                ['ic.staff_translator', 'Translator - FIEA Staff', 'EIA Services', 'own', false],
                ['ic.cellphone', 'Cellphone', 'EIA Services', 'own', false],
                ['ic.health', 'Health Promoter Services', 'EIA Services', 'own', false],
                ['ic.water_equipment', 'EIA Water Testing Equipment', 'EIA Services', 'own', false],
            ],
            'MAT' => [
                ['mat.material.custom', 'Material', 'Materials', 'materials', false],
                ['mat.transport.custom', 'Transportation of materials to community', 'Transportation', 'transport', false],
            ],
            default => throw new InvalidArgumentException('Tipo de catálogo inválido.'),
        };

        return array_map(static fn (array $row): array => [
            'catalog_key' => $row[0],
            'description_en' => $row[1],
            'description_es' => null,
            'section' => $row[2],
            'bucket' => $row[3],
            'arranged_eligible' => $row[4],
        ], $rows);
    }

    /** @return array{catalog_key:null,description_en:string,description_es:null,section:string,bucket:string,arranged_eligible:bool} */
    public function customMiscellaneous(string $description): array
    {
        return [
            'catalog_key' => null,
            'description_en' => $description,
            'description_es' => null,
            'section' => 'Miscellaneous',
            'bucket' => 'dr',
            'arranged_eligible' => true,
        ];
    }
}
