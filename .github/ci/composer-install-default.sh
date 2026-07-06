#!/usr/bin/env bash
# CI helper: path repo for TimeTrack + full update (Symfony 7.4 via composer.json constraints).
set -euo pipefail

jq '
  .repositories = [
    {"type": "path", "url": "time-track-bundle", "options": {"symlink": false}}
  ] |
  .require["nowo-tech/time-track-bundle"] = "^1.0@dev" |
  .require["symfony/config"] = "^7.4" |
  .require["symfony/dependency-injection"] = "^7.4" |
  .require["symfony/form"] = "^7.4" |
  .require["symfony/framework-bundle"] = "^7.4" |
  .require["symfony/http-kernel"] = "^7.4" |
  .require["symfony/routing"] = "^7.4" |
  .require["symfony/security-bundle"] = "^7.4" |
  .require["symfony/translation"] = "^7.4" |
  .require["symfony/twig-bundle"] = "^7.4" |
  .require["symfony/validator"] = "^7.4" |
  .require["symfony/yaml"] = "^7.4" |
  .require["doctrine/doctrine-bundle"] = "^2.10"
' composer.json > composer.json.tmp
mv composer.json.tmp composer.json

composer update --with-all-dependencies --no-interaction --prefer-dist --no-progress
