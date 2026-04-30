<?php

require_once __DIR__ . '/config.php';

if (!class_exists('Logger')) {
    class Logger
    {
        const LOG_LEVEL_ERROR = 0;
        const LOG_LEVEL_WARN = 1;
        const LOG_LEVEL_INFO = 2;
        const LOG_LEVEL_DEBUG = 3;

        private static $singleton;

        public static function getInstance()
        {
            if (!isset(self::$singleton)) {
                self::$singleton = new Logger();
            }
            return self::$singleton;
        }

        private function __construct() {}

        public function error($msg)
        {
            if (self::LOG_LEVEL_ERROR <= LoggerConfig::logLevel()) {
                $this->out('ERROR', $msg);
            }
        }

        public function warn($msg)
        {
            if (self::LOG_LEVEL_WARN <= LoggerConfig::logLevel()) {
                $this->out('WARN', $msg);
            }
        }

        public function info($msg)
        {
            if (self::LOG_LEVEL_INFO <= LoggerConfig::logLevel()) {
                $this->out('INFO', $msg);
            }
        }

        public function debug($msg)
        {
            if (self::LOG_LEVEL_DEBUG <= LoggerConfig::logLevel()) {
                $this->out('DEBUG', $msg);
            }
        }

        private function out($level, $msg)
        {
            $pid = getmypid();
            $time = $this->getTime();
            $logMessage = "[{$time}][{$pid}][{$level}] " . rtrim((string)$msg) . "\n";

            if (!LoggerConfig::isLogfile()) {
                error_log($logMessage, 0);
                return;
            }

            $logDir = LoggerConfig::logDirPath();
            if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                error_log($logMessage, 0);
                return;
            }

            if (!is_writable($logDir)) {
                error_log($logMessage, 0);
                return;
            }

            $logFilePath = $logDir . DIRECTORY_SEPARATOR . LoggerConfig::logFileName() . '.log';
            $result = @file_put_contents($logFilePath, $logMessage, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                error_log($logMessage, 0);
                return;
            }

            clearstatcache(true, $logFilePath);
            if (!is_file($logFilePath)) {
                return;
            }

            $fileSize = @filesize($logFilePath);
            if ($fileSize === false || $fileSize <= LoggerConfig::logFileMaxSize()) {
                return;
            }

            $oldPath = $logDir . DIRECTORY_SEPARATOR . LoggerConfig::logFileName() . '_' . date('YmdHis');
            $oldLogFilePath = $oldPath . '.log';
            if (!@rename($logFilePath, $oldLogFilePath)) {
                return;
            }

            $contents = @file_get_contents($oldLogFilePath);
            if ($contents === false) {
                return;
            }

            $gz = @gzopen($oldPath . '.gz', 'w9');
            if ($gz) {
                @gzwrite($gz, $contents);
                @gzclose($gz);
                @unlink($oldLogFilePath);
            }

            $retentionDate = new DateTime();
            $retentionDate->modify('-' . LoggerConfig::logFilePeriod() . ' day');
            $dh = @opendir($logDir);
            if ($dh === false) {
                return;
            }

            $pattern = '/' . preg_quote(LoggerConfig::logFileName(), '/') . '_(\d{14}).*\.gz$/';
            while (($fileName = readdir($dh)) !== false) {
                if (preg_match($pattern, $fileName, $matches) !== 1) {
                    continue;
                }
                $logCreatedDate = DateTime::createFromFormat('YmdHis', $matches[1]);
                if ($logCreatedDate instanceof DateTime && $logCreatedDate < $retentionDate) {
                    @unlink($logDir . DIRECTORY_SEPARATOR . $fileName);
                }
            }
            closedir($dh);
        }

        private function getTime()
        {
            $microTime = microtime(true);
            $sec = (int)$microTime;
            $msec = (int)(($microTime - $sec) * 1000);
            return date('Y-m-d H:i:s', $sec) . sprintf('.%03d', $msec);
        }
    }
}
