<?php

namespace Src\Data;

use Src\Core\Config;

class ProductRepository
{
    private $storage;
    private $filename = PRODUCTS_FILE; // Use constant
    private $products = null;

    public function __construct(JsonStorage $storage)
    {
        $this->storage = $storage;
    }

    private function load()
    {
        if ($this->products === null) {
            $this->products = $this->storage->read($this->filename);
        }
    }

    private function save(): bool
    {
        return $this->storage->write($this->filename, $this->products);
    }

    public function getAllCategories(): array
    {
        $this->load();
        return array_keys($this->products);
    }

    public function getProductsByCategory(string $categoryKey): array
    {
        $this->load();
        return $this->products[$categoryKey] ?? [];
    }

    public function getProduct(string $categoryKey, string $productId): ?array
    {
        $this->load();
        return $this->products[$categoryKey][$productId] ?? null;
    }

    public function addProduct(string $categoryKey, string $productId, array $data): bool
    {
        $this->load();
        if (isset($this->products[$categoryKey][$productId])) {
            return false; // Already exists
        }
        $this->products[$categoryKey][$productId] = $data;
        return $this->save();
    }

    public function updateProduct(string $categoryKey, string $productId, array $data): bool
    {
        $this->load();
        if (!isset($this->products[$categoryKey][$productId])) {
            return false;
        }
        $this->products[$categoryKey][$productId] = array_merge($this->products[$categoryKey][$productId], $data);
        return $this->save();
    }

    public function deleteProduct(string $categoryKey, string $productId): bool
    {
        $this->load();
        if (isset($this->products[$categoryKey][$productId])) {
            unset($this->products[$categoryKey][$productId]);
            return $this->save();
        }
        return false;
    }

    public function addCategory(string $categoryKey): bool
    {
        $this->load();
        if (isset($this->products[$categoryKey])) {
            return false;
        }
        $this->products[$categoryKey] = [];
        return $this->save();
    }

    // Instant Item Management
    public function addInstantItem(string $categoryKey, string $productId, string $itemContent): bool
    {
        $this->load();
        if (!isset($this->products[$categoryKey][$productId])) {
            return false;
        }
        if (!isset($this->products[$categoryKey][$productId]['items'])) {
            $this->products[$categoryKey][$productId]['items'] = [];
        }
        $this->products[$categoryKey][$productId]['items'][] = $itemContent;
        return $this->save();
    }

    public function popInstantItem(string $categoryKey, string $productId): ?string
    {
        $this->load();
        if (isset($this->products[$categoryKey][$productId]['items']) && !empty($this->products[$categoryKey][$productId]['items'])) {
             $item = array_shift($this->products[$categoryKey][$productId]['items']);
             if ($this->save()) {
                 return $item;
             } else {
                 array_unshift($this->products[$categoryKey][$productId]['items'], $item);
             }
        }
        return null;
    }

    public function removeInstantItemAtIndex(string $categoryKey, string $productId, int $index): bool
    {
        $this->load();
        if (isset($this->products[$categoryKey][$productId]['items'][$index])) {
            array_splice($this->products[$categoryKey][$productId]['items'], $index, 1);
            return $this->save();
        }
        return false;
    }

    public function parseCompositeKey(string $compositeKey): ?array
    {
        $this->load();
        $categories = array_keys($this->products);

        // Sort by length descending to match longest category name first
        usort($categories, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($categories as $cat) {
            if (strpos($compositeKey, $cat . '_') === 0) {
                $prodId = substr($compositeKey, strlen($cat) + 1);
                if ($prodId !== false && $prodId !== '') {
                    return ['category' => $cat, 'product' => $prodId];
                }
            }
        }
        return null;
    }
}
