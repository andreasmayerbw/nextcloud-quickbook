#!/bin/sh
# Translations, the Nextcloud way (same tool and file formats as Nextcloud's own apps):
#   translationfiles/templates/quickbook.pot   all strings found in the code (generated)
#   translationfiles/<lang>/quickbook.po       the translations (edit these, e.g. with Poedit)
#   quickbook/l10n/<lang>.js and .json         what Nextcloud loads (generated, committed, shipped)
#   quickbook/appinfo/info.xml                 <summary lang> and <description lang> (generated)
#
#   dev/l10n.sh           extract strings, bring every .po up to date, write quickbook/l10n/
#   dev/l10n.sh de fr     the same, and start translations for these languages when missing
#
# Runs Nextcloud's translationtool in a throwaway container (needs Docker, nothing else).
set -eu
cd "$(dirname "$0")/.."

APP=quickbook
# Pinned: https://github.com/nextcloud/docker-ci/tree/master/translations/translationtool
TOOL_COMMIT=5ed96fa6f9535415f9da1e672f38d7f7c659b2ee
TOOL_SHA256=667ca0a3d7ca8858f66f72007792d5bbab867dba6b1bb458d95ec7a74f17385f
TOOL=dev/.cache/translationtool-$TOOL_COMMIT.phar

if [ ! -f "$TOOL" ]; then
	mkdir -p dev/.cache
	curl -fsSL -o "$TOOL.tmp" "https://raw.githubusercontent.com/nextcloud/docker-ci/$TOOL_COMMIT/translations/translationtool/translationtool.phar"
	echo "$TOOL_SHA256  $TOOL.tmp" | sha256sum -c - >/dev/null || { echo "translationtool.phar has an unexpected checksum" >&2; rm -f "$TOOL.tmp"; exit 1; }
	mv "$TOOL.tmp" "$TOOL"
fi

for lang in "$@"; do
	case $lang in *[!A-Za-z_@]*|'') echo "Not a language code: $lang" >&2; exit 1 ;; esac
done
mkdir -p translationfiles $APP/l10n

docker run --rm -i \
	-v "$PWD/$APP:/work/$APP" \
	-v "$PWD/translationfiles:/work/translationfiles" \
	-v "$PWD/$TOOL:/translationtool.phar:ro" \
	-v "$PWD/dev/l10n-info.php:/l10n-info.php:ro" \
	-e APP=$APP -e OWNER="$(id -u):$(id -g)" -w /work \
	php:8.3-cli-alpine sh -eu -s -- "$@" <<'CONTAINER'
apk add --no-cache gettext >/dev/null
POT=translationfiles/templates/$APP.pot

rm -f "$POT"
php /translationtool.phar create-pot-files
# Paths relative to the repository, and no date that changes on every run
sed -i -e 's|^#: /work/|#: |' -e '/^"POT-Creation-Date: /d' "$POT"

for lang in "$@"; do
	if [ ! -f "translationfiles/$lang/$APP.po" ]; then
		mkdir -p "translationfiles/$lang"
		msginit --no-translator --locale="$lang.UTF-8" --input="$POT" --output-file="translationfiles/$lang/$APP.po" 2>/dev/null
		echo "Started translationfiles/$lang/$APP.po"
	fi
done

for po in translationfiles/*/"$APP".po; do
	[ -f "$po" ] || continue
	lang=$(basename "$(dirname "$po")")
	msgmerge --quiet --update --backup=none --no-fuzzy-matching --no-wrap "$po" "$POT"
	msgattrib --no-obsolete --no-wrap --output-file="$po" "$po"
	todo=$(msgattrib --untranslated --no-wrap "$po" | grep -c '^msgid "..*"' || true)
	echo "$lang: $todo strings still to translate"
done

rm -f "$APP"/l10n/*.js "$APP"/l10n/*.json
php /translationtool.phar convert-po-files
# The translated app summary and description also go into info.xml (Apps page, App Store)
php /l10n-info.php "$APP"
chown -R "$OWNER" translationfiles "$APP/l10n" "$APP/appinfo/info.xml"
CONTAINER
