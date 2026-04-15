#!/usr/bin/make -f

SHELL:=/bin/bash

define checkExecutables
	$(foreach exec,$(1),\
		$(if $(shell command -v $(exec)),,$(error Unable to find `$(exec)` in your PATH)))
endef

.PHONY: help
help:               ## Shows this help
	$(call checkExecutables, fgrep)
	@fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##//'

.PHONY: init
init:               ## Initializes the project and all dependencies
	$(call checkExecutables, composer)
	@make install-vendors

.PHONY: test
test:               ## Runs all tests
	@vendor/bin/phpunit

.PHONY: install-vendors
install-vendors:    ## Installs all vendor dependencies
	$(call checkExecutables, composer)
	@composer install

.PHONY: update-vendors
update-vendors:     ## Updates all vendor dependencies
	$(call checkExecutables, composer)
	@composer update
