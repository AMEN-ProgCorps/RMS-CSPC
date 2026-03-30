# RMS-CSPC
Records Management System Version 2 Development

## Setup

This project uses Laravel and Docker for development.

### Prerequisites
- Docker
- Docker Compose

### Running with Docker
1. Clone the repository
2. Run `docker-compose up --build`
3. Access the application at `http://localhost:8000`

### Local Development (without Docker)
1. Install PHP 8.2+ and Composer
2. Run `composer install`
3. Run `npm install && npm run dev`
4. Copy `.env.example` to `.env` and configure
5. Run `php artisan key:generate`
6. Run `php artisan serve`
