<?php
// Test SSL checking
echo "Testing SSL functions...\n";

// Check if openssl_x509_parse exists
if (function_exists('openssl_x509_parse')) {
    echo "openssl_x509_parse: Available\n";
} else {
    echo "openssl_x509_parse: NOT Available\n";
}

// Check if openssl_check exists  
if (function_exists('openssl_free_key')) {
    echo "openssl functions: Available\n";
} else {
    echo "openssl functions: NOT Available\n";
}

// Check cURL
if (function_exists('curl_init')) {
    echo "cURL: Available\n";
} else {
    echo "cURL: NOT Available\n";
}

// Test fetching a certificate
$host = 'google.com';
$port = 443;

$errno = 0;
$error = '';
$connection = @fsockopen("ssl://{$host}", $port, $errno, $error, 5);

if ($connection) {
    fputs($connection, "GET / HTTP/1.0\r\nHost: {$host}\r\n\r\n");
    $headers = '';
    while (!feof($connection) && !strpos($headers, "\r\n\r\n")) {
        $headers .= fread($connection, 8192);
    }
    
    // Extract the certificate
    $cert = '';
    if (preg_match('/BEGIN CERTIFICATE/',$headers)) {
        $parts = explode('BEGIN CERTIFICATE', $headers);
        $cert = 'BEGIN CERTIFICATE' . array_shift($parts);
    }
    
    if (!empty($cert)) {
        // Parse the certificate
        $x509 = openssl_x509_parse($cert);
        if ($x509) {
            echo "Certificate parsed successfully!\n";
            echo "Subject: " . ($x509['subject']['CN'] ?? 'N/A') . "\n";
            echo "Issuer: " . ($x509['issuer']['CN'] ?? 'N/A') . "\n";
            
            $notBefore = $x509['validFrom_time_t'] ?? 0;
            $notAfter = $x509['validTo_time_t'] ?? 0;
            
            echo "Valid from: " . date('Y-m-d H:i:s', $notBefore) . "\n";
            echo "Valid until: " . date('Y-m-d H:i:s', $notAfter) . "\n";
            
            $now = time();
            $daysRemaining = floor(($notAfter - $now) / 86400);
            echo "Days remaining: {$daysRemaining}\n";
            
            if ($daysRemaining <= 3) {
                echo "⚠️ WARNING: Certificate expires in {$daysRemaining} days!\n";
            }
        } else {
            echo "Failed to parse certificate\n";
        }
    } else {
        echo "No certificate found in response\n";
    }
    
    fclose($connection);
} else {
    echo "Could not connect to {$host}:{$port} - Error: {$errno} - {$error}\n";
}