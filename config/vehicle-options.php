<?php
declare(strict_types=1);

/** Local model summaries; no remote requests or database writes are performed here. */
function agVehicleOptionNameKey(string $value): string
{
    return preg_replace('/[\s\x{00a0}\x{200b}\x{feff}]+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
}

function agVehicleOptionBrandKey(string $brand): string
{
    $key = agVehicleOptionNameKey($brand);
    $aliases = [
        '현대자동차' => '현대', 'hyundai' => '현대',
        '기아자동차' => '기아', 'kia' => '기아',
        'genesis' => '제네시스',
        '메르세데스-벤츠' => '벤츠', '메르세데스벤츠' => '벤츠', 'mercedes-benz' => '벤츠',
        '르노코리아' => '르노', '르노삼성' => '르노', 'renault' => '르노',
        'kg모빌리티' => 'kgm', 'kgmobility' => 'kgm', '쌍용' => 'kgm',
        'chevrolet' => '쉐보레', 'audi' => '아우디', 'volvo' => '볼보',
        'landrover' => '랜드로버', 'volkswagen' => '폭스바겐', 'tesla' => '테슬라',
    ];
    return $aliases[$key] ?? $key;
}

function agVehicleOptionSummary(string $brand, string $vehicle): ?array
{
    static $index = null;
    if ($index === null) {
        $index = [];
        $path = __DIR__ . '/../data/vehicle-options-official.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        foreach (($decoded['vehicles'] ?? []) as $record) {
            if (!is_array($record) || empty($record['options']) || !is_array($record['options'])) continue;
            $brands = array_merge([(string)($record['brand_name'] ?? '')], (array)($record['brand_aliases'] ?? []));
            $vehicles = array_merge([(string)($record['vehicle_name'] ?? '')], (array)($record['vehicle_aliases'] ?? []));
            foreach ($brands as $brandName) {
                foreach ($vehicles as $vehicleName) {
                    $brandKey = agVehicleOptionBrandKey((string)$brandName);
                    $vehicleKey = agVehicleOptionNameKey((string)$vehicleName);
                    if ($brandKey !== '' && $vehicleKey !== '') $index[$brandKey . '::' . $vehicleKey] = $record;
                }
            }
        }
    }
    return $index[agVehicleOptionBrandKey($brand) . '::' . agVehicleOptionNameKey($vehicle)] ?? null;
}

function agVehicleOptionSummaryItems(?array $summary): array
{
    $items = [];
    foreach (($summary['options'] ?? []) as $option) {
        if (!is_array($option) || empty($option['is_active'])) continue;
        $option['source_url'] = (string)($summary['source_url'] ?? '');
        $option['source_checked_at'] = (string)($summary['source_checked_at'] ?? '');
        $option['is_official_site_summary'] = 1;
        // Model summaries never assert a selected trim's fitment or additional price.
        $option['is_standard'] = 0;
        $option['option_price'] = 0;
        $items[] = $option;
    }
    return $items;
}

function agVehicleOptionsForTrim(array $registered, ?array $summary): array
{
    $modelOptions = agVehicleOptionSummaryItems($summary);
    if (!$registered) return ['options' => $modelOptions, 'model_options' => []];

    $registeredNames = [];
    $officialByName = [];
    foreach ($modelOptions as $option) {
        $officialByName[agVehicleOptionNameKey((string)$option['option_name'])] = $option;
    }
    foreach ($registered as &$option) {
        $name = agVehicleOptionNameKey((string)($option['option_name'] ?? $option['name'] ?? ''));
        $registeredNames[$name] = true;
        $match = $officialByName[$name] ?? null;
        if ($match) {
            if (empty($option['description'])) $option['description'] = $match['description'];
            if (empty($option['image_path'])) $option['image_path'] = $match['image_path'];
        }
    }
    unset($option);
    $additional = array_values(array_filter($modelOptions, static function (array $option) use ($registeredNames): bool {
        // An explicitly hidden registered option must not reappear as a summary card.
        return !isset($registeredNames[agVehicleOptionNameKey((string)$option['option_name'])]);
    }));
    return ['options' => $registered, 'model_options' => $additional];
}
