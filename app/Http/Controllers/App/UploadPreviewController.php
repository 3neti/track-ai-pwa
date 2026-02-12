<?php

namespace App\Http\Controllers\App;

use App\Contracts\SarasClientInterface;
use App\Http\Controllers\Controller;
use App\Models\Upload;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadPreviewController extends Controller
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
    ) {}

    /**
     * Stream/return the file preview for an upload.
     */
    public function preview(Upload $upload): Response|StreamedResponse
    {
        $this->authorize('view', $upload);

        if (! $upload->is_previewable) {
            abort(404, 'This upload cannot be previewed.');
        }

        // Check for local file first (works for any status)
        if ($upload->hasLocalFile()) {
            return $this->localFilePreview($upload);
        }

        // If no local file and not yet uploaded, can't preview
        if ($upload->status !== Upload::STATUS_UPLOADED) {
            abort(404, 'This upload is not yet available for preview.');
        }

        // In stub mode, return placeholder content
        if ($this->sarasClient->isStubMode()) {
            return $this->stubPreview($upload);
        }

        // In live mode, proxy from Saras (to be implemented)
        // For now, return stub even in live mode as fallback
        return $this->stubPreview($upload);
    }

    /**
     * Serve the locally stored file.
     */
    protected function localFilePreview(Upload $upload): Response
    {
        $path = $upload->getLocalFilePath();

        if (! $path || ! file_exists($path)) {
            abort(404, 'Local file not found.');
        }

        $content = file_get_contents($path);
        $filename = $this->sanitizeFilename($upload->title).'.'.$this->getExtension($upload->mime);

        return response($content, 200, [
            'Content-Type' => $upload->mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Get file extension from mime type.
     */
    protected function getExtension(?string $mime): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
        ];

        return $mimeToExt[$mime] ?? 'bin';
    }

    /**
     * Sanitize filename for Content-Disposition header.
     */
    protected function sanitizeFilename(string $filename): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    }

    /**
     * Return stub preview content for development.
     */
    protected function stubPreview(Upload $upload): Response
    {
        $previewType = $upload->preview_type;

        if ($previewType === 'image') {
            // Return a placeholder image (1x1 transparent PNG as base, or a nicer placeholder)
            $placeholder = $this->generatePlaceholderImage($upload->title);

            return response($placeholder, 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'inline; filename="preview.png"',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        if ($previewType === 'pdf') {
            // Return a simple PDF placeholder
            $pdf = $this->generatePlaceholderPdf($upload->title);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="preview.pdf"',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        abort(404, 'Preview not available.');
    }

    /**
     * Generate a placeholder image with text.
     */
    protected function generatePlaceholderImage(string $title): string
    {
        // Create a simple 400x300 placeholder image
        $width = 400;
        $height = 300;
        $image = imagecreatetruecolor($width, $height);

        // Colors
        $bgColor = imagecolorallocate($image, 241, 245, 249); // slate-100
        $textColor = imagecolorallocate($image, 71, 85, 105); // slate-600
        $borderColor = imagecolorallocate($image, 203, 213, 225); // slate-300

        // Fill background
        imagefill($image, 0, 0, $bgColor);

        // Draw border
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

        // Add text
        $text = 'Preview: '.substr($title, 0, 30);
        $fontSize = 4;
        $textWidth = imagefontwidth($fontSize) * strlen($text);
        $textHeight = imagefontheight($fontSize);
        $x = ($width - $textWidth) / 2;
        $y = ($height - $textHeight) / 2;
        imagestring($image, $fontSize, (int) $x, (int) $y, $text, $textColor);

        // Add subtitle
        $subtitle = '(Stub Mode Preview)';
        $subtitleWidth = imagefontwidth(2) * strlen($subtitle);
        imagestring($image, 2, (int) (($width - $subtitleWidth) / 2), (int) $y + 20, $subtitle, $textColor);

        // Capture output
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    /**
     * Generate a placeholder PDF.
     */
    protected function generatePlaceholderPdf(string $title): string
    {
        // Minimal valid PDF with text
        $content = "%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj
<< /Length 100 >>
stream
BT
/F1 24 Tf
50 700 Td
(Preview: {$title}) Tj
0 -30 Td
/F1 12 Tf
(Stub Mode - No actual file content) Tj
ET
endstream
endobj
5 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
xref
0 6
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000266 00000 n 
0000000418 00000 n 
trailer
<< /Size 6 /Root 1 0 R >>
startxref
497
%%EOF";

        return $content;
    }
}
