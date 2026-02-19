# Telegram E-Commerce Bot

A PHP-based Telegram bot for selling digital products with support for manual and instant delivery.

## Setup & Configuration

### Security Note ⚠️

This bot requires a Telegram Bot Token to function. For security reasons, the token is **not** stored in the source code.

### Installation

1.  **Clone the repository.**
2.  **Configure the Environment:**
    *   Copy `.env.example` to a new file named `.env`:
        ```bash
        cp .env.example .env
        ```
    *   Open `.env` and replace `your_token_here` with your actual Telegram Bot Token:
        ```env
        TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrsTUVwxyz
        ```
    *   **Important:** Ensure `.env` is **never** committed to version control (it is ignored by default in `.gitignore`).

3.  **Deployment:**
    *   Ensure your web server (Apache/Nginx) is configured to deny access to `.json`, `.log`, and `.env` files to prevent sensitive data exposure.
    *   Set the Webhook URL for your bot to point to `bot.php`.

## Features

*   **Product Management:** Add, edit, and remove products and categories via the Admin Panel.
*   **Delivery Modes:**
    *   **Manual:** Admin manually fulfills the order.
    *   **Instant:** Bot automatically delivers a code/link from a stock list.
*   **User Support:** Direct chat between users and admins.
*   **Statistics:** View sales volume and user stats.

## File Structure

*   `bot.php`: Main entry point (Webhook).
*   `config.php`: Configuration loader.
*   `functions.php`: Core logic and helper functions.
*   `products.json`: Product catalog.
*   `user_purchases.json`: Purchase history.
*   `user_data.json`: User balances and status.
*   `bot_config_data.json`: Admin IDs and settings.
