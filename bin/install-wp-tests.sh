#!/usr/bin/env bash
#
# Install WordPress test suite for Comic Easel REST.
#
# Adapted from the wp-cli scaffold-command template. Uses SQLite for the test
# database so the CI matrix does not need a MySQL service container.
#
# Required env:
#   WP_VERSION   default: latest
#   WP_TESTS_DIR default: /tmp/wordpress-tests-lib
#   SKIP_DB_CREATE default: 1 (we use SQLite, no MySQL to create)
#
# Usage:
#   bash bin/install-wp-tests.sh

set -e

WP_VERSION="${WP_VERSION:-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
SKIP_DB_CREATE="${SKIP_DB_CREATE:-1}"

download() {
	if command -v curl > /dev/null; then
		curl -sL "$1" -o "$2"
	elif command -v wget > /dev/null; then
		wget -nv -O "$2>" "$1"
	else
		echo "Neither curl nor wget found." >&2
		exit 1
	fi
}

resolve_latest_wp_version() {
	svn ls https://develop.svn.wordpress.org/tags/ | tail -n 1 | tr -d '/'
}

if [[ ! -d "$WP_TESTS_DIR" ]]; then
	echo "Cloning WordPress test suite..."
	mkdir -p "$(dirname "$WP_TESTS_DIR")"
	if [[ "$WP_VERSION" == "latest" ]]; then
		WP_VERSION="$(resolve_latest_wp_version)"
	fi
	svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

if [[ ! -d "$WP_CORE_DIR" ]]; then
	echo "Downloading WordPress ${WP_VERSION}..."
	mkdir -p "$WP_CORE_DIR"
	if [[ "$WP_VERSION" == "latest" ]]; then
		WP_VERSION="$(resolve_latest_wp_version)"
	fi
	svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/" "$WP_CORE_DIR"
fi

# SQLite drop-in. Reads from WP_CORE_DIR if set, falls back to /tmp/wordpress.
if [[ ! -f "${WP_CORE_DIR}/wp-content/db.php" ]]; then
	echo "Installing SQLite drop-in..."
	mkdir -p "${WP_CORE_DIR}/wp-content"
	download https://raw.githubusercontent.com/WordPress/sqlite-database-integration/main/db.copy "${WP_CORE_DIR}/wp-content/db.php"
	if [[ ! -f "${WP_CORE_DIR}/wp-content/plugins/sqlite-database-integration/load.php" ]]; then
		mkdir -p "${WP_CORE_DIR}/wp-content/plugins/sqlite-database-integration"
		download https://github.com/WordPress/sqlite-database-integration/archive/refs/heads/main.tar.gz /tmp/sqlite-plugin.tar.gz
		tar -xzf /tmp/sqlite-plugin.tar.gz -C /tmp
		cp -r /tmp/sqlite-database-integration-main/* "${WP_CORE_DIR}/wp-content/plugins/sqlite-database-integration/"
	fi
fi

# Install parent plugin (comic-easel) into the WP plugins directory. The test
# bootstrap lists it as an active_plugin, so without this the REST controller
# tests fail because the `comic` CPT doesn't exist.
if [[ ! -d "${WP_CORE_DIR}/wp-content/plugins/comic-easel" ]]; then
	echo "Cloning comic-easel parent plugin..."
	git clone --depth 1 https://github.com/Frumph/comic-easel.git "${WP_CORE_DIR}/wp-content/plugins/comic-easel"
fi

# Install companion plugin (this repo) into the WP plugins directory so the
# test bootstrap can find it under the slug `comic-easel-rest`.
CER_PLUGIN_DIR_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
if [[ ! -d "${WP_CORE_DIR}/wp-content/plugins/comic-easel-rest" ]]; then
	echo "Symlinking comic-easel-rest companion plugin..."
	mkdir -p "${WP_CORE_DIR}/wp-content/plugins/comic-easel-rest"
	for f in "${CER_PLUGIN_DIR_LOCAL}"/*.php "${CER_PLUGIN_DIR_LOCAL}"/composer.json "${CER_PLUGIN_DIR_LOCAL}"/phpcs.xml.dist "${CER_PLUGIN_DIR_LOCAL}"/phpunit.xml.dist "${CER_PLUGIN_DIR_LOCAL}"/readme.txt; do
		ln -s "$f" "${WP_CORE_DIR}/wp-content/plugins/comic-easel-rest/$(basename "$f")"
	done
	for d in includes functions tests; do
		ln -s "${CER_PLUGIN_DIR_LOCAL}/$d" "${WP_CORE_DIR}/wp-content/plugins/comic-easel-rest/$d"
	done
fi

echo "Done. WP_TESTS_DIR=${WP_TESTS_DIR}"
echo "Set DB_FILE in phpunit.xml env or bootstrap to point at a writable .sqlite file."