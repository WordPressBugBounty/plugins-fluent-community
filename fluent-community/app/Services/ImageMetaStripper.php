<?php

namespace FluentCommunity\App\Services;

/**
 * Removes EXIF (incl. GPS), XMP, IPTC and comment metadata from an uploaded image.
 *
 * JPEG, PNG and WebP are rewritten at the container level, so pixels are never
 * re-encoded and no image library is needed. WordPress' own Imagick strip keeps
 * the EXIF profile on purpose, so it cannot be relied on for this.
 *
 * A non-normal EXIF orientation survives as a one-tag EXIF block: it is only still
 * present when the rotation could not be baked into the pixels, and dropping it
 * would display the photo sideways.
 */
class ImageMetaStripper
{
    /**
     * @return bool true when the file holds no removable metadata afterwards
     */
    public static function strip($filePath, $mimeType)
    {
        if (!is_file($filePath) || !is_writable($filePath)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
            return false;
        }

        // the rewrite holds about three copies of the file in memory
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        if ($mimeType === 'image/heic') {
            $clean = self::heicWithoutMeta($filePath);

            return $clean !== null && self::replaceFile($filePath, $clean);
        }

        $parsers = [
            'image/jpeg'  => 'stripJpeg',
            'image/jpg'   => 'stripJpeg',
            'image/pjpeg' => 'stripJpeg',
            'image/png'   => 'stripPng',
            'image/webp'  => 'stripWebp',
        ];

        // GIF carries no EXIF; anything else is not an image type the upload endpoints accept
        if (!isset($parsers[$mimeType])) {
            return false;
        }

        $data = file_get_contents($filePath); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ($data === false) {
            return false;
        }

        $clean = call_user_func([ self::class, $parsers[$mimeType] ], $data);

        // malformed container: leave the file as uploaded rather than risk corrupting it
        if ($clean === null) {
            return false;
        }

        if ($clean === $data) {
            return true;
        }

        return self::replaceFile($filePath, $clean);
    }

    public static function stripJpeg($data)
    {
        if (strncmp($data, "\xFF\xD8", 2) !== 0) {
            return null;
        }

        $length = strlen($data);
        $output = "\xFF\xD8";
        $pos = 2;

        while ($pos + 2 <= $length) {
            if ($data[$pos] !== "\xFF") {
                return null;
            }

            $marker = ord($data[$pos + 1]);

            // fill bytes before a marker
            if ($marker === 0xFF) {
                ++$pos;
                continue;
            }

            // End of image. Bytes after it (MPF secondary images, gain maps, vendor
            // trailers) carry their own EXIF, so drop them.
            if ($marker === 0xD9) {
                return $output . "\xFF\xD9";
            }

            // TEM and RSTn have no length field
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $output .= substr($data, $pos, 2);
                $pos += 2;
                continue;
            }

            if ($pos + 4 > $length) {
                return null;
            }

            $segmentLength = unpack('n', substr($data, $pos + 2, 2))[1];
            if ($segmentLength < 2 || $pos + 2 + $segmentLength > $length) {
                return null;
            }

            $segment = substr($data, $pos, 2 + $segmentLength);
            $payload = substr($segment, 4);
            $pos += 2 + $segmentLength;

            // Start of scan: keep the header and the entropy-coded data, then go on parsing
            // segments, since progressive files put more tables and scans (and metadata) after it
            if ($marker === 0xDA) {
                $scanEnd = self::findScanEnd($data, $pos, $length);
                if ($scanEnd === null) {
                    return null;
                }

                $output .= $segment . substr($data, $pos, $scanEnd - $pos);
                $pos = $scanEnd;
                continue;
            }

            $isApp = $marker >= 0xE0 && $marker <= 0xEF;

            if ($marker === 0xE1 && strncmp($payload, "Exif\x00\x00", 6) === 0) {
                $orientation = self::readTiffOrientation(substr($payload, 6));
                if ($orientation > 1) {
                    $exif = "Exif\x00\x00" . self::minimalTiff($orientation);
                    $output .= "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
                }
                continue;
            }

            // keep JFIF (APP0), the ICC colour profile (APP2) and Adobe colour transform (APP14)
            $keepApp = $marker === 0xE0
                || $marker === 0xEE
                || ($marker === 0xE2 && strncmp($payload, "ICC_PROFILE\x00", 12) === 0);

            if (($isApp && !$keepApp) || $marker === 0xFE) {
                continue;
            }

            $output .= $segment;
        }

        return null;
    }

    /**
     * Returns the offset of the first marker after entropy-coded scan data. Inside that data
     * 0xFF is only ever followed by a stuffed 0x00 or a restart marker (RSTn).
     */
    private static function findScanEnd($data, $pos, $length)
    {
        while (true) {
            $pos = strpos($data, "\xFF", $pos);
            if ($pos === false || $pos + 1 >= $length) {
                return null;
            }

            $next = ord($data[$pos + 1]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $pos += 2;
                continue;
            }

            // fill byte: the marker starts at the last 0xFF of the run
            if ($next === 0xFF) {
                ++$pos;
                continue;
            }

            return $pos;
        }
    }

    public static function stripPng($data)
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (strncmp($data, $signature, 8) !== 0) {
            return null;
        }

        $removable = [ 'eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME' ];
        $length = strlen($data);
        $output = $signature;
        $pos = 8;

        while ($pos + 12 <= $length) {
            $chunkLength = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);

            if ($pos + 12 + $chunkLength > $length) {
                return null;
            }

            $chunk = substr($data, $pos, 12 + $chunkLength);
            $pos += 12 + $chunkLength;

            if ($type === 'eXIf') {
                $orientation = self::readTiffOrientation(substr($chunk, 8, $chunkLength));
                if ($orientation > 1) {
                    $output .= self::pngChunk('eXIf', self::minimalTiff($orientation));
                }
                continue;
            }

            if (in_array($type, $removable, true)) {
                continue;
            }

            $output .= $chunk;

            if ($type === 'IEND') {
                return $output;
            }
        }

        return null;
    }

    public static function stripWebp($data)
    {
        if (strlen($data) < 12 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
            return null;
        }

        // readers stop at the declared RIFF size, so bytes appended after it are not chunks
        $length = min(strlen($data), 8 + unpack('V', substr($data, 4, 4))[1]);
        $body = 'WEBP';
        $flagsOffset = null;
        $keptExif = false;
        $pos = 12;

        while ($pos + 8 <= $length) {
            $fourCC = substr($data, $pos, 4);
            $chunkLength = unpack('V', substr($data, $pos + 4, 4))[1];

            if ($pos + 8 + $chunkLength > $length) {
                return null;
            }

            $payload = substr($data, $pos + 8, $chunkLength);
            $pos += 8 + $chunkLength + ($chunkLength & 1);

            if ($fourCC === 'EXIF') {
                // some writers keep the JPEG-style "Exif\0\0" prefix inside the chunk
                $tiff = strncmp($payload, "Exif\x00\x00", 6) === 0 ? substr($payload, 6) : $payload;
                $orientation = self::readTiffOrientation($tiff);
                if ($orientation > 1) {
                    $body .= self::webpChunk('EXIF', self::minimalTiff($orientation));
                    $keptExif = true;
                }
                continue;
            }

            if ($fourCC === 'XMP ') {
                continue;
            }

            if ($fourCC === 'VP8X' && $chunkLength >= 1) {
                $flagsOffset = strlen($body) + 8;
            }

            $body .= self::webpChunk($fourCC, $payload);
        }

        if ($flagsOffset !== null) {
            // VP8X flags: 0x08 = has EXIF, 0x04 = has XMP
            $flags = ord($body[$flagsOffset]) & ~0x04;
            $flags = $keptExif ? ($flags | 0x08) : ($flags & ~0x08);
            $body[$flagsOffset] = chr($flags);
        }

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /**
     * Reads tag 0x0112 from IFD0 of a TIFF/EXIF block. Returns 0 when absent or unreadable.
     */
    public static function readTiffOrientation($tiff)
    {
        if (strlen($tiff) < 8) {
            return 0;
        }

        $byteOrder = substr($tiff, 0, 2);
        if ($byteOrder === 'II') {
            $short = 'v';
            $long = 'V';
        } elseif ($byteOrder === 'MM') {
            $short = 'n';
            $long = 'N';
        } else {
            return 0;
        }

        $ifdOffset = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifdOffset + 2 > strlen($tiff)) {
            return 0;
        }

        $entryCount = unpack($short, substr($tiff, $ifdOffset, 2))[1];

        for ($i = 0; $i < $entryCount; $i++) {
            $entryPos = $ifdOffset + 2 + $i * 12;
            if ($entryPos + 12 > strlen($tiff)) {
                return 0;
            }

            if (unpack($short, substr($tiff, $entryPos, 2))[1] === 0x0112) {
                // the spec says SHORT, but some writers store a LONG (type 4)
                $isLong = unpack($short, substr($tiff, $entryPos + 2, 2))[1] === 4;
                $orientation = $isLong
                    ? unpack($long, substr($tiff, $entryPos + 8, 4))[1]
                    : unpack($short, substr($tiff, $entryPos + 8, 2))[1];
                return ($orientation >= 1 && $orientation <= 8) ? $orientation : 0;
            }
        }

        return 0;
    }

    /**
     * A big-endian TIFF block whose only IFD0 entry is Orientation (SHORT, count 1).
     */
    private static function minimalTiff($orientation)
    {
        return "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0)
            . pack('N', 0);
    }

    private static function pngChunk($type, $payload)
    {
        return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
    }

    private static function webpChunk($fourCC, $payload)
    {
        return $fourCC . pack('V', strlen($payload)) . $payload . (strlen($payload) & 1 ? "\x00" : '');
    }

    /**
     * Writes beside the original and swaps it in, so a failed or partial write never damages the upload.
     */
    private static function replaceFile($filePath, $contents)
    {
        $tempPath = $filePath . '.fcom-tmp';

        if (file_put_contents($tempPath, $contents, LOCK_EX) !== strlen($contents)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @unlink($tempPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            return false;
        }

        @chmod($tempPath, fileperms($filePath) & 0777); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

        if (!rename($tempPath, $filePath)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            @unlink($tempPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            return false;
        }

        return true;
    }

    /**
     * HEIC is an ISO-BMFF container; only Imagick can rewrite it. HEIC rotation lives in
     * the irot box, not in EXIF, so stripping EXIF here cannot turn the photo sideways.
     * Returns the re-encoded file, or null when Imagick cannot read or write HEIC.
     */
    private static function heicWithoutMeta($filePath)
    {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return null;
        }

        try {
            $image = new \Imagick($filePath);
            self::stripImagickKeepingIcc($image);
            $blob = $image->getImageBlob();
            $image->destroy();
        } catch (\Exception $e) {
            return null;
        }

        return $blob === '' ? null : $blob;
    }

    /**
     * Imagick::stripImage() also drops the ICC profile, which shifts colours on wide-gamut
     * phone photos (Display P3), so put it back afterwards.
     */
    public static function stripImagickKeepingIcc(\Imagick $image)
    {
        $profiles = $image->getImageProfiles('icc', true);
        $image->stripImage();

        if (!empty($profiles['icc'])) {
            $image->profileImage('icc', $profiles['icc']);
        }
    }
}
