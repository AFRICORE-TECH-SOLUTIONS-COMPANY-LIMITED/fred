<?php

use WHMCS\Database\Capsule;

/** @return array{added: list<string>, revoked: list<string>} */
function fred_zone_sync(array $params): array
{
    $response = fred_api_call($params, ['action' => 'getAllowedZones']);
    $zones = $response['data']['zones'] ?? null;
    if (($response['status'] ?? '') !== 'success' || ! is_array($zones) || array_values($zones) !== $zones) {
        throw new RuntimeException('The gateway did not return an allowed zone list.');
    }
    foreach ($zones as $zone) {
        if (! is_string($zone) || ! preg_match('/^\.[a-z0-9]+(?:\.[a-z0-9]+)*\.tz$|^\.tz$/D', $zone)) {
            throw new RuntimeException('The gateway returned an invalid zone.');
        }
    }

    $connection = Capsule::connection();
    $lock = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['fred_zone_sync']);
    if (! (int) $lock->acquired) {
        throw new RuntimeException('Fred zone sync is already running.');
    }
    try {
        $existing = [];
        foreach (Capsule::table('tbldomainpricing')->get(['extension', 'autoreg']) as $row) {
            $existing[strtolower($row->extension)] = strtolower($row->autoreg);
        }

        $added = [];
        foreach (array_diff(array_unique($zones), array_keys($existing)) as $zone) {
            $result = localAPI('CreateOrUpdateTLD', ['extension' => $zone, 'auto_registrar' => 'fred']);
            if (($result['result'] ?? '') !== 'success') {
                throw new RuntimeException('WHMCS could not add '.$zone.'.');
            }
            $added[] = $zone;
        }

        return [
            'added' => $added,
            'revoked' => array_values(array_diff(array_keys($existing, 'fred', true), $zones)),
        ];
    } finally {
        $connection->selectOne('SELECT RELEASE_LOCK(?)', ['fred_zone_sync']);
    }
}

function fred_log_zone_sync(array $report): void
{
    if ($report['added']) {
        logActivity('Fred zone sync added '.implode(', ', $report['added']).'. Set prices in Domain Pricing to enable sales.');
    }
    if ($report['revoked']) {
        logActivity('Fred zones no longer allowed for new sales: '.implode(', ', $report['revoked']).'. Review registration and transfer prices in Domain Pricing.');
    }
}

function fred_register_zone_sync_hook(): void
{
    add_hook('DailyCronJob', 1, function (): void {
        try {
            if (! function_exists('getRegistrarConfigOptions')) {
                require_once ROOTDIR.'/includes/registrarfunctions.php';
            }
            if (! function_exists('fred_api_call')) {
                require_once __DIR__.'/fred.php';
            }
            $params = getRegistrarConfigOptions('fred');
            if (is_array($params) && ! empty($params['APIKey'])) {
                fred_log_zone_sync(fred_zone_sync($params));
            }
        } catch (Throwable $exception) {
            logActivity('Fred zone sync could not complete. Review module configuration and try Sync allowed zones.');
        }
    });
}
