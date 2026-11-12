<?php
/**
 * FRED EPP Registrar Module Hooks File
 *
 * Hooks allow you to tie into events that occur within the WHMCS application.
 *
 * This allows you to execute your own code in addition to, or sometimes even
 * instead of that which WHMCS executes by default.
 *
 * WHMCS recommends as good practice that all named hook functions are prefixed
 * with the keyword "hook", followed by your module name, followed by the action
 * of the hook function. This helps prevent naming conflicts with other addons
 * and modules.
 *
 * For every hook function you create, you must also register it with WHMCS.
 * There are two ways of registering hooks, both are demonstrated below.
 *
 * @see https://developers.whmcs.com/hooks/
 *
 * @copyright Copyright (c) 2025 FRED EPP API
 * @license https://www.whmcs.com/license/ WHMCS Eula
 */

// Optional historical inventory synchronization (disabled until explicitly configured).
require_once __DIR__ . '/inventory-sync.php';
require_once __DIR__ . '/zone-sync.php';
fred_register_zone_sync_hook();

// Require any libraries needed for the module to function.
// require_once __DIR__ . '/path/to/library/loader.php';
//
// Also, perform any initialization required by the service's library.

/**
 * Safely read the domain id from WHMCS' client-area requests.
 */
function hook_fred_domain_id_from_request()
{
    return (int) ($_REQUEST['domainid'] ?? $_REQUEST['id'] ?? 0);
}

/**
 * Build the WHMCS custom registrar page URL for this module.
 */
function hook_fred_custom_domain_url($domainId, $action)
{
    return 'clientarea.php?action=domaindetails&domainid=' . (int) $domainId . '&modop=custom&a=' . rawurlencode($action);
}

/**
 * Confirm the requested domain belongs to this registrar before redirecting.
 */
function hook_fred_domain_uses_module($domainId)
{
    if ($domainId <= 0 || !class_exists('\WHMCS\Database\Capsule')) {
        return false;
    }

    try {
        $registrar = \WHMCS\Database\Capsule::table('tbldomains')
            ->where('id', $domainId)
            ->value('registrar');

        return $registrar === 'fred';
    } catch (\Exception $e) {
        // If WHMCS cannot read the domain, leave the normal page untouched.
        return false;
    }
}

/**
 * WHMCS' built-in EPP page calls GetEPPCode immediately. For FRED, redirect
 * that page to our safer confirmation page so the client must click the button.
 */
add_hook('ClientAreaPage', 1, function() {
    $domainId = hook_fred_domain_id_from_request();

    if (($_REQUEST['action'] ?? '') !== 'domaingetepp' || !hook_fred_domain_uses_module($domainId)) {
        return;
    }

    header('Location: ' . hook_fred_custom_domain_url($domainId, 'eppcodepage'));
    exit;
});

/**
 * Point any visible "Get EPP Code" sidebar item to the custom confirmation page.
 */
add_hook('ClientAreaPrimarySidebar', 1, function($primarySidebar) {
    $domainId = hook_fred_domain_id_from_request();
    if (!hook_fred_domain_uses_module($domainId)) {
        return;
    }

    $targetUrl = hook_fred_custom_domain_url($domainId, 'eppcodepage');
    $updateItem = function($item) use (&$updateItem, $targetUrl) {
        if (!is_object($item) || !method_exists($item, 'getChildren')) {
            return;
        }

        foreach ($item->getChildren() as $child) {
            $label = method_exists($child, 'getLabel') ? (string) $child->getLabel() : '';
            $name = method_exists($child, 'getName') ? (string) $child->getName() : '';

            if (stripos($label, 'EPP') !== false || stripos($name, 'EPP') !== false) {
                if (method_exists($child, 'setUri')) {
                    $child->setUri($targetUrl);
                }
            }

            $updateItem($child);
        }
    };

    $updateItem($primarySidebar);
});

/**
 * Register a hook with WHMCS.
 *
 * add_hook(string $hookPointName, int $priority, string|array|Closure $function)
 */
add_hook('AdminHomeWidgets', 1, function() {
    return new FredWidget();
});

/**
 * FRED EPP Registrar Module Admin Dashboard Widget.
 *
 * @see https://developers.whmcs.com/addon-modules/admin-dashboard-widgets/
 */
class FredWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = 'Fred';
    protected $description = 'FRED EPP API integration for .tz domain management';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 120;
    protected $requiredPermission = '';

    public function getData()
    {
        return array();
    }

    public function generateOutput($data)
    {
        return <<<EOF
<div class="widget-content-padded">
    <p><strong>Fred Domain Management</strong></p>
    <p>Tanzania ccTLD Registry Module</p>
    <p>For support: 0795961258 and 0795973208<br>Email: domains@africoretechsolutions.com</p>
</div>
EOF;
    }
}

/**
 * Add DNSSEC Panel to WHMCS Admin Domain Details
 */
add_hook('AdminClientDomainsTabFields', 1, function($vars) {
    $domainId = (int) $vars['id'];
    
    if (!hook_fred_domain_uses_module($domainId)) {
        return [];
    }

    if (!function_exists('fred_api_call')) {
        require_once dirname(__FILE__) . '/fred.php';
    }

    $domain = \WHMCS\Database\Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$domain) return [];

    $params = getregistrarconfigoptions('fred');
    $params['domainname'] = $domain->domain;

    $keysetId = '';
    $records = [];
    try {
        $requestData = [
            'api_key' => $params['APIKey'],
            'action' => 'getDomainInfo',
            'domainName' => $domain->domain,
        ];
        $response = fred_api_call($params, $requestData);
        if (($response['status'] ?? '') === 'success') {
            $keysetId = $response['data']['keyset'] ?? 'None';
        }

        $requestData = [
            'api_key' => $params['APIKey'],
            'action' => 'getDnssecRecords',
            'domainName' => $domain->domain,
        ];
        $dnsResponse = fred_api_call($params, $requestData);
        if (($dnsResponse['status'] ?? '') === 'success') {
            $records = $dnsResponse['data']['records'] ?? [];
        }
    } catch (\Exception $e) {
        $keysetId = 'Error: ' . $e->getMessage();
    }

    $html = '<div style="background-color:#f9f9f9; padding: 15px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px;">';
    if (!empty($_SESSION['fred_dnssec_error'])) {
        $html .= '<div style="color: white; background-color: #d9534f; padding: 10px; margin-bottom: 10px; border-radius: 3px;"><strong>Error:</strong> ' . htmlspecialchars($_SESSION['fred_dnssec_error']) . '</div>';
        unset($_SESSION['fred_dnssec_error']);
    }
    $html .= '<strong>Active Keyset ID:</strong> ' . htmlspecialchars($keysetId) . '<br><br>';
    
    if (!empty($records)) {
        $html .= '<table width="100%" class="datatable" style="margin-bottom:15px;"><tr><th>Flags</th><th>Algorithm</th><th>Public Key</th><th>Action</th></tr>';
        foreach ($records as $record) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($record['flags']) . '</td>';
            $html .= '<td>' . htmlspecialchars($record['alg']) . '</td>';
            $html .= '<td title="' . htmlspecialchars($record['pubKey']) . '">' . htmlspecialchars(substr($record['pubKey'], 0, 40)) . '...</td>';
            $html .= '<td><label><input type="checkbox" name="fred_remove_dnssec[]" value="' . htmlspecialchars($record['pubKey']) . '"> Remove on Save</label></td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
    }

    $html .= '<strong>Add New DNSKEY Record</strong><br>';
    $html .= '<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">';
    $html .= '<tr><td class="fieldlabel" style="width:20%;">Flags</td><td class="fieldarea"><select name="fred_dnssec_flags" class="form-control"><option value="257">257 (KSK/CSK)</option><option value="256">256 (ZSK)</option></select></td></tr>';
    $html .= '<tr><td class="fieldlabel">Algorithm</td><td class="fieldarea"><select name="fred_dnssec_alg" class="form-control"><option value="8">8 (RSA/SHA-256)</option><option value="13" selected>13 (ECDSA P-256)</option><option value="14">14 (ECDSA P-384)</option></select></td></tr>';
    $html .= '<tr><td class="fieldlabel">Public Key</td><td class="fieldarea"><textarea name="fred_dnssec_pubkey" class="form-control" rows="3" style="width:100%;"></textarea><br><small>To add, paste the raw key here and click Save Changes below.</small></td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    return ['DNSSEC Management' => $html];
});

add_hook('AdminClientDomainsTabFieldsSave', 1, function($vars) {
    $domainId = (int) ($vars['domainid'] ?? $vars['id'] ?? 0);
    
    if (!hook_fred_domain_uses_module($domainId)) {
        return;
    }

    if (!function_exists('fred_api_call')) {
        require_once dirname(__FILE__) . '/fred.php';
    }

    $domain = \WHMCS\Database\Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$domain) return;

    $params = getregistrarconfigoptions('fred');
    $params['domainname'] = $domain->domain;

    // Handle Removals
    if (!empty($_POST['fred_remove_dnssec']) && is_array($_POST['fred_remove_dnssec'])) {
        foreach ($_POST['fred_remove_dnssec'] as $pubKeyToRemove) {
            try {
                $requestData = [
                    'api_key' => $params['APIKey'],
                    'action' => 'removeDnssecRecord',
                    'domainName' => $domain->domain,
                    'publicKey' => $pubKeyToRemove,
                ];
                fred_api_call($params, $requestData);
            } catch (\Exception $e) {
                $_SESSION['fred_dnssec_error'] = 'Failed to remove DNSSEC record: ' . $e->getMessage();
            }
        }
    }

    // Handle Addition
    if (!empty($_POST['fred_dnssec_pubkey'])) {
        try {
            $requestData = [
                'api_key' => $params['APIKey'],
                'action' => 'addDnssecRecord',
                'domainName' => $domain->domain,
                'flags' => $_POST['fred_dnssec_flags'],
                'protocol' => '3',
                'algorithm' => $_POST['fred_dnssec_alg'],
                'publicKey' => trim($_POST['fred_dnssec_pubkey']),
            ];
            fred_api_call($params, $requestData);
        } catch (\Exception $e) {
            logModuleCall('fred', 'addDnssecRecord', $requestData, $e->getMessage());
            $_SESSION['fred_dnssec_error'] = $e->getMessage();
        }
    }
});
