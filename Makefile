# Makefile

# ============================================
# EXELEARNING STATIC BUILD
# ============================================

# Check if Bun is installed
check-bun:
	@command -v bun >/dev/null 2>&1 || { \
		echo ""; \
		echo "Error: Bun is not installed."; \
		echo "   Install it from: https://bun.sh/"; \
		echo "   Quick install: curl -fsSL https://bun.sh/install | bash"; \
		echo ""; \
		exit 1; \
	}

EDITOR_SUBMODULE_PATH := exelearning
EDITOR_OUTPUT_DIR := $(CURDIR)/dist/static
EDITOR_REPO_DEFAULT := https://github.com/exelearning/exelearning.git
EDITOR_REF_DEFAULT := main

# Fetch editor source code from remote repository (branch/tag, shallow clone)
fetch-editor-source:
	@set -e; \
	get_env() { \
		if [ -f .env ]; then \
			grep -E "^$$1=" .env | tail -n1 | cut -d '=' -f2-; \
		fi; \
	}; \
	REPO_URL="$${EXELEARNING_EDITOR_REPO_URL:-$$(get_env EXELEARNING_EDITOR_REPO_URL)}"; \
	REF="$${EXELEARNING_EDITOR_REF:-$$(get_env EXELEARNING_EDITOR_REF)}"; \
	REF_TYPE="$${EXELEARNING_EDITOR_REF_TYPE:-$$(get_env EXELEARNING_EDITOR_REF_TYPE)}"; \
	if [ -z "$$REPO_URL" ]; then REPO_URL="$(EDITOR_REPO_DEFAULT)"; fi; \
	if [ -z "$$REF" ]; then REF="$${EXELEARNING_EDITOR_DEFAULT_BRANCH:-$$(get_env EXELEARNING_EDITOR_DEFAULT_BRANCH)}"; fi; \
	if [ -z "$$REF" ]; then REF="$(EDITOR_REF_DEFAULT)"; fi; \
	if [ -z "$$REF_TYPE" ]; then REF_TYPE="auto"; fi; \
	echo "Fetching editor source from $$REPO_URL (ref=$$REF, type=$$REF_TYPE)"; \
	rm -rf $(EDITOR_SUBMODULE_PATH); \
	git init -q $(EDITOR_SUBMODULE_PATH); \
	git -C $(EDITOR_SUBMODULE_PATH) remote add origin "$$REPO_URL"; \
	case "$$REF_TYPE" in \
		tag) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			;; \
		branch) \
			git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
			git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			;; \
		auto) \
			if git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "refs/tags/$$REF:refs/tags/$$REF" > /dev/null 2>&1; then \
				echo "Resolved $$REF as tag"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q "tags/$$REF"; \
			else \
				echo "Resolved $$REF as branch"; \
				git -C $(EDITOR_SUBMODULE_PATH) fetch --depth 1 origin "$$REF"; \
				git -C $(EDITOR_SUBMODULE_PATH) checkout -q FETCH_HEAD; \
			fi; \
			;; \
		*) \
			echo "Error: EXELEARNING_EDITOR_REF_TYPE must be one of: auto, branch, tag"; \
			exit 1; \
			;; \
	esac

# Build static version of eXeLearning editor
build-editor: check-bun fetch-editor-source
	@echo "Building eXeLearning static editor..."
	rm -rf $(EDITOR_OUTPUT_DIR)
	cd $(EDITOR_SUBMODULE_PATH) && bun install && OUTPUT_DIR=$(EDITOR_OUTPUT_DIR) bun run build:static
	@# If the build script ignored OUTPUT_DIR, copy from fetched source output.
	@if [ ! -f "$(EDITOR_OUTPUT_DIR)/index.html" ] && [ -f "$(EDITOR_SUBMODULE_PATH)/dist/static/index.html" ]; then \
		echo "Copying build output to dist/static/..."; \
		mkdir -p $(EDITOR_OUTPUT_DIR); \
		cp -R $(EDITOR_SUBMODULE_PATH)/dist/static/* $(EDITOR_OUTPUT_DIR)/; \
	fi
	@echo ""
	@echo "============================================"
	@echo "  Static editor built at dist/static/"
	@echo "============================================"

# Backward-compatible alias
build-editor-no-update: build-editor

# Clean editor build
clean-editor:
	rm -rf dist/static
	rm -rf $(EDITOR_SUBMODULE_PATH)/dist/static
	rm -rf $(EDITOR_SUBMODULE_PATH)/node_modules

# ============================================
# WORDPRESS ENVIRONMENT
# ============================================

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env

start-if-not-running:
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8889)" = "000" ]; then \
		echo "wp-env is NOT running. Starting (previous updating) containers..."; \
		npx wp-env start --update; \
		npx wp-env run cli wp plugin activate exelearning; \
		echo "Visit http://localhost:8888/wp-admin/ to access the eXeLearning dashboard."; \
	else \
		echo "wp-env is already running, skipping start."; \
	fi

# Bring up Docker containers (fetch editor source and rebuild static editor)
up: check-docker build-editor-no-update start-if-not-running

# Start with Playground runtime (no Docker required, for quick testing)
up-playground: build-editor-no-update
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888)" = "000" ]; then \
		echo "Starting wp-env with Playground runtime..."; \
		npx wp-env start --runtime=playground --update; \
		echo "Visit http://localhost:8888/wp-admin/ to access the eXeLearning dashboard (admin/password)."; \
	else \
		echo "wp-env is already running, skipping start."; \
	fi

# Reset the WordPress database
reset:
	npx wp-env reset development

flush-permalinks:
	npx wp-env run cli wp rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	npx wp-env run cli sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

# Stop and remove Docker containers
down: check-docker
	npx wp-env stop

# Clean the environments, the same that running "npx wp-env clean all"
clean:
	npx wp-env clean development
	npx wp-env clean tests
	npx wp-env run cli wp plugin activate exelearning
	npx wp-env run cli wp language core install es_ES --activate
	npx wp-env run cli wp site switch-language es_ES



destroy:
	npx wp-env destroy

# Pass the wp plugin-check
check-plugin-old: check-docker start-if-not-running
	npx wp-env run cli wp plugin install plugin-check --activate --color
	npx wp-env run cli wp plugin check exelearning --exclude-directories=tests --exclude-checks=file_type,image_functions --ignore-warnings --color

# Pass the wp plugin-check with proper error handling
check-plugin: check-docker start-if-not-running
	# Install plugin-check if needed (don't fail if already active)
	@npx wp-env run cli wp plugin install plugin-check --activate --color || true

	# Run plugin check with colored output, capture exit code, and fail if needed
	@echo "Running WordPress Plugin Check..."
	@npx wp-env run cli wp plugin check exelearning \
		--exclude-directories=tests,exelearning,dist \
		--exclude-checks=file_type,image_functions \
		--ignore-warnings \
		--color; \
	EXIT_CODE=$$?; \
	echo ""; \
	if [ $$EXIT_CODE -eq 0 ]; then \
		echo "Plugin Check: ✓ No errors found."; \
	else \
		echo "Plugin Check: ✗ Errors found (exit code: $$EXIT_CODE)."; \
		exit $$EXIT_CODE; \
	fi


# Combined check for lint, tests, untranslated, and more
check: fix lint check-plugin test check-untranslated mo

check-all: check

tests: test

# Run unit tests with PHPUnit. Use FILE or FILTER (or both).
test: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning $$CMD --testdox --colors=always

# Run unit tests in verbose mode. Honor TEST filter if provided.
test-verbose: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(TEST)" ]; then CMD="$$CMD --filter $(TEST)"; fi; \
	CMD="$$CMD --debug --verbose"; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning $$CMD --colors=always

# Minimum coverage threshold (percentage)
# Note: 74% is the achievable maximum given that some code cannot be unit tested:
# - Content_Proxy: exit(), header(), readfile() calls
# - Editor: exit(), include(), ob_start() calls
# - REST_API: file upload handling requires $_FILES superglobal
MIN_COVERAGE ?= 74

# Run tests with code coverage report.
# IMPORTANT: Requires wp-env started with Xdebug enabled:
#   npx wp-env start --xdebug=coverage
# If coverage shows 0%, restart wp-env with the --xdebug=coverage flag.
# NOTE: Uses PHPUnit (not ParaTest) because WordPress tests share a single
# database and don't support parallel execution reliably.
test-coverage: start-if-not-running
	@mkdir -p artifacts/coverage
	@CMD="env XDEBUG_MODE=coverage ./vendor/bin/phpunit --testdox --colors=always --coverage-text=artifacts/coverage/coverage.txt --coverage-html artifacts/coverage/html --coverage-clover artifacts/coverage/clover.xml"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning $$CMD; \
	EXIT_CODE=$$?; \
	echo ""; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "                    COVERAGE SUMMARY                        "; \
	echo "════════════════════════════════════════════════════════════"; \
	grep -E "^\s*(Summary|Classes|Methods|Lines):" artifacts/coverage/coverage.txt 2>/dev/null | head -4 || echo "Coverage data not available"; \
	echo ""; \
	echo "Per-class coverage:"; \
	echo "────────────────────────────────────────────────────────────"; \
	awk '/^[A-Z]/ { class=$$1 } /Methods:.*Lines:/ { \
		lines_pct = $$0; \
		gsub(/.*Lines:[[:space:]]*/, "", lines_pct); \
		gsub(/%.*/, "", lines_pct); \
		if (lines_pct+0 >= 90) color="\033[32m"; \
		else if (lines_pct+0 >= 50) color="\033[33m"; \
		else color="\033[31m"; \
		printf "  %s%-40s\033[0m %s\n", color, class, $$0 \
	}' artifacts/coverage/coverage.txt 2>/dev/null; \
	echo "────────────────────────────────────────────────────────────"; \
	echo "  \033[32m●\033[0m ≥90%  \033[33m●\033[0m ≥50%  \033[31m●\033[0m <50%"; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "Full report: artifacts/coverage/html/index.html"; \
	echo ""; \
	if [ $$EXIT_CODE -ne 0 ]; then exit $$EXIT_CODE; fi; \
	COVERAGE=$$(grep -E "^\s*Lines:" artifacts/coverage/coverage.txt 2>/dev/null | grep -oE "[0-9]+\.[0-9]+" | head -1); \
	if [ -z "$$COVERAGE" ]; then \
		echo "Error: Could not extract coverage percentage"; \
		exit 1; \
	fi; \
	COVERAGE_INT=$$(echo "$$COVERAGE" | cut -d. -f1); \
	echo ""; \
	if [ "$$COVERAGE_INT" -lt "$(MIN_COVERAGE)" ]; then \
		echo "════════════════════════════════════════════════════════════"; \
		echo "  ❌ COVERAGE CHECK FAILED"; \
		echo "     Current: $$COVERAGE% | Required: $(MIN_COVERAGE)%"; \
		echo "════════════════════════════════════════════════════════════"; \
		exit 1; \
	else \
		echo "════════════════════════════════════════════════════════════"; \
		echo "  ✅ COVERAGE CHECK PASSED"; \
		echo "     Current: $$COVERAGE% | Required: $(MIN_COVERAGE)%"; \
		echo "════════════════════════════════════════════════════════════"; \
	fi

# Ensure tests environment has admin user and plugin active
setup-tests-env:
	@echo "Setting up tests environment..."
	@npx wp-env run tests-cli wp core install \
		--url=http://localhost:8889 \
		--title="eXeLearning Tests" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.com \
		--skip-email 2>/dev/null || true
	@npx wp-env run cli wp language core install es_ES --activate
	@npx wp-env run cli wp site switch-language es_ES
	@npx wp-env run tests-cli wp plugin activate exelearning 2>/dev/null || true
	@npx wp-env run tests-cli wp rewrite structure '/%postname%/' --hard 2>/dev/null || true

# Run E2E tests with Playwright against wp-env tests environment (port 8889)
test-e2e: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- $(ARGS)

test-e2e-visual: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- --ui


logs:
	npx wp-env logs

logs-test:
	npx wp-env logs --environment=tests


# Install PHP_CodeSniffer and WordPress Coding Standards in the container
install-phpcs: check-docker start-if-not-running
	@echo "Checking if PHP_CodeSniffer is installed..."
	@if ! npx wp-env run cli bash -c '[ -x "$$HOME/.composer/vendor/bin/phpcs" ]' > /dev/null 2>&1; then \
		echo "Installing PHP_CodeSniffer and WordPress Coding Standards..."; \
		npx wp-env run cli composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
		npx wp-env run cli composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs --no-interaction; \
	else \
		echo "PHP_CodeSniffer is already installed."; \
	fi


# Check code style with PHP Code Sniffer (uses same setup as CI)
lint:
	./vendor/bin/phpcs --standard=.phpcs.xml.dist .

# Automatically fix code style with PHP Code Beautifier (uses same setup as CI)
fix:
	./vendor/bin/phpcbf --standard=.phpcs.xml.dist . || true

# Run PHP Mess Detector ignoring vendor and node_modules
phpmd:
	phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,node_modules,tests

# Finds the CLI container used by wp-env
cli-container:
	@docker ps --format "{{.Names}}" \
	| grep "\-cli\-" \
	| grep -v "tests-cli" \
	|| ( \
		echo "No main CLI container found. Please run 'make up' first." ; \
		exit 1 \
	)

# Fix wihout tty for use on git hooks
fix-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCBF (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcbf --standard=wp-content/plugins/exelearning/.phpcs.xml.dist wp-content/plugins/exelearning

# Lint wihout tty for use on git hooks
lint-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCS (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcs --standard=wp-content/plugins/exelearning/.phpcs.xml.dist wp-content/plugins/exelearning


# Update Composer dependencies
update: check-docker
	composer update --no-cache --with-all-dependencies
	npm update

# Generate a .pot file for translations
pot:
	composer make-pot

# Update .po files from .pot file
po:
	composer update-po

# Generate .mo files from .po files
mo:
	composer make-mo

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Generate the exelearning-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No version specified. Usage: 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in exelearning.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" exelearning.php
	$(SED_INPLACE) "s/define( 'EXELEARNING_VERSION', '[^']*'/define( 'EXELEARNING_VERSION', '$(VERSION)'/" exelearning.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	# Create the ZIP package with proper folder structure
	rm -rf /tmp/exelearning-package
	mkdir -p /tmp/exelearning-package/exelearning
	rsync -av --exclude-from=.distignore ./ /tmp/exelearning-package/exelearning/
	cd /tmp/exelearning-package && zip -r "$(CURDIR)/exelearning-$(VERSION).zip" exelearning
	rm -rf /tmp/exelearning-package

	# Restore the version in exelearning.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" exelearning.php
	$(SED_INPLACE) "s/define( 'EXELEARNING_VERSION', '[^']*'/define( 'EXELEARNING_VERSION', '0.0.0'/" exelearning.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "eXeLearning Static Editor:"
	@echo "  build-editor       - Build static eXeLearning editor from configured repo/ref"
	@echo "  build-editor-no-update - Alias of build-editor"
	@echo "  clean-editor       - Remove static editor build and fetched source node_modules"
	@echo "  fetch-editor-source - Download editor source from configured repo/ref"
	@echo ""
	@echo "General:"
	@echo "  up                 - Fetch source, build static editor, and start Docker containers"
	@echo "  up-playground      - Same as 'up' but using Playground runtime (no Docker required)"
	@echo "  down               - Stop and remove Docker containers"
	@echo "  reset              - Reset the WordPress database"
	@echo "  logs               - Show the docker container logs"
	@echo "  logs-test          - Show logs from test environment"
	@echo "  clean              - Clean up WordPress environment"
	@echo "  destroy            - Destroy the WordPress environment"
	@echo "  flush-permalinks   - Flush the created permalinks"
	@echo "  create-user        - Create a WordPress user if it doesn't exist."
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  fix                - Automatically fix code style with PHP_CodeSniffer"
	@echo "  lint               - Check code style with PHP_CodeSniffer"
	@echo "  fix-no-tty         - Same as 'fix' but without TTY (for git hooks)"
	@echo "  lint-no-tty        - Same as 'lint' but without TTY (for git hooks)"
	@echo "  check-plugin       - Run WordPress plugin-check tests"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run fix, lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for 'check'"
	@echo ""
	@echo "Testing:"
	@echo "  test               - Run PHPUnit tests. Accepts optional variables:"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "                       Examples:"
	@echo "                         make test FILTER=MyTest"
	@echo "                         make test FILE=tests/MyTest.php"
	@echo "                         make test FILE=tests/MyTest.php FILTER=test_my_feature"
	@echo "  test-coverage      - Run PHPUnit with coverage (requires: npx wp-env start --xdebug=coverage)"
	@echo ""
	@echo "  test-e2e           - Run E2E tests (non-interactive)"
	@echo "  test-e2e-visual    - Run E2E tests with visual test UI"
	@echo ""
	@echo "Translations:"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo ""
	@echo "Packaging & Updates:"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Create ZIP package. Usage: make package VERSION=x.y.z"
	@echo ""
	@echo "  help               - Show this help message"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
