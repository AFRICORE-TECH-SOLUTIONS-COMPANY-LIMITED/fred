<?php

/**
 * FRED EPP Registrar Module for WHMCS
 *
 * Registrar Modules allow you to create modules that allow for domain
 * registration, management, transfers, and other functionality within
 * WHMCS.
 *
 * This module integrates with the FRED EPP API to provide domain registration
 * and management capabilities for .tz and other domains.
 *
 * Registrar Modules are stored in a unique directory within the
 * modules/registrars/ directory that matches the module's unique name.
 * This name should be all lowercase, containing only letters and numbers,
 * and always start with a letter.
 *
 * Within the module itself, all functions must be prefixed with the module
 * filename, followed by an underscore, and then the function name. For
 * example this file, the filename is "fred.php" and therefore all
 * functions begin "fred_".
 *
 * For more information, please refer to the online documentation.
 *
 * @see https://developers.whmcs.com/domain-registrars/
 *
 * @copyright Copyright (c) 2025 FRED EPP API
 * @license https://www.whmcs.com/license/ WHMCS Eula
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/inventory-sync.php';
require_once __DIR__ . '/zone-sync.php';

use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;
use WHMCS\Module\Registrar\Fred\ApiClient;

const FRED_DEFAULT_API_ENDPOINT = 'https://fred.umojasolutions.co.tz/api/v1/epp';

/**
 * Return the full domain name in a consistent, safe format for API calls.
 */
function fred_domain_name($params)
{
    return strtolower(trim($params['sld'] . '.' . ltrim($params['tld'], '.')));
}

/**
 * Normalize the API endpoint so older saved module settings use the new gateway.
 */
function fred_api_endpoint($params)
{
    $endpoint = trim((string) ($params['APIEndpoint'] ?? ''));

    if ($endpoint === '') {
        return FRED_DEFAULT_API_ENDPOINT;
    }

    return rtrim($endpoint, '/');
}

/**
 * Send a JSON action request to the FRED gateway.
 */
function fred_api_call($params, array $requestData)
{
    $apiKey = trim((string) ($params['APIKey'] ?? ''));
    $requestData['api_key'] = $apiKey;

    // The client sends the key as X-API-Key and keeps it in JSON for action compatibility.
    $api = new ApiClient(fred_api_endpoint($params), $apiKey);

    return $api->call($requestData);
}

/**
 * Convert a FRED API success response into WHMCS' simple success/error style.
 */
function fred_success_or_error($response, $fallbackMessage)
{
    if (($response['status'] ?? '') === 'success') {
        return ['success' => true];
    }

    return ['error' => $response['message'] ?? $fallbackMessage];
}

/**
 * The new gateway sends EPP Code to contacts instead of revealing the raw code.
 */
function fred_auth_code_message(array $data)
{
    if (!empty($data['message'])) {
        return str_replace('AuthInfo', 'EPP Code', $data['message']);
    }

    if (!empty($data['sent'])) {
        return 'EPP code request accepted. The registry will send it to the domain contacts.';
    }

    return 'EPP code request accepted.';
}

/**
 * Ask the FRED gateway to send the domain EPP code to domain contacts.
 */
function fred_send_epp_code_request($params)
{
    return fred_api_call($params, [
        'action' => 'getEPPCode',
        'domainName' => fred_domain_name($params),
    ]);
}

/**
 * Native WHMCS GetEPPCode function.
 * Since FRED does not return the EPP code to the registrar directly,
 * we just request the registry to send it and return a message.
 */
function fred_GetEPPCode($params)
{
    try {
        $response = fred_send_epp_code_request($params);
        if (($response['status'] ?? '') === 'success') {
            return [
                'eppcode' => 'The EPP code has been generated and sent to the domain contacts by the registry.',
            ];
        } else {
            return [
                'error' => $response['message'] ?? 'Failed to request EPP code.',
            ];
        }
    } catch (\Exception $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Ask the FRED gateway for the saved child nameserver nsset.
 */
function fred_get_child_nameservers_request($params, $nssetId = null)
{
    $requestData = [
        'action' => 'getChildNameservers',
        'domainName' => fred_domain_name($params),
    ];

    if (!empty($nssetId)) {
        // The Laravel controller accepts nssetId/nsset, not child_nsset_id.
        $requestData['nssetId'] = $nssetId;
    }

    return fred_api_call($params, $requestData);
}

/**
 * Create or update the saved child nameserver nsset without changing delegation.
 */
function fred_save_child_nameservers_request($params, array $nameservers, $nssetId = null)
{
    $requestData = [
        'action' => 'createChildNameservers',
        'domainName' => fred_domain_name($params),
        'nameservers' => $nameservers,
    ];

    if (!empty($nssetId)) {
        $requestData['nssetId'] = $nssetId;
    }

    return fred_api_call($params, $requestData);
}

/**
 * Apply the saved child nameserver nsset as the domain's active delegation.
 */
function fred_use_child_nameservers_request($params, $nssetId = null)
{
    $requestData = [
        'action' => 'useChildNameservers',
        'domainName' => fred_domain_name($params),
    ];

    if (!empty($nssetId)) {
        $requestData['nssetId'] = $nssetId;
    }

    return fred_api_call($params, $requestData);
}

/**
 * Location for small module metadata that WHMCS does not natively store per domain.
 */
function fred_storage_file()
{
    return __DIR__ . '/fred_storage.php';
}

/**
 * Read safe module metadata from disk.
 */
function fred_storage_read()
{
    $file = fred_storage_file();
    if (!is_readable($file)) {
        $legacyFile = __DIR__ . '/fred_storage.json';
        if (!is_readable($legacyFile)) {
            return [];
        }

        // Older builds used JSON. Read it once so upgrades do not lose nsset handles.
        $legacyData = json_decode((string) file_get_contents($legacyFile), true);

        return is_array($legacyData) ? $legacyData : [];
    }

    $contents = (string) file_get_contents($file);
    $json = preg_replace('/^<\?php exit; \?>\s*/', '', $contents);
$data = json_decode((string) $json, true);

return is_array($data) ? $data : [];
}

/**
* Save module metadata with file locking to avoid concurrent write corruption.
*/
function fred_storage_write(array $data)
{
$file = fred_storage_file();
$contents = "<?php exit; ?>\n" . json_encode($data, JSON_PRETTY_PRINT);
file_put_contents($file, $contents, LOCK_EX);

// Keep saved nsset metadata private on shared hosting where possible.
if (function_exists('chmod')) {
@chmod($file, 0600);
}
}

/**
* Remember the child nsset handle returned by FRED for the domain.
*/
function fred_get_saved_child_nsset_id($params)
{
$data = fred_storage_read();
$domainName = fred_domain_name($params);

return (string) ($data['domains'][$domainName]['child_nsset_id'] ?? '');
}

/**
* Store the child nsset handle for later reads, similar to Blesta's service field.
*/
function fred_save_child_nsset_id($params, $nssetId)
{
$nssetId = trim((string) $nssetId);
if ($nssetId === '') {
return;
}

$data = fred_storage_read();
$domainName = fred_domain_name($params);
$data['domains'][$domainName]['child_nsset_id'] = $nssetId;
fred_storage_write($data);
}

/**
* Read locally remembered child nameservers for WHMCS' one-at-a-time form.
*/
function fred_get_saved_child_nameservers($params)
{
$data = fred_storage_read();
$domainName = fred_domain_name($params);
$nameservers = $data['domains'][$domainName]['child_nameservers'] ?? [];

return is_array($nameservers) ? $nameservers : [];
}

/**
* Save child nameserver rows so WHMCS can build a full FRED nsset over time.
*/
function fred_save_child_nameservers_locally($params, array $nameservers)
{
$data = fred_storage_read();
$domainName = fred_domain_name($params);
$data['domains'][$domainName]['child_nameservers'] = array_values($nameservers);
fred_storage_write($data);
}

/**
* Merge child nameservers by hostname and keep the newest glue IP address.
*/
function fred_merge_child_nameserver(array $nameservers, $hostname, $ipAddress)
{
$byName = [];
foreach ($nameservers as $nameserver) {
if (!empty($nameserver['name'])) {
$byName[strtolower((string) $nameserver['name'])] = $nameserver;
}
}

$byName[strtolower((string) $hostname)] = [
'name' => (string) $hostname,
'addresses' => [(string) $ipAddress],
];

return array_values($byName);
}

/**
* Create or reuse a short-lived session token for the EPP send button.
*/
function fred_epp_button_token()
{
if (session_status() === PHP_SESSION_NONE) {
session_start();
}

if (empty($_SESSION['fred_epp_button_token'])) {
$_SESSION['fred_epp_button_token'] = bin2hex(random_bytes(16));
}

return $_SESSION['fred_epp_button_token'];
}

/**
* Make sure the send button was submitted by the current client session.
*/
function fred_valid_epp_button_token($token)
{
if (session_status() === PHP_SESSION_NONE) {
session_start();
}

return !empty($_SESSION['fred_epp_button_token']) &&
is_string($token) &&
hash_equals($_SESSION['fred_epp_button_token'], $token);
}

/**
* Build the WHMCS custom page response for the Get EPP Code page.
*/
function fred_epp_page_response($params, $message = null, $type = 'info')
{
$domainId = (int) ($params['domainid'] ?? 0);
$url = 'clientarea.php?action=domaindetails&domainid=' . $domainId . '&modop=custom&a=eppcodepage';

return [
'templatefile' => 'eppcodepage',
'breadcrumb' => [
$url => 'Get EPP Code',
],
'vars' => [
'domainid' => $domainId,
'domain' => fred_domain_name($params),
'message' => $message,
'messageType' => $type,
'eppToken' => fred_epp_button_token(),
],
];
}

/**
* Convert a child host label into a safe fully-qualified hostname.
*/
function fred_child_hostname($value, $domainName)
{
$value = strtolower(trim((string) $value));
$domainName = strtolower(trim((string) $domainName));

if ($value === '' || preg_match('/[<>"\'\s]/', $value)) {
    throw new \InvalidArgumentException('Child nameserver hostnames cannot be empty or contain spaces.');
    }

    if (strpos($value, '.') === false) {
    $value .= '.' . $domainName;
    }

    if ($value !== $domainName && substr($value, -strlen('.' . $domainName)) !== '.' . $domainName) {
    throw new \InvalidArgumentException('Child nameservers must be inside ' . $domainName . '. Use labels like ns1 or
    ns2.');
    }

    if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
    throw new \InvalidArgumentException('Invalid child nameserver hostname: ' . $value);
    }

    return $value;
    }

    /**
    * Read child nameserver rows from a WHMCS form.
    */
    function fred_child_nameservers_from_post($params)
    {
    $domainName = fred_domain_name($params);
    $hosts = $_POST['child_host'] ?? [];
    $ips = $_POST['child_ip'] ?? [];
    $nameservers = [];

    foreach ($hosts as $index => $host) {
    $host = trim((string) $host);
    $ip = trim((string) ($ips[$index] ?? ''));

    if ($host === '' && $ip === '') {
    continue;
    }

    $hostname = fred_child_hostname($host, $domainName);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    throw new \InvalidArgumentException('Invalid glue IP address for ' . $hostname . '.');
    }

    $nameservers[] = [
    'name' => $hostname,
    'addresses' => [$ip],
    ];
    }

    if (count($nameservers) < 2 || count($nameservers)> 10) {
        throw new \InvalidArgumentException('The registry requires 2 to 10 child nameservers with glue IP addresses.');
        }

        return $nameservers;
        }

        /**
        * Display a child hostname as a short label when it belongs to the domain.
        */
        function fred_child_label_from_hostname($hostname, $domainName)
        {
        $hostname = strtolower(trim((string) $hostname));
        $suffix = '.' . strtolower(trim((string) $domainName));

        if ($hostname !== '' && substr($hostname, -strlen($suffix)) === $suffix) {
        return substr($hostname, 0, -strlen($suffix));
        }

        return $hostname;
        }

        /**
        * Build the WHMCS custom page response for child nameservers.
        */
        function fred_child_nameserver_page_response(
        $params,
        array $nameservers = [],
        $nssetId = '',
        $message =
        null,
        $type = 'info'
        ) {
        $domainId = (int) ($params['domainid'] ?? 0);
        $domainName = fred_domain_name($params);
        $url = 'clientarea.php?action=domaindetails&domainid=' . $domainId . '&modop=custom&a=childnameservers';
        $rows = [];

        for ($i = 0; $i < 5; $i++) { $nameserver=$nameservers[$i] ?? []; $addressList=$nameserver['addresses'] ?? [];
            $rows[]=[ 'number'=> $i + 1,
            'host' => fred_child_label_from_hostname($nameserver['name'] ?? '', $domainName),
            'ip' => (string) ($addressList[0] ?? ''),
            ];
            }

            return [
            'templatefile' => 'childnameservers',
            'breadcrumb' => [
            $url => 'Child Nameservers',
            ],
            'vars' => [
            'domainid' => $domainId,
            'domain' => $domainName,
            'message' => $message,
            'messageType' => $type,
            'childNssetId' => (string) $nssetId,
            'childNameservers' => $rows,
            ],
            ];
            }

            /**
            * Split a contact's full name into WHMCS first/last name fields.
            */
            function fred_split_name($name)
            {
            $parts = preg_split('/\s+/', trim((string) $name), 2);

            return [
            $parts[0] ?? '',
            $parts[1] ?? '',
            ];
            }

            /**
            * Map gateway contact data into the labels WHMCS displays on the WHOIS screen.
            */
            function fred_whmcs_contact(array $contact)
            {
            [$firstName, $lastName] = fred_split_name($contact['name'] ?? '');

            return [
            'First Name' => $contact['firstName'] ?? $firstName,
            'Last Name' => $contact['lastName'] ?? $lastName,
            'Company Name' => $contact['companyName'] ?? $contact['organization'] ?? '',
            'Email Address' => $contact['email'] ?? '',
            'Address 1' => $contact['address1'] ?? $contact['address'] ?? '',
            'Address 2' => $contact['address2'] ?? '',
            'City' => $contact['city'] ?? '',
            'State' => $contact['state'] ?? '',
            'Postcode' => $contact['postcode'] ?? $contact['postalCode'] ?? '',
            'Country' => $contact['country'] ?? '',
            'Phone Number' => $contact['phone'] ?? '',
            ];
            }

            /**
            * Define module related metadata
            *
            * Provide some module information including the display name and API Version to
            * determine the method of decoding the input values.
            *
            * @return array
            */
            function fred_MetaData()
            {
            return array(
            'DisplayName' => 'Fred',
            'APIVersion' => '1.1',
            );
            }

            /**
            * Define registrar configuration options.
            *
            * The values you return here define what configuration options
            * we store for the module. These values are made available to
            * each module function.
            *
            * You can store an unlimited number of configuration settings.
            * The following field types are supported:
            * * Text
            * * Password
            * * Yes/No Checkboxes
            * * Dropdown Menus
            * * Radio Buttons
            * * Text Areas
            *
            * @return array
            */
            function fred_getConfigArray()
            {
            return [
            // Friendly display name for the module
            'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Fred',
            ],
            // API Key for authentication with the FRED EPP API
            'APIKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your FRED EPP API key for authentication',
            ],
            // API Endpoint URL
            'InventorySync' => [
                'FriendlyName' => 'Inventory synchronization',
                'Type' => 'yesno',
                'Default' => '',
                'Description' => 'Export inventory at 00:00, 08:00 and 16:00 Tanzania time. Requires the system cron every five minutes and compatible gateway support.',
            ],
            'APIEndpoint' => [
            'FriendlyName' => 'API Endpoint',
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://fred.umojasolutions.co.tz/api/v1/epp',
            'Description' => 'The FRED API endpoint URL',
            ],
            ];
            }

            /**
            * Register a domain.
            *
            * Attempt to register a domain with the domain registrar.
            *
            * This is triggered when the following events occur:
            * * Payment received for a domain registration order
            * * When a pending domain registration order is accepted
            * * Upon manual request by an admin user
            *
            * @param array $params common module parameters
            *
            * @see https://developers.whmcs.com/domain-registrars/module-parameters/
            *
            * @return array
            */
            function fred_RegisterDomain($params)
            {
            // user defined configuration values
            $apiKey = $params['APIKey'];

            // registration parameters
            $sld = $params['sld'];
            $tld = $params['tld'];
            $registrationPeriod = $params['regperiod'];

            /** Nameservers. */
            $nameserver1 = $params['ns1'];
            $nameserver2 = $params['ns2'];
            $nameserver3 = $params['ns3'];
            $nameserver4 = $params['ns4'];
            $nameserver5 = $params['ns5'];

            // registrant information
            $firstName = $params['firstname'];
            $lastName = $params['lastname'];
            $companyName = $params['companyname'];
            $email = $params['email'];
            $address1 = $params['address1'];
            $address2 = $params['address2'];
            $city = $params['city'];
            $state = $params['state'];
            $postcode = $params['postcode'];
            $countryCode = $params['countrycode'];
            $phoneNumberFormatted = $params['fullphonenumber'];

            /** Admin contact information. */
            $adminFirstName = $params['adminfirstname'];
            $adminLastName = $params['adminlastname'];
            $adminCompanyName = $params['admincompanyname'];
            $adminEmail = $params['adminemail'];
            $adminAddress1 = $params['adminaddress1'];
            $adminAddress2 = $params['adminaddress2'];
            $adminCity = $params['admincity'];
            $adminState = $params['adminstate'];
            $adminPostcode = $params['adminpostcode'];
            $adminCountry = $params['admincountry'];
            $adminPhoneNumberFormatted = $params['adminfullphonenumber'];

            // domain addon purchase status
            $enableDnsManagement = (bool) $params['dnsmanagement'];
            $enableEmailForwarding = (bool) $params['emailforwarding'];
            $enableIdProtection = (bool) $params['idprotection'];

            /** Premium domain parameters. */
            $premiumDomainsEnabled = (bool) $params['premiumEnabled'];
            $premiumDomainsCost = $params['premiumCost'];

            // Create registrant info in the format expected by the API
            $registrantInfo = [
            'name' => trim($firstName . ' ' . $lastName),
            'organization' => $companyName,
            'address' => $address1 . (empty($address2) ? '' : "\n" . $address2),
            'city' => $city,
            'state' => $state,
            'postalCode' => $postcode,
            'country' => $countryCode,
            'email' => $email,
            'phone' => $phoneNumberFormatted,
            ];

            // Use the admin contact supplied by WHMCS when available.
            $adminInfo = [
            'name' => trim(($adminFirstName ?: $firstName) . ' ' . ($adminLastName ?: $lastName)),
            'organization' => $adminCompanyName ?: $companyName,
            'address' => ($adminAddress1 ?: $address1) . (empty($adminAddress2) ? '' : "\n" . $adminAddress2),
            'city' => $adminCity ?: $city,
            'state' => $adminState ?: $state,
            'postalCode' => $adminPostcode ?: $postcode,
            'country' => $adminCountry ?: $countryCode,
            'email' => $adminEmail ?: $email,
            'phone' => $adminPhoneNumberFormatted ?: $phoneNumberFormatted,
            ];

            // Build request data according to the FRED EPP API documentation
            $requestData = array(
            'api_key' => $apiKey,
            'action' => 'registerDomain',
            'domainName' => fred_domain_name($params),
            'period' => $registrationPeriod,
            'nameservers' => array_filter([
            $nameserver1,
            $nameserver2,
            $nameserver3,
            $nameserver4,
            $nameserver5
            ]),
            'registrantInfo' => $registrantInfo,
            'adminInfo' => $adminInfo,
            'addons' => array(
            'dnsManagement' => $enableDnsManagement,
            'emailForwarding' => $enableEmailForwarding,
            'idProtection' => $enableIdProtection,
            ),
            );

            if ($premiumDomainsEnabled && $premiumDomainsCost) {
            $requestData['premium'] = [
            'isPremium' => true,
            'acceptedPrice' => $premiumDomainsCost
            ];
            }

            try {
            // Send the request through the shared gateway client.
            $response = fred_api_call($params, $requestData);

            // Process the response
            if ($response['status'] === 'success') {
            return array(
            'success' => true,
            );
            } else {
            return array(
            'error' => $response['message'] ?? 'Unknown error occurred',
            );
            }
            } catch (\Exception $e) {
            return array(
            'error' => $e->getMessage(),
            );
            }
            }

            /**
            * Initiate domain transfer.
            *
            * Attempt to create a domain transfer request for a given domain.
            *
            * @param array $params common module parameters
            *
            * @see https://developers.whmcs.com/domain-registrars/module-parameters/
            *
            * @return array
            */
            function fred_TransferDomain($params)
            {
            // user defined configuration values
            $apiKey = $params['APIKey'];

            // registration parameters
            $sld = $params['sld'];
            $tld = $params['tld'];
            $eppCode = $params['eppcode'];
            $registrationPeriod = $params['regperiod'];

            // Contact details and other parameters are the same as in RegisterDomain
            // skipped for brevity

            // Build request data
            $requestData = array(
            'api_key' => $apiKey,
            'action' => 'transferDomain',
            'domainName' => fred_domain_name($params),
            'authInfo' => $eppCode,
            'period' => $registrationPeriod
            );

            try {
            // Send the request through the shared gateway client.
            $response = fred_api_call($params, $requestData);

            // Process the response
            if ($response['status'] === 'success') {
            return array(
            'success' => true,
            );
            } else {
            return array(
            'error' => $response['message'] ?? 'Unknown error occurred',
            );
            }
            } catch (\Exception $e) {
            return array(
            'error' => $e->getMessage(),
            );
            }
            }

            // Rest of the module functions continue below, each following the same pattern
            // of converting registrarmodule_* functions to fred_* functions and updating
            // the API calls to match the FRED EPP API documentation

            /**
            * Renew a domain.
            *
            * @param array $params common module parameters
            *
            * @return array
            */
            function fred_RenewDomain($params)
            {
            // user defined configuration values
            $apiKey = $params['APIKey'];

            // registration parameters
            $sld = $params['sld'];
            $tld = $params['tld'];
            $registrationPeriod = $params['regperiod'];

            // Build request data
            $requestData = array(
            'api_key' => $apiKey,
            'action' => 'renewDomain',
            'domainName' => fred_domain_name($params),
            'period' => $registrationPeriod,
            );

            try {
            // Send the request through the shared gateway client.
            $response = fred_api_call($params, $requestData);

            // Process the response
            if ($response['status'] === 'success') {
            return array(
            'success' => true,
            );
            } else {
            return array(
            'error' => $response['message'] ?? 'Unknown error occurred',
            );
            }
            } catch (\Exception $e) {
            return array(
            'error' => $e->getMessage(),
            );
            }
            }

            /**
            * Fetch current nameservers.
            *
            * @param array $params common module parameters
            *
            * @return array
            */
            function fred_GetNameservers($params)
            {
            // user defined configuration values
            $apiKey = $params['APIKey'];

            // domain parameters
            $sld = $params['sld'];
            $tld = $params['tld'];

            // Build request data
            $requestData = array(
            'api_key' => $apiKey,
            'action' => 'getNameservers',
            'domainName' => fred_domain_name($params),
            );

            try {
            // Send the request through the shared gateway client.
            $response = fred_api_call($params, $requestData);

            // Process the response
            if ($response['status'] === 'success' && isset($response['data']['nameservers'])) {
            // Map nameservers to WHMCS expected format
            $nameservers = $response['data']['nameservers'];
            $result = array();

            for ($i = 0; $i < 5; $i++) { if (isset($nameservers[$i])) { $result['ns' . ($i + 1)]=$nameservers[$i]; } }
                return $result; } else { return array( 'error'=> $response['message'] ?? 'Failed to retrieve
                nameservers',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Save nameserver changes.
                *
                * @param array $params common module parameters
                *
                * @return array
                */
                function fred_SaveNameservers($params)
                {
                // user defined configuration values
                $apiKey = $params['APIKey'];

                // domain parameters
                $sld = $params['sld'];
                $tld = $params['tld'];

                // nameserver values
                $nameservers = array_filter([
                $params['ns1'] ?? '',
                $params['ns2'] ?? '',
                $params['ns3'] ?? '',
                $params['ns4'] ?? '',
                $params['ns5'] ?? ''
                ]);

                // Build request data
                $requestData = array(
                'api_key' => $apiKey,
                'action' => 'updateNameservers',
                'domainName' => fred_domain_name($params),
                'nameservers' => $nameservers
                );

                try {
                // Send the request through the shared gateway client.
                $response = fred_api_call($params, $requestData);

                // Process the response
                if ($response['status'] === 'success') {
                return array(
                'success' => true,
                );
                } else {
                return array(
                'error' => $response['message'] ?? 'Failed to update nameservers',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Get the current WHOIS Contact Information.
                *
                * @param array $params common module parameters
                *
                * @return array
                */
                function fred_GetContactDetails($params)
                {
                // user defined configuration values
                $apiKey = $params['APIKey'];

                // domain parameters
                $sld = $params['sld'];
                $tld = $params['tld'];

                // Build request data
                $requestData = array(
                'api_key' => $apiKey,
                'action' => 'getContactDetails',
                'domainName' => fred_domain_name($params),
                );

                try {
                // Send the request through the shared gateway client.
                $response = fred_api_call($params, $requestData);

                // Process the response
                if ($response['status'] === 'success' && isset($response['data'])) {
                $contacts = $response['data']['contacts'] ?? $response['data'];
                $registrant = is_array($contacts['registrant'] ?? null) ? $contacts['registrant'] : [];
                $admin = is_array($contacts['admin'][0] ?? null)
                ? $contacts['admin'][0]
                : (is_array($contacts['admin'] ?? null) ? $contacts['admin'] : $registrant);

                // Map contact data to WHMCS format
                return array(
                'Registrant' => fred_whmcs_contact($registrant),
                'Technical' => fred_whmcs_contact(is_array($contacts['tech'] ?? null)
                ? $contacts['tech']
                : $admin),
                'Admin' => fred_whmcs_contact($admin),
                'Billing' => fred_whmcs_contact(is_array($contacts['billing'] ?? null)
                ? $contacts['billing']
                : $registrant),
                );
                } else {
                return array(
                'error' => $response['message'] ?? 'Failed to retrieve contact details',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Update the WHOIS Contact Information for a given domain.
                *
                * @param array $params common module parameters
                *
                * @return array
                */
                function fred_SaveContactDetails($params)
                {
                // user defined configuration values
                $apiKey = $params['APIKey'];

                // domain parameters
                $sld = $params['sld'];
                $tld = $params['tld'];

                // whois information
                $contactDetails = $params['contactdetails'];

                // Per the API documentation, we need to create registrantInfo and adminInfo
                // in the format expected by the FRED EPP API with the 'name' field
                // instead of separate firstName/lastName
                $registrantInfo = [
                'name' => trim($contactDetails['Registrant']['First Name'] . ' ' . $contactDetails['Registrant']['Last
                Name']),
                'organization' => $contactDetails['Registrant']['Company Name'],
                'address' => $contactDetails['Registrant']['Address 1']
                . (empty($contactDetails['Registrant']['Address 2']) ? '' : "\n" .
                $contactDetails['Registrant']['Address
                2']),
                'city' => $contactDetails['Registrant']['City'],
                'state' => $contactDetails['Registrant']['State'],
                'postalCode' => $contactDetails['Registrant']['Postcode'],
                'country' => $contactDetails['Registrant']['Country'],
                'email' => $contactDetails['Registrant']['Email Address'],
                'phone' => $contactDetails['Registrant']['Phone Number'],
                ];

                // As requested, use the same info for admin contacts as registrant
                $adminInfo = $registrantInfo;

                // Build request data according to the API documentation
                $requestData = array(
                'api_key' => $apiKey,
                'action' => 'updateContactDetails',
                'domainName' => fred_domain_name($params),
                'registrantInfo' => $registrantInfo,
                'adminInfo' => $adminInfo
                );

                try {
                // Send the request through the shared gateway client.
                $response = fred_api_call($params, $requestData);

                // Process the response
                if ($response['status'] === 'success') {
                return array(
                'success' => true,
                );
                } else {
                return array(
                'error' => $response['message'] ?? 'Failed to update contact details',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Add a proper WHMCS custom client-area page for EPP/AuthInfo sending.
                */
                function fred_ClientAreaCustomButtonArray()
                {
                return [
                'Get EPP Code' => 'eppcodepage',
                'Child Nameservers' => 'childnameservers',
                'DNSSEC Management' => 'dnssecpage',
                ];
                }

                function fred_AdminCustomButtonArray()
                {
                return [
                'Sync inventory' => 'SyncInventory',
                'Sync allowed zones' => 'SyncAllowedZones',
                'Get EPP Code' => 'eppcodepage',
                'Child Nameservers' => 'childnameservers',
                'DNSSEC Management' => 'dnssecpage',
                ];
                }


                /**
                * Explicitly allow the custom EPP page to be invoked from the client area.
                */
                function fred_ClientAreaAllowedFunctions()
                {
                return [
                'eppcodepage',
                'childnameservers',
                'dnssecpage',
                ];
                }

                /**
                * Render and process the custom Get EPP Code client-area page.
                */
                function fred_eppcodepage($params)
                {
                // First page load only shows a button. Nothing is sent until the client posts this form.
                if (empty($_POST['fred_send_epp'])) {
                return fred_epp_page_response($params);
                }

                if (!fred_valid_epp_button_token($_POST['fred_epp_token'] ?? null)) {
                return fred_epp_page_response($params, 'Your session expired. Please try again.', 'danger');
                }

                try {
                // The button has been confirmed, so now we ask the registry to email AuthInfo.
                $response = fred_send_epp_code_request($params);

                if (($response['status'] ?? '') === 'success') {
                unset($_SESSION['fred_epp_button_token']);

                return fred_epp_page_response(
                $params,
                fred_auth_code_message($response['data'] ?? []),
                'success'
                );
                }

                return fred_epp_page_response(
                $params,
                $response['message'] ?? 'Failed to send EPP code.',
                'danger'
                );
                } catch (\Exception $e) {
                return fred_epp_page_response($params, $e->getMessage(), 'danger');
                }
                }

                /**
                * Render and process the child nameserver client-area page.
                */
                function fred_childnameservers($params)
                {
                $nssetId = trim((string) ($_POST['child_nsset_id'] ?? $_GET['nssetId']
                ?? fred_get_saved_child_nsset_id($params)));
                $nameservers = fred_get_saved_child_nameservers($params);

                if ($nssetId !== '') {
                try {
                $response = fred_get_child_nameservers_request($params, $nssetId);
                if (($response['status'] ?? '') === 'success') {
                $data = $response['data'] ?? [];
                $nameservers = is_array($data['nameservers'] ?? null) ? $data['nameservers'] : [];
                $nssetId = (string) ($data['nssetId'] ?? $nssetId);
                fred_save_child_nsset_id($params, $nssetId);
                }
                } catch (\Exception $e) {
                // It is okay if no child nsset exists yet; the form below can create it.
                }
                }

                if (empty($_POST['fred_child_ns_action'])) {
                return fred_child_nameserver_page_response($params, $nameservers, $nssetId);
                }

                try {
                $nameservers = fred_child_nameservers_from_post($params);
                $response = fred_save_child_nameservers_request($params, $nameservers, $nssetId);

                if (($response['status'] ?? '') !== 'success') {
                return fred_child_nameserver_page_response(
                $params,
                $nameservers,
                $nssetId,
                $response['message'] ?? 'Failed to save child nameservers.',
                'danger'
                );
                }

                $data = $response['data'] ?? [];
                $nssetId = (string) ($data['nssetId'] ?? $nssetId);
                fred_save_child_nsset_id($params, $nssetId);
                $nameservers = is_array($data['nameservers'] ?? null) ? $data['nameservers'] : $nameservers;
                fred_save_child_nameservers_locally($params, $nameservers);
                $message = 'Child nameservers saved successfully.';

                if (($_POST['fred_child_ns_action'] ?? '') === 'use') {
                $useResponse = fred_use_child_nameservers_request($params, $nssetId);
                if (($useResponse['status'] ?? '') !== 'success') {
                return fred_child_nameserver_page_response(
                $params,
                $nameservers,
                $nssetId,
                $useResponse['message'] ?? 'Saved, but failed to use child nameservers on the domain.',
                'danger'
                );
                }
                $message = 'Child nameservers saved and applied to the domain.';
                }

                return fred_child_nameserver_page_response($params, $nameservers, $nssetId, $message, 'success');
                } catch (\Exception $e) {
                return fred_child_nameserver_page_response(
                $params,
                $nameservers,
                $nssetId,
                $e->getMessage(),
                'danger'
                );
                }
                }

                /**
                * Check Domain Availability.
                *
                * @param array $params common module parameters
                *
                * @return ResultsList
                */
                function fred_CheckAvailability($params)
                {
                // availability check parameters
                $searchTerm = strtolower(trim($params['searchTerm']));
                $tldsToInclude = (array) ($params['tldsToInclude'] ?? []);

                try {
                $results = new ResultsList();

                foreach ($tldsToInclude as $tld) {
                $tld = ltrim(strtolower(trim($tld)), '.');
                $domainName = $searchTerm . '.' . $tld;

                // The new action endpoint checks one fully-qualified domain per request.
                $response = fred_api_call($params, [
                'action' => 'checkDomain',
                'domainName' => $domainName,
                ]);

                $searchResult = new SearchResult($searchTerm, $tld);
                $data = $response['data'] ?? [];
                $available = (bool) ($data['available'] ?? false);

                if (($response['status'] ?? '') === 'success') {
                $searchResult->setStatus(
                $available ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED
                );
                } else {
                $searchResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                }

                $results->append($searchResult);
                }

                return $results;
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Domain Suggestion Settings.
                *
                * @return array
                */
                function fred_DomainSuggestionOptions()
                {
                return array(
                'includeCCTlds' => array(
                'FriendlyName' => 'Include Country Level TLDs',
                'Type' => 'yesno',
                'Description' => 'Tick to enable country level TLD suggestions',
                ),
                );
                }

                /**
                * Get Domain Suggestions.
                *
                * @param array $params common module parameters
                *
                * @return ResultsList
                */
                function fred_GetDomainSuggestions($params)
                {
                // Similar implementation to CheckAvailability but with domain suggestions
                // Omitted for brevity as it follows the same pattern

                return new ResultsList(); // Placeholder
                }

                /**
                * Get Balance - A unique function for FRED EPP API
                *
                * @param array $params common module parameters
                *
                * @return array Balance information
                */
                function fred_GetBalance($params)
                {
                // user defined configuration values
                $apiKey = $params['APIKey'];

                // Build request data
                $requestData = array(
                'api_key' => $apiKey,
                'action' => 'getBalance',
                );

                try {
                // Send the request through the shared gateway client.
                $response = fred_api_call($params, $requestData);

                // Process the response
                if ($response['status'] === 'success') {
                return array(
                'success' => true,
                'balance' => $response['data']['balance'],
                'currency' => $response['data']['currency'],
                );
                } else {
                return array(
                'error' => $response['message'] ?? 'Failed to retrieve balance information',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                /**
                * Client Area Output.
                *
                * @param array $params common module parameters
                *
                * @return string HTML Output
                */
                function fred_ClientArea($params)
                {
                // Keep the WHMCS domain overview clean; management is handled by the built-in tabs/actions.
                return '';
                }

                /**
                * Get domain information.
                *
                * @param array $params common module parameters
                *
                * @return array
                */
                function fred_GetDomainInfo($params)
                {
                // user defined configuration values
                $apiKey = $params['APIKey'];

                // domain parameters
                $sld = $params['sld'];
                $tld = $params['tld'];

                // Build request data
                $requestData = array(
                'api_key' => $apiKey,
                'action' => 'getDomainInfo',
                'domainName' => fred_domain_name($params),
                );

                try {
                // Send the request through the shared gateway client.
                $response = fred_api_call($params, $requestData);

                // Process the response
                if ($response['status'] === 'success' && isset($response['data'])) {
                $data = $response['data'];

                return array(
                'success' => true,
                'expirydate' => $data['expiryDate'] ?? $data['expiresAt'] ?? $data['expires'] ?? '',
                'creationdate' => $data['creationDate'] ?? $data['createdAt'] ?? $data['created'] ?? '',
                'domain_status' => $data['status'] ?? $data['statuses'] ?? [],
                'nameservers' => $data['nameservers'] ?? [],
                );
                } else {
                return array(
                'error' => $response['message'] ?? 'Failed to retrieve domain information',
                );
                }
                } catch (\Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                function fred_Sync($params)
                {
                $domainName = fred_domain_name($params);

                try {
                // Sync also uses the POST action API, so WHMCS never falls back to query-string secrets.
                $responseData = fred_api_call($params, [
                'action' => 'getDomainInfo',
                'domainName' => $domainName,
                ]);

                if (($responseData['status'] ?? '') !== 'success') {
                throw new Exception('API request failed');
                }

                $domainData = $responseData['data'];
                $whmcsStatus = 'Active';
                $statuses = $domainData['status'] ?? $domainData['statuses'] ?? [];
                if (!empty($statuses)) {
                if (!in_array('ok', (array) $statuses)) {
                $whmcsStatus = 'Inactive';
                }
                }

                $expires = $domainData['expires'] ?? $domainData['expiresAt'] ?? $domainData['expiryDate'] ?? null;
                $expiryDate = $expires
                ? date('Y-m-d', strtotime($expires))
                : '';

                return array(
                'active' => $whmcsStatus == 'Active',
                'expired' => false,
                'expirydate' => $expiryDate,
                'status' => $whmcsStatus,
                );
                } catch (Exception $e) {
                return array(
                'error' => $e->getMessage(),
                );
                }
                }

                function fred_dnssecpage($params)
                {
                $domainName = fred_domain_name($params);
                $apiKey = $params['APIKey'];
                $successful = '';
                $error = '';

                if (!empty($_POST['add_dnssec'])) {
                try {
                $requestData = [
                'api_key' => $apiKey,
                'action' => 'addDnssecRecord',
                'domainName' => $domainName,
                'flags' => $_POST['flags'],
                'protocol' => $_POST['protocol'],
                'algorithm' => $_POST['algorithm'],
                'publicKey' => $_POST['publicKey'],
                ];
                $response = fred_api_call($params, $requestData);
                if (($response['status'] ?? '') === 'success') {
                $successful = 'DNSSEC record added successfully.';
                } else {
                $error = 'Failed to add DNSSEC record: ' . ($response['message'] ?? 'Unknown error');
                }
                } catch (\Exception $e) {
                $error = $e->getMessage();
                }
                }

                if (!empty($_POST['remove_dnssec']) && !empty($_POST['pubKey'])) {
                try {
                $requestData = [
                'api_key' => $apiKey,
                'action' => 'removeDnssecRecord',
                'domainName' => $domainName,
                'publicKey' => $_POST['pubKey'],
                ];
                $response = fred_api_call($params, $requestData);
                if (($response['status'] ?? '') === 'success') {
                $successful = 'DNSSEC record removed successfully.';
                } else {
                $error = 'Failed to remove DNSSEC record: ' . ($response['message'] ?? 'Unknown error');
                }
                } catch (\Exception $e) {
                $error = $e->getMessage();
                }
                }

                $records = [];
                try {
                $requestData = [
                'api_key' => $apiKey,
                'action' => 'getDnssecRecords',
                'domainName' => $domainName,
                ];
                $response = fred_api_call($params, $requestData);
                if (($response['status'] ?? '') === 'success') {
                $records = $response['data']['records'] ?? [];
                }
                } catch (\Exception $e) {
                $error = $error ?: $e->getMessage();
                }

                return [
                'templatefile' => 'dnssecpage',
                'requirelogin' => true,
                'vars' => [
                'successful' => $successful,
                'error' => $error,
                'records' => $records,
                'domainid' => $params['domainid'],
                ],
                ];
                }
/** @return array<string, mixed> */
function fred_SyncInventory(array $params): array
{
    if (empty($_SESSION['adminid'])) {
        return ['error' => 'Administrator access is required.'];
    }
    if (empty($params['InventorySync'])) {
        return ['error' => 'Enable Inventory synchronization in the FRED registrar settings first.'];
    }
    try {
        fred_inventory_sync($params, true);

        return ['success' => true];
    } catch (\Throwable) {
        return ['error' => 'Inventory sync could not complete. The saved batch will be retried.'];
    }
}

function fred_SyncAllowedZones(array $params): array
{
    if (empty($_SESSION['adminid'])) {
        return ['error' => 'Administrator access is required.'];
    }

    try {
        fred_log_zone_sync(fred_zone_sync($params));

        return ['success' => true];
    } catch (Throwable $exception) {
        return ['error' => 'Fred zone sync could not complete. Check the API configuration and try again.'];
    }
}
