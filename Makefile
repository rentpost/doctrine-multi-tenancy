#!/usr/bin/make -f

SHELL := /bin/bash

# Test that we have the necessary binaries available
define checkExecutables
	$(foreach exec,$(1),\
		$(if $(shell command -v $(exec)),,$(error Unable to find `$(exec)` in your PATH)))
endef

.PHONY: help
help:                ## Shows this help
	$(call checkExecutables, fgrep)
	@fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##//'

##
## Doctrine Multi-Tenancy — Doctrine multi-tenancy library make targets. Run `make help` to list targets.
##

##
## --- Setup ---------------------------------------------------------------
##

.PHONY: init
init:                ## Initializes the project and all dependencies
	$(call checkExecutables, composer)
	@make install-vendors


##
## --- Dependencies --------------------------------------------------------
##

.PHONY: install-vendors
install-vendors:     ## Installs all vendor dependencies
	$(call checkExecutables, composer)
	@composer install


.PHONY: update-vendors
update-vendors:      ## Updates all vendor dependencies
	$(call checkExecutables, composer)
	@composer update


##
## --- Testing -------------------------------------------------------------
##

.PHONY: test
test:                ## Runs all tests
	@vendor/bin/phpunit
