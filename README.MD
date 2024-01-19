# Composer Plugin Patchsets

This is an unmaintained fork of
[github.com/wieni/composer-plugin-patchsets](https://github.com/wieni/composer-plugin-patchsets).
See that for the general documentation.

This fork attempts to fix automatically re-patching dependencies when the patch
repositories are updated as well as automatically patching all dependencies on
initial install.

This fork was abandoned because it was deemed more feasible to call
`composer patches-relock` and/or `composer patches-repatch` at the appropriate
places manually rather than maintaining this fork. Feel free to use the code,
however, or open an issue if you want to make a case for actually making this a
properly maintained plugin.

See also:
- https://github.com/wieni/composer-plugin-patchsets/issues/1
- https://github.com/wieni/composer-plugin-patchsets/pull/2

As of now, this fork is not published on [Packagist](https://packagist.org).
