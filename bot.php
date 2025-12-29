<?php

// --- Include Autoloader & Config ---
require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/config.php'; // Keep legacy config for constants if needed, or rely on Config class

use Src\Core\TelegramBot;
use Src\Core\Config;
use Src\Core\Router;
use Src\Data\JsonStorage;
use Src\Data\ProductRepository;
use Src\Data\UserRepository;
use Src\Controllers\ShopController;
use Src\Controllers\OrderController;
use Src\Controllers\AdminController;
use Src\Controllers\SupportController;
use Src\Helpers\KeyboardHelper;

// --- Initialization ---
$storage = new JsonStorage();
$config = new Config($storage);
$bot = new TelegramBot(API_TOKEN); // API_TOKEN from config.php

$productRepo = new ProductRepository($storage);
$userRepo = new UserRepository($storage);
$keyboardHelper = new KeyboardHelper($config, $productRepo);

$shopController = new ShopController($bot, $productRepo, $userRepo, $keyboardHelper);
$orderController = new OrderController($bot, $config, $productRepo, $userRepo);
$supportController = new SupportController($bot, $config, $userRepo);
$adminController = new AdminController($bot, $config, $productRepo, $userRepo);

$router = new Router(
    $bot,
    $config,
    $productRepo,
    $userRepo,
    $shopController,
    $orderController,
    $adminController,
    $supportController
);

// --- Get Update ---
$update = json_decode(file_get_contents('php://input'));

if ($update) {
    // Dispatch
    $router->handle($update);
}
