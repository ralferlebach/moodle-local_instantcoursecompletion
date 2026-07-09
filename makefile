# Makefile for local_instantcoursecompletion
# Mirrors the moodle-plugin-ci check suite used in GitHub Actions.
#
# Targets:
#   make all          — fix + full check suite (default)
#   make fix          — auto-fix PHP style + PHPDoc + rebuild AMD
#   make check        — check-only (no auto-fix)
#   make clear        — clear terminal
#
# Individual checks:
#   make lint-php     — PHPCS Moodle coding standard
#   make lint-phpdoc  — Moodle PHPDoc checker
#   make lint-js      — ESLint on AMD source files (skipped when amd/src/ is empty)
#   make lint-mustache — Mustache template syntax
#   make lint-cpd     — PHP Copy/Paste Detector (informational)
#   make lint-md      — PHP Mess Detector (informational)
#
# Auto-fixers:
#   make fix-lint-php — phpcbf PHP code-style auto-fix
#   make fix-phpdoc   — moodlecheck PHPDoc report
#   make amd          — rebuild AMD minified files
#
# Tests:
#   make phpunit      — PHPUnit testsuite for this plugin
#
# Paths are auto-detected from the makefile's own location.
# The plugin lives at <MOODLE_ROOT>/local/instantcoursecompletion/ — always two
# levels below the Moodle root — so both PLUGIN_DIR and MOODLE_ROOT are
# derived automatically and work on any installation.
# Override on the command line if necessary:
#   make lint-php MOODLE_ROOT=/opt/moodle

THIS_DIR      := $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))
PLUGIN_DIR    ?= $(THIS_DIR)
MOODLE_ROOT   ?= $(abspath $(PLUGIN_DIR)/../..)
PLUGIN_NAME   ?= local_instantcoursecompletion
PLUGIN_REL    ?= local/instantcoursecompletion
PHP           ?= $(shell which php 2>/dev/null || echo /usr/bin/php)
PHPCS         ?= phpcs
PHPCBF        ?= phpcbf
NPX           ?= npx

.PHONY: all fix check clear \
        lint-php lint-phpdoc lint-js lint-mustache lint-cpd lint-md \
        fix-lint-php fix-phpdoc amd phpunit

all: clear fix check
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

fix: clear fix-phpdoc fix-lint-php amd
	@echo ""
	@echo "=== All fixes complete. ==="

check: clear lint-php lint-phpdoc lint-mustache lint-cpd phpunit
	@echo ""
	@echo "=== All checks complete. Review output above for errors. ==="

clear:
	clear

lint-php:
	@echo "=== phpcs (Moodle standard, excludes tools/) ==="
	-cd $(PLUGIN_DIR) && $(PHPCS) \
		--standard=moodle \
		--extensions=php \
		--severity=1 \
		--no-cache \
		--ignore=tools/ \
		.

fix-lint-php:
	@echo ""
	@echo "=== phpcbf (auto-fix) ==="
	-cd $(PLUGIN_DIR) && $(PHPCBF) \
		--standard=moodle \
		--extensions=php \
		.

lint-phpdoc:
	@echo ""
	@echo "=== PHPDoc (local_moodlecheck, excludes tools/) ==="
	-cd $(MOODLE_ROOT) && $(PHP) local/moodlecheck/cli/moodlecheck.php \
		--path=$(PLUGIN_REL) \
		--exclude=$(PLUGIN_REL)/tools \
		--format=text 2>&1 | grep -B1 '    Line' | grep -v '^--$$' || true

fix-phpdoc:
	@echo ""
	@echo "=== fix_phpdoc (tools/fix_phpdoc.php) ==="
	-$(PHP) $(PLUGIN_DIR)/tools/fix_phpdoc.php $(PLUGIN_DIR)

lint-mustache:
	@echo ""
	@echo "=== Mustache syntax check ==="
	-$(PHP) $(PLUGIN_DIR)/tools/mustache_check.php \
		$(PLUGIN_DIR)/templates 2>&1 | grep -v '^OK:' || true

lint-cpd:
	@echo ""
	@echo "=== PHP Copy/Paste Detector ==="
	-cd $(PLUGIN_DIR) && phpcpd --min-lines 5 --min-tokens 70 . || true

lint-md:
	@echo ""
	@echo "=== PHP Mess Detector ==="
	-cd $(PLUGIN_DIR) && phpmd . text \
		cleancode,codesize,controversial,design,naming,unusedcode \
		--exclude tests,tools || true

lint-js:
	@echo ""
	@echo "=== ESLint (skipped when amd/src/ is empty) ==="
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		cd $(MOODLE_ROOT) && $(NPX) grunt eslint --root=. \
			--files=$(PLUGIN_REL)/amd/src/ --show-lint-warnings; \
	else \
		echo "No AMD source files — ESLint skipped."; \
	fi

amd:
	@echo ""
	@echo "=== AMD rebuild (skipped when amd/src/ is empty) ==="
	@if ls $(PLUGIN_DIR)/amd/src/*.js 2>/dev/null | grep -q .; then \
		files=$$(find $(PLUGIN_REL)/amd/src -name '*.js' \
			| tr '\n' ',' | sed 's/,$$//'); \
		cd $(MOODLE_ROOT) && $(NPX) grunt amd --root=. --force --files="$$files"; \
	else \
		echo "No AMD source files — skipped."; \
	fi

phpunit:
	@echo ""
	@echo "=== PHPUnit ==="
	@if ! $(PHP) -r \
		"define('CLI_SCRIPT',1); require '$(MOODLE_ROOT)/config.php'; \
		exit(empty(\$$CFG->phpunit_dataroot) ? 1 : 0);" 2>/dev/null; then \
		echo "SKIP: phpunit_dataroot not configured."; \
		echo "      Add to config.php: \$$CFG->phpunit_dataroot = '...';"; \
	else \
		if ! cd $(MOODLE_ROOT) && $(PHP) admin/tool/phpunit/cli/util.php --diag \
				> /dev/null 2>&1; then \
			echo "PHPUnit environment uninitialised or outdated — reinitialising..."; \
			cd $(MOODLE_ROOT) && $(PHP) admin/tool/phpunit/cli/init.php; \
		fi; \
		tmpout=$$(mktemp); \
		cd $(MOODLE_ROOT) && $(PHP) vendor/bin/phpunit \
			--testsuite $(PLUGIN_NAME)_testsuite \
			--testdox > "$$tmpout" 2>&1; \
		phpunit_exit=$$?; \
		grep -v "^ ✔\|^ ✓\|^ ↩" "$$tmpout" || true; \
		rm -f "$$tmpout"; \
		exit $$phpunit_exit; \
	fi
