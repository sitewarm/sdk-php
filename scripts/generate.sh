#!/usr/bin/env sh
# Regenerates src/Operations.php and the models (src/Model, src/ObjectSerializer.php) from openapi/openapi.yaml.
# Needs Python 3 with PyYAML and Docker. Run from the repository root.
set -eu

python3 scripts/gen_operations.py
rm -rf build/gen
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/work -w /work \
  openapitools/openapi-generator-cli:v7.26.0 generate \
  -i build/openapi.prep.yaml -g php -o build/gen \
  --global-property "models,supportingFiles=ObjectSerializer.php:ModelInterface.php,modelTests=false,modelDocs=false" \
  --additional-properties "invokerPackage=Sitewarm\\Sdk,modelPackage=Model,srcBasePath=src,hideGenerationTimestamp=true" >/dev/null
rm -rf src/Model src/ObjectSerializer.php
cp -R build/gen/src/Model src/Model
cp build/gen/src/ObjectSerializer.php src/ObjectSerializer.php
echo "generated models"
