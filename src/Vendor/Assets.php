<?php
namespace GlpiPlugin\Glpiticketreportsign\Vendor;

use Plugin;

/**
 * Resolves URLs for the third-party JS we depend on (pdf.js,
 * signature_pad).
 *
 * Strategy: prefer a locally-vendored copy under public/vendor/
 * when present, fall back to a public CDN otherwise. This makes
 * the plugin work out of the box on a normal install while still
 * supporting air-gapped sites that drop the files into vendor/.
 *
 * The CDN versions chosen here are deliberately on the last UMD
 * release line (pdf.js 3.x). pdf.js 4.x is ESM-only and would
 * require a different loader, which would force every consumer
 * to set up build tooling.
 */
class Assets
{
    private const PDFJS_VERSION   = '3.11.174';
    private const SIGPAD_VERSION  = '5.0.4';

    /** @return array{pdfJs:string,pdfWorker:string,sigPad:string} */
    public static function urls(): array
    {
        $base       = plugin_glpiticketreportsign_web_dir();
        $vendorBase = $base . '/public/vendor';
        $vendorDir  = __DIR__ . '/../../public/vendor';

        return [
            'pdfJs' => is_file($vendorDir . '/pdf.min.js')
                ? $vendorBase . '/pdf.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' . self::PDFJS_VERSION . '/pdf.min.js',
            'pdfWorker' => is_file($vendorDir . '/pdf.worker.min.js')
                ? $vendorBase . '/pdf.worker.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' . self::PDFJS_VERSION . '/pdf.worker.min.js',
            'sigPad' => is_file($vendorDir . '/signature_pad.umd.min.js')
                ? $vendorBase . '/signature_pad.umd.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/signature_pad/' . self::SIGPAD_VERSION . '/signature_pad.umd.min.js',
        ];
    }
}
