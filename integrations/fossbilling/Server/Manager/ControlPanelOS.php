<?php

declare(strict_types=1);

class Server_Manager_ControlPanelOS extends Server_Manager
{
    protected function init(): void
    {
        if (empty($this->_config['host']) && empty($this->_config['ip'])) {
            throw new Server_Exception('ControlPanel OS API host is required');
        }
        if (empty($this->_config['accesshash'])) {
            throw new Server_Exception('ControlPanel OS API key is required');
        }
    }

    public static function getForm(): array
    {
        return [
            'label' => 'ControlPanel OS',
            'form' => [
                'host' => ['text', ['label' => 'Panel API host', 'description' => 'panel.example.com or 92.5.152.65']],
                'port' => ['text', ['label' => 'API port', 'description' => '8080 if not behind HTTPS proxy']],
                'secure' => ['radio', ['label' => 'Use HTTPS', 'multiOptions' => ['1' => 'Yes', '0' => 'No']]],
                'accesshash' => ['password', ['label' => 'Server API key', 'secret' => true]],
            ],
        ];
    }

    public static function getSecretFields(): array
    {
        return ['accesshash'];
    }

    public function getLoginUrl(?Server_Account $account)
    {
        return $this->basePanelUrl();
    }

    public function getResellerLoginUrl(?Server_Account $account)
    {
        return $this->basePanelUrl();
    }

    public function testConnection()
    {
        $response = $this->apiRequest('GET', '/test');

        return (bool) ($response['success'] ?? false);
    }

    public function createAccount(Server_Account $account)
    {
        $package = $account->getPackage();
        $response = $this->apiRequest('POST', '/create', [
            'username' => $account->getUsername(),
            'password' => $account->getPassword(),
            'domain' => $account->getDomain(),
            'package' => method_exists($package, 'getName') ? $package->getName() : null,
            'email' => $account->getEmail(),
        ]);

        if (!($response['success'] ?? false)) {
            throw new Server_Exception($response['error'] ?? 'Failed to create ControlPanel OS account');
        }
        if (isset($response['username']) && method_exists($account, 'setUsername')) {
            $account->setUsername($response['username']);
        }

        return true;
    }

    public function synchronizeAccount(Server_Account $account)
    {
        $response = $this->apiRequest('POST', '/synchronize', $this->accountIdentity($account));
        if (isset($response['status']) && method_exists($account, 'setStatus')) {
            $account->setStatus($response['status']);
        }

        return $account;
    }

    public function suspendAccount(Server_Account $account)
    {
        $response = $this->apiRequest('POST', '/suspend', $this->accountIdentity($account));

        return (bool) ($response['success'] ?? false);
    }

    public function unsuspendAccount(Server_Account $account)
    {
        $response = $this->apiRequest('POST', '/unsuspend', $this->accountIdentity($account));

        return (bool) ($response['success'] ?? false);
    }

    public function cancelAccount(Server_Account $account)
    {
        $response = $this->apiRequest('POST', '/cancel', $this->accountIdentity($account));

        return (bool) ($response['success'] ?? false);
    }

    public function changeAccountPassword(Server_Account $account, string $newPassword)
    {
        $response = $this->apiRequest('POST', '/change-password', $this->accountIdentity($account) + [
            'password' => $newPassword,
        ]);

        return (bool) ($response['success'] ?? false);
    }

    public function changeAccountUsername(Server_Account $account, string $newUsername)
    {
        throw new Server_Exception('ControlPanel OS does not support username changes yet');
    }

    public function changeAccountDomain(Server_Account $account, string $newDomain)
    {
        throw new Server_Exception('ControlPanel OS does not support primary domain changes yet');
    }

    public function changeAccountIp(Server_Account $account, string $newIp)
    {
        throw new Server_Exception('ControlPanel OS assigns IPs from node routing and does not support account IP changes');
    }

    public function changeAccountPackage(Server_Account $account, Server_Package $package)
    {
        $response = $this->apiRequest('POST', '/change-package', $this->accountIdentity($account) + [
            'package' => $package->getName(),
        ]);

        return (bool) ($response['success'] ?? false);
    }

    private function accountIdentity(Server_Account $account): array
    {
        return [
            'username' => $account->getUsername(),
            'domain' => $account->getDomain(),
        ];
    }

    private function apiRequest(string $method, string $path, array $payload = []): array
    {
        $url = rtrim($this->apiBaseUrl(), '/') . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->_config['accesshash'],
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            throw new Server_Exception('ControlPanel OS API request failed: ' . ($raw ?: $error ?: ('HTTP ' . $httpCode)));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new Server_Exception('ControlPanel OS API returned invalid JSON');
        }

        return $decoded;
    }

    private function apiBaseUrl(): string
    {
        return $this->basePanelUrl() . '/v1/fossbilling/server';
    }

    private function basePanelUrl(): string
    {
        $scheme = !empty($this->_config['secure']) ? 'https' : 'http';
        $host = $this->_config['host'] ?: $this->_config['ip'];
        $port = $this->_config['port'] ?? null;
        $portPart = $port ? ':' . $port : '';

        return $scheme . '://' . $host . $portPart;
    }
}
