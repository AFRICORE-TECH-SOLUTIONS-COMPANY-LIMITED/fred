# FRED WHMCS Domain Registrar Module

## Installation

1. Download `fred-whmcs-v1.4.zip` from the [v1.4 release](https://github.com/AFRICORE-TECH-SOLUTIONS-COMPANY-LIMITED/fred/releases/tag/v1.4) once the package has been published.
2. Extract the ZIP into the root of your WHMCS installation. If you extract it locally, upload the resulting `modules/registrars/fred/` directory to the same path in WHMCS. Confirm that `fred.php` is at `<whmcs-root>/modules/registrars/fred/fred.php`.
3. In WHMCS admin, open **Configuration → System Settings → Domain Registrars**, activate **Fred**, and configure its **API Endpoint** and **API Key**. The gateway must allow requests from your WHMCS server's IP address.

## Release packages

Publishing a GitHub Release triggers the **Package FRED release** workflow. It checks out the release tag, lints the production PHP files, and verifies the ZIP before attaching it to that release. The archive contains the module's PHP files, Smarty templates, logo, and runtime library files under `modules/registrars/fred/`.

Tags use the `v1.4` or `v1.4.1` format, with optional prerelease suffixes such as `-rc.1`. The package name includes the full tag. The direct download URL format is:

```text
https://github.com/AFRICORE-TECH-SOLUTIONS-COMPANY-LIMITED/fred/releases/download/{version}/fred-whmcs-{version}.zip
```

To package an already published release, dispatch the workflow from the default branch and pass the release tag as the `version` input. For v1.4, run:

```bash
gh workflow run release.yml --repo AFRICORE-TECH-SOLUTIONS-COMPANY-LIMITED/fred --ref main -f version=v1.4
```

A rebuild replaces the asset of the same name. GitHub requires the release to allow asset changes. Replacement deletes the old asset before uploading the new one.

## FRED EPP API Documentation

## Overview

The FRED EPP API provides a modern interface for domain registration, transfer, and management operations via the FRED EPP protocol. It acts as a bridge between HTTP/JSON applications and the EPP protocol used by domain registries.

## Features

- Check domain availability
- Register new domains
- Manage domain contacts and nameservers
- Transfer domains between registrars
- Renew domain registrations
- Manage EPP authorization codes
- Check account balance information

## Authentication

All API requests require authentication via an API key. The API key can be provided in one of three ways:

1. **HTTP Header**: Include the `X-API-KEY` header in your request.
2. **Query Parameter**: Append the `api_key` parameter to the URL.
3. **Request Body**: Include the API key in the JSON request body (POST requests only).

### Example

```json
{
  "api_key": "your_api_key_here",
  "action": "checkDomain",
  "domainName": "example.tz"
}
```

## IP Restrictions

API access is restricted to authorized IP addresses for enhanced security. Please ensure your requests come from a whitelisted IP address or contact your account manager to add your IP to the whitelist.

## Endpoints

- **POST https://fred.umojasolutions.co.tz/api/v1/epp**: Main API endpoint for all operations. Specify the action to perform in the request body.

## Available Actions

### checkDomain

Check if a domain is available for registration.

**Required Parameters:**

- `domainName`: The domain name to check availability

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "checkDomain",
  "domainName": "example.tz"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "available": true,
    "domainName": "example.tz",
    "reason": "Domain available for registration"
  }
}
```

### getDomainInfo

Get detailed information about a domain.

**Required Parameters:**

- `domainName`: The domain name to get information about

**Optional Parameters:**

- `authInfo`: Authorization code (required only for domains at other registrars)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "getDomainInfo",
  "domainName": "example.tz"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "domainName": "example.tz",
    "status": ["ok"],
    "registrant": "REG-12345",
    "admin": "ADM-12345",
    "registrar": "REG-AFRICORE",
    "creationDate": "2023-01-01T00:00:00Z",
    "expiryDate": "2024-01-01T00:00:00Z"
  }
}
```

### getNameservers

Retrieve nameservers for a domain.

**Required Parameters:**

- `domainName`: The domain name to retrieve nameservers for

**Optional Parameters:**

- `authInfo`: Authorization code (required only for domains at other registrars)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "getNameservers",
  "domainName": "example.tz"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "nameservers": ["ns1.example.com", "ns2.example.com"]
  }
}
```

### updateNameservers

Update nameservers for a domain.

**Required Parameters:**

- `domainName`: The domain name to update nameservers for
- `nameservers`: Array of nameserver hostnames (2-13 nameservers)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "updateNameservers",
  "domainName": "example.tz",
  "nameservers": ["ns1.newprovider.com", "ns2.newprovider.com"]
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "updated": true
  }
}
```

### registerDomain

Register a new domain.

**Required Parameters:**

- `domainName`: The domain name to register
- `registrantInfo`: Object containing registrant contact information
- `adminInfo`: Object containing administrative contact information
- `nameservers`: Array of nameserver hostnames

**Optional Parameters:**

- `period`: Registration period in years (default: 1)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "registerDomain",
  "domainName": "mynewdomain.tz",
  "period": 2,
  "registrantInfo": {
    "name": "John Doe",
    "organization": "Example Corp",
    "address": "123 Main St",
    "city": "Dar es Salaam",
    "state": "Dar es Salaam",
    "postalCode": "12345",
    "country": "TZ",
    "email": "john@example.com",
    "phone": "+255123456789"
  },
  "adminInfo": {
    "name": "Jane Doe",
    "organization": "Example Corp",
    "address": "123 Main St",
    "city": "Dar es Salaam",
    "state": "Dar es Salaam",
    "postalCode": "12345",
    "country": "TZ",
    "email": "jane@example.com",
    "phone": "+255123456789"
  },
  "nameservers": ["ns1.example.com", "ns2.example.com"]
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "registered": true
  }
}
```

### transferDomain

Transfer a domain to your registrar.

**Required Parameters:**

- `domainName`: The domain name to transfer
- `authInfo`: The authorization/EPP code for the domain

**Optional Parameters:**

- `period`: Number of years to add during transfer (default varies by TLD)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "transferDomain",
  "domainName": "domain-to-transfer.tz",
  "authInfo": "ABC123XYZ",
  "period": 1
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "transferInitiated": true
  }
}
```

### renewDomain

Renew a domain registration.

**Required Parameters:**

- `domainName`: The domain name to renew

**Optional Parameters:**

- `period`: Renewal period in years (default: 1)

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "renewDomain",
  "domainName": "example.tz",
  "period": 2
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "renewed": true
  }
}
```

### getContactDetails

Retrieve contact details for a domain.

**Required Parameters:**

- `domainName`: The domain name to retrieve contact details for

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "getContactDetails",
  "domainName": "example.tz"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "contacts": {
      "registrant": {
        "name": "John Doe",
        "organization": "Example Corp",
        "address": "123 Main St",
        "city": "Dar es Salaam",
        "state": "Dar es Salaam",
        "postalCode": "12345",
        "country": "TZ",
        "email": "john@example.com",
        "phone": "+255123456789"
      },
      "admin": {
        "name": "Jane Doe",
        "organization": "Example Corp",
        "address": "123 Main St",
        "city": "Dar es Salaam",
        "state": "Dar es Salaam",
        "postalCode": "12345",
        "country": "TZ",
        "email": "jane@example.com",
        "phone": "+255123456789"
      }
    }
  }
}
```

### updateContactDetails

Update contact details for a domain.

**Required Parameters:**

- `domainName`: The domain name to update contact details for
- `registrantInfo`: Object containing updated registrant contact information
- `adminInfo`: Object containing updated administrative contact information

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "updateContactDetails",
  "domainName": "example.tz",
  "registrantInfo": {
    "name": "John Smith",
    "organization": "New Company Ltd",
    "address": "456 Business Ave",
    "city": "Dar es Salaam",
    "state": "Dar es Salaam",
    "postalCode": "12345",
    "country": "TZ",
    "email": "john@newcompany.com",
    "phone": "+255987654321"
  },
  "adminInfo": {
    "name": "Jane Smith",
    "organization": "New Company Ltd",
    "address": "456 Business Ave",
    "city": "Dar es Salaam",
    "state": "Dar es Salaam",
    "postalCode": "12345",
    "country": "TZ",
    "email": "jane@newcompany.com",
    "phone": "+255987654322"
  }
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "updated": true
  }
}
```

### getEPPCode

Retrieve the EPP/Auth code for a domain.

**Required Parameters:**

- `domainName`: The domain name to retrieve the EPP code for

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "getEPPCode",
  "domainName": "example.tz"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "eppCode": "ABC123XYZ"
  }
}
```

### setEPPCode

Set a new EPP/Auth code for a domain.

**Required Parameters:**

- `domainName`: The domain name to set a new EPP code for
- `newEppCode`: The new EPP code to set

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "setEPPCode",
  "domainName": "example.tz",
  "newEppCode": "NEW987XYZ"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "updated": true
  }
}
```

### getBalance

Retrieve account credit balance information for all zones.

**No additional parameters required**

**Example Request:**

```json
{
  "api_key": "your_api_key_here",
  "action": "getBalance"
}
```

**Example Response:**

```json
{
  "status": "success",
  "data": {
    "balance": {
      "TZ": {
        "available": 10000.0,
        "currency": "TZS"
      },
      "CO.TZ": {
        "available": 8000.0,
        "currency": "TZS"
      }
    }
  }
}
```

## Response Format

### Success Response

```json
{
  "status": "success",
  "data": {
    // Action-specific response data
  }
}
```

### Error Response

```json
{
  "status": "error",
  "message": "Error description",
  "errorCode": 2302 // Optional EPP error code
}
```

## Contact Data Format

When submitting contact information (registrant or admin contacts), use the following format:

```json
{
  "name": "Full Name",
  "organization": "Organization Name",
  "address": "Street Address",
  "city": "City",
  "state": "State/Province",
  "postalCode": "Postal/ZIP Code",
  "country": "Country Code (ISO 3166-1 alpha-2)",
  "email": "email@example.com",
  "phone": "+255123456789",
  "fax": "+255123456788" // Optional
}
```

**Note about phone numbers:** Phone numbers should be formatted in international format with a single '+' prefix. The API will automatically format phone numbers to ensure proper format.

## Common EPP Error Codes

- **2002**: Command use error
- **2005**: Parameter value syntax error
- **2201**: Authorization error (invalid credentials)
- **2302**: Object exists
- **2303**: Object does not exist
- **2304**: Object status prohibits operation
- **2306**: Parameter value policy error

## Client Libraries

For convenience, we offer client libraries in several programming languages:

### PHP

```php
<?php
// Example PHP client code
$apiKey = 'your_api_key_here';
$apiUrl = 'https://fred.umojasolutions.co.tz/api/v1/epp';

$data = [
    'api_key' => $apiKey,
    'action' => 'checkDomain',
    'domainName' => 'example.tz'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
print_r($result);
```

## Support and Resources

- **Email**: [domains@africoretechsolutions.com] (mailto:domains@africoretechsolutions.com)
- **Phone**: [0795961258](tel:0795961258)

## License

&copy; 2025 Africore Tech Solutions Company Limited- FRED EPP API. All rights reserved.
