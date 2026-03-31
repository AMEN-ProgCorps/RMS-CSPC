# RMS-CSPC
Records Management System Version 2 Development

## Setup

### Prerequisites
- PHP 8.1 or higher
- Composer
- Node.js and npm (for frontend assets)

### Laravel Installation
1. Install the latest Laravel version:
   ```bash
   composer create-project laravel/laravel records-manangement-system
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Install Node.js dependencies:
   ```bash
   npm install
   ```

4. Copy the environment file and generate application key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. Run database migrations (if applicable):
   ```bash
   php artisan migrate
   ```

6. Start the development server:
   ```bash
   php artisan serve
   ```

For more information, visit the [Laravel documentation](https://laravel.com/docs).
