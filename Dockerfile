# 1️⃣ Imagen base con PHP
FROM php:8.2-fpm

# 2️⃣ Instalar extensiones necesarias y herramientas
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libonig-dev \
    curl \
    && docker-php-ext-install pdo_mysql mbstring zip

# 3️⃣ Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 4️⃣ Crear carpeta de trabajo
WORKDIR /var/www/html

# 5️⃣ Copiar todo el proyecto al contenedor
COPY . .

# 6️⃣ Instalar dependencias de Laravel
RUN composer install --no-dev --optimize-autoloader

# 7️⃣ Exponer puerto para Render
EXPOSE 8000

# 8️⃣ Comando para arrancar Laravel
CMD php artisan serve --host 0.0.0.0 --port $PORT
