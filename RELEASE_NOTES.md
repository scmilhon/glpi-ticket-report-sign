Security hardening, in-person signing and full translations

GLPI plugin that generates per-ticket PDF reports, with digital signature from both technician and client, and email-based remote signing. Compatible with GLPI 10, 11 and 12.

## What's new since v0.0.8

**In-person signing.** A technician can now collect a signature directly on the ticket, verified with a 6-digit code sent to the signer's email — no link or second device required. Supports a walk-in signer who isn't one of the ticket's registered requesters.

**Editable notification templates.** Sign-request, resend and closed-without-signature emails are now editable from Setup → Notifications, instead of fixed wording.

**Full translations.** The PDF report's own text (section headers, field labels, status words) is now translated along with the rest of the plugin. Previously it was hardcoded in Spanish regardless of the interface language.

## Security hardening

Following a full external security review, all 23 findings are resolved:

- Private follow-ups are never included in a report handed to the requester.
- Signatures are bound to a content fingerprint of the report, so any edit after signing invalidates it.
- Every ajax endpoint checks the plugin's own read/create rights.
- The signing link's HMAC key is the installation's real per-instance secret, not the public constant every GLPI ships with.
- CSRF exemptions are scoped precisely to the anonymous, token-authenticated signing endpoints.
- Vendored third-party libraries (FPDF, pdf.js, signature_pad, php-qrcode) are declared so dependency scanners can see them; demo/example code removed; CDN fallbacks integrity-pinned.
- Table creation goes through GLPI's own Migration API instead of hand-written SQL.
- Uninstall now removes generated report documents.

## Fixes

- The "mark as diagnosis" checkbox on a follow-up/solution — missing on every GLPI version — now renders correctly.

## Requirements

- GLPI 10.0 – 12.0
- PHP 8.2+, `gd` extension

## Languages

Interface, PDF report and documentation available in Spanish and English.

---

Refuerzo de seguridad, firma presencial y traducciones completas

Plugin de GLPI que genera informes PDF por ticket, con firma digital del técnico y del cliente, y envío del enlace de firma por correo. Compatible con GLPI 10, 11 y 12.

## Novedades desde v0.0.8

**Firma presencial.** Un técnico ahora puede recolectar una firma directamente en el ticket, verificada con un código de 6 dígitos enviado al correo del firmante — sin necesidad de enlace ni de otro dispositivo. Incluye soporte para un firmante presencial que no es uno de los solicitantes registrados del ticket.

**Plantillas de notificación editables.** Los correos de solicitud de firma, reenvío y cierre sin firma ahora se editan desde Configuración → Notificaciones, en lugar de tener un texto fijo.

**Traducciones completas.** El texto del propio informe PDF (encabezados de sección, etiquetas de campo, estados) ahora se traduce junto con el resto del plugin. Antes estaba fijo en español sin importar el idioma de la interfaz.

## Refuerzo de seguridad

Tras una revisión de seguridad externa completa, se resolvieron los 23 hallazgos:

- Los seguimientos privados nunca se incluyen en un informe entregado al solicitante.
- Las firmas quedan vinculadas a una huella del contenido del informe, así que cualquier edición posterior a la firma la invalida.
- Cada endpoint ajax verifica los permisos propios del plugin (lectura/creación).
- La clave HMAC del enlace de firma es el secreto real de la instalación, no la constante pública que trae toda instalación de GLPI.
- Las excepciones de CSRF están limitadas con precisión a los endpoints anónimos autenticados por token.
- Las librerías de terceros incluidas (FPDF, pdf.js, signature_pad, php-qrcode) quedan declaradas para que las herramientas de auditoría de dependencias las detecten; se eliminó código de demostración/ejemplo; los respaldos por CDN quedan verificados por integridad.
- La creación de tablas pasa por la API Migration propia de GLPI en lugar de SQL escrito a mano.
- La desinstalación ahora elimina los documentos de informes generados.

## Correcciones

- La casilla "marcar como diagnóstico" en un seguimiento/solución — ausente en todas las versiones de GLPI — ahora se muestra correctamente.

## Requisitos

- GLPI 10.0 – 12.0
- PHP 8.2+, extensión `gd`

## Idiomas

Interfaz, informe PDF y documentación disponibles en español e inglés.
