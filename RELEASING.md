# Releasing

Versions come from git tags, [Packagist](https://packagist.org/packages/zemkogabor/xinfra-laravel) picks them up automatically.

1. Update `CHANGELOG.md`
2. Tag and push: `git tag v1.0.0 && git push origin v1.0.0`

First time only: submit the repository on packagist.org and make sure the GitHub hook is active
(Packagist shows a warning on the package page if it is not).

This package depends on `zemkogabor/xinfra-php`, so a new version of that must be released first
if this release needs it.
