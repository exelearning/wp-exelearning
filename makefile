# Extend the main Makefile with release-package translation requirements.
include Makefile

.PHONY: package-translations

# Generate PHP and JavaScript runtime translations, then verify that every PO
# catalog produced a non-empty MO file and at least one hashed JSON catalog.
package-translations: mo json
	@set -e; \
	found=0; \
	for po in languages/exelearning-*.po; do \
		if [ ! -e "$$po" ]; then \
			continue; \
		fi; \
		found=1; \
		locale="$${po#languages/exelearning-}"; \
		locale="$${locale%.po}"; \
		mo="$${po%.po}.mo"; \
		if [ ! -s "$$mo" ]; then \
			echo "Error: Missing or empty generated translation file: $$mo" >&2; \
			exit 1; \
		fi; \
		if ! find languages -maxdepth 1 -type f -name "exelearning-$${locale}-*.json" -size +0c | grep -q .; then \
			echo "Error: Missing or empty generated JSON translation for locale: $$locale" >&2; \
			exit 1; \
		fi; \
	done; \
	if [ "$$found" -eq 0 ]; then \
		echo "Error: No translation source files found under languages/." >&2; \
		exit 1; \
	fi

# Add runtime translation generation and validation to the existing package recipe.
package: package-translations
