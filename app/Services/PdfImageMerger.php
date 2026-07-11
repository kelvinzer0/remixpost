<?php

namespace App\Services;

/**
 * Minimal PDF generator for merging images into a PDF.
 *
 * Pure PHP implementation — no external library or PHP extension required
 * (only GD for image cropping/resizing, which is already installed).
 *
 * Generates a multi-page PDF where each page contains one image fitted to
 * the page with center-crop to the target aspect ratio.
 *
 * PDF structure (simplified):
 *   %PDF-1.4 header
 *   Catalog → Pages → Kids[page1, page2, ...]
 *   Each Page → Contents (image draw) + Resources (XObject = image)
 *   Each image stored as DCTDecode (JPEG) XObject
 *   xref table + trailer
 *
 * Reference: Adobe PDF Reference 1.7 (ISO 32000-1)
 *
 * @license Apache-2.0 (implemented from PDF spec, not derived from third-party code)
 */
class PdfImageMerger
{
    /**
     * Aspect ratio presets (width:height).
     * Values are unitless ratios — actual page size is derived from these.
     */
    public const RATIOS = [
        'a4-portrait'       => ['w' => 595, 'h' => 842, 'label' => 'A4 Portrait (1:1.41)'],
        'a4-landscape'      => ['w' => 842, 'h' => 595, 'label' => 'A4 Landscape (1.41:1)'],
        'letter-portrait'   => ['w' => 612, 'h' => 792, 'label' => 'Letter Portrait'],
        'letter-landscape'  => ['w' => 792, 'h' => 612, 'label' => 'Letter Landscape'],
        'square'            => ['w' => 700, 'h' => 700, 'label' => 'Square (1:1)'],
        '16-9-landscape'    => ['w' => 842, 'h' => 474, 'label' => '16:9 Landscape'],
        '9-16-portrait'     => ['w' => 474, 'h' => 842, 'label' => '9:16 Portrait'],
        '4-3-landscape'     => ['w' => 800, 'h' => 600, 'label' => '4:3 Landscape'],
        '3-4-portrait'      => ['w' => 600, 'h' => 800, 'label' => '3:4 Portrait'],
    ];

    /**
     * Generate a PDF from an array of image files.
     *
     * Each image is center-cropped to the target ratio, then placed on its
     * own PDF page at full page size (no margins).
     *
     * @param array  $imagePaths  Array of absolute file paths to source images
     * @param string $ratioKey    Key from self::RATIOS (e.g. 'a4-portrait')
     * @param string $outputPath  Absolute path where PDF will be saved
     * @return bool  True on success, false on failure
     */
    public static function generate(array $imagePaths, string $ratioKey, string $outputPath): bool
    {
        if (empty($imagePaths)) {
            return false;
        }

        if (!isset(self::RATIOS[$ratioKey])) {
            return false;
        }

        $ratio = self::RATIOS[$ratioKey];
        $pageW = $ratio['w'];
        $pageH = $ratio['h'];

        // Process each image: crop to target ratio, encode as JPEG
        $imageObjects = [];
        foreach ($imagePaths as $path) {
            $processed = self::processImage($path, $pageW, $pageH);
            if ($processed) {
                $imageObjects[] = $processed;
            }
        }

        if (empty($imageObjects)) {
            return false;
        }

        // Build PDF
        return self::writePdf($imageObjects, $pageW, $pageH, $outputPath);
    }

    /**
     * Process a single image: load, center-crop to target ratio, encode JPEG.
     *
     * @return array|null  ['jpeg' => binary, 'width' => px, 'height' => px] or null on failure
     */
    private static function processImage(string $path, int $targetW, int $targetH): ?array
    {
        // Load image based on MIME type
        $image = self::loadImage($path);
        if (!$image) {
            return null;
        }

        $origW = imagesx($image);
        $origH = imagesy($image);

        // Target aspect ratio
        $targetRatio = $targetW / $targetH;
        $origRatio = $origW / $origH;

        // Center-crop to target ratio
        if ($origRatio > $targetRatio) {
            // Source is wider than target — crop width
            $newW = (int) ($origH * $targetRatio);
            $newH = $origH;
            $srcX = (int) (($origW - $newW) / 2);
            $srcY = 0;
        } else {
            // Source is taller than target — crop height
            $newW = $origW;
            $newH = (int) ($origW / $targetRatio);
            $srcX = 0;
            $srcY = (int) (($origH - $newH) / 2);
        }

        // Create cropped canvas
        $cropped = imagecrop($image, ['x' => $srcX, 'y' => $srcY, 'width' => $newW, 'height' => $newH]);
        if ($cropped === false) {
            imagedestroy($image);
            return null;
        }

        // Resize to reasonable PDF resolution (max 1500px on longest side)
        // — keeps file size manageable while maintaining print quality (~150 DPI)
        $maxDim = 1500;
        if ($newW > $newH && $newW > $maxDim) {
            $finalW = $maxDim;
            $finalH = (int) ($maxDim * $newH / $newW);
        } elseif ($newH >= $newW && $newH > $maxDim) {
            $finalH = $maxDim;
            $finalW = (int) ($maxDim * $newW / $newH);
        } else {
            $finalW = $newW;
            $finalH = $newH;
        }

        $resized = imagecreatetruecolor($finalW, $finalH);
        imagecopyresampled($resized, $cropped, 0, 0, 0, 0, $finalW, $finalH, $newW, $newH);

        // Encode as JPEG (quality 85 — good balance for print + file size)
        ob_start();
        imagejpeg($resized, null, 85);
        $jpegData = ob_get_clean();

        // Cleanup
        imagedestroy($image);
        imagedestroy($cropped);
        imagedestroy($resized);

        if (!$jpegData) {
            return null;
        }

        return [
            'jpeg' => $jpegData,
            'width' => $finalW,
            'height' => $finalH,
        ];
    }

    /**
     * Load an image from file path using GD, based on MIME type.
     */
    private static function loadImage(string $path): ?\GdImage
    {
        if (!file_exists($path)) {
            return null;
        }

        $mime = mime_content_type($path);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png'  => @imagecreatefrompng($path) ?: null,
            'image/gif'  => @imagecreatefromgif($path) ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/bmp'  => @imagecreatefrombmp($path) ?: null,
            default      => null,
        };
    }

    /**
     * Write the PDF file with one page per image.
     *
     * PDF structure:
     *   1  Catalog
     *   2  Pages (parent of all page objects)
     *   3..N  Page objects (one per image)
     *   N+1..2N  Content streams (one per page — draws the image)
     *   2N+1..3N  Image XObjects (JPEG data, DCTDecode)
     *   last  xref + trailer
     *
     * @param array  $images  Array of ['jpeg' => binary, 'width' => px, 'height' => px]
     * @param int    $pageW   Page width in PDF points (1/72 inch)
     * @param int    $pageH   Page height in PDF points
     * @param string $outputPath  Where to save the PDF
     */
    private static function writePdf(array $images, int $pageW, int $pageH, string $outputPath): bool
    {
        $numImages = count($images);

        // Build PDF objects
        $objects = []; // 1-indexed: [1 => catalog, 2 => pages, 3.. = pages/contents/images]
        $offsets = []; // byte offset of each object for xref table

        // Object 1: Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        // Object 2: Pages (will reference all page object numbers)
        // CRITICAL: /Kids must be an array of INDIRECT REFERENCES ("N 0 R"),
        // not plain numbers. Plain numbers like [3 4 5] are parsed as
        // integers by PDF readers, causing "non-page object in page tree"
        // errors and rendering blank pages.
        $pageObjectNums = range(3, 3 + $numImages - 1);
        $kidsRefs = array_map(fn($n) => "{$n} 0 R", $pageObjectNums);
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kidsRefs) . "] /Count {$numImages} >>";

        // Objects 3..(3+N-1): Page objects
        // Each page references:
        //   - Parent (Pages object #2)
        //   - MediaBox (page dimensions)
        //   - Contents (content stream for this page)
        //   - Resources (XObject = the image)
        $contentObjStart = 3 + $numImages;      // first content stream object number
        $imageObjStart = $contentObjStart + $numImages;  // first image XObject number

        for ($i = 0; $i < $numImages; $i++) {
            $pageObjNum = 3 + $i;
            $contentObjNum = $contentObjStart + $i;
            $imageObjNum = $imageObjStart + $i;

            $objects[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Contents {$contentObjNum} 0 R /Resources << /XObject << /Im{$i} {$imageObjNum} 0 R >> >> >>";
        }

        // Objects (3+N)..(3+2N-1): Content streams
        // Each content stream draws the image to fill the entire page:
        //   q          — save graphics state
        //   W H 0 0 0  — scale: pageW pageH 0 0 0 cm (translate + scale to fill page)
        //   /ImN Do    — paint the image
        //   Q          — restore graphics state
        //
        // cm operator takes 6 numbers: a b c d e f — affine transform matrix
        // To scale image to pageW × pageH and place at origin:
        //   pageW 0 0 pageH 0 0 cm
        for ($i = 0; $i < $numImages; $i++) {
            $stream = "q\n{$pageW} 0 0 {$pageH} 0 0 cm\n/Im{$i} Do\nQ\n";
            $streamLen = strlen($stream);
            $objects[$contentObjStart + $i] = "<< /Length {$streamLen} >>\nstream\n{$stream}endstream";
        }

        // Objects (3+2N)..: Image XObjects (JPEG data)
        // Each image XObject:
        //   /Type /XObject
        //   /Subtype /Image
        //   /Width W /Height H (in pixels)
        //   /ColorSpace /DeviceRGB
        //   /BitsPerComponent 8
        //   /Filter /DCTDecode (JPEG)
        //   /Length N (bytes of JPEG data)
        for ($i = 0; $i < $numImages; $i++) {
            $img = $images[$i];
            $jpegLen = strlen($img['jpeg']);
            $objNum = $imageObjStart + $i;
            $objects[$objNum] = "<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$jpegLen} >>\nstream\n" . $img['jpeg'] . "\nendstream";
        }

        // Assemble PDF
        $pdf = "%PDF-1.4\n";
        // Binary comment to mark as binary PDF (helps some readers)
        $pdf .= "%" . chr(226) . chr(227) . chr(226) . "\n";

        // Write each object, recording byte offsets for xref
        foreach ($objects as $num => $content) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$content}\nendobj\n";
        }

        // xref table
        $xrefStart = strlen($pdf);
        $numObjects = count($objects) + 1; // +1 for object 0 (free)
        $pdf .= "xref\n";
        $pdf .= "0 {$numObjects}\n";
        $pdf .= "0000000000 65535 f \n"; // object 0 = free

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // trailer
        $pdf .= "trailer\n";
        $pdf .= "<< /Size {$numObjects} /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= "{$xrefStart}\n";
        $pdf .= "%%EOF\n";

        // Write to file
        $result = file_put_contents($outputPath, $pdf);

        return $result !== false;
    }
}
