<?php

namespace WHMCS\Database {
    class Capsule
    {
        public static function connection(): object
        {
            return new class {
                public function selectOne(string $query, array $bindings): object
                {
                    return (object) ['acquired' => 1];
                }
            };
        }

        public static function table(string $table): object
        {
            if ($table !== 'tbldomainpricing') {
                throw new \RuntimeException('Unexpected table');
            }

            return new class {
                public function get(array $columns): array
                {
                    return $GLOBALS['domainPricing'];
                }
            };
        }
    }
}

namespace {
    function add_hook(string $name, int $priority, callable $handler): void
    {
        $GLOBALS['hooks'][$name] = $handler;
    }

    function fred_api_call(array $params, array $payload): array
    {
        check($payload === ['action' => 'getAllowedZones'], 'Gateway action');
        return $GLOBALS['gatewayResponse'];
    }

    function localAPI(string $action, array $params): array
    {
        check($action === 'CreateOrUpdateTLD', 'WHMCS action');
        check($params === ['extension' => '.or.tz', 'auto_registrar' => 'fred'], 'New zones use the Fred module key without pricing');
        $GLOBALS['domainPricing'][] = (object) ['extension' => $params['extension'], 'autoreg' => $params['auto_registrar']];
        return ['result' => 'success'];
    }

    function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    require __DIR__.'/../zone-sync.php';

    $GLOBALS['domainPricing'] = [
        (object) ['extension' => '.co.tz', 'autoreg' => 'other'],
        (object) ['extension' => '.go.tz', 'autoreg' => 'Fred'],
        (object) ['extension' => '.mil.tz', 'autoreg' => 'other'],
    ];
    $GLOBALS['gatewayResponse'] = ['status' => 'success', 'data' => ['zones' => ['.co.tz', '.or.tz']]];

    fred_register_zone_sync_hook();
    check(isset($GLOBALS['hooks']['DailyCronJob']), 'Automatic sync is registered');
    check(fred_zone_sync([]) === ['added' => ['.or.tz'], 'revoked' => ['.go.tz']], 'Adds missing zones and flags only revoked Fred zones');
    check(fred_zone_sync([]) === ['added' => [], 'revoked' => ['.go.tz']], 'Repeat sync does not change existing zones');
    $GLOBALS['gatewayResponse'] = ['status' => 'error'];

    try {
        fred_zone_sync([]);
        throw new LogicException('A failed gateway response must abort sync');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() === 'The gateway did not return an allowed zone list.', 'Gateway error is surfaced');
    }

    echo "Fred allowed-zone sync checks passed.\n";
}
