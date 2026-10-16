<?php

namespace FluentCommunity\App\Services\Insights;

/**
 * Turns a card payload into CSV. Every cell that a spreadsheet could read as a
 * formula (= + - @, tab, carriage return) is prefixed with a quote, and the
 * file starts with a UTF-8 BOM so Excel reads non-Latin names correctly.
 */
class CsvExporter
{
    /**
     * @param array $payload a card payload (card, type, data)
     * @return string
     */
    public static function fromPayload(array $payload)
    {
        $rows = self::rows($payload);

        // An in-memory stream, not a file: fputcsv() is the only correct CSV quoting in PHP.
        $handle = fopen('php://temp', 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function cell($value)
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = implode(' ', array_map('strval', $value));
        }

        $value = wp_strip_all_tags((string)$value);

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private static function rows(array $payload)
    {
        $data = isset($payload['data']) ? $payload['data'] : [];
        $type = isset($payload['type']) ? $payload['type'] : '';

        switch ($type) {
            case 'timeseries':
                $header = [__('Date', 'fluent-community')];
                foreach ($data['series'] as $series) {
                    $header[] = $series['label'];
                }
                $rows = [$header];
                foreach ($data['buckets'] as $index => $bucket) {
                    $row = [$bucket];
                    foreach ($data['series'] as $series) {
                        $row[] = $series['values'][$index];
                    }
                    $rows[] = $row;
                }

                return $rows;

            case 'table':
                $columns = array_values(array_filter($data['columns'], function ($column) {
                    return !in_array($column['type'], ['sparkline', 'avatar'], true);
                }));
                $rows = [
                    array_map(function ($column) {
                        return $column['label'];
                    }, $columns),
                ];
                foreach ($data['rows'] as $row) {
                    $rows[] = array_map(function ($column) use ($row) {
                        return isset($row[$column['key']]) ? $row[$column['key']] : '';
                    }, $columns);
                }

                return $rows;

            case 'kpi':
                $rows = [[__('Metric', 'fluent-community'), __('Value', 'fluent-community'), __('Previous', 'fluent-community')]];
                foreach ($data['items'] as $item) {
                    $rows[] = [$item['label'], $item['value'], $item['previous']];
                }

                return $rows;

            case 'distribution':
                $rows = [[__('Group', 'fluent-community'), __('Value', 'fluent-community')]];
                foreach ($data['buckets'] as $bucket) {
                    $rows[] = [$bucket['label'], $bucket['value']];
                }

                return $rows;

            case 'attention':
                $rows = [[__('Item', 'fluent-community'), __('Count', 'fluent-community')]];
                foreach ($data['items'] as $item) {
                    $rows[] = [$item['label'], $item['count']];
                }

                return $rows;
        }

        return [];
    }
}
