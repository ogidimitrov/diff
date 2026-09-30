# ogidimitrov/diff

[![CI](https://github.com/ogidimitrov/diff/actions/workflows/ci.yml/badge.svg)](https://github.com/ogidimitrov/diff/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/ogidimitrov/diff.svg?label=latest)](https://packagist.org/packages/ogidimitrov/diff)
[![Downloads](https://img.shields.io/packagist/dt/ogidimitrov/diff.svg?label=downloads)](https://packagist.org/packages/ogidimitrov/diff)
[![PHP](https://img.shields.io/packagist/php-v/ogidimitrov/diff.svg?label=php)](https://packagist.org/packages/ogidimitrov/diff)
[![License](https://img.shields.io/packagist/l/ogidimitrov/diff.svg?label=license)](https://github.com/ogidimitrov/diff/blob/main/LICENSE)

Robust diff, match and patch for PHP, in the spirit of Google's
[Diff Match and Patch](https://github.com/google/diff-match-patch) library.

- **Diff** — compute the character-based difference between two texts.
- **Match** — find the closest occurrence of a pattern in a text, tolerating typos.
- **Patch** — apply a list of edits to a text, best-effort, even if the text has drifted.

## Highlights

- Pure PHP, no runtime dependencies beyond `ext-mbstring`.
- Fully **UTF-8 aware**: offsets, lengths and edits are measured in characters,
  so multi-byte text (accented letters, emoji, …) is never split in half.
- The familiar `diff_main` / `match_main` / `patch_make` API, plus the tuning
  properties (`Diff_Timeout`, `Match_Threshold`, `Patch_Margin`, …).
- Myers' diff algorithm with the usual speed-ups (common prefix/suffix trimming,
  a half-match heuristic and an automatic line-level pre-pass), the Bitap fuzzy
  matcher, and Unidiff-style patching.

## Requirements

- PHP 8.1 or newer
- `ext-mbstring`

## Installation

```bash
composer require ogidimitrov/diff
```

## Usage

### Diff

```php
use OgiDimitrov\Diff\SimpleDiff;

$simpleDiff = new SimpleDiff();

$before = 'We meet in the morning to plan the week.';
$after  = 'We meet in the evening to plan the week ahead.';

$changes = $simpleDiff->diff_main($before, $after, false);
```

`$changes` is a list of `[operation, text]` pairs, where the operation is one of
`SimpleDiff::DIFF_EQUAL`, `SimpleDiff::DIFF_DELETE` or
`SimpleDiff::DIFF_INSERT`:

```php
[
    [SimpleDiff::DIFF_EQUAL,  'We meet in the '],
    [SimpleDiff::DIFF_DELETE, 'mor'],
    [SimpleDiff::DIFF_INSERT, 'eve'],
    [SimpleDiff::DIFF_EQUAL,  'ning to plan the week'],
    [SimpleDiff::DIFF_INSERT, ' ahead'],
    [SimpleDiff::DIFF_EQUAL,  '.'],
]
```

A diff can be rendered as HTML, rehydrated into either text, or encoded compactly:

```php
$simpleDiff->diff_prettyHtml($changes);         // HTML with <ins>/<del>
$simpleDiff->diff_text1($changes);              // $before
$simpleDiff->diff_text2($changes);              // $after
$simpleDiff->diff_levenshtein($changes);        // number of edits

$delta = $simpleDiff->diff_toDelta($changes);
$simpleDiff->diff_fromDelta($before, $delta);
```

### Match

```php
$text = 'It was the best of times, it was the worst of times.';

$simpleDiff->match_main($text, 'times', 0);    // 19 (nearest to the start)
$simpleDiff->match_main($text, 'times', 45);   // 46 (nearest to the end)
$simpleDiff->match_main($text, 'wrost');       // 37 (typo tolerated)
$simpleDiff->match_main($text, 'wersed');      // -1 (too far off)

$simpleDiff->Match_Threshold = 0.7;
$simpleDiff->match_main($text, 'wersed');      // 37
```

### Patch

```php
$before = 'We meet in the morning to plan the week.';
$after  = 'We meet in the evening to plan the week ahead.';

$patches = $simpleDiff->patch_make($before, $after);

echo $simpleDiff->patch_toText($patches);
```

```text
@@ -12,11 +12,11 @@
 the
-mor
+eve
 ning
@@ -32,9 +32,15 @@
 the week
+ ahead
 .
```

Applying the patch to a text that has since drifted still works:

```php
[$result, $applied] = $simpleDiff->patch_apply(
    $patches,
    'Every week we meet in the morning to plan the week.'
);

// $result  = 'Every week we meet in the evening to plan the week ahead.'
// $applied = [true, true]
```

The matcher locates each patch near where it was expected and reports, patch by
patch, whether it landed. Placing a patch inside highly repetitive text is
best-effort: the position chosen is implementation-defined and can differ
slightly from other Diff Match and Patch ports.

## Tuning

The original library's knobs are exposed as properties on the `SimpleDiff`
instance:

| Property                 | Default | Meaning                                                            |
|--------------------------|---------|--------------------------------------------------------------------|
| `Diff_Timeout`           | `1.0`   | Seconds to spend on a diff before giving up (`0` = no limit).       |
| `Diff_EditCost`          | `4`     | Cost of an empty edit when simplifying a diff.                      |
| `Match_Threshold`        | `0.5`   | Score at which a match is rejected (`0.0` exact, `1.0` very loose). |
| `Match_Distance`         | `1000`  | Distance that scores a full `1.0` worse.                            |
| `Match_MaxBits`          | int width | Bitap pattern length limit.                                       |
| `Patch_DeleteThreshold`  | `0.5`   | Similarity required before a large deletion is accepted.            |
| `Patch_Margin`           | `4`     | Context characters carried by each patch (minimum 1).               |

## API

All methods live on `OgiDimitrov\Diff\SimpleDiff`.

Diff:

- `diff_main(before, after, checklines = true)`
- `diff_cleanupMerge`, `diff_cleanupSemantic`, `diff_cleanupSemanticLossless`, `diff_cleanupEfficiency`
- `diff_commonPrefix`, `diff_commonSuffix`
- `diff_linesToChars`, `diff_charsToLines`
- `diff_levenshtein`, `diff_xIndex`
- `diff_toDelta`, `diff_fromDelta`
- `diff_text1`, `diff_text2`, `diff_prettyHtml`

Match:

- `match_main(text, pattern, loc = 0)`

Patch:

- `patch_make(a, b = null, c = null)`
- `patch_toText`, `patch_fromText`
- `patch_apply`, `patch_addPadding`, `patch_splitMax`

## Testing

```bash
composer install
composer test   # PHPUnit
composer stan   # PHPStan, level 9
```

The suite covers the documented behaviour, the awkward corners (empty inputs,
multi-byte text, deadline expiry, malformed deltas, patches that cannot be
placed, …) and a seeded randomised pass that checks the invariants every diff
must satisfy: a diff rehydrates into both texts, its delta decodes back to the
same diff, the clean-up passes never lose text, and patches serialise
round-trip.

## License

Released under the [MIT License](LICENSE).

The algorithms are those of Google's Diff Match and Patch, originally written
by Neil Fraser. This package is an independent implementation, not a copy of any
existing port. See [NOTICE](NOTICE) for details.
