# Extend the main Makefile with release-package translation requirements.
include Makefile

.PHONY: package-translations

# Compile every committed PO catalog and verify that each generated MO exists
# and is non-empty before the package recipe copies files into the release ZIP.
package-translations: mo
	@set -e; \
	found=0; \
	for po in languages/exelearning-*.po; do \
		if [ ! -e "$$po" ]; then \
			continue; \
		fi; \
		found=1; \
		mo="$${po%.po}.mo"; \
		if [ ! -s "$$mo" ]; then \
			echo "Error: Missing or empty generated translation file: $$mo" >&2; \
			exit 1; \
		fi; \
	done; \
	if [ "$$found" -eq 0 ]; then \
		echo "Error: No translation source files found under languages/." >&2; \
		exit 1; \
	fi

# Add translation generation and validation to the existing package recipe.
package: package-translations
