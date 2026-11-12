<?php

namespace WHMCS\Module\Registrar\Fred;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Resumable inventory export. The host adapter provides persistence, transport and locking.
 */
class InventoryExporter
{
    public function __construct(
        private Closure $load,
        private Closure $save,
        private Closure $fetch,
        private Closure $highWater,
        private Closure $send,
        private Closure $uuid,
    ) {}

    /** @return array<string, mixed> */
    public function run(bool $manual = false, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable)->setTimezone(new DateTimeZone('Africa/Dar_es_Salaam'));
        $slot = $now->format('Y-m-d').':'.str_pad((string) (intdiv((int) $now->format('G'), 8) * 8), 2, '0', STR_PAD_LEFT);
        $state = ($this->load)();
        if (empty($state['installationId'])) {
            $state['installationId'] = ($this->uuid)();
            ($this->save)($state);
        }
        if (empty($state['registered'])) {
            $response = ($this->send)(['action' => 'registerDomainSyncInstallation', 'installationId' => $state['installationId']]);
            if (($response['status'] ?? '') !== 'success' || ($response['data']['installationId'] ?? '') !== $state['installationId']) {
                throw new RuntimeException('Inventory source registration was not acknowledged.');
            }
            $state['registered'] = true;
            ($this->save)($state);
        }
        if (empty($state['running'])) {
            if (! $manual && ($state['slot'] ?? null) === $slot) {
                return $state;
            }
            $state = array_merge($state, [
                'slot' => $slot, 'running' => true, 'cursor' => 0, 'highWater' => ($this->highWater)(), 'pending' => null,
                'counts' => array_fill_keys(['submitted', 'imported', 'updated', 'unchanged', 'skipped', 'verification_pending'], 0),
            ]);
            ($this->save)($state);
        }
        for ($batch = 0; $batch < 2; $batch++) {
            if (empty($state['pending'])) {
                $records = ($this->fetch)($state['cursor'], $state['highWater']);
                if ($records === []) {
                    $state['running'] = false;
                    $state['completedAt'] = $now->format(DATE_ATOM);
                    ($this->save)($state);

                    return $state;
                }
                $state['pending'] = [
                    'action' => 'syncDomains', 'schemaVersion' => 1, 'mode' => 'upsert',
                    'installationId' => $state['installationId'], 'batchId' => ($this->uuid)(), 'domains' => $records,
                ];
                ($this->save)($state);
            }
            $response = ($this->send)($state['pending']);
            $this->validateAcknowledgement($state['pending'], $response);
            foreach ($response['data']['results'] as $result) {
                $state['counts']['submitted']++;
                $state['counts'][$result['status']]++;
                if (($result['verificationStatus'] ?? '') === 'pending') {
                    $state['counts']['verification_pending']++;
                }
            }
            $state['cursor'] = max(array_column($state['pending']['domains'], 'whmcsDomainId'));
            $state['lastSyncId'] = $response['data']['syncId'];
            $state['pending'] = null;
            ($this->save)($state);
        }

        return $state;
    }

    /** @param array<string, mixed> $request
     * @param  array<string, mixed>  $response
     */
    public function validateAcknowledgement(array $request, array $response): void
    {
        $results = $response['data']['results'] ?? null;
        if (($response['status'] ?? '') !== 'success' || ($response['data']['batchId'] ?? '') !== $request['batchId']
            || ! is_string($response['data']['syncId'] ?? null) || $response['data']['syncId'] === ''
            || ! is_array($results) || array_keys($results) !== array_keys($request['domains'])) {
            throw new RuntimeException('Incomplete inventory acknowledgement; batch retained for retry.');
        }
        foreach ($request['domains'] as $index => $domain) {
            $result = $results[$index];
            if (($result['whmcsDomainId'] ?? null) !== $domain['whmcsDomainId']
                || ($result['domainName'] ?? '') !== strtolower(rtrim(trim($domain['domainName']), '.'))
                || ! in_array($result['status'] ?? '', ['imported', 'updated', 'unchanged', 'skipped'], true)) {
                throw new RuntimeException('Invalid inventory acknowledgement; batch retained for retry.');
            }
        }
    }
}
