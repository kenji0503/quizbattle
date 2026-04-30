<?php

if (!class_exists('LoggerConfig')) {
    class LoggerConfig
    {
        public static function isLogfile(): bool
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_FILE', '1') : (getenv('QB_LOG_FILE') ?: '1');
            return !in_array(strtolower((string)$raw), ['0', 'false', 'off', 'no'], true);
        }

        public static function logLevel(): int
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_LEVEL', '3') : (getenv('QB_LOG_LEVEL') ?: '3');
            return max(0, min(3, (int)$raw));
        }

        public static function logDirPath(): string
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_DIR', '') : (getenv('QB_LOG_DIR') ?: '');
            if ($raw !== '') {
                return rtrim($raw, "/\\");
            }
            return rtrim(__DIR__, "/\\");
        }

        public static function logFileName(): string
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_NAME', 'console') : (getenv('QB_LOG_NAME') ?: 'console');
            return $raw !== '' ? $raw : 'console';
        }

        public static function logFileMaxSize(): int
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_MAXSIZE', '10485760') : (getenv('QB_LOG_MAXSIZE') ?: '10485760');
            return max(1024, (int)$raw);
        }

        public static function logFilePeriod(): int
        {
            $raw = function_exists('envValue') ? envValue('QB_LOG_PERIOD', '30') : (getenv('QB_LOG_PERIOD') ?: '30');
            return max(1, (int)$raw);
        }
    }
}
