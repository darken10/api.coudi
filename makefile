deploy:
	ssh o2switch 'cd ~/sites/api.coudi.liptra.net  && git pull origin main && make install && php artisan optimize '


install: vendor/autoload.php .env public/storage
	php artisan optimize
	php artisan migrate


.env:
	cp .env.example .env
	php artisan key:generate

public/storage:
	php artisan storage:link

vendor/autoload.php: composer.lock
	composer install --no-dev --optimize-autoloader
	touch vendor/autoload.php



