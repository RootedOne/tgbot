<?php

namespace Src\Data;

use Src\Core\Constants;

class UserRepository
{
    private $storage;
    // Use constants defined in config.php or Constants class
    private $stateFile = Constants::STATE_FILE;
    private $dataFile = Constants::USER_DATA_FILE;
    private $purchasesFile = Constants::USER_PURCHASES_FILE;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    public function getState(int $userId): ?array
    {
        $states = $this->storage->read($this->stateFile);
        return $states[$userId] ?? null;
    }

    public function setState(int $userId, array $state): bool
    {
        $states = $this->storage->read($this->stateFile);
        $states[$userId] = $state;
        return $this->storage->write($this->stateFile, $states);
    }

    public function clearState(int $userId): bool
    {
        $states = $this->storage->read($this->stateFile);
        if (isset($states[$userId])) {
            unset($states[$userId]);
            return $this->storage->write($this->stateFile, $states);
        }
        return true;
    }

    // --- User Data ---
    public function getUserData(int $userId): array
    {
        $allData = $this->storage->read($this->dataFile);
        return $allData[$userId] ?? ['balance' => 0, 'is_banned' => false];
    }

    public function updateUserData(int $userId, array $data): bool
    {
        $allData = $this->storage->read($this->dataFile);
        $allData[$userId] = $data;
        return $this->storage->write($this->dataFile, $allData);
    }

    public function isBanned(int $userId): bool
    {
        $data = $this->getUserData($userId);
        return $data['is_banned'] ?? false;
    }

    public function banUser(int $userId): bool
    {
        $data = $this->getUserData($userId);
        $data['is_banned'] = true;
        return $this->updateUserData($userId, $data);
    }

    public function unbanUser(int $userId): bool
    {
        $data = $this->getUserData($userId);
        $data['is_banned'] = false;
        return $this->updateUserData($userId, $data);
    }

    // --- Purchases ---
    public function getPurchases(int $userId): array
    {
        $allPurchases = $this->storage->read($this->purchasesFile);
        return $allPurchases[$userId] ?? [];
    }

    public function getPurchase(int $userId, int $index): ?array
    {
        $purchases = $this->getPurchases($userId);
        return $purchases[$index] ?? null;
    }

    public function addPurchase(int $userId, array $purchaseData): int
    {
        $allPurchases = $this->storage->read($this->purchasesFile);
        if (!isset($allPurchases[$userId])) {
            $allPurchases[$userId] = [];
        }

        if (!isset($purchaseData['date'])) {
            $purchaseData['date'] = date('Y-m-d H:i:s');
        }

        $allPurchases[$userId][] = $purchaseData;
        $index = count($allPurchases[$userId]) - 1;

        if ($this->storage->write($this->purchasesFile, $allPurchases)) {
            return $index;
        }
        return -1;
    }

    public function updatePurchase(int $userId, int $index, array $newData): bool
    {
        $allPurchases = $this->storage->read($this->purchasesFile);
        if (isset($allPurchases[$userId][$index])) {
            $allPurchases[$userId][$index] = array_merge($allPurchases[$userId][$index], $newData);
            return $this->storage->write($this->purchasesFile, $allPurchases);
        }
        return false;
    }

    public function getGlobalStats(): array
    {
        $userData = $this->storage->read($this->dataFile);
        $purchases = $this->storage->read($this->purchasesFile);

        $totalUsers = count($userData);
        $bannedUsers = 0;
        foreach ($userData as $u) {
            if (!empty($u['is_banned'])) $bannedUsers++;
        }

        $totalOrders = 0;
        $totalVolume = 0.0;

        foreach ($purchases as $userOrders) {
            if (is_array($userOrders)) {
                $totalOrders += count($userOrders);
                foreach ($userOrders as $order) {
                    if (isset($order['price']) && is_numeric($order['price'])) {
                        $totalVolume += (float)$order['price'];
                    }
                }
            }
        }

        return [
            'total_users' => $totalUsers,
            'banned_users' => $bannedUsers,
            'total_orders' => $totalOrders,
            'total_volume' => $totalVolume
        ];
    }
}
