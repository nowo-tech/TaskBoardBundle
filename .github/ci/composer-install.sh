#!/usr/bin/env bash
# CI helper: patch composer.json (path repo + constraints) then full update.
# Usage: composer-install.sh <symfony-minor> <doctrine-bundle-constraint>
# Example: composer-install.sh 7.4 '^2.10'
set -euo pipefail

SYMFONY="${1:?Symfony version required (e.g. 7.4)}"
DOCTRINE_BUNDLE="${2:?Doctrine bundle constraint required (e.g. ^2.10)}"

echo "Using doctrine/doctrine-bundle ${DOCTRINE_BUNDLE} with Symfony ^${SYMFONY}"

jq --arg symfony "^${SYMFONY}" --arg doctrine "${DOCTRINE_BUNDLE}" '
  .repositories = [
    {"type": "path", "url": "time-track-bundle", "options": {"symlink": false}}
  ] |
  ."require-dev"["nowo-tech/time-track-bundle"] = "^1.0@dev" |
  del(.require["nowo-tech/time-track-bundle"]) |
  .require["symfony/config"] = $symfony |
  .require["symfony/dependency-injection"] = $symfony |
  .require["symfony/form"] = $symfony |
  .require["symfony/framework-bundle"] = $symfony |
  .require["symfony/http-kernel"] = $symfony |
  .require["symfony/routing"] = $symfony |
  .require["symfony/security-bundle"] = $symfony |
  .require["symfony/translation"] = $symfony |
  .require["symfony/twig-bundle"] = $symfony |
  .require["symfony/validator"] = $symfony |
  .require["symfony/yaml"] = $symfony |
  .require["doctrine/doctrine-bundle"] = $doctrine
' composer.json > composer.json.tmp
mv composer.json.tmp composer.json

composer update --with-all-dependencies --no-interaction --prefer-dist --no-progress
