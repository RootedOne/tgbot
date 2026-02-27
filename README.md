# Telegram E-Commerce Bot

A PHP-based Telegram bot for selling digital products with support for manual and instant delivery.

## Setup & Configuration

### Security Note ⚠️

This bot requires a Telegram Bot Token and MySQL Database credentials to function. For security reasons, sensitive data is **not** stored in the source code.

### Installation

1.  **Clone the repository.**
2.  **Database Setup:**
    *   Create a MySQL database (e.g., `telegram_bot`).
    *   Import the database schema using `schema.sql`:
        ```bash
        mysql -u root -p telegram_bot < schema.sql
        ```

3.  **Configure the Environment:**
    *   Copy `.env.example` to a new file named `.env`:
        ```bash
        cp .env.example .env
        ```
    *   Open `.env` and fill in your details:
        ```env
        TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrsTUVwxyz
        DB_HOST=127.0.0.1
        DB_NAME=telegram_bot
        DB_USER=root
        DB_PASSWORD=your_password
        ```
    *   **Important:** Ensure `.env` is **never** committed to version control (it is ignored by default in `.gitignore`).

4.  **Migration (Optional):**
    *   If you have existing data in JSON files (`products.json`, `user_data.json`, etc.), run the migration script:
        ```bash
        php migrate.php
        ```
    *   This will import your data into the MySQL database.

5.  **Deployment:**
    *   Ensure your web server (Apache/Nginx) is configured to deny access to `.json`, `.log`, and `.env` files to prevent sensitive data exposure.
    *   Set the Webhook URL for your bot to point to `bot.php`.

## Features

*   **Product Management:** Add, edit, and remove products and categories via the Admin Panel.
*   **Delivery Modes:**
    *   **Manual:** Admin manually fulfills the order.
    *   **Instant:** Bot automatically delivers a code/link from a stock list.
*   **User Support:** Direct chat between users and admins.
*   **Statistics:** View sales volume and user stats.
*   **Database Storage:** Robust MySQL backend for users, products, and transactions.

## File Structure

*   `bot.php`: Main entry point (Webhook).
*   `config.php`: Configuration loader.
*   `functions.php`: Core logic and helper functions.
*   `schema.sql`: Database schema definition.
*   `migrate.php`: Data migration script (JSON -> MySQL).
