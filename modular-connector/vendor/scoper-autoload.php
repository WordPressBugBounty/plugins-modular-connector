<?php

// scoper-autoload.php @generated by PhpScoper

$loader = (static function () {
    // Backup the autoloaded Composer files
    $existingComposerAutoloadFiles = isset($GLOBALS['__composer_autoload_files']) ? $GLOBALS['__composer_autoload_files'] : [];

    $loader = require_once __DIR__.'/autoload.php';
    // Ensure InstalledVersions is available
    $installedVersionsPath = __DIR__.'/composer/InstalledVersions.php';
    if (file_exists($installedVersionsPath)) require_once $installedVersionsPath;

    // Restore the backup and ensure the excluded files are properly marked as loaded
    $GLOBALS['__composer_autoload_files'] = \array_merge(
        $existingComposerAutoloadFiles,
        \array_fill_keys(['b1a9503f908d23d01b424cd50ad564bf', '9eaa6b0f3f04e58e17ae5ecb754ea313', 'e6f249170e5be982500ad31b9375003b', 'acbe0d033c55cd0a032b415e08d14f4c', '05c29678bc01eda3cd6ae031d973f266', 'b48cbeb76a71e226a23fa64ac2b94dc6', '9c18074faba44f352b1ed7cd76d310eb', '36dfd6ed9dd74e8062aa61f09caf8554', '017e775e6e0168d2c04879abf59e2696', '5928a00fa978807cf85d90ec3f4b0147', 'd7f9e941f1adce41bb1aee1cb0864635', '6f778153132d85ec04f61f7b33a8a8f9', 'f8e3169d9142b4b8d07316146da2a05a', '28099935d0ea91a1b5e09408e356eacb', '99b27172349c9ec3abea78f62e2938bb', '9250916e8af80e0d1bb31401fd2e15a7', '674e404d8857dd99db32bc218bb5643a', 'b178954ba4692b8876c08a4a97e6ce23', 'c5e5dfa7f2077b89dbc43523332b50aa', '83cc8b953df9a6f7e51f674d84d57730', 'a875add15ea9a7df1a6c0c26cc9e4590', '1cbb53d50065225a14c2360be2ccbf6f', '54b9ab13bc86d8251a04a939888e357e', 'a89966141ddd51b9b7e868bc3b2f9bb0', '7edcabe1b67fbb38f4972a722bbbb429', 'f49032536fdd06afd9df7191c3f21453', '51421aa3e5e8003b70a289762d146a2a', '7bdb062931f6e7102434c3ad28423eb6', '18e965175c6bcd96deba6bc791a44373', '7b0b5d7b98f96ad751222ae5cc98cfcb', 'd1fb64fd99fc22e28e29a95cc0ea533a'], true)
    );

    return $loader;
})();

// Class aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#class-aliases
if (!function_exists('humbug_phpscoper_expose_class')) {
    function humbug_phpscoper_expose_class($exposed, $prefixed) {
        if (!class_exists($exposed, false) && !interface_exists($exposed, false) && !trait_exists($exposed, false)) {
            spl_autoload_call($prefixed);
        }
    }
}
humbug_phpscoper_expose_class('Normalizer', 'Modular\ConnectorDependencies\Normalizer');
humbug_phpscoper_expose_class('CURLStringFile', 'Modular\ConnectorDependencies\CURLStringFile');
humbug_phpscoper_expose_class('ReturnTypeWillChange', 'Modular\ConnectorDependencies\ReturnTypeWillChange');
humbug_phpscoper_expose_class('UnhandledMatchError', 'Modular\ConnectorDependencies\UnhandledMatchError');
humbug_phpscoper_expose_class('ValueError', 'Modular\ConnectorDependencies\ValueError');
humbug_phpscoper_expose_class('PhpToken', 'Modular\ConnectorDependencies\PhpToken');
humbug_phpscoper_expose_class('Stringable', 'Modular\ConnectorDependencies\Stringable');
humbug_phpscoper_expose_class('Attribute', 'Modular\ConnectorDependencies\Attribute');
humbug_phpscoper_expose_class('JsonException', 'Modular\ConnectorDependencies\JsonException');

// Function aliases. For more information see:
// https://github.com/humbug/php-scoper/blob/master/docs/further-reading.md#function-aliases
if (!function_exists('__')) { function __() { return \Modular\ConnectorDependencies\__(...func_get_args()); } }
if (!function_exists('array_is_list')) { function array_is_list() { return \Modular\ConnectorDependencies\array_is_list(...func_get_args()); } }
if (!function_exists('array_key_first')) { function array_key_first() { return \Modular\ConnectorDependencies\array_key_first(...func_get_args()); } }
if (!function_exists('array_key_last')) { function array_key_last() { return \Modular\ConnectorDependencies\array_key_last(...func_get_args()); } }
if (!function_exists('ctype_alnum')) { function ctype_alnum() { return \Modular\ConnectorDependencies\ctype_alnum(...func_get_args()); } }
if (!function_exists('ctype_alpha')) { function ctype_alpha() { return \Modular\ConnectorDependencies\ctype_alpha(...func_get_args()); } }
if (!function_exists('ctype_cntrl')) { function ctype_cntrl() { return \Modular\ConnectorDependencies\ctype_cntrl(...func_get_args()); } }
if (!function_exists('ctype_digit')) { function ctype_digit() { return \Modular\ConnectorDependencies\ctype_digit(...func_get_args()); } }
if (!function_exists('ctype_graph')) { function ctype_graph() { return \Modular\ConnectorDependencies\ctype_graph(...func_get_args()); } }
if (!function_exists('ctype_lower')) { function ctype_lower() { return \Modular\ConnectorDependencies\ctype_lower(...func_get_args()); } }
if (!function_exists('ctype_print')) { function ctype_print() { return \Modular\ConnectorDependencies\ctype_print(...func_get_args()); } }
if (!function_exists('ctype_punct')) { function ctype_punct() { return \Modular\ConnectorDependencies\ctype_punct(...func_get_args()); } }
if (!function_exists('ctype_space')) { function ctype_space() { return \Modular\ConnectorDependencies\ctype_space(...func_get_args()); } }
if (!function_exists('ctype_upper')) { function ctype_upper() { return \Modular\ConnectorDependencies\ctype_upper(...func_get_args()); } }
if (!function_exists('ctype_xdigit')) { function ctype_xdigit() { return \Modular\ConnectorDependencies\ctype_xdigit(...func_get_args()); } }
if (!function_exists('enum_exists')) { function enum_exists() { return \Modular\ConnectorDependencies\enum_exists(...func_get_args()); } }
if (!function_exists('fdiv')) { function fdiv() { return \Modular\ConnectorDependencies\fdiv(...func_get_args()); } }
if (!function_exists('get_debug_type')) { function get_debug_type() { return \Modular\ConnectorDependencies\get_debug_type(...func_get_args()); } }
if (!function_exists('get_resource_id')) { function get_resource_id() { return \Modular\ConnectorDependencies\get_resource_id(...func_get_args()); } }
if (!function_exists('getallheaders')) { function getallheaders() { return \Modular\ConnectorDependencies\getallheaders(...func_get_args()); } }
if (!function_exists('grapheme_extract')) { function grapheme_extract() { return \Modular\ConnectorDependencies\grapheme_extract(...func_get_args()); } }
if (!function_exists('grapheme_stripos')) { function grapheme_stripos() { return \Modular\ConnectorDependencies\grapheme_stripos(...func_get_args()); } }
if (!function_exists('grapheme_stristr')) { function grapheme_stristr() { return \Modular\ConnectorDependencies\grapheme_stristr(...func_get_args()); } }
if (!function_exists('grapheme_strlen')) { function grapheme_strlen() { return \Modular\ConnectorDependencies\grapheme_strlen(...func_get_args()); } }
if (!function_exists('grapheme_strpos')) { function grapheme_strpos() { return \Modular\ConnectorDependencies\grapheme_strpos(...func_get_args()); } }
if (!function_exists('grapheme_strripos')) { function grapheme_strripos() { return \Modular\ConnectorDependencies\grapheme_strripos(...func_get_args()); } }
if (!function_exists('grapheme_strrpos')) { function grapheme_strrpos() { return \Modular\ConnectorDependencies\grapheme_strrpos(...func_get_args()); } }
if (!function_exists('grapheme_strstr')) { function grapheme_strstr() { return \Modular\ConnectorDependencies\grapheme_strstr(...func_get_args()); } }
if (!function_exists('grapheme_substr')) { function grapheme_substr() { return \Modular\ConnectorDependencies\grapheme_substr(...func_get_args()); } }
if (!function_exists('hrtime')) { function hrtime() { return \Modular\ConnectorDependencies\hrtime(...func_get_args()); } }
if (!function_exists('idn_to_ascii')) { function idn_to_ascii() { return \Modular\ConnectorDependencies\idn_to_ascii(...func_get_args()); } }
if (!function_exists('idn_to_utf8')) { function idn_to_utf8() { return \Modular\ConnectorDependencies\idn_to_utf8(...func_get_args()); } }
if (!function_exists('is_countable')) { function is_countable() { return \Modular\ConnectorDependencies\is_countable(...func_get_args()); } }
if (!function_exists('mb_check_encoding')) { function mb_check_encoding() { return \Modular\ConnectorDependencies\mb_check_encoding(...func_get_args()); } }
if (!function_exists('mb_chr')) { function mb_chr() { return \Modular\ConnectorDependencies\mb_chr(...func_get_args()); } }
if (!function_exists('mb_convert_case')) { function mb_convert_case() { return \Modular\ConnectorDependencies\mb_convert_case(...func_get_args()); } }
if (!function_exists('mb_convert_encoding')) { function mb_convert_encoding() { return \Modular\ConnectorDependencies\mb_convert_encoding(...func_get_args()); } }
if (!function_exists('mb_convert_variables')) { function mb_convert_variables() { return \Modular\ConnectorDependencies\mb_convert_variables(...func_get_args()); } }
if (!function_exists('mb_decode_mimeheader')) { function mb_decode_mimeheader() { return \Modular\ConnectorDependencies\mb_decode_mimeheader(...func_get_args()); } }
if (!function_exists('mb_decode_numericentity')) { function mb_decode_numericentity() { return \Modular\ConnectorDependencies\mb_decode_numericentity(...func_get_args()); } }
if (!function_exists('mb_detect_encoding')) { function mb_detect_encoding() { return \Modular\ConnectorDependencies\mb_detect_encoding(...func_get_args()); } }
if (!function_exists('mb_detect_order')) { function mb_detect_order() { return \Modular\ConnectorDependencies\mb_detect_order(...func_get_args()); } }
if (!function_exists('mb_encode_mimeheader')) { function mb_encode_mimeheader() { return \Modular\ConnectorDependencies\mb_encode_mimeheader(...func_get_args()); } }
if (!function_exists('mb_encode_numericentity')) { function mb_encode_numericentity() { return \Modular\ConnectorDependencies\mb_encode_numericentity(...func_get_args()); } }
if (!function_exists('mb_encoding_aliases')) { function mb_encoding_aliases() { return \Modular\ConnectorDependencies\mb_encoding_aliases(...func_get_args()); } }
if (!function_exists('mb_get_info')) { function mb_get_info() { return \Modular\ConnectorDependencies\mb_get_info(...func_get_args()); } }
if (!function_exists('mb_http_input')) { function mb_http_input() { return \Modular\ConnectorDependencies\mb_http_input(...func_get_args()); } }
if (!function_exists('mb_http_output')) { function mb_http_output() { return \Modular\ConnectorDependencies\mb_http_output(...func_get_args()); } }
if (!function_exists('mb_internal_encoding')) { function mb_internal_encoding() { return \Modular\ConnectorDependencies\mb_internal_encoding(...func_get_args()); } }
if (!function_exists('mb_language')) { function mb_language() { return \Modular\ConnectorDependencies\mb_language(...func_get_args()); } }
if (!function_exists('mb_list_encodings')) { function mb_list_encodings() { return \Modular\ConnectorDependencies\mb_list_encodings(...func_get_args()); } }
if (!function_exists('mb_ord')) { function mb_ord() { return \Modular\ConnectorDependencies\mb_ord(...func_get_args()); } }
if (!function_exists('mb_output_handler')) { function mb_output_handler() { return \Modular\ConnectorDependencies\mb_output_handler(...func_get_args()); } }
if (!function_exists('mb_parse_str')) { function mb_parse_str() { return \Modular\ConnectorDependencies\mb_parse_str(...func_get_args()); } }
if (!function_exists('mb_scrub')) { function mb_scrub() { return \Modular\ConnectorDependencies\mb_scrub(...func_get_args()); } }
if (!function_exists('mb_str_pad')) { function mb_str_pad() { return \Modular\ConnectorDependencies\mb_str_pad(...func_get_args()); } }
if (!function_exists('mb_str_split')) { function mb_str_split() { return \Modular\ConnectorDependencies\mb_str_split(...func_get_args()); } }
if (!function_exists('mb_stripos')) { function mb_stripos() { return \Modular\ConnectorDependencies\mb_stripos(...func_get_args()); } }
if (!function_exists('mb_stristr')) { function mb_stristr() { return \Modular\ConnectorDependencies\mb_stristr(...func_get_args()); } }
if (!function_exists('mb_strlen')) { function mb_strlen() { return \Modular\ConnectorDependencies\mb_strlen(...func_get_args()); } }
if (!function_exists('mb_strpos')) { function mb_strpos() { return \Modular\ConnectorDependencies\mb_strpos(...func_get_args()); } }
if (!function_exists('mb_strrchr')) { function mb_strrchr() { return \Modular\ConnectorDependencies\mb_strrchr(...func_get_args()); } }
if (!function_exists('mb_strrichr')) { function mb_strrichr() { return \Modular\ConnectorDependencies\mb_strrichr(...func_get_args()); } }
if (!function_exists('mb_strripos')) { function mb_strripos() { return \Modular\ConnectorDependencies\mb_strripos(...func_get_args()); } }
if (!function_exists('mb_strrpos')) { function mb_strrpos() { return \Modular\ConnectorDependencies\mb_strrpos(...func_get_args()); } }
if (!function_exists('mb_strstr')) { function mb_strstr() { return \Modular\ConnectorDependencies\mb_strstr(...func_get_args()); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower() { return \Modular\ConnectorDependencies\mb_strtolower(...func_get_args()); } }
if (!function_exists('mb_strtoupper')) { function mb_strtoupper() { return \Modular\ConnectorDependencies\mb_strtoupper(...func_get_args()); } }
if (!function_exists('mb_strwidth')) { function mb_strwidth() { return \Modular\ConnectorDependencies\mb_strwidth(...func_get_args()); } }
if (!function_exists('mb_substitute_character')) { function mb_substitute_character() { return \Modular\ConnectorDependencies\mb_substitute_character(...func_get_args()); } }
if (!function_exists('mb_substr')) { function mb_substr() { return \Modular\ConnectorDependencies\mb_substr(...func_get_args()); } }
if (!function_exists('mb_substr_count')) { function mb_substr_count() { return \Modular\ConnectorDependencies\mb_substr_count(...func_get_args()); } }
if (!function_exists('normalizer_is_normalized')) { function normalizer_is_normalized() { return \Modular\ConnectorDependencies\normalizer_is_normalized(...func_get_args()); } }
if (!function_exists('normalizer_normalize')) { function normalizer_normalize() { return \Modular\ConnectorDependencies\normalizer_normalize(...func_get_args()); } }
if (!function_exists('preg_last_error_msg')) { function preg_last_error_msg() { return \Modular\ConnectorDependencies\preg_last_error_msg(...func_get_args()); } }
if (!function_exists('str_contains')) { function str_contains() { return \Modular\ConnectorDependencies\str_contains(...func_get_args()); } }
if (!function_exists('str_ends_with')) { function str_ends_with() { return \Modular\ConnectorDependencies\str_ends_with(...func_get_args()); } }
if (!function_exists('str_starts_with')) { function str_starts_with() { return \Modular\ConnectorDependencies\str_starts_with(...func_get_args()); } }

return $loader;
