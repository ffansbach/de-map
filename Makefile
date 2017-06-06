images:
	docker-compose build

up:
	docker-compose up de-map

lint:
	docker-compose run --rm php sh -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l'
