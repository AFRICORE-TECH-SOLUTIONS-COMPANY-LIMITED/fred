<?php

namespace WHMCS\Module\Registrar\Fred {
    function curl_init(): object { return (object) ['options' => []]; }
    function curl_setopt(object $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
    function curl_exec(object $handle): string
    {
        $payload = json_decode($handle->options[CURLOPT_POSTFIELDS], true);
        $GLOBALS['requests'][] = $payload;
        if ($payload['action'] === 'registerDomainSyncInstallation') {
            return json_encode(['status' => 'success', 'data' => ['installationId' => $payload['installationId']]]);
        }
        return json_encode(['status' => 'success', 'data' => ['syncId' => 'sync-id', 'batchId' => $payload['batchId'], 'results' => array_map(fn ($record) => [
            'whmcsDomainId' => $record['whmcsDomainId'], 'domainName' => strtolower(rtrim($record['domainName'], '.')), 'status' => 'imported', 'verificationStatus' => 'pending',
        ], $payload['domains'])]]);
    }
    function curl_errno(object $handle): int { return 0; }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace WHMCS\Database {
    class Capsule
    {
        public static array $domains = [];
        public static ?string $state = null;
        public static bool $locked = false;
        public static function table(string $table): \InventoryTestQuery { return new \InventoryTestQuery($table); }
        public static function connection(): object
        {
            return new class {
                public function selectOne(string $sql, array $bindings): object
                {
                    if (str_contains($sql, 'RELEASE_LOCK')) { return (object) ['released' => 1]; }
                    return (object) ['acquired' => Capsule::$locked ? 0 : 1];
                }
            };
        }
    }
}

namespace {
    use WHMCS\Database\Capsule;
    use WHMCS\Module\Registrar\Fred\InventoryExporter;

    define('WHMCS', true);
    $GLOBALS['hooks'] = $GLOBALS['requests'] = $GLOBALS['logs'] = [];
    function add_hook(string $name, int $priority, Closure $callback): void { $GLOBALS['hooks'][$name] = $callback; }
    function logModuleCall(...$args): void { $GLOBALS['logs'][] = $args; }
    function logActivity(string $message): void { $GLOBALS['logs'][] = $message; }
    function getRegistrarConfigOptions(string $module): array
    {
        check($module === 'fred', 'Configuration must use the installed fred module identifier');
        return ['APIKey' => 'private-key', 'APIEndpoint' => 'https://gateway.example/api/v1/epp', 'InventorySync' => 'on'];
    }
    class InventoryTestRows
    {
        public function __construct(private array $rows) {}
        public function map(Closure $callback): self { return new self(array_map($callback, $this->rows)); }
        public function all(): array { return $this->rows; }
    }
    class InventoryTestQuery
    {
        private array $conditions = [];
        private int $limit = 50;
        public function __construct(private string $table) {}
        public function where(string $column, mixed $operator, mixed $value = null): self
        {
            $this->conditions[] = [$column, func_num_args() === 2 ? '=' : $operator, func_num_args() === 2 ? $operator : $value];
            return $this;
        }
        public function orderBy(string $column): self { return $this; }
        public function limit(int $limit): self { $this->limit = $limit; return $this; }
        private function rows(): array
        {
            return array_values(array_filter(Capsule::$domains, function ($row): bool {
                foreach ($this->conditions as [$column, $operator, $value]) {
                    $actual = $row->{$column};
                    if (! match ($operator) { '=' => $actual === $value, '>' => $actual > $value, '<=' => $actual <= $value }) { return false; }
                }
                return true;
            }));
        }
        public function max(string $column): int { return max([0, ...array_map(fn ($row) => $row->{$column}, $this->rows())]); }
        public function get(array $columns): InventoryTestRows { return new InventoryTestRows(array_slice($this->rows(), 0, $this->limit)); }
        public function value(string $column): ?string { return Capsule::$state; }
        public function updateOrInsert(array $keys, array $values): void { Capsule::$state = $values['value']; }
    }
    function check(bool $condition, string $message): void
    {
        if (! $condition) { throw new RuntimeException($message); }
    }
    require_once __DIR__.'/../fred.php';

    $passed = 0;
    $cases = [];
    $cases['three daily slots and old record refresh'] = function (): void {
        $state = [];
        $requests = [];
        $records = [['whmcsDomainId' => 1, 'domainName' => 'example.co.tz', 'whmcsStatus' => 'Expired']];
        $exporter = exporter($state, $requests, $records);
        foreach (['00:00', '00:05', '08:00', '08:05', '16:00', '16:05'] as $time) {
            $exporter->run(false, new DateTimeImmutable('2026-10-30 '.$time, new DateTimeZone('Africa/Dar_es_Salaam')));
        }
        check(count($requests) === 4, 'Expected registration plus exactly three exports');
        check($state['counts']['verification_pending'] === 1, 'Pending verification must be reported separately');
        $records[0]['whmcsStatus'] = 'Active';
        $exporter->run(false, new DateTimeImmutable('2026-10-31 00:00:00', new DateTimeZone('Africa/Dar_es_Salaam')));
        check($requests[4]['domains'][0]['whmcsStatus'] === 'Active', 'Later runs must revisit old IDs');
    };
    foreach (['lost', 'incomplete', 'reordered', 'id', 'domain', 'status'] as $fault) {
        $cases['retains pending batch after '.$fault.' acknowledgement'] = function () use ($fault): void {
            $state = $requests = [];
            $records = [['whmcsDomainId' => 1, 'domainName' => 'example.co.tz'], ['whmcsDomainId' => 2, 'domainName' => 'other.co.tz']];
            $exporter = exporter($state, $requests, $records, $fault);
            $failed = false;
            try { $exporter->run(); } catch (RuntimeException) { $failed = true; }
            check($failed && $state['cursor'] === 0 && $state['pending'] !== null, 'Unacknowledged work must retain its payload and cursor');
            $pending = $state['pending'];
            $exporter = exporter($state, $requests, $records);
            $exporter->run();
            check($requests[1] === $requests[2] && $requests[2] === $pending, 'Retry must reuse the exact original batch');
            check($state['cursor'] === 2, 'Cursor advances only after a complete acknowledgement');
        };
    }
    $cases['installed fred adapter exports all statuses and logs no inventories'] = function (): void {
        Capsule::$state = null;
        $GLOBALS['requests'] = $GLOBALS['logs'] = [];
        Capsule::$domains = [
            (object) ['id' => 1, 'registrar' => 'fred', 'domain' => 'expired.co.tz', 'status' => 'Expired', 'registrationdate' => '2020-01-01', 'expirydate' => '2025-01-01', 'nextduedate' => '2025-01-01'],
            (object) ['id' => 2, 'registrar' => 'other', 'domain' => 'excluded.co.tz', 'status' => 'Active'],
        ];
        ($GLOBALS['hooks']['AfterCronJob'])();
        check($GLOBALS['requests'][1]['domains'][0]['domainName'] === 'expired.co.tz', 'Expired inventory assigned to fred must be exported');
        check(count($GLOBALS['requests'][1]['domains']) === 1, 'Other module inventories must be excluded');
        check(! str_contains(json_encode($GLOBALS['logs']), 'private-key') && ! str_contains(json_encode($GLOBALS['logs']), 'expired.co.tz'), 'Logs must exclude credentials and inventories');
        check(! isset($GLOBALS['hooks']['DailyCronJob']), 'Daily-only hook must be removed');
        check(fred_getConfigArray()['InventorySync']['Default'] === '', 'Automation must remain opt-in');
    };
    $cases['disabled and overlapping runs do not contact the gateway'] = function (): void {
        $GLOBALS['requests'] = [];
        fred_inventory_sync(['InventorySync' => '']);
        Capsule::$locked = true;
        fred_inventory_sync(getRegistrarConfigOptions('fred'));
        Capsule::$locked = false;
        check($GLOBALS['requests'] === [], 'Disabled or overlapping runs must not send any requests');
    };
    $cases['manual sync requires administrator access'] = function (): void {
        $_SESSION = [];
        check(isset(fred_SyncInventory(getRegistrarConfigOptions('fred'))['error']), 'Manual action requires administrator session');
        $_SESSION['adminid'] = 1;
        check(isset(fred_SyncInventory(['InventorySync' => ''])['error']), 'Manual action must explain disabled synchronization');
    };

    function exporter(array &$state, array &$requests, array &$records, ?string $fault = null): InventoryExporter
    {
        return new InventoryExporter(
            function () use (&$state): array { return $state; },
            function (array $saved) use (&$state): void { $state = $saved; },
            function (int $cursor, int $highWater) use (&$records): array { return array_values(array_filter($records, fn ($record) => $record['whmcsDomainId'] > $cursor && $record['whmcsDomainId'] <= $highWater)); },
            function () use (&$records): int { return max(array_column($records, 'whmcsDomainId')); },
            function (array $request) use (&$requests, $fault): array {
                $requests[] = $request;
                if ($request['action'] === 'registerDomainSyncInstallation') { return ['status' => 'success', 'data' => ['installationId' => $request['installationId']]]; }
                $results = array_map(fn ($record) => ['whmcsDomainId' => $record['whmcsDomainId'], 'domainName' => $record['domainName'], 'status' => 'imported', 'verificationStatus' => 'pending'], $request['domains']);
                if ($fault === 'lost') { throw new RuntimeException('Lost acknowledgement'); }
                if ($fault === 'incomplete') { $results = []; }
                if ($fault === 'reordered') { $results = array_reverse($results); }
                if ($fault === 'id') { $results[0]['whmcsDomainId'] = 99; }
                if ($fault === 'domain') { $results[0]['domainName'] = 'else.co.tz'; }
                if ($fault === 'status') { $results[0]['status'] = 'pending'; }
                return ['status' => 'success', 'data' => ['syncId' => 'sync-id', 'batchId' => $request['batchId'], 'results' => $results]];
            },
            fn (): string => bin2hex(random_bytes(16)),
        );
    }
    foreach ($cases as $name => $case) {
        $case();
        echo 'PASS '.$name.PHP_EOL;
        $passed++;
    }
    echo $passed.' inventory tests passed.'.PHP_EOL;
}
