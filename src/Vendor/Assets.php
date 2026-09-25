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

    // Subresource Integrity hashes for the exact CDN files these
    // version numbers name — computed from the files now vendored in
    // public/vendor/ by default (see that folder's .gitkeep), which
    // is also how these should be regenerated (sha384) if the pinned
    // versions above are ever bumped: only used on the CDN fallback
    // branch below, when a site has deliberately removed a vendored
    // file, so a compromised/rewritten response at that URL is
    // rejected by the browser instead of executing inside every
    // technician's and requester's authenticated GLPI session.
    private const PDFJS_SRI     = 'sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e';
    private const PDFWORKER_SRI = 'sha384-SnzOobpRMLXZ52iJvZm/C0fYw0OQemTXzTjIsdsfMcrCtCEe9qgzxTd3RSklO5x2';
    private const SIGPAD_SRI    = 'sha384-sAvQstqOmAfqxD54T2ZBU8/E8IQJJuNT4hEHwUULkoBRze+eTQ7tZNcXEA5yPzhE';

    /**
     * @return array{
     *   pdfJs:string, pdfJsIntegrity:?string,
     *   pdfWorker:string, pdfWorkerIntegrity:?string,
     *   sigPad:string, sigPadIntegrity:?string,
     * } The *Integrity values are null for a locally-vendored file
     *   (same-origin, no SRI needed) and a sha384 hash on the CDN
     *   fallback branch.
     */
    public static function urls(): array
    {
        $base       = plugin_glpiticketreportsign_web_dir();
        $vendorBase = $base . '/public/vendor';
        $vendorDir  = __DIR__ . '/../../public/vendor';

        $pdfJsLocal     = is_file($vendorDir . '/pdf.min.js');
        $pdfWorkerLocal = is_file($vendorDir . '/pdf.worker.min.js');
        $sigPadLocal    = is_file($vendorDir . '/signature_pad.umd.min.js');

        return [
            'pdfJs' => $pdfJsLocal
                ? $vendorBase . '/pdf.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' . self::PDFJS_VERSION . '/pdf.min.js',
            'pdfJsIntegrity' => $pdfJsLocal ? null : self::PDFJS_SRI,
            'pdfWorker' => $pdfWorkerLocal
                ? $vendorBase . '/pdf.worker.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' . self::PDFJS_VERSION . '/pdf.worker.min.js',
            'pdfWorkerIntegrity' => $pdfWorkerLocal ? null : self::PDFWORKER_SRI,
            'sigPad' => $sigPadLocal
                ? $vendorBase . '/signature_pad.umd.min.js'
                : 'https://cdnjs.cloudflare.com/ajax/libs/signature_pad/' . self::SIGPAD_VERSION . '/signature_pad.umd.min.js',
            'sigPadIntegrity' => $sigPadLocal ? null : self::SIGPAD_SRI,
        ];
    }
}
