SHELL = /bin/bash
.DEFAULT_GOAL := help

# https://mwop.net/blog/2023-12-11-advent-makefile.html
##@ Help
help: ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

.PHONY: all
all: style lint analyze tests ## Do everything

# ### #

.PHONY: style
style: ## Fix any style issues
	@echo
	@echo "--> Style: php-cs-fixer"
	vendor/bin/php-cs-fixer fix -v
	@echo

.PHONY: lint
lint: ## Check if the code is valid
	@echo
	@echo "--> Lint"
	find src -name "*.php" -exec php -l {} \;
	find tests -name "*.php" -exec php -l {} \;
	@echo

.PHONY: analyze
analyze: ## Static analysis
	@echo
	@echo "--> PHPStan"
	vendor/bin/phpstan analyse
	@echo

.PHONY: tests
tests: ## Run tests
	@echo
	@echo "--> Tests: Pest"
	@echo
	./vendor/bin/pest
