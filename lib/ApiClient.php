<?php

namespace WHMCS\Module\Registrar\Fred;

/**
 * FRED API Client
 *
 * API Client for the FRED JSON API gateway, providing one safe place for
 * domain registration and management requests.
 */
class ApiClient
{
    /**
     * API Endpoint URL
     * @var string
     */
    protected $apiEndpoint;

    /**
     * API Authentication Key
     * @var string
     */
    protected $apiKey;

    /**
     * Store API response results
     * @var array
     */
    protected $results = [];

    /**
     * Auth method to use
     * HEADER: Send as X-API-KEY header
     * PARAM: Send as URL parameter
     * BODY: Send in request body
     * 
     * @var string
     */
    protected $authMethod = 'HEADER';

    /**
     * Constructor
     *
     * @param string $apiEndpoint API Endpoint URL
     * @param string $apiKey API Authentication Key (optional)
     * @param string $authMethod Authentication method (HEADER, PARAM, BODY)
     */
    public function __construct($apiEndpoint, $apiKey = null, $authMethod = 'HEADER')
    {
        // Keep endpoint handling predictable, even if WHMCS stores a trailing slash.
        $this->apiEndpoint = rtrim((string) $apiEndpoint, '/');
        $this->apiKey = $apiKey;

        // Protect live credentials by requiring HTTPS for non-local API endpoints.
        $parts = parse_url($this->apiEndpoint);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !$isLocal) {
            throw new \InvalidArgumentException('API endpoint must use HTTPS unless it is a local development URL.');
        }
        
        // Set authentication method (defaults to HEADER if invalid)
        $validMethods = ['HEADER', 'PARAM', 'BODY'];
        $this->authMethod = in_array(strtoupper($authMethod), $validMethods) ? 
                             strtoupper($authMethod) : 'HEADER';
    }

    /**
     * Make API call to the FRED API
     *
     * @param array $requestData Request data to send to the API
     *
     * @throws \Exception Connection error or API response error
     *
     * @return array API response
     */
    public function call($requestData)
    {
        // Include the API key in both JSON and header by default. This matches
        // the new gateway while keeping older action-style payloads compatible.
        $finalRequestData = $requestData;
        $apiKey = $this->apiKey ?? $requestData['api_key'] ?? '';
        if ($apiKey !== '' && !isset($finalRequestData['api_key'])) {
            $finalRequestData['api_key'] = $apiKey;
        }

        $url = $this->apiEndpoint;
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $apiKey
        ];
        
        // Remove API key from body if we're not using BODY auth
        if ($this->authMethod === 'PARAM' && isset($finalRequestData['api_key'])) {
            unset($finalRequestData['api_key']);
        }
        
        // Apply authentication method
        switch ($this->authMethod) {
            case 'HEADER':
                // Header is already present above using the canonical casing.
                break;
                
            case 'PARAM':
                $separator = (strpos($url, '?') === false) ? '?' : '&';
                $url .= $separator . 'api_key=' . urlencode($apiKey);
                break;
                
            // BODY keeps the API key in the JSON request data.
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($finalRequestData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception('Connection Error: ' . curl_errno($ch) . ' - ' . curl_error($ch));
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->results = $this->processResponse($response);

        // Log the API call for debugging purposes
        $logRequestData = $requestData;
        if (isset($logRequestData['api_key'])) {
            $logRequestData['api_key'] = str_repeat('*', 8); // Mask API key in logs
        }
        foreach (['authInfo', 'authCode'] as $secretField) {
            if (isset($logRequestData[$secretField])) {
                $logRequestData[$secretField] = str_repeat('*', 8);
            }
        }
        
        // Only log first part of response if it's very large
        $logResponse = (strlen($response) > 1000) ? 
                       substr($response, 0, 1000) . '... [truncated]' : 
                       $response;
        
        if (in_array($requestData['action'] ?? '', ['syncDomains', 'getDomainSyncStatus', 'registerDomainSyncInstallation'], true)) {
            $logRequestData = ['action' => $requestData['action'], 'batchId' => $requestData['batchId'] ?? null, 'submitted' => count($requestData['domains'] ?? [])];
            $safeResponse = ['status' => $this->results['status'] ?? 'unknown', 'resultCount' => count($this->results['data']['results'] ?? [])];
            $logResponse = json_encode($safeResponse);
        }

        logModuleCall(
            'Fred',
            $requestData['action'] ?? 'API Call',
            json_encode($logRequestData, JSON_PRETTY_PRINT),
            $logResponse,
            $safeResponse ?? $this->results,
            []
        );

        // Check for successful HTTP response
        if ($httpCode < 200 || $httpCode > 299) {
            throw new \Exception(
                'HTTP Error: ' . $httpCode . ' - ' . 
                ($this->results['message'] ?? 'Unknown error')
            );
        }

        // Check for API error response
        if (isset($this->results['status']) && $this->results['status'] === 'error') {
            $errorMessage = $this->results['message'] ?? 'Unknown API error';
            $errorCode = $this->results['errorCode'] ?? 'unknown';
            throw new \Exception("API Error {$errorCode}: {$errorMessage}");
        }

        return $this->results;
    }

    /**
     * Process API response JSON
     *
     * @param string $response JSON response from API
     *
     * @return array Decoded response
     */
    protected function processResponse($response)
    {
        $decodedResponse = json_decode($response, true);
        
        if ($decodedResponse === null && json_last_error() !== JSON_ERROR_NONE) {
            // Include a snippet of the raw response to help debug what HTML/error the server actually returned
            $cleanResponse = trim(strip_tags($response));
            $snippet = substr($cleanResponse, 0, 500);
            throw new \Exception('Invalid JSON response: ' . json_last_error_msg() . '. Raw Server Output: ' . ($snippet ?: 'EMPTY'));
        }
        
        return $decodedResponse;
    }

    /**
     * Get a value from the API response using dot notation
     *
     * @param string $key Key to retrieve (supports dot notation for nested values)
     *
     * @return mixed Value from response or empty string if not found
     */
    public function getFromResponse($key)
    {
        if (empty($key)) {
            return '';
        }

        // Handle dot notation for nested arrays
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $this->results;
            
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return '';
                }
                $value = $value[$k];
            }
            
            return $value;
        }

        // Simple key lookup
        return isset($this->results[$key]) ? $this->results[$key] : '';
    }

    /**
     * Set API Key
     *
     * @param string $apiKey The API key to use for authentication
     *
     * @return void
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Set authentication method
     *
     * @param string $method The method to use (HEADER, PARAM, BODY)
     *
     * @return void
     */
    public function setAuthMethod($method)
    {
        $validMethods = ['HEADER', 'PARAM', 'BODY'];
        $method = strtoupper($method);
        
        if (in_array($method, $validMethods)) {
            $this->authMethod = $method;
        }
    }
}
