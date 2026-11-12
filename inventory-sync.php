<?php

use WHMCS\Database\Capsule;
use WHMCS\Module\Registrar\Fred\InventoryExporter;

require_once __DIR__.'/lib/InventoryExporter.php';

/** @return array<string, mixed> */
function fred_inventory_sync(array $params, bool $manual = false): array
{
    if (empty($params['InventorySync'])) {
        return ['enabled' => false];
    }
    $connection = Capsule::connection();
    $locked = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['fred_inventory_sync']);
    if (! (int) $locked->acquired) {
        return ['running' => true];
    }
    try {
        $exporter = new InventoryExporter(
            fn (): array => json_decode((string) Capsule::table('tblconfiguration')->where('setting', 'FredInventoryState')->value('value'), true) ?: [],
            function (array $state): void {
                Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'FredInventoryState'], ['value' => json_encode($state, JSON_THROW_ON_ERROR)]);
            },
            fn (int $cursor, int $highWater): array => Capsule::table('tbldomains')->where('registrar', 'fred')->where('id', '>', $cursor)->where('id', '<=', $highWater)->orderBy('id')->limit(50)
                ->get(['id', 'domain', 'status', 'registrationdate', 'expirydate', 'nextduedate'])->map(fn ($domain): array => [
                    'whmcsDomainId' => (int) $domain->id, 'domainName' => $domain->domain, 'whmcsStatus' => $domain->status,
                    'registrationDate' => $domain->registrationdate, 'expiryDate' => $domain->expirydate, 'nextDueDate' => $domain->nextduedate,
                ])->all(),
            fn (): int => (int) Capsule::table('tbldomains')->where('registrar', 'fred')->max('id'),
            fn (array $payload): array => fred_api_call($params, $payload),
            function (): string {
                $bytes = random_bytes(16);
                $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
                $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
                $hex = bin2hex($bytes);

                return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
            },
        );
        $state = $exporter->run($manual);

        return ['enabled' => true, 'running' => $state['running'] ?? false, 'counts' => $state['counts'] ?? [], 'lastSyncId' => $state['lastSyncId'] ?? null];
    } finally {
        $connection->selectOne('SELECT RELEASE_LOCK(?)', ['fred_inventory_sync']);
    }
}

/**
 * System cron claims one complete export per eight-hour slot and resumes failed batches.
 * Sponsorship and assignment checks are enforced by the gateway's read-only verifier.
 */
add_hook('AfterCronJob', 1, function (): void {
    try {
        if (! function_exists('getRegistrarConfigOptions')) {
            require_once ROOTDIR.'/includes/registrarfunctions.php';
        }
        if (! function_exists('fred_api_call')) {
            require_once __DIR__.'/fred.php';
        }
        $params = getRegistrarConfigOptions('fred');
        if (! is_array($params) || empty($params['InventorySync'])) {
            return;
        }
        fred_inventory_sync($params);
    } catch (\Throwable) {
        logActivity('FRED inventory sync paused; saved progress is retained for the next cron retry.');
    }
});
