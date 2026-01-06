<?php

namespace Src\Data;

class JsonStorage
{
    public function read(string $filename): array
    {
        if (!file_exists($filename)) {
            return [];
        }
        $json = file_get_contents($filename);
        return json_decode($json, true) ?: [];
    }

    public function write(string $filename, array $data): bool
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($jsonData === false) {
            error_log("JsonStorage: json_encode error for {$filename}: " . json_last_error_msg());
            return false;
        }

        if (file_put_contents($filename, $jsonData) === false) {
            error_log("JsonStorage: file_put_contents error for {$filename}. Check permissions.");
            return false;
        }
        return true;
    }
}
