<?php
namespace App\Core;
class Validator { public static function required(mixed $v): bool { return trim((string)$v) !== ''; } }
