<?php
namespace App;

use RouterOS\Client;
use RouterOS\Query;

/**
 * MikroTik API Client
 */
class MikroTikAPI {
    private $host;
    private $port;
    private $username;
    private $password;
    private $client;

    public function __construct($host = null, $username = null, $password = null, $port = 8728) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Connect to MikroTik
     */
    public function connect() {
        // If already connected, return true
        if ($this->client !== null) {
            return true;
        }

        try {
            $this->client = new Client([
                'host' => $this->host,
                'user' => $this->username,
                'pass' => $this->password,
                'port' => (int)$this->port,
                'timeout' => 8, // 8 seconds timeout (balanced between speed and reliability)
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test connection
     */
    public function testConnection() {
        try {
            if ($this->connect()) {
                // Try to get system resource
                $query = new Query('/system/resource/print');
                $response = $this->client->query($query)->read();
                return !empty($response);
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get Netwatch list
     */
    public function getNetwatchList() {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            $query = new Query('/tool/netwatch/print');
            $response = $this->client->query($query)->read();

            return ['success' => true, 'data' => $response];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get system resources
     */
    public function getSystemResources() {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            $query = new Query('/system/resource/print');
            $response = $this->client->query($query)->read();

            return ['success' => true, 'data' => $response];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get interfaces
     */
    public function getInterfaces() {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            $query = new Query('/interface/print');
            $response = $this->client->query($query)->read();

            return ['success' => true, 'data' => $response];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get netwatch (wrapper for compatibility)
     */
    public function getNetwatch() {
        $result = $this->getNetwatchList();
        return $result['success'] ? $result['data'] : [];
    }

    /**
     * Get hotspot active users
     */
    public function getHotspotActiveUsers() {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            $query = new Query('/ip/hotspot/active/print');
            $response = $this->client->query($query)->read();

            return ['success' => true, 'data' => $response];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find hotspot user by MAC address (case-insensitive)
     *
     * @param string $macAddress MAC address to search for
     * @return array|null User data if found, null otherwise
     */
    public function findHotspotUserByMAC($macAddress) {
        $result = $this->getHotspotActiveUsers();

        if (!$result['success']) {
            return null;
        }

        $macAddress = strtoupper(str_replace([':', '-', '.'], '', $macAddress));

        foreach ($result['data'] as $user) {
            $userMac = $user['mac-address'] ?? '';
            $userMacNormalized = strtoupper(str_replace([':', '-', '.'], '', $userMac));

            if ($userMacNormalized === $macAddress) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Get hotspot active users with live traffic monitoring
     * This method fetches current RX/TX rates for hotspot users
     *
     * @param array $macAddresses Array of MAC addresses to monitor
     * @return array Result with matched users including live traffic rates
     */
    public function getHotspotTrafficByMAC($macAddresses) {
        try {
            // Get all active users first (it will handle connection)
            $activeResult = $this->getHotspotActiveUsers();
            if (!$activeResult['success']) {
                return $activeResult;
            }

            $matchedUsers = [];

            // Normalize input MAC addresses
            $normalizedInput = [];
            foreach ($macAddresses as $mac) {
                $normalized = strtoupper(str_replace([':', '-', '.'], '', $mac));
                $normalizedInput[$normalized] = $mac;
            }

            // Match and get traffic info
            foreach ($activeResult['data'] as $user) {
                $userMac = $user['mac-address'] ?? '';
                $userMacNormalized = strtoupper(str_replace([':', '-', '.'], '', $userMac));

                if (isset($normalizedInput[$userMacNormalized])) {
                    $originalMac = $normalizedInput[$userMacNormalized];

                    // Try to get live monitoring data
                    // Note: /ip/hotspot/active/monitor requires ID
                    $matchedUsers[$originalMac] = [
                        'found' => true,
                        'username' => $user['user'] ?? 'N/A',
                        'ip' => $user['address'] ?? 'N/A',
                        'mac' => $userMac,
                        'uptime' => $user['uptime'] ?? 'N/A',
                        'bytes_in' => $user['bytes-in'] ?? 0,
                        'bytes_out' => $user['bytes-out'] ?? 0,
                        'id' => $user['.id'] ?? null,
                    ];
                }
            }

            // Fill in non-matched MACs
            foreach ($macAddresses as $mac) {
                if (!isset($matchedUsers[$mac])) {
                    $matchedUsers[$mac] = [
                        'found' => false,
                        'username' => 'N/A',
                        'ip' => 'N/A',
                        'mac' => $mac,
                    ];
                }
            }

            return ['success' => true, 'data' => $matchedUsers];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Monitor specific hotspot user by ID to get live RX/TX rates
     * Note: This uses /ip/hotspot/active/monitor which requires listening
     *
     * @param string $userId User .id from hotspot active
     * @return array User data with current RX/TX rates
     */
    public function monitorHotspotUser($userId) {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'error' => 'Connection failed'];
            }

            $query = (new Query('/ip/hotspot/active/monitor'))
                ->equal('.id', $userId)
                ->equal('once', '');

            $response = $this->client->query($query)->read();

            if (empty($response)) {
                return ['success' => false, 'error' => 'No data returned'];
            }

            return ['success' => true, 'data' => $response[0]];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
