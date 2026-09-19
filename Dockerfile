FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_pgsql curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .

RUN mkdir -p uploads && chmod -R 777 uploads

EXPOSE 8000

CMD ["sh", "-c", "php -d include_path=/etc/secrets:. -S 0.0.0.0:${PORT:-8000} -t /app"]
