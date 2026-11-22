<?php

/**
 * Writes the translated app summary and description into appinfo/info.xml, as
 * <summary lang="…"> and <description lang="…"> (the form the App Store and the
 * Apps page read). The texts come from l10n/<lang>.json, so the .po files stay
 * the only place where translations are edited. Run by dev/l10n.sh.
 *
 * Usage: php l10n-info.php <app directory>
 */

declare(strict_types=1);

$app = rtrim($argv[1] ?? '', '/');
$file = $app . '/appinfo/info.xml';
$xml = file_get_contents($file);
if ($xml === false) {
	fwrite(STDERR, "Cannot read $file\n");
	exit(1);
}

$fields = [
	'summary' => '~\t<summary(?: lang="en")?>(.*?)</summary>\n~s',
	'description' => '~\t<description(?: lang="en")?><!\[CDATA\[(.*?)\]\]></description>\n~s',
];

// The English texts, and info.xml without any of the translated elements
$english = [];
foreach ($fields as $tag => $pattern) {
	if (preg_match($pattern, $xml, $m) !== 1) {
		fwrite(STDERR, "No English <$tag> in $file\n");
		exit(1);
	}
	$english[$tag] = $tag === 'summary' ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1) : $m[1];
	$xml = preg_replace('~\t<' . $tag . ' lang="(?!en")[^"]+">.*?</' . $tag . '>\n~s', '', $xml);
}

// The App Store's info.xml schema has its own list of language codes and a maximum length
// for the summary. Nextcloud's regional variants go to the store's code for the language.
const STORE_LANGUAGES = ['af', 'ar', 'ast', 'az', 'bg', 'be', 'bn', 'br', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'eo', 'es', 'es-ar', 'es-co', 'es-mx', 'es-ni', 'es-ve', 'et', 'eu', 'fa', 'fi', 'fr', 'fy', 'ga', 'gd', 'gl', 'he', 'hi', 'hr', 'hu', 'ia', 'id', 'io', 'is', 'it', 'ja', 'ka', 'kk', 'km', 'kn', 'ko', 'lb', 'lt', 'lv', 'mk', 'ml', 'mn', 'mr', 'my', 'nb', 'ne', 'nl', 'nn', 'os', 'pa', 'pl', 'pt', 'pt-br', 'ro', 'ru', 'sk', 'sl', 'sq', 'sr', 'sr-latn', 'sv', 'sw', 'ta', 'te', 'th', 'tr', 'tt', 'udm', 'uk', 'ur', 'vi', 'zh-hans', 'zh-hant'];
const STORE_CODES = [
	'bn_BD' => 'bn', 'cy_GB' => 'cy', 'es_AR' => 'es-ar', 'es_CO' => 'es-co', 'es_MX' => 'es-mx', 'es_NI' => 'es-ni',
	'et_EE' => 'et', 'hi_IN' => 'hi', 'ka_GE' => 'ka', 'lt_LT' => 'lt', 'nn_NO' => 'nn', 'pt_BR' => 'pt-br', 'pt_PT' => 'pt',
	'sr@latin' => 'sr-latn', 'ur_PK' => 'ur', 'zh_CN' => 'zh-hans', 'zh_TW' => 'zh-hant',
];
const SUMMARY_MAX = 128;

$translated = ['summary' => [], 'description' => []];
$skipped = [];
$files = glob($app . '/l10n/*.json');
sort($files);
foreach ($files as $json) {
	$nextcloudLang = basename($json, '.json');
	$lang = STORE_CODES[$nextcloudLang] ?? $nextcloudLang;
	if (!in_array($lang, STORE_LANGUAGES, true)) {
		$skipped[] = $nextcloudLang;
		continue;
	}
	$texts = json_decode(file_get_contents($json), true, 8, JSON_THROW_ON_ERROR)['translations'];
	foreach ($english as $tag => $text) {
		$translation = $texts[trim($text)] ?? '';
		if ($tag === 'summary' && is_string($translation) && mb_strlen(trim($translation)) > SUMMARY_MAX) {
			fwrite(STDERR, "$nextcloudLang: the summary is longer than " . SUMMARY_MAX . " characters, shorten it in the .po file\n");
			exit(1);
		}
		if (is_string($translation) && trim($translation) !== '' && !str_contains($translation, ']]>')) {
			$translated[$tag][$lang] = trim($translation);
		}
	}
}

foreach ($fields as $tag => $pattern) {
	$elements = $tag === 'summary'
		? "\t<summary lang=\"en\">" . htmlspecialchars($english[$tag], ENT_XML1) . "</summary>\n"
		: "\t<description lang=\"en\"><![CDATA[" . $english[$tag] . "]]></description>\n";
	foreach ($translated[$tag] as $lang => $text) {
		$elements .= $tag === 'summary'
			? "\t<summary lang=\"$lang\">" . htmlspecialchars($text, ENT_XML1) . "</summary>\n"
			: "\t<description lang=\"$lang\"><![CDATA[" . $text . "]]></description>\n";
	}
	$xml = preg_replace_callback($pattern, static fn (): string => $elements, $xml, 1);
}

file_put_contents($file, $xml);
echo 'info.xml: summary in ' . count($translated['summary']) . ' languages, description in ' . count($translated['description']) . " languages\n";
if ($skipped !== []) {
	echo 'info.xml: no language code in the App Store for ' . implode(', ', $skipped) . "\n";
}
