# Local development for the Send4 WordPress plugin.
#
#   make up        start WordPress + MariaDB
#   make install   install WordPress, the form plugins, and seed the test forms
#   make connect   point this site at Send4 using .env credentials
#   make e2e       submit the seeded form through the real CF7 pipeline
#   make down      stop; make reset also throws the database away

SHELL := /bin/bash
WP := bin/wp

-include .env
export

.PHONY: up down reset install connect status forms e2e simulate cron-run queue logs test test-php74 test-multisite lint pot zip release-check help

help:
	@grep -E '^[a-z-]+:.*?##' $(MAKEFILE_LIST) | sed 's/:.*##/\t/' | column -t -s $$'\t'

up: ## Start the containers
	docker compose up -d db wordpress

down: ## Stop the containers, keeping data
	docker compose down

reset: ## Stop the containers and delete the database and WordPress files
	docker compose down -v

install: up ## Install WordPress, activate the form plugins, seed the test forms
	$(WP) core install \
		--url=http://localhost:$${WP_HOST_PORT:-8088} \
		--title="Send4 plugin dev" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=dev@send4.local \
		--skip-email
	$(WP) plugin install contact-form-7 wpforms-lite --activate
	$(WP) plugin activate send4
	$(WP) rewrite structure '/%postname%/' --hard
	$(WP) eval-file wp-content/plugins/send4/dev/seed-forms.php
	@echo
	@echo "WordPress:  http://localhost:$${WP_HOST_PORT:-8088}/wp-admin (admin / admin)"
	@echo "Test forms: http://localhost:$${WP_HOST_PORT:-8088}/contact/ and /contact-choices/"
	@echo "Next:       put credentials in .env, then run 'make connect'"

connect: ## Connect to Send4 with the credentials in .env
	@test -n "$${SEND4_CLIENT_ID:-}" || (echo "Set SEND4_CLIENT_ID in .env (copy .env.example)" && exit 1)
	$(WP) send4 connect --client-id="$${SEND4_CLIENT_ID}" --client-secret="$${SEND4_CLIENT_SECRET}"

status: ## Print the plugin's view of the world
	$(WP) send4 status

forms: ## List the forms the plugin can see
	$(WP) send4 forms

e2e: ## Submit the seeded Contact Form 7 form the way a visitor would
	$(WP) eval-file wp-content/plugins/send4/dev/submit-cf7.php

simulate: ## Fire a Gravity Forms or Elementor submission from fixtures: make simulate ADAPTER=gravityforms
	$(WP) eval-file wp-content/plugins/send4/dev/simulate.php $${ADAPTER:-gravityforms}

cron-run: ## Run due WP-Cron events now (drains the retry queue)
	$(WP) cron event run --due-now

queue: ## Show the retry queue
	$(WP) send4 queue

logs: ## Tail the WordPress debug log
	docker compose exec -T wordpress tail -n 100 -f /var/www/html/wp-content/debug.log

# The tests container bind-mounts this directory, vendor/ included, so the
# dependencies have to exist on the host before it can run anything.
vendor: composer.json
	composer install --no-interaction --prefer-dist

test: vendor ## Run the PHPUnit suite
	docker compose up -d db
	docker compose run --rm --build tests vendor/bin/phpunit

# CI runs the suite on 7.4 as well as 8.3, and 7.4 is the version the plugin
# header promises. Reproducing that leg locally is the only way to find out
# before a push that something is 8-only.
test-php74: vendor ## Run the PHPUnit suite on PHP 7.4, as CI does
	docker compose up -d db
	docker compose build --build-arg PHP_VERSION=7.4 tests
	docker compose run --rm tests vendor/bin/phpunit
	docker compose build tests

test-multisite: vendor ## Run the PHPUnit suite as a multisite network, as CI does
	docker compose up -d db
	docker compose run --rm -e WP_TESTS_MULTISITE=1 --build tests vendor/bin/phpunit

lint: vendor ## Run PHPCS and PHPStan
	vendor/bin/phpcs
	php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress

# The header declares Domain Path: /languages, so translators expect a template
# there. Regenerate before a release: strings change in most PRs, and a stale
# template only means a few untranslated strings, never a broken site.
#
# The mount point is a path no file in the repo could contain, on purpose.
# make-pot makes a path relative with a plain str_replace of the source
# directory, so mounted at /app it also ate the "/app" in
# admin/views/partials/app-bar.php and sent translators to a partials-bar.php
# that does not exist. tests/Unit/PotReferencesTest.php watches for a repeat.
pot: ## Regenerate languages/send4.pot from the source
	docker run --rm --user root -v "$(CURDIR)":/send4-pot-source -w /send4-pot-source wordpress:cli-2.12.0-php8.3 \
		wp i18n make-pot . languages/send4.pot --slug=send4 --domain=send4 --allow-root \
		--exclude=vendor,tests,dev,docker,build,bin,public/lib,node_modules

zip: ## Build the distributable plugin zip
	bin/build-zip.sh

# The same check the release workflow runs on the pushed tag. Run it before
# tagging: a tag cannot be moved once WordPress.org has published it.
release-check: ## Check a release is consistent: make release-check [TAG=v0.2.0]
	bin/check-version.sh $(TAG)
