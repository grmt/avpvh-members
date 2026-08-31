#!/usr/bin/env python3
"""Standalone tests for normalization shared by the Python imports."""

from _avpvh_import_common import normalize_address_key, normalize_name_key


def assert_equal(expected, actual, label: str) -> None:
    if expected != actual:
        raise AssertionError(f'{label}: expected {expected!r}, got {actual!r}')


assert_equal(
    normalize_name_key('José', 'Voorbeeld'),
    normalize_name_key('Jose', 'Voorbeeld'),
    'accent-insensitive name',
)
assert_equal(
    normalize_name_key('Test', 'Voorbeeld', 'van der'),
    normalize_name_key('Test', 'van der Voorbeeld'),
    'separate and combined suffix',
)
assert_equal(
    normalize_name_key('Test', 'Voorbeeld', 'van der'),
    normalize_name_key('Test', 'v/d Voorbeeld'),
    'v/d suffix',
)
assert_equal(
    normalize_name_key('Test', 'Voorbeeld', 'van der'),
    normalize_name_key('Test', 'vd Voorbeeld'),
    'vd suffix',
)
assert_equal(
    normalize_name_key('Testvoornaam Een', 'Test Achternaam'),
    normalize_name_key('Testvoornaam-Een', 'Test.Achternaam'),
    'punctuation normalization',
)

city_aliases = {('nederland', 'den bosch'): "'s-Hertogenbosch"}
street_aliases = {
    ('nederland', '1234 ab', "'s hertogenbosch", 'f van voorbeeldweg'):
        'Frederika van Voorbeeldweg',
}
short = normalize_address_key({
    'street': 'F. van Voorbeeldweg',
    'house_number': '12a',
    'postal_code': '1234ab',
    'city': 'Den Bosch',
    'country': 'Nederland',
}, city_aliases, street_aliases)
canonical = normalize_address_key({
    'street': 'Frederika van Voorbeeldweg',
    'house_number': '12a',
    'postal_code': '1234 AB',
    'city': "'s-Hertogenbosch",
    'country': 'Nederland',
}, city_aliases, street_aliases)
assert_equal(canonical, short, 'scoped address aliases')

print('Python import normalization tests: OK')
