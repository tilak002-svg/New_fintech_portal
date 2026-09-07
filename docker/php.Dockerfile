# Local development image only — not required for conventional PHP hosting,
# which just needs pdo_mysql + bcmath enabled on the host's own PHP install.
FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_mysql bcmath
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
