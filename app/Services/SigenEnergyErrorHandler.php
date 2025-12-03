<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SigenEnergyErrorHandler
{
    /**
     * Map of error codes to human-readable descriptions and handling strategies
     */
    private static array $errorMap = [
        // Success
        0 => ['type' => 'success', 'message' => 'Success', 'action' => 'none'],
        
        // Parameter & Validation Errors
        1000 => ['type' => 'validation', 'message' => 'Invalid or missing parameters', 'action' => 'retry_with_validation'],
        
        // Device & System Errors
        1101 => ['type' => 'device', 'message' => 'Invalid device serial number', 'action' => 'check_device_config'],
        1102 => ['type' => 'device', 'message' => 'Device registration incomplete', 'action' => 'contact_support'],
        1104 => ['type' => 'device', 'message' => 'Device offline', 'action' => 'check_device_connectivity'],
        1106 => ['type' => 'system', 'message' => 'Station not found', 'action' => 'verify_system_id'],
        1107 => ['type' => 'device', 'message' => 'AIO units and Inverters only', 'action' => 'check_device_type'],
        1108 => ['type' => 'system', 'message' => 'Station info not found', 'action' => 'verify_system_id'],
        1302 => ['type' => 'system', 'message' => 'Station status anomaly', 'action' => 'check_system_status'],
        
        // VPP & Control Errors
        1103 => ['type' => 'vpp', 'message' => 'Device controlled by another VPP', 'action' => 'release_vpp_control'],
        1105 => ['type' => 'firmware', 'message' => 'Firmware does not support VPP', 'action' => 'update_firmware'],
        1112 => ['type' => 'vpp', 'message' => 'Device controlled by Evergen VPP', 'action' => 'resolve_vpp_conflict'],
        1501 => ['type' => 'command', 'message' => 'Command execution failed', 'action' => 'retry_command'],
        1502 => ['type' => 'system', 'message' => 'Sigenergy system internal error', 'action' => 'wait_and_retry'],
        
        // Permission & Access Errors
        1201 => ['type' => 'rate_limit', 'message' => 'Rate limit exceeded (5 minute rule)', 'action' => 'wait_rate_limit'],
        1301 => ['type' => 'auth', 'message' => 'Client not found', 'action' => 'check_credentials'],
        1303 => ['type' => 'auth', 'message' => 'Client already exists', 'action' => 'use_existing_client'],
        1304 => ['type' => 'firmware', 'message' => 'Firmware version mismatch', 'action' => 'update_firmware'],
        1401 => ['type' => 'permission', 'message' => 'No permission to operate station', 'action' => 'request_permission'],
        1402 => ['type' => 'permission', 'message' => 'No permission', 'action' => 'check_authorization'],
        
        // Rate Limiting & Interface Errors
        1109 => ['type' => 'network', 'message' => 'Remote procedure call failed', 'action' => 'retry_network'],
        1110 => ['type' => 'rate_limit', 'message' => 'API rate limit exceeded', 'action' => 'implement_backoff'],
        1111 => ['type' => 'permission', 'message' => 'Station access denied', 'action' => 'verify_permissions'],
        
        // System Configuration Errors
        1503 => ['type' => 'config', 'message' => 'Anti-backflow setting enabled', 'action' => 'disable_anti_backflow'],
        1504 => ['type' => 'config', 'message' => 'Peak shaving enabled', 'action' => 'adjust_peak_shaving'],
        
        // Account & Developer Errors
        1600 => ['type' => 'account', 'message' => 'Invalid invitation', 'action' => 'request_new_invitation'],
        1601 => ['type' => 'account', 'message' => 'Account system error', 'action' => 'contact_support'],
        1602 => ['type' => 'account', 'message' => 'Account already registered', 'action' => 'use_existing_account'],
        1603 => ['type' => 'account', 'message' => 'Account under review', 'action' => 'wait_approval'],
        1604 => ['type' => 'developer', 'message' => 'Developer not approved', 'action' => 'request_developer_access'],
        11002 => ['type' => 'auth', 'message' => 'Account locked (5 failed attempts)', 'action' => 'wait_unlock'],
        11003 => ['type' => 'auth', 'message' => 'Authentication failed', 'action' => 'check_credentials'],
    ];

    /**
     * Handle API error response
     */
    public static function handleError(int $errorCode, string $message = '', array $context = []): array
    {
        $errorInfo = self::$errorMap[$errorCode] ?? [
            'type' => 'unknown',
            'message' => $message ?: 'Unknown error',
            'action' => 'contact_support'
        ];

        $logContext = array_merge($context, [
            'error_code' => $errorCode,
            'error_type' => $errorInfo['type'],
            'error_message' => $errorInfo['message'],
            'recommended_action' => $errorInfo['action']
        ]);

        // Log based on error severity
        switch ($errorInfo['type']) {
            case 'rate_limit':
                Log::warning('Sigenergy API rate limit exceeded', $logContext);
                break;
            case 'auth':
            case 'permission':
                Log::error('Sigenergy API authorization error', $logContext);
                break;
            case 'device':
            case 'system':
                Log::error('Sigenergy device/system error', $logContext);
                break;
            case 'command':
                Log::error('Sigenergy command execution failed', $logContext);
                break;
            case 'validation':
                Log::warning('Sigenergy API validation error', $logContext);
                break;
            default:
                Log::error('Sigenergy API error', $logContext);
        }

        return [
            'success' => false,
            'error_code' => $errorCode,
            'error_type' => $errorInfo['type'],
            'error_message' => $errorInfo['message'],
            'recommended_action' => $errorInfo['action'],
            'context' => $context
        ];
    }

    /**
     * Check if error is retryable
     */
    public static function isRetryableError(int $errorCode): bool
    {
        $retryableErrors = [
            1109, // RPC fail
            1110, // API rate limit
            1201, // Access restriction
            1501, // Command execution failed
            1502, // System internal error
        ];

        return in_array($errorCode, $retryableErrors);
    }

    /**
     * Get recommended wait time for retryable errors
     */
    public static function getRetryDelay(int $errorCode): int
    {
        $delayMap = [
            1109 => 5,     // RPC fail - 5 seconds
            1110 => 60,    // API rate limit - 1 minute
            1201 => 300,   // Access restriction - 5 minutes
            1501 => 10,    // Command failed - 10 seconds
            1502 => 30,    // System error - 30 seconds
            11002 => 180,  // Account locked - 3 minutes
        ];

        return $delayMap[$errorCode] ?? 60;
    }

    /**
     * Check if error indicates system optimization should be paused
     */
    public static function shouldPauseOptimization(int $errorCode): bool
    {
        $pauseErrors = [
            1104, // Device offline
            1302, // Station status anomaly
            1503, // Anti-backflow enabled
            1504, // Peak shaving enabled
            1601, // Account system error
        ];

        return in_array($errorCode, $pauseErrors);
    }
}