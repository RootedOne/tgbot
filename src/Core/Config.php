<?php

namespace Src\Core;

use Src\Data\JsonStorage;

class Config
{
    private $storage;
    private $configFile = BOT_CONFIG_DATA_FILE;
    private $configData = null;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    private function load()
    {
        if ($this->configData === null) {
            $this->configData = $this->storage->read($this->configFile);
        }
    }

    private function save(): bool
    {
        return $this->storage->write($this->configFile, $this->configData);
    }

    public function get(string $key, $default = null)
    {
        $this->load();
        return $this->configData[$key] ?? $default;
    }

    public function set(string $key, $value): bool
    {
        $this->load();
        $this->configData[$key] = $value;
        return $this->save();
    }

    public function getAdminIds(): array
    {
        // Check config.php constant first for backward compatibility
        if (defined('ADMIN_IDS') && is_array(ADMIN_IDS)) {
            // Merge with dynamic admins if you want, or just return them
            // Here we assume if constant exists, it's the source or a fallback
             $dynamicAdmins = $this->get('admins', []);
             return array_unique(array_merge(ADMIN_IDS, $dynamicAdmins));
        }

        // If specific global array variable $admin_ids exists (legacy pattern)
        global $admin_ids;
        if (isset($admin_ids) && is_array($admin_ids)) {
            $dynamicAdmins = $this->get('admins', []);
            return array_unique(array_merge($admin_ids, $dynamicAdmins));
        }

        // Default to dynamic config
        return $this->get('admins', []);
    }

    public function isAdmin(int $userId): bool
    {
        return in_array($userId, $this->getAdminIds());
    }

    public function getPaymentDetails(): array
    {
        $this->load();

        // Fallback to constants if not set in dynamic config
        $holder = $this->configData['payment_card_holder'] ?? (defined('PAYMENT_CARD_HOLDER') ? PAYMENT_CARD_HOLDER : 'Not Set');
        $number = $this->configData['payment_card_number'] ?? (defined('PAYMENT_CARD_NUMBER') ? PAYMENT_CARD_NUMBER : 'Not Set');

        return [
            'card_holder' => $holder,
            'card_number' => $number
        ];
    }
}
