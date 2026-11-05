#!/usr/bin/env bash
# Rename the OpenEvents plugin to Feriae.
# Run from the repository root on a clean working tree:
#   bash rename-to-feriae.sh
# Review the result with `git diff --stat` and `git status` before committing.
set -euo pipefail

if [[ ! -f openevents.php ]]; then
	echo "openevents.php not found. Run this from the plugin root." >&2
	exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
	echo "Working tree is not clean. Commit or stash changes first." >&2
	exit 1
fi

# 1. Text replacements in every tracked text file (binary files skipped).
#    Order matters: longer, more specific forms first.
git ls-files -z | while IFS= read -r -d '' file; do
	grep -Iq . "$file" 2>/dev/null || continue
	perl -pi -e '
		s/X-OE-Bench/X-Feriae-Bench/g;
		s/HTTP_X_OE_BENCH/HTTP_X_FERIAE_BENCH/g;
		s/OPENEVENTS/FERIAE/g;
		s/X-OE-Bench/X-Feriae-Bench/g;
		s/HTTP_X_OE_BENCH/HTTP_X_FERIAE_BENCH/g;
		s/OpenEvents/Feriae/g;
		s/openEvents/feriae/g;
		s/openevents/feriae/g;
		s/(?<![A-Za-z0-9])_oe_/_feriae_/g;
		s/\boe([_-])/feriae$1/g;
	' "$file"
done

# Readme title with a descriptor for directory search.
perl -pi -e 's/^=== Feriae ===$/=== Feriae – Events Calendar, RSVP & Tickets ===/' readme.txt

# 2. File renames.
git mv openevents.php feriae.php
git mv templates/archive-oe_event.html templates/archive-feriae_event.html
git mv templates/single-oe_event.html templates/single-feriae_event.html
git mv templates/taxonomy-oe_event_category.html templates/taxonomy-feriae_event_category.html

# 3. Report anything left over for manual review.
echo "Remaining matches (should be empty):"
git grep -nIiE 'openevents|open events|\boe[_-]|(^|[^A-Za-z0-9])_oe_' -- . ':!package-lock.json' || true

echo
echo "Done. Next: npm install, npm run build, composer lint, npm run make-pot, and the test suites."
