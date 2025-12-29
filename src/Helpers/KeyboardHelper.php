<?php

namespace Src\Helpers;

use Src\Core\Config;
use Src\Data\ProductRepository;

class KeyboardHelper
{
    private $config;
    private $productRepo;

    public function __construct(Config $config, ProductRepository $productRepo)
    {
        $this->config = $config;
        $this->productRepo = $productRepo;
    }

    public function generateMainMenu(bool $isAdmin): string
    {
        $layoutMode = $this->config->get('main_menu_layout_mode', 'auto');
        $allButtons = $this->generateAllButtons($isAdmin);

        if ($layoutMode === 'manual') {
            $rows = $this->generateManualLayout($allButtons);
        } else {
            $rows = $this->generateAutoLayout($allButtons);
        }

        return json_encode(['inline_keyboard' => $rows]);
    }

    private function generateAllButtons(bool $isAdmin): array
    {
        $buttons = [];
        $categories = $this->productRepo->getAllCategories();

        foreach ($categories as $catKey) {
            $displayName = ucfirst(str_replace('_', ' ', $catKey));
            $buttons['view_category_' . $catKey] = [
                'text' => "🛍️ " . htmlspecialchars($displayName),
                'callback_data' => 'view_category_' . $catKey
            ];
        }

        $buttons[CALLBACK_MY_PRODUCTS] = ['text' => "📦 محصولات من", 'callback_data' => CALLBACK_MY_PRODUCTS];
        $buttons[CALLBACK_SUPPORT] = ['text' => "💬 پشتیبانی", 'callback_data' => CALLBACK_SUPPORT];

        if ($isAdmin) {
            $buttons[CALLBACK_ADMIN_PANEL] = ['text' => "⚙️ Admin Panel", 'callback_data' => CALLBACK_ADMIN_PANEL];
        }

        return $buttons;
    }

    private function generateManualLayout(array $allButtons): array
    {
        $manualLayout = $this->config->get('main_menu_manual_layout', []);
        $keyboardRows = [];

        foreach ($manualLayout as $rowKeys) {
            $row = [];
            foreach ($rowKeys as $key) {
                $key = trim($key);
                if (isset($allButtons[$key])) {
                    $row[] = $allButtons[$key];
                }
            }
            if (!empty($row)) {
                $keyboardRows[] = $row;
            }
        }
        return $keyboardRows;
    }

    private function generateAutoLayout(array $allButtons): array
    {
        $columns = $this->config->get('main_menu_columns', 1);
        return array_chunk(array_values($allButtons), $columns);
    }
}
